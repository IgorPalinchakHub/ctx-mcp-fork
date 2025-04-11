<?php
// Path: /src/Source/XdebugTrace/Domain/Service/MethodCallAnalyzerService.php

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\CallContext;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TypedParameter;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;
use Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser\PhpParserAdapter;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced service for analyzing method calls in PHP files
 */
class MethodCallAnalyzerService
{
    /**
     * Map of methods that have been analyzed
     *
     * @var array<string, bool>
     */
    private array $analyzedMethods = [];

    /**
     * Cache of method call objects
     *
     * @var array<string, MethodCall>
     */
    private array $methodCallMap = [];

    /**
     * Map of class names to file paths
     *
     * @var array<string, string>
     */
    private array $classNamespaceMap = [];

    /**
     * Cache of file contents
     *
     * @var array<string, string>
     */
    private array $fileCache = [];

    /**
     * Dependency graph for cycle detection
     */
    private DependencyGraph $dependencyGraph;

    /**
     * Constructor
     */
    public function __construct(
        private TypeRepository $typeRepository,
        private TypeInferenceService $typeInferenceService,
        private SkipRulesService $skipRulesService,
        private PhpParserAdapter $parserAdapter,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger ??= new NullLogger();
        $this->dependencyGraph = new DependencyGraph();
    }

    /**
     * Analyze a method to discover its call hierarchy
     *
     * @param string $className Fully qualified class name
     * @param string $methodName Method name to analyze
     * @param string $filePath Path to the file containing the class
     * @param int $maxDepth Maximum depth to analyze
     * @return MethodCall|null Root method call or null if analysis failed
     */
    public function analyzeMethod(
        string $className,
        string $methodName,
        string $filePath,
        int $maxDepth = 20
    ): ?MethodCall {
        $entryPoint = "{$className}::{$methodName}";

        $this->logger->info("Starting analysis of {$entryPoint} in {$filePath}");

        // Skip if this method should be skipped
        if ($this->skipRulesService->shouldSkipClass($className) ||
            $this->skipRulesService->shouldSkipMethod($methodName)) {
            $this->logger->info("Skipping {$entryPoint} based on skip rules");
            return null;
        }

        // Parse the file
        $ast = $this->parserAdapter->parseFile($filePath);
        if ($ast === null) {
            $this->logger->error("Failed to parse file {$filePath}");
            return null;
        }

        // Find the class node
        $classNode = $this->parserAdapter->findClass($ast, $className);
        if (!$classNode) {
            $this->logger->error("Class {$className} not found in {$filePath}");

            // Try to find the class by short name
            $classShortName = $this->parserAdapter->getShortClassName($className);
            foreach ($this->parserAdapter->findInstanceOf($ast, Node\Stmt\Class_::class) as $node) {
                if ($node->name->toString() === $classShortName) {
                    $classNode = $node;

                    // Update the full class name if we found it by short name
                    if ($node->namespacedName) {
                        $className = $node->namespacedName->toString();
                        $this->logger->info("Found class by short name: {$className}");
                    }
                    break;
                }
            }

            if (!$classNode) {
                return null;
            }
        }

        // Find the method node
        $methodNode = null;
        foreach ($classNode->getMethods() as $node) {
            if ($node->name->toString() === $methodName) {
                $methodNode = $node;
                break;
            }
        }

        if ($methodNode === null) {
            $this->logger->error("Method {$methodName} not found in {$className}");
            return null;
        }

        // Create a method call object for the entry point
        $isStatic = $methodNode->isStatic();
        $lineNumber = $methodNode->getStartLine();
        $returnType = $this->parserAdapter->extractMethodReturnType($methodNode);

        $callContext = new CallContext($filePath, $lineNumber);

        $rootCall = new MethodCall(
            $className,
            $methodName,
            $isStatic,
            [], // Parameters will be populated later
            $returnType,
            $callContext
        );

        // Store in the method call map
        $this->methodCallMap[$entryPoint] = $rootCall;

        // Extract method parameters
        $parameterData = $this->parserAdapter->extractMethodParameters($methodNode);
        $parameters = [];

        foreach ($parameterData as $paramName => $paramInfo) {
            $param = new TypedParameter(
                $paramName,
                $paramInfo['type'] ?? null,
                null,
                null,
                $paramInfo['hasDefault'] ?? false,
                $paramInfo['defaultValue'] ?? null
            );

            $parameters[] = $param;
        }

        $rootCall->setParameters($parameters);

        // Map file path to class name for quick lookup
        $this->registerClassNameForFile($className, $filePath);

        // Recursively analyze method calls
        $this->analyzeMethodCalls($rootCall, $filePath, $methodNode, $maxDepth);

        return $rootCall;
    }

