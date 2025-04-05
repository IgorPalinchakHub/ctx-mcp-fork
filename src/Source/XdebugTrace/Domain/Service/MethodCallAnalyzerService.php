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

/**
 * Service for analyzing method calls in PHP files
 */
class MethodCallAnalyzerService
{
    private array $analyzedMethods = [];
    private array $methodCallMap = [];
    private array $classNamespaceMap = [];
    private array $fileCache = [];


    public function __construct(
        private TypeRepository $typeRepository,
        private TypeInferenceService $typeInferenceService,
        private SkipRulesService $skipRulesService,
        private PhpParserAdapter $parserAdapter,
        private ?LoggerInterface $logger = null,
    ) {
    }


    public function analyzeMethod(
        string $className,
        string $methodName,
        string $filePath,
        int $maxDepth = 20,
    ): ?MethodCall
    {
        $entryPoint = "{$className}::{$methodName}";

        $this->logger?->info("Starting analysis of {$entryPoint} in {$filePath}");

        // Skip if this method should be skipped
        if ($this->skipRulesService->shouldSkipClass($className) ||
            $this->skipRulesService->shouldSkipMethod($methodName)) {
            $this->logger?->info("Skipping {$entryPoint} based on skip rules");
            return null;
        }

        // Parse the file
        $ast = $this->parserAdapter->parseFile($filePath);
        if ($ast === null) {
            $this->logger?->error("Failed to parse file {$filePath}");
            return null;
        }

        // Find the class node
        $classNode = $this->parserAdapter->findClass($ast, $className);
        if (!$classNode) {
            $this->logger?->error("Class {$className} not found in {$filePath}");

            // Try to find the class by short name
            $classShortName = $this->getShortClassName($className);
            foreach ($this->parserAdapter->findInstanceOf($ast, Node\Stmt\Class_::class) as $node) {
                if ($node->name->toString() === $classShortName) {
                    $classNode = $node;

                    // Update the full class name if we found it by short name
                    if ($node->namespacedName) {
                        $className = $node->namespacedName->toString();
                        $this->logger?->info("Found class by short name: {$className}");
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
            $this->logger?->error("Method {$methodName} not found in {$className}");
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


    private function analyzeMethodCalls(
        MethodCall $methodCall,
        string $filePath,
        Node\Stmt\ClassMethod $methodNode,
        int $maxDepth,
        int $depth = 0,
        array $visited = [],
    ): void
    {
        $entryPoint = $methodCall->getSignature();

        // Skip if we've already analyzed this method or reached max depth or detecting recursion
        if (isset($this->analyzedMethods[$entryPoint]) ||
            $depth >= $maxDepth ||
            in_array($entryPoint, $visited, true)) {
            return;
        }

        $this->analyzedMethods[$entryPoint] = true;
        $visited[] = $entryPoint;

        // Find method calls within the method body
        $calls = $this->extractMethodCalls($methodCall->getClassName(), $methodCall->getMethodName(), $methodNode);

        $this->logger?->debug("Found " . count($calls) . " method calls in {$entryPoint}");

        // Process each called method
        foreach ($calls as $calledMethod) {
            $callKey = $calledMethod['signature'];
            list($calledClassName, $calledMethodName) = explode('::', $callKey);

            // Skip if this method should be skipped
            if ($this->skipRulesService->shouldSkipClass($calledClassName) ||
                $this->skipRulesService->shouldSkipMethod($calledMethodName)) {
                $this->logger?->debug("Skipping call to {$callKey} based on skip rules");
                continue;
            }

            // Find the file for the called class
            $calledFilePath = $this->findFileForClass($calledClassName);
            if ($calledFilePath === null) {
                $this->logger?->debug("Could not find file for class {$calledClassName}, skipping");
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
                    $this->logger?->warning("Could not parse file for class {$calledClassName}: {$calledFilePath}");
                    continue;
                }

                // Find the class node
                $calledClassNode = $this->parserAdapter->findClass($calledAst, $calledClassName);
                if (!$calledClassNode) {
                    // Try to find by short name
                    $shortClassName = $this->getShortClassName($calledClassName);
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

                // Log child method call for debugging
                $this->logger?->debug("Found child method call: {$calledClassName}::{$calledMethodName}", [
                    'static' => $calledMethod['isStatic'],
                    'file' => $calledFilePath,
                    'hasParameters' => !empty($calledMethod['parameters']),
                ]);

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


    private function extractMethodCalls(string $className, string $methodName, Node\Stmt\ClassMethod $methodNode): array
    {
        $calls = [];
        $variableTypes = []; // Track variable types in this scope

        // Initialize with this reference
        $variableTypes['this'] = $className;

        // Log method analysis
        $this->logger?->debug("Extracting method calls from {$className}::{$methodName}", [
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
        $methodCallVisitor = new class($className, $methodName, $variableTypes, $this->typeInferenceService, $this->parserAdapter) extends NodeVisitorAbstract {
            /** @var array<array> */
            private array $calls = [];

            /** @var array<string, string> */
            private array $variableTypes = [];

            public function __construct(
                private string $className,
                private string $methodName,
                array $initialVariableTypes,
                private TypeInferenceService $typeInferenceService,
                private PhpParserAdapter $parserAdapter
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
                    $this->variableTypes[$varName] = $className;
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
                if (!($node->class instanceof Node\Name) || !($node->name instanceof Node\Identifier)) {
                    return;
                }

                $className = $node->class->toString();
                $methodName = $node->name->toString();

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

                $className = $node->class->toString();

                // Format arguments
                $parameters = $this->formatArguments($node->args);

                // Create call info for constructor
                $call = [
                    'signature' => "{$className}::__construct",
                    'isStatic' => false,
                    'parameters' => $parameters,
                    'returnType' => $className,
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
                    return $expr->class->toString();
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

                return $this->typeInferenceService->inferMethodReturnType($objectType, $methodName);
            }

            private function inferStaticCallReturnType(Node\Expr\StaticCall $node): ?string
            {
                if (!($node->name instanceof Node\Identifier) || !($node->class instanceof Node\Name)) {
                    return null;
                }

                $className = $node->class->toString();
                $methodName = $node->name->toString();

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

        return $methodCallVisitor->getCalls();
    }


    private function findFileForClass(string $className): ?string
    {
        // Check if we already know where this class is
        if (isset($this->classNamespaceMap[$className])) {
            return $this->classNamespaceMap[$className];
        }

        // Try to find the file using common conventions
        $classPathParts = explode('\\', $className);
        $shortClassName = array_pop($classPathParts);

        // Common directories to search
        $directories = ['src', 'lib', 'app', 'vendor'];

        // Try to locate the file
        foreach ($directories as $dir) {
            // PSR-4 style namespace mapping
            $classPath = $dir . '/' . implode('/', $classPathParts) . '/' . $shortClassName . '.php';
            if (file_exists($classPath)) {
                $this->registerClassNameForFile($className, $classPath);
                return $classPath;
            }

            // Try with lowercase directories (some projects use this convention)
            $lowerNamespace = strtolower(implode('/', $classPathParts));
            $classPath = $dir . '/' . $lowerNamespace . '/' . $shortClassName . '.php';
            if (file_exists($classPath)) {
                $this->registerClassNameForFile($className, $classPath);
                return $classPath;
            }
        }

        // If not found, try to scan known files for the class
        foreach ($this->fileCache as $filePath => $content) {
            $ast = $this->parserAdapter->parseFile($filePath);
            if ($ast) {
                $classNode = $this->parserAdapter->findClass($ast, $className);
                if ($classNode) {
                    $this->registerClassNameForFile($className, $filePath);
                    return $filePath;
                }
            }
        }

        // Try using reflection if available
        try {
            if (class_exists($className)) {
                $reflector = new \ReflectionClass($className);
                $reflectionFile = $reflector->getFileName();
                if ($reflectionFile) {
                    $this->registerClassNameForFile($className, $reflectionFile);
                    return $reflectionFile;
                }
            }
        } catch (\Throwable $e) {
            // Couldn't resolve class with reflection, continue with other methods
        }

        return null;
    }


    private function registerClassNameForFile(string $className, string $filePath): void
    {
        $this->classNamespaceMap[$className] = $filePath;
    }


    private function getShortClassName(string $fullyQualifiedName): string
    {
        $parts = explode('\\', $fullyQualifiedName);
        return end($parts);
    }


    public function getAnalyzedMethodsCount(): int
    {
        return count($this->analyzedMethods);
    }


    public function getAnalyzedMethods(): array
    {
        return $this->analyzedMethods;
    }


    public function clearCache(): void
    {
        $this->analyzedMethods = [];
        $this->methodCallMap = [];
        $this->classNamespaceMap = [];
        $this->fileCache = [];
    }
}
