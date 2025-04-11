<?php
// Path: /src/Source/XdebugTrace/Infrastructure/Parser/PhpParserAdapter.php

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\TypeInferenceService;
use Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Cache\LRUCache;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enhanced adapter for PHP Parser library to work with PHP AST
 */
class PhpParserAdapter
{
    private Parser $parser;
    private NodeFinder $nodeFinder;
    private PrettyPrinter $prettyPrinter;

    /**
     * LRU cache for parsed files
     */
    private LRUCache $parsedFilesCache;

    /**
     * Cache for class to file mapping
     *
     * @var array<string, string>
     */
    private array $classFileMap = [];

    /**
     * PSR-4 namespace to directory mappings
     * (populated from composer and manual configurations)
     *
     * @var array<string, string>
     */
    private array $namespaceMap = [];

    /**
     * List of search directories for files
     *
     * @var array<string>
     */
    private array $searchDirectories = [];

    /**
     * Track found class aliasing (use statements)
     *
     * @var array<string, array<string, string>> Maps file path to [alias => FQN]
     */
    private array $classAliases = [];

    public function __construct(
        private TypeRepository $typeRepository,
        private TypeInferenceService $typeInferenceService,
        private ?LoggerInterface $logger = null,
        int $cacheSize = 100,
        ?array $additionalNamespacePaths = null,
        ?array $searchDirectories = null
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
        $this->prettyPrinter = new PrettyPrinter();
        $this->parsedFilesCache = new LRUCache($cacheSize);
        $this->logger ??= new NullLogger();

        // Setup search directories
        $this->searchDirectories = $searchDirectories ?? ['src', 'lib', 'app', 'vendor'];

        // Try to extract namespace mappings from Composer autoloader
        $this->initializeNamespaceMappings();

        // Add any additional namespace paths passed in the constructor
        if ($additionalNamespacePaths) {
            foreach ($additionalNamespacePaths as $namespace => $path) {
                $this->namespaceMap[trim($namespace, '\\')] = $path;
            }
        }
    }