    /**
     * Recursively analyze method calls
     *
     * @param MethodCall $methodCall Current method call to analyze
     * @param string $filePath Path to the file containing the method
     * @param Node\Stmt\ClassMethod $methodNode AST node for the method
     * @param int $maxDepth Maximum depth to analyze
     * @param int $depth Current depth in the call stack
     * @param array<string> $visited Method signatures already visited (to detect cycles)
     */
    private function analyzeMethodCalls(
        MethodCall $methodCall,
        string $filePath,
        Node\Stmt\ClassMethod $methodNode,
        int $maxDepth,
        int $depth = 0,
        array $visited = []
    ): void {
        $entryPoint = $methodCall->getSignature();

        // Skip if we've already analyzed this method or reached max depth or detecting recursion
        if (isset($this->analyzedMethods[$entryPoint]) ||
            $depth >= $maxDepth ||
            in_array($entryPoint, $visited, true)) {
            return;
        }

        $this->analyzedMethods[$entryPoint] = true;
        $visited[] = $entryPoint;

        // Add the method to the dependency graph
        $this->dependencyGraph->addNode($entryPoint);

        // Find method calls within the method body
        $calls = $this->extractMethodCalls($methodCall->getClassName(), $methodCall->getMethodName(), $methodNode, $filePath);

        $this->logger->debug("Found " . count($calls) . " method calls in {$entryPoint}");

        // Process each called method
        foreach ($calls as $calledMethod) {
            $callKey = $calledMethod['signature'];
            list($calledClassName, $calledMethodName) = explode('::', $callKey);

            // Skip if this method should be skipped
            if ($this->skipRulesService->shouldSkipClass($calledClassName) ||
                $this->skipRulesService->shouldSkipMethod($calledMethodName)) {
                $this->logger->debug("Skipping call to {$callKey} based on skip rules");
                continue;
            }

            // Add the dependency to the graph
            $this->dependencyGraph->addEdge($entryPoint, $callKey);

            // Find the file for the called class
            $calledFilePath = $this->findFileForClass($calledClassName);
            if ($calledFilePath === null) {
                $this->logger->debug("Could not find file for class {$calledClassName}, skipping");
                continue;
            }

            // Create a method call object for the called method
            $childMethodCall = null;

            // Check if we've already created this method call
            if (isset($this->methodCallMap[$callKey])) {
                $childMethodCall = $this->methodCallMap[$callKey];
            } else {
                // Parse the called file
                $calledAst = $this->parserAdapter->parseFile($calledFilePath);
                if ($calledAst === null) {
                    $this->logger->warning("Could not parse file for class {$calledClassName}: {$calledFilePath}");
                    continue;
                }

                // Find the class node
                $calledClassNode = $this->parserAdapter->findClass($calledAst, $calledClassName);
                if (!$calledClassNode) {
                    // Try to find by short name
                    $shortClassName = $this->parserAdapter->getShortClassName($calledClassName);
                    foreach ((new NodeFinder())->findInstanceOf($calledAst, Node\Stmt\Class_::class) as $node) {
                        if ($node->name->toString() === $shortClassName) {
                            $calledClassNode = $node;

                            // Update class name if we found it by short name
                            if ($node->namespacedName) {
                                $calledClassName = $node->namespacedName->toString();
                                $callKey = "{$calledClassName}::{$calledMethodName}";
                            }
                            break;
                        }
                    }

                    if (!$calledClassNode) {
                        continue;
                    }
                }

                // Find the method node
                $calledMethodNode = null;
                foreach ($calledClassNode->getMethods() as $node) {
                    if ($node->name->toString() === $calledMethodName) {
                        $calledMethodNode = $node;
                        break;
                    }
                }

                if ($calledMethodNode === null) {
                    continue;
                }

                // Set the return type if we can determine it
                $returnType = $calledMethod['returnType'] ??
                    $this->parserAdapter->extractMethodReturnType($calledMethodNode);

                // Create the call context with file and line information
                $callContext = new CallContext(
                    $calledFilePath,
                    $calledMethodNode->getStartLine(),
                    $filePath,
                    $calledMethod['line'] ?? 0
                );

                // Create the child method call
                $childMethodCall = new MethodCall(
                    $calledClassName,
                    $calledMethodName,
                    $calledMethod['isStatic'],
                    [], // Parameters will be populated below
                    $returnType,
                    $callContext
                );

                // Store in the method call map
                $this->methodCallMap[$callKey] = $childMethodCall;

                // Register class name for future lookups
                $this->registerClassNameForFile($calledClassName, $calledFilePath);
            }

            // Add parameters to the method call
            if (!empty($calledMethod['parameters'])) {
                $parameters = [];

                foreach ($calledMethod['parameters'] as $index => $paramData) {
                    $param = new TypedParameter(
                        $paramData['name'] ?? "param" . ($index + 1),
                        $paramData['type'] ?? null,
                        $paramData['value'] ?? null,
                        $paramData['sourceVariable'] ?? null
                    );

                    $parameters[] = $param;
                }

                $childMethodCall->setParameters($parameters);
            }

            // Add the child method call to the parent
            $methodCall->addChildCall($childMethodCall);

            // Recursively analyze the called method if we haven't analyzed it yet
            if (!isset($this->analyzedMethods[$callKey]) && $calledFilePath !== null) {
                $calledAst = $this->parserAdapter->parseFile($calledFilePath);
                if ($calledAst !== null) {
                    // Find the class node again
                    $calledClassNode = $this->parserAdapter->findClass($calledAst, $calledClassName);
                    if (!$calledClassNode) {
                        continue;
                    }

                    // Find the method node again
                    $calledMethodNode = null;
                    foreach ($calledClassNode->getMethods() as $node) {
                        if ($node->name->toString() === $calledMethodName) {
                            $calledMethodNode = $node;
                            break;
                        }
                    }

                    if ($calledMethodNode !== null) {
                        // Recursively analyze the called method
                        $this->analyzeMethodCalls(
                            $childMethodCall,
                            $calledFilePath,
                            $calledMethodNode,
                            $maxDepth,
                            $depth + 1,
                            $visited
                        );
                    }
                }
            }
        }
    }

    /**
     * Extract method calls from a method node
     *
     * @param string $className Class containing the method
     * @param string $methodName Name of the method
     * @param Node\Stmt\ClassMethod $methodNode AST node for the method
     * @param string $contextFilePath Path to the file containing the method (for resolving aliases)
     * @return array List of method calls found
     */
    private function extractMethodCalls(
        string $className,
        string $methodName,
        Node\Stmt\ClassMethod $methodNode,
        string $contextFilePath
    ): array {
        $calls = [];
        $variableTypes = []; // Track variable types in this scope

        // Initialize with this reference
        $variableTypes['this'] = $className;

        // Log method analysis
        $this->logger->debug("Extracting method calls from {$className}::{$methodName}", [
            'hasStatements' => !empty($methodNode->stmts),
            'stmtCount' => count($methodNode->stmts ?? []),
        ]);

        // Add parameter types
        $params = $this->parserAdapter->extractMethodParameters($methodNode);
        foreach ($params as $paramName => $paramInfo) {
            if (!empty($paramInfo['type'])) {
                $variableTypes[$paramName] = $paramInfo['type'];
            }
        }

        // Check for PHPDoc parameter types
        $docComment = $methodNode->getDocComment();
        if ($docComment) {
            $paramTypes = $this->typeInferenceService->extractParamTypesFromDocComment($docComment->getText());
            foreach ($paramTypes as $paramName => $paramType) {
                $variableTypes[$paramName] = $paramType;
            }
        }

        // Create a new visitor that will collect method calls
        $methodCallVisitor = new class(
            $className,
            $methodName,
            $variableTypes,
            $this->typeInferenceService,
            $this->parserAdapter,
            $contextFilePath,
            $this->logger
        ) extends NodeVisitorAbstract {
            /** @var array<array> Method calls collected */
            private array $calls = [];

            /** @var array<string, string> Type mapping for variables */
            private array $variableTypes = [];

            /** @var array<string> Properties accessed in the method */
            private array $accessedProperties = [];

            public function __construct(
                private string $className,
                private string $methodName,
                array $initialVariableTypes,
                private TypeInferenceService $typeInferenceService,
                private PhpParserAdapter $parserAdapter,
                private string $contextFilePath,
                private LoggerInterface $logger
            ) {
                $this->variableTypes = $initialVariableTypes;
            }

            public function enterNode(Node $node): void
            {
                // Track variable assignments
                if ($node instanceof Node\Expr\Assign) {
                    $this->handleAssignment($node);
                }

                // Track method calls
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->handleMethodCall($node);
                } elseif ($node instanceof Node\Expr\StaticCall) {
                    $this->handleStaticCall($node);
                } elseif ($node instanceof Node\Expr\New_) {
                    $this->handleNewExpr($node);
                }
            }

            private function handleAssignment(Node\Expr\Assign $node): void
            {
                if (!($node->var instanceof Node\Expr\Variable) || !is_string($node->var->name)) {
                    return;
                }

                $varName = $node->var->name;

                // If assigning a new object
                if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                    $className = $node->expr->class->toString();
                    // Resolve any class aliases
                    $resolvedClassName = $this->parserAdapter->resolveClassName($className, $this->contextFilePath);
                    $this->variableTypes[$varName] = $resolvedClassName;
                    return;
                }

                // If assigning from a method call
                if ($node->expr instanceof Node\Expr\MethodCall) {
                    $type = $this->inferMethodCallReturnType($node->expr);
                    if ($type) {
                        $this->variableTypes[$varName] = $type;
                    }
                    return;
                }

                // If assigning from a static call
                if ($node->expr instanceof Node\Expr\StaticCall) {
                    $type = $this->inferStaticCallReturnType($node->expr);
                    if ($type) {
                        $this->variableTypes[$varName] = $type;
                    }
                    return;
                }

                // If assigning from another variable
                if ($node->expr instanceof Node\Expr\Variable && is_string($node->expr->name)) {
                    $sourceVar = $node->expr->name;
                    if (isset($this->variableTypes[$sourceVar])) {
                        $this->variableTypes[$varName] = $this->variableTypes[$sourceVar];
                    }
                    return;
                }
            }