    /**
     * Initialize namespace to directory mappings from Composer autoloader
     */
    private function initializeNamespaceMappings(): void
    {
        try {
            // Try different approaches to get Composer's namespace mappings
            $this->extractNamespacesFromComposerClasses();
            $this->extractNamespacesFromComposerJson();

            $this->logger->debug('Initialized namespace mappings', [
                'count' => count($this->namespaceMap),
                'namespaces' => array_keys($this->namespaceMap)
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to initialize namespace mappings: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Extract namespace mappings from Composer classes
     */
    private function extractNamespacesFromComposerClasses(): void
    {
        if (!class_exists('\Composer\Autoload\ClassLoader')) {
            return;
        }

        try {
            // Try to get Composer's autoloader
            $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
            $composerDir = dirname($reflection->getFileName(), 2);

            // Load the PSR-4 mappings from installed.json if available
            $installedJsonPath = $composerDir . '/installed.json';
            if (file_exists($installedJsonPath)) {
                $installed = json_decode(file_get_contents($installedJsonPath), true);

                if (isset($installed['packages'])) {
                    foreach ($installed['packages'] as $package) {
                        if (isset($package['autoload']['psr-4'])) {
                            foreach ($package['autoload']['psr-4'] as $namespace => $path) {
                                // Store normalized namespace => path mapping
                                $this->namespaceMap[trim($namespace, '\\')] = rtrim($path, '/');
                            }
                        }
                    }
                }
            }

            // Additionally, try to get mappings directly from the autoloader
            $this->extractNamespacesFromClassloader();
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to extract namespaces from Composer classes: ' . $e->getMessage());
        }
    }

    /**
     * Extract namespace mappings from the Composer classloader
     */
    private function extractNamespacesFromClassloader(): void
    {
        try {
            $classLoader = null;

            // Find the ClassLoader instance
            foreach (get_declared_classes() as $className) {
                if ($className === 'Composer\Autoload\ClassLoader') {
                    continue;
                }

                if (is_subclass_of($className, 'Composer\Autoload\ClassLoader')) {
                    $reflection = new \ReflectionClass($className);
                    if ($reflection->hasMethod('getLoader')) {
                        $loaderMethod = $reflection->getMethod('getLoader');
                        if ($loaderMethod->isStatic()) {
                            $classLoader = $loaderMethod->invoke(null);
                            break;
                        }
                    } elseif ($reflection->hasMethod('getInstance')) {
                        $instanceMethod = $reflection->getMethod('getInstance');
                        if ($instanceMethod->isStatic()) {
                            $instance = $instanceMethod->invoke(null);
                            $classLoader = $instance;
                            break;
                        }
                    }
                }
            }

            if ($classLoader) {
                // Try calling getPrefixesPsr4()
                if (method_exists($classLoader, 'getPrefixesPsr4')) {
                    $prefixesPsr4 = $classLoader->getPrefixesPsr4();
                    foreach ($prefixesPsr4 as $namespace => $paths) {
                        if (!empty($paths)) {
                            $this->namespaceMap[trim($namespace, '\\')] = rtrim($paths[0], '/');
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to extract namespaces from classloader: ' . $e->getMessage());
        }
    }

    /**
     * Extract namespace mappings from composer.json file
     */
    private function extractNamespacesFromComposerJson(): void
    {
        try {
            $composerJsonPath = $this->findComposerJsonFile();

            if (!$composerJsonPath || !file_exists($composerJsonPath)) {
                return;
            }

            $composerJson = json_decode(file_get_contents($composerJsonPath), true);

            if (!is_array($composerJson)) {
                return;
            }

            $projectRoot = dirname($composerJsonPath);

            // Check PSR-4 autoloading
            if (isset($composerJson['autoload']['psr-4'])) {
                foreach ($composerJson['autoload']['psr-4'] as $namespace => $path) {
                    $this->namespaceMap[trim($namespace, '\\')] = $this->resolvePath($path, $projectRoot);
                }
            }

            // Also check for PSR-0 autoloading (older style)
            if (isset($composerJson['autoload']['psr-0'])) {
                foreach ($composerJson['autoload']['psr-0'] as $namespace => $path) {
                    $namespace = trim($namespace, '\\');
                    $path = $this->resolvePath($path, $projectRoot);
                    // In PSR-0, underscores in the namespace convert to directory separators
                    $this->namespaceMap[$namespace] = $path;
                }
            }

            // Also add dev autoload mappings
            if (isset($composerJson['autoload-dev']['psr-4'])) {
                foreach ($composerJson['autoload-dev']['psr-4'] as $namespace => $path) {
                    $this->namespaceMap[trim($namespace, '\\')] = $this->resolvePath($path, $projectRoot);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to extract namespaces from composer.json: ' . $e->getMessage());
        }
    }

    /**
     * Find composer.json file by searching up from current directory
     */
    private function findComposerJsonFile(): ?string
    {
        $path = getcwd();

        // Try going up directories until we find composer.json or hit root
        while ($path && $path !== dirname($path)) {
            $composerJsonPath = $path . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerJsonPath)) {
                return $composerJsonPath;
            }
            $path = dirname($path);
        }

        return null;
    }

    /**
     * Resolve a relative path against a base path
     */
    private function resolvePath(string $path, string $basePath): string
    {
        $path = rtrim($path, '/\\');

        if (str_starts_with($path, './') || str_starts_with($path, '../')) {
            return realpath($basePath . DIRECTORY_SEPARATOR . $path) ?: $basePath . DIRECTORY_SEPARATOR . $path;
        }

        if (!str_starts_with($path, '/')) {
            return $basePath . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    /**
     * Set additional search directories
     *
     * @param array<string> $directories Directories to search in
     */
    public function setSearchDirectories(array $directories): void
    {
        $this->searchDirectories = $directories;
    }

    /**
     * Parse a PHP file and return its AST
     *
     * @param string $filePath Path to the file to parse
     * @return array|null AST nodes or null if parsing failed
     */
    public function parseFile(string $filePath): ?array
    {
        // First check cache
        if ($this->parsedFilesCache->has($filePath)) {
            return $this->parsedFilesCache->get($filePath);
        }

        if (!file_exists($filePath)) {
            $this->logger->warning("File does not exist: {$filePath}");
            return null;
        }

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                $this->logger->error("Failed to read file: {$filePath}");
                return null;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                $this->logger->error("Failed to parse file: {$filePath}");
                return null;
            }

            // Apply name resolution to the AST
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $ast = $traverser->traverse($ast);

            // Cache the parsed result
            $this->parsedFilesCache->put($filePath, $ast);

            // Extract class aliases (use statements)
            $this->extractUseStatements($ast, $filePath);

            // Extract and store type information from the file
            $this->extractTypeInformation($ast, $filePath);

            return $ast;
        } catch (\Throwable $e) {
            $this->logger->error("Error parsing file {$filePath}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Extract use statements from the AST
     */
    private function extractUseStatements(array $ast, string $filePath): void
    {
        $useStatements = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Use_::class);
        $this->classAliases[$filePath] = [];

        foreach ($useStatements as $use) {
            foreach ($use->uses as $useUse) {
                $alias = $useUse->alias ? $useUse->alias->toString() : $this->getShortClassName($useUse->name->toString());
                $fqn = $useUse->name->toString();
                $this->classAliases[$filePath][$alias] = $fqn;
            }
        }
    }

    /**
     * Extract type information from the AST
     */
    private function extractTypeInformation(array $ast, string $filePath): void
    {
        // Find class definitions
        $classes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if (!$class->namespacedName) {
                continue;
            }

            $className = $class->namespacedName->toString();

            // Register class name to file mapping
            $this->classFileMap[$className] = $filePath;

            // Process methods
            foreach ($class->getMethods() as $method) {
                $methodName = $method->name->toString();

                // Extract method return type
                $returnType = $this->extractMethodReturnType($method);
                if ($returnType) {
                    $this->typeRepository->setMethodReturnType($className, $methodName, $returnType);
                }

                // Extract method parameters
                $params = $this->extractMethodParameters($method);
                if (!empty($params)) {
                    $this->typeRepository->storeMethodParams($className, $methodName, $params);
                }

                // Extract types from docblock
                $docComment = $method->getDocComment();
                if ($docComment) {
                    $docText = $docComment->getText();

                    // Extract return type from docblock
                    $docReturnType = $this->typeInferenceService->extractReturnTypeFromDocComment($docText);
                    if ($docReturnType && !$returnType) {
                        $this->typeRepository->setMethodReturnType($className, $methodName, $docReturnType);
                    }

                    // Extract param types from docblock
                    $docParamTypes = $this->typeInferenceService->extractParamTypesFromDocComment($docText);
                    foreach ($docParamTypes as $paramName => $paramType) {
                        if (isset($params[$paramName]) && empty($params[$paramName]['type'])) {
                            $params[$paramName]['type'] = $paramType;
                        }
                    }

                    if (!empty($docParamTypes)) {
                        $this->typeRepository->storeMethodParams($className, $methodName, $params);
                    }
                }
            }

            // Process properties
            foreach ($class->getProperties() as $property) {
                if (!isset($property->props[0])) {
                    continue;
                }

                $propertyName = $property->props[0]->name->toString();

                if ($property->type) {
                    $propertyType = $this->resolveTypeNodeToClassName($property->type);

                    if ($propertyType) {
                        $this->typeRepository->setPropertyType($className, $propertyName, $propertyType);
                    }
                } elseif ($property->getDocComment()) {
                    // Try to extract type from PHPDoc
                    $docComment = $property->getDocComment()->getText();
                    $propertyType = $this->typeInferenceService->extractPropertyTypeFromDocComment(
                        $docComment,
                        $propertyName
                    );

                    if ($propertyType) {
                        $this->typeRepository->setPropertyType($className, $propertyName, $propertyType);
                    }
                }
            }

            // Mark this class as having complete type info
            $this->typeRepository->markTypeInfoComplete($className);
        }

        // Also process interfaces
        $interfaces = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Interface_::class);
        foreach ($interfaces as $interface) {
            if (!$interface->namespacedName) {
                continue;
            }

            $interfaceName = $interface->namespacedName->toString();

            // Register interface name to file mapping
            $this->classFileMap[$interfaceName] = $filePath;

            // Process methods
            foreach ($interface->getMethods() as $method) {
                $methodName = $method->name->toString();

                // Extract method return type
                $returnType = $this->extractMethodReturnType($method);
                if ($returnType) {
                    $this->typeRepository->setMethodReturnType($interfaceName, $methodName, $returnType);
                }

                // Extract method parameters
                $params = $this->extractMethodParameters($method);
                if (!empty($params)) {
                    $this->typeRepository->storeMethodParams($interfaceName, $methodName, $params);
                }
            }

            // Mark interface as having complete type info
            $this->typeRepository->markTypeInfoComplete($interfaceName);
        }

        // Process traits
        $traits = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Trait_::class);
        foreach ($traits as $trait) {
            if (!$trait->namespacedName) {
                continue;
            }

            $traitName = $trait->namespacedName->toString();

            // Register trait name to file mapping
            $this->classFileMap[$traitName] = $filePath;
        }
    }

    /**
     * Extract the namespace from an AST
     */
    public function extractNamespace(array $ast): ?string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                return $node->name->toString();
            }
        }

        return null;
    }

    /**
     * Extract the class name from an AST
     */
    public function extractClassName(array $ast): ?string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                foreach ($stmt->stmts as $subStmt) {
                    if ($subStmt instanceof Node\Stmt\Class_ ||
                        $subStmt instanceof Node\Stmt\Interface_ ||
                        $subStmt instanceof Node\Stmt\Trait_) {
                        return $subStmt->name->toString();
                    }
                }
            } elseif ($stmt instanceof Node\Stmt\Class_ ||
                $stmt instanceof Node\Stmt\Interface_ ||
                $stmt instanceof Node\Stmt\Trait_) {
                return $stmt->name->toString();
            }
        }

        return null;
    }

    /**
     * Find methods in a class from the AST
     *
     * @return Node\Stmt\ClassMethod[]
     */
    public function findClassMethods(array $ast): array
    {
        return $this->nodeFinder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
    }

    /**
     * Find a specific method in a class from the AST
     */
    public function findClassMethod(array $ast, string $methodName): ?Node\Stmt\ClassMethod
    {
        $methods = $this->findClassMethods($ast);

        foreach ($methods as $method) {
            if ($method->name->toString() === $methodName) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Find instances of a specific node type in the AST
     */
    public function findInstanceOf(array $ast, string $nodeType): array
    {
        return $this->nodeFinder->findInstanceOf($ast, $nodeType);
    }

    /**
     * Find a class node in the AST
     */
    public function findClass(array $ast, string $className): ?Node\Stmt\Class_
    {
        $classes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);
        $shortClassName = $this->getShortClassName($className);

        // First try exact match
        foreach ($classes as $class) {
            if ($class->namespacedName && $class->namespacedName->toString() === $className) {
                return $class;
            }
        }

        // Try short name match
        $matchedByShortName = null;
        foreach ($classes as $class) {
            if ($class->name->toString() === $shortClassName) {
                $matchedByShortName = $class;
                break;
            }
        }

        // If found by short name, use it
        if ($matchedByShortName) {
            return $matchedByShortName;
        }

        // Try with namespace if available
        $namespace = $this->extractNamespace($ast);
        if ($namespace) {
            $possibleClassName = $namespace . '\\' . $shortClassName;
            foreach ($classes as $class) {
                if ($class->namespacedName && $class->namespacedName->toString() === $possibleClassName) {
                    return $class;
                }
            }
        }

        // If still not found, try more advanced approaches
        // Try to check if $className is a fully qualified name without leading backslash
        $classNameWithLeadingSlash = '\\' . $className;
        foreach ($classes as $class) {
            if ($class->namespacedName && '\\' . $class->namespacedName->toString() === $classNameWithLeadingSlash) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Get the short class name from a fully qualified name
     */
    public function getShortClassName(string $fullyQualifiedName): string
    {
        $parts = explode('\\', $fullyQualifiedName);
        return end($parts);
    }

    /**
     * Create a node traverser with name resolution
     */
    public function createNodeTraverser(): NodeTraverser
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        return $traverser;
    }

    /**
     * Extract method parameters from a method node
     */
    // In PhpParserAdapter.php, improve parameter extraction

    public function extractMethodParameters(Node\Stmt\ClassMethod $methodNode): array {
        $params = [];

        foreach ($methodNode->params as $index => $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $paramName = $param->var->name;
                $paramType = null;

                // Extract parameter type and add more detailed information
                if ($param->type) {
                    $paramType = $this->resolveTypeNodeToClassName($param->type);
                }

                $params[$paramName] = [
                    'name' => $paramName,  // Use actual parameter name
                    'position' => $index,  // Track position for matching with arguments
                    'type' => $paramType,
                    'hasDefault' => $param->default !== null,
                    'defaultValue' => $param->default !== null ? $this->formatDefaultValue($param->default) : null,
                ];
            }
        }

        return $params;
    }

    /**
     * Format the default value of a parameter
     */
    private function formatDefaultValue(Node\Expr $default): string
    {
        try {
            return $this->prettyPrinter->prettyPrintExpr($default);
        } catch (\Throwable $e) {
            if ($default instanceof Node\Scalar\String_) {
                return "'" . $default->value . "'";
            }

            if ($default instanceof Node\Scalar\LNumber) {
                return (string)$default->value;
            }

            if ($default instanceof Node\Scalar\DNumber) {
                return (string)$default->value;
            }

            if ($default instanceof Node\Expr\ConstFetch) {
                return $default->name->toString();
            }

            if ($default instanceof Node\Expr\Array_) {
                return '[]';
            }

            return '[default value]';
        }
    }

    /**
     * Extract method return type from a method node
     */
    public function extractMethodReturnType(Node\Stmt\ClassMethod $methodNode): ?string
    {
        if ($methodNode->returnType) {
            return $this->resolveTypeNodeToClassName($methodNode->returnType);
        }

        // Check PHPDoc for return type
        $docComment = $methodNode->getDocComment();
        if ($docComment) {
            return $this->typeInferenceService->extractReturnTypeFromDocComment($docComment->getText());
        }

        return null;
    }

    /**
     * Resolve a type node to a class name
     */
    public function resolveTypeNodeToClassName($typeNode): ?string
    {
        if ($typeNode instanceof Node\Name) {
            $typeName = $typeNode->toString();

            // Handle basic types vs class names
            if (in_array(strtolower($typeName), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed', 'void', 'null', 'resource', 'callable', 'iterable'])) {
                return strtolower($typeName);
            }

            return $typeName;
        }

        if ($typeNode instanceof Node\NullableType) {
            $type = $this->resolveTypeNodeToClassName($typeNode->type);
            return $type ? $type : null;
        }

        if ($typeNode instanceof Node\UnionType && isset($typeNode->types)) {
            // Attempt to find the first valid class in a union
            foreach ($typeNode->types as $subType) {
                $result = $this->resolveTypeNodeToClassName($subType);
                if ($result && $result !== 'null') {
                    return $result;
                }
            }
        }

        if ($typeNode instanceof Node\IntersectionType && isset($typeNode->types)) {
            // For intersection types, return the first type as primary
            if (!empty($typeNode->types)) {
                return $this->resolveTypeNodeToClassName($typeNode->types[0]);
            }
        }

        return null;
    }

    /**
     * Format an expression node as a string
     */
    public function formatExpr(Node\Expr $expr): string
    {
        try {
            return $this->prettyPrinter->prettyPrintExpr($expr);
        } catch (\Throwable $e) {
            // Fallback for various node types
            if ($expr instanceof Node\Scalar\String_) {
                return "'" . $expr->value . "'";
            }

            if ($expr instanceof Node\Scalar\LNumber) {
                return (string)$expr->value;
            }

            if ($expr instanceof Node\Scalar\DNumber) {
                return (string)$expr->value;
            }

            if ($expr instanceof Node\Expr\ConstFetch) {
                return $expr->name->toString();
            }

            if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                return '$' . $expr->name;
            }

            if ($expr instanceof Node\Expr\Array_) {
                return '[array]';
            }

            return '[expression]';
        }
    }

    /**
     * Find a file for a class using multiple strategies
     */
    public function findFileForClass(string $className): ?string
    {
        // Already in cache?
        if (isset($this->classFileMap[$className])) {
            return $this->classFileMap[$className];
        }

        // Try multiple strategies
        $file = $this->findFileViaReflection($className)
            ?? $this->findFileViaPsr4($className)
            ?? $this->findFileViaSearch($className);

        if ($file) {
            $this->classFileMap[$className] = $file;
        }

        return $file;
    }

    /**
     * Try to find class file via PHP reflection
     */
    private function findFileViaReflection(string $className): ?string
    {
        try {
            if (class_exists($className) || interface_exists($className) || trait_exists($className)) {
                $reflector = new \ReflectionClass($className);
                $file = $reflector->getFileName();
                if ($file && file_exists($file)) {
                    return $file;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug("Reflection failed for {$className}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Try to find class file via PSR-4 mapping
     */
    private function findFileViaPsr4(string $className): ?string
    {
        // Sort namespaces by length (longest first) to match most specific first
        $namespaces = array_keys($this->namespaceMap);
        usort($namespaces, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        foreach ($namespaces as $namespace) {
            if (str_starts_with($className, $namespace . '\\')) {
                $relPath = substr($className, strlen($namespace) + 1);
                $filePath = $this->namespaceMap[$namespace] . DIRECTORY_SEPARATOR .
                    str_replace('\\', DIRECTORY_SEPARATOR, $relPath) . '.php';

                if (file_exists($filePath)) {
                    return $filePath;
                }
            }
        }

        return null;
    }

    /**
     * Try to find class file via searching common directories
     */
    private function findFileViaSearch(string $className): ?string
    {
        $classPathParts = explode('\\', $className);
        $shortClassName = array_pop($classPathParts);

        // Try PSR-4 style namespace mapping
        foreach ($this->searchDirectories as $dir) {
            // Full namespace structure
            $classPath = $dir . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $classPathParts)
                . DIRECTORY_SEPARATOR . $shortClassName . '.php';

            if (file_exists($classPath)) {
                return $classPath;
            }

            // Try with lowercase directories (some projects use this convention)
            $lowerNamespace = strtolower(implode(DIRECTORY_SEPARATOR, $classPathParts));
            $classPath = $dir . DIRECTORY_SEPARATOR . $lowerNamespace
                . DIRECTORY_SEPARATOR . $shortClassName . '.php';

            if (file_exists($classPath)) {
                return $classPath;
            }
        }

        // If still not found, try scanning for class files recursively in key directories
        foreach ($this->searchDirectories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $foundFile = $this->scanDirectoryForClass($dir, $shortClassName);
            if ($foundFile) {
                return $foundFile;
            }
        }

        return null;
    }

    /**
     * Scan a directory recursively for a class file
     */
    private function scanDirectoryForClass(string $dir, string $className, int $depth = 0): ?string
    {
        // Avoid going too deep to prevent performance issues
        if ($depth > 10) {
            return null;
        }

        try {
            $iterator = new \RecursiveDirectoryIterator(
                $dir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );

            foreach ($iterator as $file) {
                $filename = $file->getFilename();

                // Skip vendor and test directories to improve performance
                if ($file->isDir() && in_array($filename, ['vendor', 'node_modules', 'tests', 'test'])) {
                    continue;
                }

                // If it's a PHP file with matching name
                if ($file->isFile() && $file->getExtension() === 'php' && $filename === $className . '.php') {
                    // Parse the file to verify it contains the class
                    $path = $file->getPathname();
                    $ast = $this->parseFile($path);

                    if ($ast && $this->fileContainsClass($ast, $className)) {
                        return $path;
                    }
                }

                // Recurse into subdirectories
                if ($file->isDir()) {
                    $foundPath = $this->scanDirectoryForClass($file->getPathname(), $className, $depth + 1);
                    if ($foundPath) {
                        return $foundPath;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug("Error scanning directory {$dir}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Check if a file contains a specific class
     */
    private function fileContainsClass(array $ast, string $className): bool
    {
        $classNodes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classNodes as $node) {
            if ($node->name->toString() === $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a class name in a specific file context, handling aliases
     */
    public function resolveClassName(string $className, string $contextFile): string
    {
        // If the class name is already fully qualified (starts with \)
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        // Check if it's a simple name (no namespace)
        if (!str_contains($className, '\\')) {
            // First check if it's an alias in the current file
            if (isset($this->classAliases[$contextFile][$className])) {
                return $this->classAliases[$contextFile][$className];
            }

            // Otherwise, try to resolve it using the current file's namespace
            $ast = $this->parsedFilesCache->get($contextFile);
            if ($ast) {
                $namespace = $this->extractNamespace($ast);
                if ($namespace) {
                    return $namespace . '\\' . $className;
                }
            }
        }

        // It's already a qualified name but might still use an alias
        $parts = explode('\\', $className);
        $firstPart = array_shift($parts);

        // Check if the first part is an alias
        if (isset($this->classAliases[$contextFile][$firstPart])) {
            return $this->classAliases[$contextFile][$firstPart] .
                (empty($parts) ? '' : '\\' . implode('\\', $parts));
        }

        return $className;
    }

    /**
     * Detect project root directory by looking for common marker files
     */
    private function detectProjectRoot(): ?string
    {
        $currentDir = getcwd();
        $parentDirs = [];

        // Build path hierarchy to search
        $path = $currentDir;
        while ($path && $path !== dirname($path)) {
            $parentDirs[] = $path;
            $path = dirname($path);
        }

        // Common project root markers
        $markers = [
            'composer.json',
            'package.json',
            '.git',
            'artisan', // Laravel
            'web/index.php', // Symfony
            'public/index.php', // Many frameworks
        ];

        // Look for markers in each directory
        foreach ($parentDirs as $dir) {
            foreach ($markers as $marker) {
                if (file_exists($dir . DIRECTORY_SEPARATOR . $marker)) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Clear the caches
     */
    public function clearCache(): void
    {
        $this->parsedFilesCache->clear();
        $this->classFileMap = [];
        $this->classAliases = [];
    }

    /**
     * Register a file path for a class name
     */
    public function registerClassFile(string $className, string $filePath): void
    {
        $this->classFileMap[$className] = $filePath;
    }

    /**
     * Register namespace mappings
     *
     * @param array<string, string> $mappings Namespace prefix to directory mappings
     */
    public function registerNamespaceMappings(array $mappings): void
    {
        foreach ($mappings as $namespace => $dir) {
            $this->namespaceMap[trim($namespace, '\\')] = $dir;
        }
    }

    /**
     * Get the current namespace mappings
     *
     * @return array<string, string> Namespace mappings
     */
    public function getNamespaceMappings(): array
    {
        return $this->namespaceMap;
    }
}