            private function handleMethodCall(Node\Expr\MethodCall $node): void
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return;
                }

                $methodName = $node->name->toString();
                $objectType = $this->resolveObjectType($node->var);

                if ($objectType) {
                    // Format arguments
                    $parameters = $this->formatArguments($node->args);

                    // Determine return type
                    $returnType = $this->typeInferenceService->inferMethodReturnType($objectType, $methodName);

                    // Create call info
                    $call = [
                        'signature' => "{$objectType}::{$methodName}",
                        'isStatic' => false,
                        'parameters' => $parameters,
                        'returnType' => $returnType,
                        'line' => $node->getStartLine()
                    ];

                    $this->calls[] = $call;
                }
            }

            private function handleStaticCall(Node\Expr\StaticCall $node): void
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return;
                }

                $className = null;
                $methodName = $node->name->toString();

                // Handle different types of class references
                if ($node->class instanceof Node\Name) {
                    $rawClassName = $node->class->toString();

                    // Resolve class name (handling aliases)
                    $className = $this->parserAdapter->resolveClassName($rawClassName, $this->contextFilePath);

                    // Special cases like parent/self/static
                    if (in_array($rawClassName, ['self', 'static'])) {
                        $className = $this->className;
                    } elseif ($rawClassName === 'parent') {
                        // We'll keep it as 'parent' since we don't have the parent class info readily available
                        $className = 'parent';
                    }
                }

                if (!$className) {
                    return;
                }

                // Format arguments
                $parameters = $this->formatArguments($node->args);

                // Determine return type
                $returnType = $this->typeInferenceService->inferMethodReturnType($className, $methodName);

                // Create call info
                $call = [
                    'signature' => "{$className}::{$methodName}",
                    'isStatic' => true,
                    'parameters' => $parameters,
                    'returnType' => $returnType,
                    'line' => $node->getStartLine()
                ];

                $this->calls[] = $call;
            }

            private function handleNewExpr(Node\Expr\New_ $node): void
            {
                if (!($node->class instanceof Node\Name)) {
                    return;
                }

                $rawClassName = $node->class->toString();
                $className = $this->parserAdapter->resolveClassName($rawClassName, $this->contextFilePath);

                // Format arguments
                $parameters = $this->formatArguments($node->args);

                // Create call info for constructor
                $call = [
                    'signature' => "{$className}::__construct",
                    'isStatic' => false,
                    'parameters' => $parameters,
                    'returnType' => $className, // Constructor returns the instance
                    'line' => $node->getStartLine()
                ];

                $this->calls[] = $call;
            }

            private function formatArguments(array $args): array
            {
                $formattedArgs = [];

                foreach ($args as $index => $arg) {
                    $formattedValue = $this->parserAdapter->formatExpr($arg->value);
                    $sourceVariable = null;

                    // If the argument is a variable, track its source
                    if ($arg->value instanceof Node\Expr\Variable && is_string($arg->value->name)) {
                        $sourceVariable = $arg->value->name;
                    }

                    $formattedArgs[] = [
                        'name' => "param" . ($index + 1),
                        'value' => $formattedValue,
                        'sourceVariable' => $sourceVariable,
                        'type' => $this->inferArgumentType($arg->value)
                    ];
                }

                return $formattedArgs;
            }

            private function inferArgumentType(Node\Expr $expr): ?string
            {
                if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                    $varName = $expr->name;
                    return $this->variableTypes[$varName] ?? null;
                }

                if ($expr instanceof Node\Expr\MethodCall) {
                    return $this->inferMethodCallReturnType($expr);
                }

                if ($expr instanceof Node\Expr\StaticCall) {
                    return $this->inferStaticCallReturnType($expr);
                }

                if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
                    $className = $expr->class->toString();
                    return $this->parserAdapter->resolveClassName($className, $this->contextFilePath);
                }

                if ($expr instanceof Node\Scalar\String_) {
                    return 'string';
                }

                if ($expr instanceof Node\Scalar\LNumber) {
                    return 'int';
                }

                if ($expr instanceof Node\Scalar\DNumber) {
                    return 'float';
                }

                if ($expr instanceof Node\Expr\ConstFetch) {
                    $name = $expr->name->toString();
                    if (in_array($name, ['true', 'false'])) {
                        return 'bool';
                    }
                    if ($name === 'null') {
                        return 'null';
                    }
                }

                if ($expr instanceof Node\Expr\Array_) {
                    return 'array';
                }

                return null;
            }

            private function resolveObjectType(Node\Expr $expr): ?string
            {
                // $this reference
                if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
                    return $this->className;
                }

                // Variable with known type
                if ($expr instanceof Node\Expr\Variable && is_string($expr->name) && isset($this->variableTypes[$expr->name])) {
                    return $this->variableTypes[$expr->name];
                }

                // Property of $this
                if ($expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
                    // Try to infer property type by name conventions
                    $propertyName = $expr->name->toString();

                    // Check common patterns in property names
                    return $this->typeInferenceService->inferTypeFromVariableName($propertyName);
                }

                // Method call chain
                if ($expr instanceof Node\Expr\MethodCall) {
                    return $this->inferMethodCallReturnType($expr);
                }

                return null;
            }

            private function inferMethodCallReturnType(Node\Expr\MethodCall $node): ?string
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return null;
                }

                $methodName = $node->name->toString();
                $objectType = $this->resolveObjectType($node->var);

                if (!$objectType) {
                    return null;
                }

                $fluentPrefixes = ['add', 'set', 'with', 'build', 'create'];
                foreach ($fluentPrefixes as $prefix) {
                    if (strpos($methodName, $prefix) === 0) {
                        // In fluent interfaces, methods typically return $this
                        return $objectType;
                    }
                }


                return $this->typeInferenceService->inferMethodReturnType($objectType, $methodName);
            }

            private function inferStaticCallReturnType(Node\Expr\StaticCall $node): ?string
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return null;
                }

                $className = null;
                $methodName = $node->name->toString();

                if ($node->class instanceof Node\Name) {
                    $rawClassName = $node->class->toString();

                    // Handle special class references
                    if (in_array($rawClassName, ['self', 'static'])) {
                        $className = $this->className;
                    } elseif ($rawClassName === 'parent') {
                        $className = 'parent';
                    } else {
                        // Resolve the class name including aliases
                        $className = $this->parserAdapter->resolveClassName($rawClassName, $this->contextFilePath);
                    }
                }

                if (!$className) {
                    return null;
                }

                return $this->typeInferenceService->inferMethodReturnType($className, $methodName);
            }

            public function getCalls(): array
            {
                return $this->calls;
            }
        };

        // Create traverser and add visitor
        $traverser = $this->parserAdapter->createNodeTraverser();
        $traverser->addVisitor($methodCallVisitor);

        // Traverse the method statements
        if ($methodNode->stmts) {
            $traverser->traverse($methodNode->stmts);
        }

        $calls = $methodCallVisitor->getCalls();

        // Log the number of calls found
        $this->logger->debug("Extracted " . count($calls) . " method calls from {$className}::{$methodName}");

        return $calls;


    }

    /**
     * Find a file for a class using the PhpParserAdapter
     */
    private function findFileForClass(string $className): ?string
    {
        // Check if we already know where this class is
        if (isset($this->classNamespaceMap[$className])) {
            return $this->classNamespaceMap[$className];
        }

        // Try to find using adapter
        $filePath = $this->parserAdapter->findFileForClass($className);
        if ($filePath) {
            $this->registerClassNameForFile($className, $filePath);
            return $filePath;
        }

        return null;
    }

    /**
     * Register a class name to file mapping
     */
    private function registerClassNameForFile(string $className, string $filePath): void
    {
        $this->classNamespaceMap[$className] = $filePath;
    }

    /**
     * Get the number of methods that have been analyzed
     */
    public function getAnalyzedMethodsCount(): int
    {
        return count($this->analyzedMethods);
    }

    /**
     * Get the methods that have been analyzed
     *
     * @return array<string, bool>
     */
    public function getAnalyzedMethods(): array
    {
        return $this->analyzedMethods;
    }

    /**
     * Get detected circular dependencies
     *
     * @return array<array<string>> List of circular dependency paths
     */
    public function getCircularDependencies(): array
    {
        return $this->dependencyGraph->detectCircularDependencies();
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->analyzedMethods = [];
        $this->methodCallMap = [];
        $this->classNamespaceMap = [];
        $this->fileCache = [];
        $this->dependencyGraph = new DependencyGraph();
    }
}
