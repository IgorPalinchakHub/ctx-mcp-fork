<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

use Butschster\ContextGenerator\Application\FSPath;
use Butschster\ContextGenerator\Lib\Content\Block\TextBlock;
use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Lib\Variable\VariableResolver;
use Butschster\ContextGenerator\Modifier\ModifiersApplierInterface;
use Butschster\ContextGenerator\Source\Fetcher\SourceFetcherInterface;
use Butschster\ContextGenerator\Source\SourceInterface;
use PhpParser\NodeVisitor\NameResolver;
use Psr\Log\LoggerInterface;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Fetcher for method call stack trace sources
 * @implements SourceFetcherInterface<TraceSource>
 */
final class TraceSourceFetcher implements SourceFetcherInterface
{
    private \PhpParser\Parser $parser;
    private NodeFinder $nodeFinder;
    private PrettyPrinter $prettyPrinter;
    private ?string $currentClass = null;
    private ?string $currentMethod = null;
    private ?string $currentNamespace = null;

    /** @var array<string, array<string>> */
    private array $callMap = [];

    /** @var array<string, bool> */
    private array $analyzedMethods = [];

    /** @var array<string, array<string>> Enhanced call information storage */
    private array $callInfo = [];

    /** @var array<string> */
    private array $skipMethodPatterns = [];

    /** @var array<string> */
    private array $skipClassPatterns = [];

    /** @var array<string> */
    private array $skipDirPatterns = [];

    /** @var array<string, string> */
    private array $srcFileMap = [];

    /** @var string */
    private string $projectRoot;

    /** @var array<string, array<string, Node\Stmt\ClassMethod>> */
    private array $methodNodes = [];

    /** @var bool */
    private bool $skipSingletonMethods = true;

    /** @var bool */
    private bool $skipConstructors = false;

    /** @var bool */
    private bool $skipInvokeMethods = false;

    /** @var array<string> */
    private array $singletonMethodPatterns = ['/^getInstance$/', '/^instance$/', '/^create$/', '/^singleton$/'];

    /** @var array<string, array<string, string>> Method return types by class and method name */
    private array $methodReturnTypes = [];

    /** @var array<string, array<string, string>> Property types by class and property name */
    private array $propertyTypes = [];

    /** @var array<string, mixed> */
    private array $defaultOptions = [
        // File and class options
        'startFile' => null,
        'class' => null,
        'method' => 'fetch',
        'outputFile' => 'call_stack.md',

        // Skip options
        'skipVendorDir' => true,
        'skipFrameworks' => true,
        'skipSingletonMethods' => true,
        'skipConstructors' => false,
        'skipInvokeMethods' => false,

        // Patterns
        'singletonMethodPatterns' => [],
        'skipDirs' => [],
        'skipClasses' => [],
        'skipMethods' => [],

        // Analysis options
        'maxDepth' => 20
    ];

    /** @var array<string, string> Known framework method return types */
    public array $knownFrameworkTypes = [
        // ContentBuilder fluent interface methods
        'Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory::create' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addTitle' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addDescription' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addCodeBlock' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addBlock' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addSeparator' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',

        // Finder methods
        'Butschster\ContextGenerator\Source\File\SymfonyFinder::find' => 'Butschster\ContextGenerator\Source\File\TreeResult',

        // Variable resolver
        'Butschster\ContextGenerator\Lib\Variable\VariableResolver::resolve' => 'string',
    ];

    public function __construct(
        private ContentBuilderFactory $builderFactory = new ContentBuilderFactory(),
        private VariableResolver $variableResolver = new VariableResolver(),
        private ?LoggerInterface $logger = null,
        string $projectRoot = '',
        array $options = []
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->nodeFinder = new NodeFinder();
        $this->prettyPrinter = new PrettyPrinter();
        $this->projectRoot = $projectRoot ?: getcwd();

        // Apply any constructor options
        if (!empty($options)) {
            $this->defaultOptions = array_merge($this->defaultOptions, $options);
        }

        // Setup default skip patterns
        $this->setupDefaultSkipPatterns();
    }

    /**
     * Setup default patterns for skipping methods, classes, and directories
     */
    private function setupDefaultSkipPatterns(): void
    {
        // Default method patterns to skip
        $this->setSkipMethodPatterns([
            '/^__debugInfo$/',
            '/^__toString$/',
            '/^__isset$/',
            '/^__get$/',
            '/^__set$/',
            '/^__call$/',
            '/^__callStatic$/',
        ]);

        // Default class patterns to skip
        $this->setSkipClassPatterns([]);

        // Default directory patterns to skip
        $this->setSkipDirPatterns(['vendor/']);
    }

    /**
     * Get whether singleton methods should be skipped
     */
    public function isSkipSingletonMethods(): bool
    {
        return $this->skipSingletonMethods;
    }

    /**
     * Set whether singleton methods should be skipped
     */
    public function setSkipSingletonMethods(bool $skip): void
    {
        $this->skipSingletonMethods = $skip;
    }

    /**
     * Get whether constructor methods should be skipped
     */
    public function isSkipConstructors(): bool
    {
        return $this->skipConstructors;
    }

    /**
     * Set whether constructor methods should be skipped
     */
    public function setSkipConstructors(bool $skip): void
    {
        $this->skipConstructors = $skip;
    }

    /**
     * Get whether __invoke methods should be skipped
     */
    public function isSkipInvokeMethods(): bool
    {
        return $this->skipInvokeMethods;
    }

    /**
     * Set whether __invoke methods should be skipped
     */
    public function setSkipInvokeMethods(bool $skip): void
    {
        $this->skipInvokeMethods = $skip;
    }

    /**
     * Get the singleton method patterns
     *
     * @return array<string>
     */
    public function getSingletonMethodPatterns(): array
    {
        return $this->singletonMethodPatterns;
    }

    /**
     * Set the singleton method patterns
     *
     * @param array<string> $patterns
     */
    public function setSingletonMethodPatterns(array $patterns): void
    {
        $this->singletonMethodPatterns = $patterns;
    }

    /**
     * Add singleton method patterns to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSingletonMethodPatterns(array $patterns): void
    {
        $this->singletonMethodPatterns = array_merge($this->singletonMethodPatterns, $patterns);
    }

    /**
     * Get the method patterns to skip
     *
     * @return array<string>
     */
    public function getSkipMethodPatterns(): array
    {
        return $this->skipMethodPatterns;
    }

    /**
     * Set the method patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipMethodPatterns(array $patterns): void
    {
        $this->skipMethodPatterns = $patterns;
    }

    /**
     * Add method patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipMethodPatterns(array $patterns): void
    {
        $this->skipMethodPatterns = array_merge($this->skipMethodPatterns, $patterns);
    }

    /**
     * Get the class patterns to skip
     *
     * @return array<string>
     */
    public function getSkipClassPatterns(): array
    {
        return $this->skipClassPatterns;
    }

    /**
     * Set the class patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipClassPatterns(array $patterns): void
    {
        $this->skipClassPatterns = $patterns;
    }

    /**
     * Add class patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipClassPatterns(array $patterns): void
    {
        $this->skipClassPatterns = array_merge($this->skipClassPatterns, $patterns);
    }

    /**
     * Get the directory patterns to skip
     *
     * @return array<string>
     */
    public function getSkipDirPatterns(): array
    {
        return $this->skipDirPatterns;
    }

    /**
     * Set the directory patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipDirPatterns(array $patterns): void
    {
        $this->skipDirPatterns = $patterns;
    }

    /**
     * Add directory patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipDirPatterns(array $patterns): void
    {
        $this->skipDirPatterns = array_merge($this->skipDirPatterns, $patterns);
    }

    /**
     * Set a known method return type
     */
    public function setMethodReturnType(string $className, string $methodName, string $returnType): void
    {
        if (!isset($this->methodReturnTypes[$className])) {
            $this->methodReturnTypes[$className] = [];
        }
        $this->methodReturnTypes[$className][$methodName] = $returnType;
    }

    /**
     * Get a known method return type
     */
    public function getMethodReturnType(string $className, string $methodName): ?string
    {
        return $this->methodReturnTypes[$className][$methodName] ?? null;
    }

    /**
     * Set a known property type
     */
    public function setPropertyType(string $className, string $propertyName, string $type): void
    {
        if (!isset($this->propertyTypes[$className])) {
            $this->propertyTypes[$className] = [];
        }
        $this->propertyTypes[$className][$propertyName] = $type;
    }

    /**
     * Get a known property type
     */
    public function getPropertyType(string $className, string $propertyName): ?string
    {
        return $this->propertyTypes[$className][$propertyName] ?? null;
    }

    /**
     * Store call information including arguments and return type
     */
    public function storeCallInfo(string $callKey, bool $isStatic, array $arguments = [], ?string $returnType = null): void
    {
        if (!isset($this->callInfo[$callKey])) {
            $this->callInfo[$callKey] = [
                'isStatic' => $isStatic,
                'arguments' => $arguments,
                'returnType' => $returnType
            ];
        }
    }

    /**
     * Get call information for a method
     */
    public function getCallInfo(string $callKey): array
    {
        return $this->callInfo[$callKey] ?? [
            'isStatic' => true, // Default to static if not found
            'arguments' => [],
            'returnType' => null
        ];
    }

    public function supports(SourceInterface $source): bool
    {
        $isSupported = $source instanceof TraceSource;
        $this->logger?->debug('Checking if source is supported', [
            'sourceType' => $source::class,
            'isSupported' => $isSupported,
        ]);
        return $isSupported;
    }

    public function fetch(SourceInterface $source, ModifiersApplierInterface $modifiersApplier): string
    {
        if (!$source instanceof TraceSource) {
            $errorMessage = 'Source must be an instance of TraceSource';
            $this->logger?->error($errorMessage, [
                'sourceType' => $source::class,
            ]);
            throw new \InvalidArgumentException($errorMessage);
        }

        $description = $this->variableResolver->resolve($source->getDescription());

        $this->logger?->info('Fetching trace source content', [
            'description' => $source->getDescription(),
            'renderFormat' => $source->renderFormat,
            'hasModifiers' => !empty($source->modifiers),
        ]);

        // Create builder
        $this->logger?->debug('Creating content builder');
        $builder = $this->builderFactory
            ->create()
            ->addDescription($description);

        // Merge source options with defaults
        $options = array_merge($this->defaultOptions, $source->options ?? []);

        // Extract parameters from options
        /* @var FSPath $startFile */
        $startFile = $options['startFile'];
        $targetClass = $options['class'];
        $targetMethod = $options['method'] ?? 'fetch';
        $outputFile = $options['outputFile'] ?? 'call_stack.md';
        $maxDepth = (int)($options['maxDepth'] ?? 20);

        // If no start file or class is specified, try to determine them
        if (empty($startFile) || empty($targetClass)) {
            list($startFile, $targetClass) = $this->findDefaultStartPoint();
        }

        // Validate required parameters
        if (empty($startFile) || !$startFile->exists()) {
            throw new \InvalidArgumentException("Invalid start file specified: {$startFile}");
        }

        if (empty($targetClass)) {
            throw new \InvalidArgumentException("Target class must be specified");
        }

        // Configure skipping options
        $this->configureSkipOptions($options);

        // Find all source files in the project
        $this->indexProjectFiles();

        // Collect and store type information
        $this->collectTypeInformation();

        // Start analysis from the target method
        $entryPoint = "{$targetClass}::{$targetMethod}";

        // Store initial method info
        $this->storeCallInfo($entryPoint, false, [], $this->getMethodReturnType($targetClass, $targetMethod));

        // Process the starting file
        $this->processSourceFile($startFile->toString());

        // Start recursive method call analysis from the entry point
        $this->analyzeMethodCalls($entryPoint, $maxDepth);

        // Generate the markdown output for the call stack
        $markdownContent = $this->generateCallStackMarkdown($entryPoint, $maxDepth);

        // Save to output file
        file_put_contents($outputFile, $markdownContent);

        // Add the markdown content to the builder
        $builder->addBlock(new TextBlock($modifiersApplier->apply($markdownContent, 'trace.md')));

        $result = $builder->build();

        $this->logger?->info('Call stack trace generated successfully', [
            'entryPoint' => $entryPoint,
            'methodsAnalyzed' => count($this->analyzedMethods),
            'callMapSize' => count($this->callMap),
        ]);

        return $result;
    }

    /**
     * Find a default start point if none specified
     *
     * @return array{string, string} [startFile, targetClass]
     */
    private function findDefaultStartPoint(): array
    {
        // This is a very basic implementation
        // In a real implementation, you might want to scan for classes
        // or use a more sophisticated approach to find a good entry point

        $possibleStartFiles = [
            $this->projectRoot . '/src/Controller',
            $this->projectRoot . '/src/Command',
            $this->projectRoot . '/src/App',
        ];

        foreach ($possibleStartFiles as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*.php');
                if (!empty($files)) {
                    $startFile = $files[0];
                    $className = $this->extractClassNameFromFile($startFile);
                    $namespace = $this->extractNamespaceFromFile($startFile);

                    if ($className && $namespace) {
                        return [$startFile, $namespace . '\\' . $className];
                    }
                }
            }
        }

        return [$this->projectRoot . '/index.php', 'App\\DefaultClass'];
    }

    /**
     * Configure options for skipping classes, methods, and directories
     */
    private function configureSkipOptions(array $options): void
    {
        // Configure skipping singleton methods (getInstance, etc.)
        $this->setSkipSingletonMethods($options['skipSingletonMethods'] ?? true);

        // Configure skipping constructors
        $this->setSkipConstructors($options['skipConstructors'] ?? false);

        // Configure skipping __invoke methods
        $this->setSkipInvokeMethods($options['skipInvokeMethods'] ?? false);

        // Configure custom singleton method patterns
        if (isset($options['singletonMethodPatterns']) && is_array($options['singletonMethodPatterns'])) {
            $this->addSingletonMethodPatterns($options['singletonMethodPatterns']);
        }

        // Configure skipping vendor directory
        $skipVendor = $options['skipVendorDir'] ?? true;
        if (!$skipVendor && in_array('vendor/', $this->getSkipDirPatterns())) {
            $this->setSkipDirPatterns(array_diff($this->getSkipDirPatterns(), ['vendor/']));
        }

        // Configure additional directories to skip
        if (isset($options['skipDirs']) && is_array($options['skipDirs'])) {
            $patternsToAdd = [];
            foreach ($options['skipDirs'] as $dir) {
                // Ensure directory path ends with a slash
                $dir = rtrim($dir, '/') . '/';
                $patternsToAdd[] = $dir;
            }
            $this->addSkipDirPatterns($patternsToAdd);
        }

        // Configure additional classes to skip
        if (isset($options['skipClasses']) && is_array($options['skipClasses'])) {
            $patternsToAdd = [];
            foreach ($options['skipClasses'] as $class) {
                $pattern = '/^' . preg_quote($class, '/') . '/';
                $patternsToAdd[] = $pattern;
            }
            $this->addSkipClassPatterns($patternsToAdd);
        }

        // Configure additional methods to skip
        if (isset($options['skipMethods']) && is_array($options['skipMethods'])) {
            $patternsToAdd = [];
            foreach ($options['skipMethods'] as $method) {
                $pattern = '/^' . preg_quote($method, '/') . '$/';
                $patternsToAdd[] = $pattern;
            }
            $this->addSkipMethodPatterns($patternsToAdd);
        }

        // Add Symfony and PSR classes to skip patterns if configured
        if (isset($options['skipFrameworks']) && $options['skipFrameworks']) {
            $this->addSkipClassPatterns([
                '/^Symfony\\\\/',
                '/^Psr\\\\/',
                '/^PhpParser\\\\/',
                '/^Doctrine\\\\/',
                '/^Monolog\\\\/',
                '/^Twig\\\\/',
            ]);
        }
    }

    /**
     * Index all PHP files in the project for faster lookup
     */
    private function indexProjectFiles(): void
    {
        $this->logger?->debug('Indexing project files');

        // Define directories to scan
        $dirsToScan = ['src', 'lib', 'app'];

        // Add vendor if not skipped
        if (!in_array('vendor/', $this->getSkipDirPatterns())) {
            $dirsToScan[] = 'vendor';
        }

        foreach ($dirsToScan as $dir) {
            $dirPath = $this->projectRoot . '/' . $dir;
            if (is_dir($dirPath)) {
                $this->scanDirectoryForPhpFiles($dirPath);
            }
        }

        $this->logger?->debug('Project indexing complete', [
            'filesIndexed' => count($this->srcFileMap)
        ]);
    }

    /**
     * Recursively scan a directory for PHP files
     */
    private function scanDirectoryForPhpFiles(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Check if this directory should be skipped
        $relativePath = $this->getRelativePath($dir);
        foreach ($this->getSkipDirPatterns() as $pattern) {
            if (strpos($relativePath, $pattern) !== false) {
                return;
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $path = $file->getRealPath();

                // Check if file is in a directory that should be skipped
                $relativePath = $this->getRelativePath($path);
                $shouldSkip = false;

                foreach ($this->getSkipDirPatterns() as $pattern) {
                    if (strpos($relativePath, $pattern) !== false) {
                        $shouldSkip = true;
                        break;
                    }
                }

                if ($shouldSkip) {
                    continue;
                }

                $this->srcFileMap[$path] = $path;

                // Also store by class name to quickly find files
                $namespace = $this->extractNamespaceFromFile($path);
                if ($namespace) {
                    $className = $this->extractClassNameFromFile($path);
                    if ($className) {
                        $fullClassName = $namespace . '\\' . $className;
                        $this->srcFileMap[$fullClassName] = $path;
                    }
                }
            }
        }
    }

    /**
     * Collect type information from source files
     */
    private function collectTypeInformation(): void
    {
        $this->logger?->debug('Collecting type information from source files');

        // Add known framework types
        foreach ($this->knownFrameworkTypes as $methodKey => $returnType) {
            list($className, $methodName) = explode('::', $methodKey);
            $this->setMethodReturnType($className, $methodName, $returnType);
        }

        // Scan class files to extract return types and property types
        foreach ($this->srcFileMap as $className => $filePath) {
            // Skip non-class keys
            if ($className === $filePath) {
                continue;
            }

            // Skip classes that should be skipped
            if ($this->shouldSkipClass($className)) {
                continue;
            }

            $this->scanClassFileForTypes($filePath, $className);
        }

        // Try to use reflection for additional type information when available
        $this->collectTypesFromReflection();

        $this->logger?->debug('Type information collection complete', [
            'methodTypes' => count($this->methodReturnTypes),
            'propertyTypes' => count($this->propertyTypes)
        ]);
    }

    /**
     * Try to collect type information using reflection
     */
    private function collectTypesFromReflection(): void
    {
        foreach ($this->srcFileMap as $className => $filePath) {
            // Skip non-class keys
            if ($className === $filePath) {
                continue;
            }

            try {
                if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);

                // Process methods
                foreach ($reflection->getMethods() as $method) {
                    $methodName = $method->getName();
                    $returnType = $method->getReturnType();

                    if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
                        $this->setMethodReturnType($className, $methodName, $returnType->getName());
                    }
                }

                // Process properties
                foreach ($reflection->getProperties() as $property) {
                    $propertyName = $property->getName();
                    $propertyType = $property->getType();

                    if ($propertyType instanceof \ReflectionNamedType && !$propertyType->isBuiltin()) {
                        $this->setPropertyType($className, $propertyName, $propertyType->getName());
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore reflection errors
            }
        }
    }

    /**
     * Scan a class file for type information
     */
    private function scanClassFileForTypes(string $filePath, string $className): void
    {
        try {
            if (!file_exists($filePath)) {
                return;
            }

            $code = file_get_contents($filePath);
            if ($code === false) {
                return;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor(new class($className, $this) extends NodeVisitorAbstract {
                public function __construct(
                    private readonly string $className,
                    private readonly TraceSourceFetcher $fetcher
                ) {}

                #[\Override]
                public function enterNode(Node $node): void
                {
                    // Process methods for return types
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        $this->extractMethodReturnType($node);
                    }

                    // Process properties for types
                    if ($node instanceof Node\Stmt\Property) {
                        $this->extractPropertyType($node);
                    }
                }

                /**
                 * Extract return type from method
                 */
                private function extractMethodReturnType(Node\Stmt\ClassMethod $node): void
                {
                    $methodName = $node->name->toString();

                    // Check return type from method signature
                    if ($node->returnType) {
                        $type = $this->resolveTypeNodeToClassName($node->returnType);
                        if ($type) {
                            $this->fetcher->setMethodReturnType($this->className, $methodName, $type);
                            return;
                        }
                    }

                    // Check PHPDoc for return type
                    $docComment = $node->getDocComment();
                    if ($docComment) {
                        $type = $this->extractReturnTypeFromDocComment($docComment->getText());
                        if ($type) {
                            $this->fetcher->setMethodReturnType($this->className, $methodName, $type);
                        }
                    }

                    // For fluent interfaces, common method prefixes typically return $this
                    $fluentPrefixes = ['add', 'set', 'with', 'build', 'create', 'register', 'configure'];
                    $methodNameLower = strtolower($methodName);
                    foreach ($fluentPrefixes as $prefix) {
                        if (strpos($methodNameLower, $prefix) === 0) {
                            $this->fetcher->setMethodReturnType($this->className, $methodName, $this->className);
                            return;
                        }
                    }

                    // Factory methods often return a specific type
                    if (strpos($this->className, 'Factory') !== false || strpos($this->className, 'Builder') !== false) {
                        $baseName = str_replace(['Factory', 'Builder'], '', $this->className);
                        // If method creates something, assume it returns the base type
                        if (preg_match('/^(create|build|make|get)[A-Z]/', $methodName)) {
                            $namespaceParts = explode('\\', $this->className);
                            array_pop($namespaceParts); // Remove the last part (class name)
                            $namespace = implode('\\', $namespaceParts);
                            $this->fetcher->setMethodReturnType($this->className, $methodName, $namespace . '\\' . $baseName);
                        }
                    }
                }

                /**
                 * Extract type from property
                 */
                private function extractPropertyType(Node\Stmt\Property $node): void
                {
                    foreach ($node->props as $prop) {
                        $propertyName = $prop->name->toString();

                        // Check property type from declaration
                        if ($node->type) {
                            $type = $this->resolveTypeNodeToClassName($node->type);
                            if ($type) {
                                $this->fetcher->setPropertyType($this->className, $propertyName, $type);
                                continue;
                            }
                        }

                        // Check PHPDoc for property type
                        $docComment = $node->getDocComment();
                        if ($docComment) {
                            $type = $this->extractPropertyTypeFromDocComment($docComment->getText());
                            if ($type) {
                                $this->fetcher->setPropertyType($this->className, $propertyName, $type);
                            }
                        }
                    }
                }

                /**
                 * Extract return type from PHPDoc comment
                 */
                private function extractReturnTypeFromDocComment(string $docComment): ?string
                {
                    // Simple regex to extract @return type
                    if (preg_match('/@return\s+([^\s]+)/', $docComment, $matches)) {
                        $typeString = $matches[1];
                        return $this->extractClassTypeFromDocType($typeString);
                    }
                    return null;
                }

                /**
                 * Extract property type from PHPDoc comment
                 */
                private function extractPropertyTypeFromDocComment(string $docComment): ?string
                {
                    // Simple regex to extract @var type
                    if (preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
                        $typeString = $matches[1];
                        return $this->extractClassTypeFromDocType($typeString);
                    }
                    return null;
                }

                /**
                 * Extract class name from PHPDoc type
                 */
                private function extractClassTypeFromDocType(string $typeString): ?string
                {
                    // Handle union types (only take the first class type)
                    $typeComponents = explode('|', $typeString);
                    foreach ($typeComponents as $component) {
                        // Clean up type (remove array markers, nullable markers)
                        $cleanType = trim($component, '?[]<>');

                        // Skip primitive types
                        if (in_array(strtolower($cleanType), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed', 'null'])) {
                            continue;
                        }

                        // If it has a namespace separator or starts with uppercase, likely a class
                        if (strpos($cleanType, '\\') !== false || ctype_upper($cleanType[0] ?? '')) {
                            return $cleanType;
                        }
                    }
                    return null;
                }

                /**
                 * Resolve type node to a class name
                 */
                private function resolveTypeNodeToClassName($typeNode): ?string
                {
                    if ($typeNode instanceof Node\Name) {
                        $typeName = $typeNode->toString();

                        // Handle basic types vs class names
                        if (in_array(strtolower($typeName), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed'])) {
                            return null; // Not a class type
                        }

                        return $typeName;
                    }

                    if ($typeNode instanceof Node\NullableType) {
                        return $this->resolveTypeNodeToClassName($typeNode->type);
                    }

                    if (isset($typeNode->types)) { // For union or intersection types
                        // Attempt to find the first valid class in a union/intersection
                        foreach ($typeNode->types as $subType) {
                            $result = $this->resolveTypeNodeToClassName($subType);
                            if ($result) {
                                return $result;
                            }
                        }
                    }

                    return null;
                }
            });

            $traverser->traverse($ast);
        } catch (\Throwable $e) {
            $this->logger?->error('Error scanning class file for types: ' . $filePath, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get path relative to project root
     */
    private function getRelativePath(string $path): string
    {
        $projectRoot = $this->projectRoot;
        if (strpos($path, $projectRoot) === 0) {
            return substr($path, strlen($projectRoot) + 1);
        }
        return $path;
    }

    /**
     * Extract the namespace from a PHP file
     */
    private function extractNamespaceFromFile(string $filePath): ?string
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return null;
            }

            foreach ($ast as $node) {
                if ($node instanceof Node\Stmt\Namespace_) {
                    return $node->name->toString();
                }
            }
        } catch (\Throwable $e) {
            // Log or handle the exception
        }

        return null;
    }

    /**
     * Extract the class name from a PHP file
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return null;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return null;
            }

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
        } catch (\Throwable $e) {
            // Log or handle the exception
        }

        return null;
    }

    /**
     * Process a source file to extract class and method definitions
     */
    private function processSourceFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            $this->logger?->warning("File does not exist: $filePath");
            return;
        }

        try {
            $code = file_get_contents($filePath);
            if ($code === false) {
                return;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor(new class($this) extends NodeVisitorAbstract {
                private ?string $currentNamespace = null;

                public function __construct(private readonly TraceSourceFetcher $fetcher) {}

                #[\Override]
                public function enterNode(Node $node): void
                {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        $this->currentNamespace = $node->name->toString();
                    } elseif ($node instanceof Node\Stmt\Class_ ||
                        $node instanceof Node\Stmt\Interface_ ||
                        $node instanceof Node\Stmt\Trait_) {
                        $className = $node->name->toString();
                        if ($this->currentNamespace) {
                            $className = $this->currentNamespace . '\\' . $className;
                        }

                        $this->fetcher->processClassNode($className, $node);
                    }
                }
            });

            $traverser->traverse($ast);
        } catch (\Throwable $e) {
            $this->logger?->error('Error processing file: ' . $filePath, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process a class node to extract its methods
     */
    public function processClassNode(string $className, Node\Stmt $classNode): void
    {
        if (!isset($this->methodNodes[$className])) {
            $this->methodNodes[$className] = [];
        }

        $methods = $this->nodeFinder->findInstanceOf($classNode, Node\Stmt\ClassMethod::class);
        foreach ($methods as $method) {
            $methodName = $method->name->toString();
            $this->methodNodes[$className][$methodName] = $method;

            // Store whether the method is static
            $callKey = "{$className}::{$methodName}";
            $isStatic = $method->isStatic();
            $returnType = $this->getMethodReturnType($className, $methodName);

            $this->storeCallInfo($callKey, $isStatic, [], $returnType);
        }
    }

    /**
     * Analyze method calls recursively starting from an entry point
     */
    private function analyzeMethodCalls(string $entryPoint, int $maxDepth, int $depth = 0, array $visited = []): void
    {
        // Skip if we've already analyzed this method or reached max depth
        if (isset($this->analyzedMethods[$entryPoint]) || $depth >= $maxDepth || in_array($entryPoint, $visited, true)) {
            return;
        }

        $this->analyzedMethods[$entryPoint] = true;
        $visited[] = $entryPoint;

        // Split class and method
        list($className, $methodName) = explode('::', $entryPoint);

        // Skip if class or method should be skipped
        if ($this->shouldSkipClass($className) || $this->shouldSkipMethod($methodName)) {
            return;
        }

        // Find the file containing this class
        $filePath = $this->findFileForClass($className);
        if (!$filePath) {
            return;
        }

        // Make sure the file is processed
        $this->processSourceFile($filePath);

        // Get the method node
        $methodNode = $this->methodNodes[$className][$methodName] ?? null;
        if (!$methodNode) {
            return;
        }

        // Analyze method body for calls
        $calls = $this->extractMethodCalls($className, $methodName, $methodNode);

        // Store calls in the call map
        if (!empty($calls)) {
            $this->callMap[$entryPoint] = $calls;

            // Recursively analyze each called method
            foreach ($calls as $call) {
                $this->analyzeMethodCalls($call, $maxDepth, $depth + 1, $visited);
            }
        }
    }

    /**
     * Extract method calls from a method body
     *
     * @return array<string> List of method calls (class::method format)
     */
    private function extractMethodCalls(string $className, string $methodName, Node\Stmt\ClassMethod $methodNode): array
    {
        $calls = [];
        $variableTypes = []; // Track variable types in this scope

        // Initialize with this reference
        $variableTypes['this'] = $className;

        // Process method parameters to infer types
        foreach ($methodNode->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $varName = $param->var->name;

                if ($param->type) {
                    $type = $this->resolveTypeNodeToClassName($param->type);
                    if ($type) {
                        $variableTypes[$varName] = $type;
                    }
                }
            }
        }

        // Process method docblock for parameter types
        $docComment = $methodNode->getDocComment();
        if ($docComment) {
            $paramTypes = $this->extractParamTypesFromDocComment($docComment->getText());
            foreach ($paramTypes as $paramName => $paramType) {
                $variableTypes[$paramName] = $paramType;
            }
        }

        // Process method body for variable assignments and method calls
        $visitor = new class($calls, $className, $methodName, $this, $variableTypes) extends NodeVisitorAbstract {
            /** @var array<string> */
            private array $calls = [];

            /** @var array<string, string> Variable type mapping */
            private array $variableTypes = [];

            public function __construct(
                array &$calls,
                private readonly string $currentClass,
                private readonly string $currentMethod,
                private readonly TraceSourceFetcher $fetcher,
                array $initialVariableTypes = []
            ) {
                $this->calls = &$calls;
                $this->variableTypes = $initialVariableTypes;
            }

            #[\Override]
            public function enterNode(Node $node): void
            {
                // Track variable assignments to infer types
                if ($node instanceof Node\Expr\Assign) {
                    $this->handleAssignment($node);
                }

                // Track method calls
                match (true) {
                    $node instanceof Node\Expr\MethodCall => $this->handleMethodCall($node),
                    $node instanceof Node\Expr\StaticCall => $this->handleStaticCall($node),
                    $node instanceof Node\Expr\New_ => $this->handleNewExpr($node),
                    $node instanceof Node\Expr\FuncCall => $this->handleFuncCall($node),
                    $node instanceof Node\Expr\CallLike => $this->handleOtherCall($node),
                    default => null,
                };
            }

            /**
             * Format method arguments for display
             */
            private function formatArguments(array $args): array
            {
                $formattedArgs = [];
                foreach ($args as $arg) {
                    $formattedArgs[] = $this->formatArgument($arg);
                }
                return $formattedArgs;
            }

            /**
             * Format a single argument for display
             */
            private function formatArgument(Node\Arg $arg): string
            {
                try {
                    // Use the PrettyPrinter to get a string representation
                    return (new PrettyPrinter())->prettyPrintExpr($arg->value);
                } catch (\Throwable $e) {
                    // Fallback for cases where pretty printing fails
                    if ($arg->value instanceof Node\Scalar\String_) {
                        return "'" . $arg->value->value . "'";
                    } elseif ($arg->value instanceof Node\Scalar\LNumber) {
                        return (string)$arg->value->value;
                    } elseif ($arg->value instanceof Node\Scalar\DNumber) {
                        return (string)$arg->value->value;
                    } elseif ($arg->value instanceof Node\Expr\Variable && is_string($arg->value->name)) {
                        return '$' . $arg->value->name;
                    } elseif ($arg->value instanceof Node\Expr\ConstFetch) {
                        return $arg->value->name->toString();
                    }
                    return '...';
                }
            }

            /**
             * Handle variable assignments to track types
             */
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

            /**
             * Handle method calls
             */
            private function handleMethodCall(Node\Expr\MethodCall $node): void
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return;
                }

                $methodName = $node->name->toString();

                // Try to determine the class of the object
                $objectType = $this->resolveObjectType($node->var);

                if ($objectType) {
                    // Check if this method should be skipped
                    if ($this->fetcher->shouldSkipMethod($methodName)) {
                        return;
                    }

                    // Check if this is a singleton method that should be skipped
                    $isSingletonMethod = $this->fetcher->isSingletonMethod($methodName);
                    if ($isSingletonMethod && $this->fetcher->isSkipSingletonMethods()) {
                        return;
                    }

                    // Format arguments if any
                    $formattedArgs = $this->formatArguments($node->args);

                    // Determine return type
                    $returnType = $this->fetcher->getMethodReturnType($objectType, $methodName);

                    // Add the call to our list with isStatic = false (instance method)
                    $callKey = "{$objectType}::{$methodName}";
                    if (!in_array($callKey, $this->calls, true)) {
                        $this->calls[] = $callKey;
                        $this->fetcher->storeCallInfo($callKey, false, $formattedArgs, $returnType);
                    }
                } else {
                    // If we can't determine the object type, try to use the variable name for educated guess
                    if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                        $varName = $node->var->name;

                        // Common variable names often indicate their type
                        $commonVarNameMappings = [
                            'builder' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
                            'factory' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory',
                            'finder' => 'Butschster\ContextGenerator\Source\File\SymfonyFinder',
                            'resolver' => 'Butschster\ContextGenerator\Lib\Variable\VariableResolver',
                            'logger' => 'Psr\Log\LoggerInterface',
                        ];

                        foreach ($commonVarNameMappings as $pattern => $className) {
                            if (strpos($varName, $pattern) !== false) {
                                $formattedArgs = $this->formatArguments($node->args);
                                $returnType = $this->fetcher->getMethodReturnType($className, $methodName);

                                $callKey = "{$className}::{$methodName}";
                                if (!in_array($callKey, $this->calls, true)) {
                                    $this->calls[] = $callKey;
                                    $this->fetcher->storeCallInfo($callKey, false, $formattedArgs, $returnType);
                                }
                                return;
                            }
                        }

                        // As a fallback, check if the variable is a property of the current class
                        $propertyType = $this->fetcher->getPropertyType($this->currentClass, $varName);
                        if ($propertyType) {
                            $formattedArgs = $this->formatArguments($node->args);
                            $returnType = $this->fetcher->getMethodReturnType($propertyType, $methodName);

                            $callKey = "{$propertyType}::{$methodName}";
                            if (!in_array($callKey, $this->calls, true)) {
                                $this->calls[] = $callKey;
                                $this->fetcher->storeCallInfo($callKey, false, $formattedArgs, $returnType);
                            }
                            return;
                        }
                    }
                }
            }

            /**
             * Handle static calls
             */
            private function handleStaticCall(Node\Expr\StaticCall $node): void
            {
                if (!($node->class instanceof Node\Name) || !($node->name instanceof Node\Identifier)) {
                    return;
                }

                $className = $node->class->toString();
                $methodName = $node->name->toString();

                if ($this->fetcher->shouldSkipClass($className) ||
                    $this->fetcher->shouldSkipMethod($methodName)) {
                    return;
                }

                // Check if this is a singleton method that should be skipped
                $isSingletonMethod = $this->fetcher->isSingletonMethod($methodName);
                if ($isSingletonMethod && $this->fetcher->isSkipSingletonMethods()) {
                    return;
                }

                // Format arguments if any
                $formattedArgs = $this->formatArguments($node->args);

                // Determine return type
                $returnType = $this->fetcher->getMethodReturnType($className, $methodName);

                $call = "{$className}::{$methodName}";
                if (!in_array($call, $this->calls, true)) {
                    $this->calls[] = $call;
                    $this->fetcher->storeCallInfo($call, true, $formattedArgs, $returnType);
                }
            }

            /**
             * Handle new object creation
             */
            private function handleNewExpr(Node\Expr\New_ $node): void
            {
                if (!($node->class instanceof Node\Name)) {
                    return;
                }

                $className = $node->class->toString();

                if ($this->fetcher->shouldSkipClass($className)) {
                    return;
                }

                // Skip constructor calls if configured
                if ($this->fetcher->isSkipConstructors()) {
                    return;
                }

                // Format arguments if any
                $formattedArgs = $this->formatArguments($node->args);

                $call = "{$className}::__construct";
                if (!in_array($call, $this->calls, true)) {
                    $this->calls[] = $call;
                    $this->fetcher->storeCallInfo($call, false, $formattedArgs, null);
                }
            }

            /**
             * Handle function calls
             */
            private function handleFuncCall(Node\Expr\FuncCall $node): void
            {
                // Currently, we don't track function calls
            }

            /**
             * Handle other call-like expressions
             */
            private function handleOtherCall(Node\Expr\CallLike $node): void
            {
                // Handle invoke calls: $object()
                if ($node instanceof Node\Expr\Variable ||
                    $node instanceof Node\Expr\PropertyFetch ||
                    $node instanceof Node\Expr\StaticPropertyFetch) {

                    // Skip __invoke calls if configured
                    if ($this->fetcher->isSkipInvokeMethods()) {
                        return;
                    }

                    $objClass = $this->resolveObjectType($node);
                    if ($objClass) {
                        $call = "{$objClass}::__invoke";
                        if (!in_array($call, $this->calls, true)) {
                            $this->calls[] = $call;
                            // __invoke calls have no arguments in the AST, so we pass an empty array
                            $this->fetcher->storeCallInfo($call, false, [], null);
                        }
                    }
                }
            }

            /**
             * Resolve the type of an object expression
             */
            private function resolveObjectType(Node\Expr $expr): ?string
            {
                return match (true) {
                    // $this reference
                    $expr instanceof Node\Expr\Variable && $expr->name === 'this' => $this->currentClass,

                    // Variable with known type
                    $expr instanceof Node\Expr\Variable && is_string($expr->name) &&
                    isset($this->variableTypes[$expr->name]) => $this->variableTypes[$expr->name],

                    // Property access with known type
                    $expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable &&
                    $expr->var->name === 'this' && $expr->name instanceof Node\Identifier =>
                    $this->resolvePropertyType($this->currentClass, $expr->name->toString()),

                    // Method call chain
                    $expr instanceof Node\Expr\MethodCall => $this->inferMethodCallReturnType($expr),

                    // Static property access
                    $expr instanceof Node\Expr\StaticPropertyFetch &&
                    $expr->class instanceof Node\Name => $expr->class->toString(),

                    default => null,
                };
            }

            /**
             * Resolve a property type
             */
            private function resolvePropertyType(string $className, string $propertyName): ?string
            {
                // Check if we have the property type recorded
                return $this->fetcher->getPropertyType($className, $propertyName);
            }

            /**
             * Infer the return type of a method call
             */
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

                // Strategy 1: Check our known return types
                $returnType = $this->fetcher->getMethodReturnType($objectType, $methodName);
                if ($returnType) {
                    return $returnType;
                }

                // Strategy 2: Check framework known return types
                $methodKey = "$objectType::$methodName";
                if (isset($this->fetcher->knownFrameworkTypes[$methodKey])) {
                    return $this->fetcher->knownFrameworkTypes[$methodKey];
                }

                // Strategy 3: For fluent interfaces, methods might return $this
                $fluentPrefixes = ['add', 'set', 'with', 'build', 'register', 'configure'];
                $methodNameLower = strtolower($methodName);
                foreach ($fluentPrefixes as $prefix) {
                    if (strpos($methodNameLower, $prefix) === 0) {
                        return $objectType; // Likely returns $this
                    }
                }

                // Strategy 4: For factory methods, might return a specific type
                if (preg_match('/^(create|make|build|get)([A-Z].*?)$/', $methodName, $matches)) {
                    $createdType = $matches[2];

                    // Try to find the actual class in the same namespace
                    $namespaceParts = explode('\\', $objectType);
                    array_pop($namespaceParts); // Remove the last part (class name)
                    $namespace = implode('\\', $namespaceParts);

                    $possibleType = $namespace . '\\' . $createdType;

                    // Just return this as a guess - we can't verify it
                    return $possibleType;
                }

                return null;
            }

            /**
             * Infer the return type of a static call
             */
            private function inferStaticCallReturnType(Node\Expr\StaticCall $node): ?string
            {
                if (!($node->name instanceof Node\Identifier) || !($node->class instanceof Node\Name)) {
                    return null;
                }

                $className = $node->class->toString();
                $methodName = $node->name->toString();

                // Strategy 1: Check our known return types
                $returnType = $this->fetcher->getMethodReturnType($className, $methodName);
                if ($returnType) {
                    return $returnType;
                }

                // Strategy 2: For factory/singleton methods, often return an instance of their class
                if (in_array($methodName, ['create', 'getInstance', 'instance', 'factory', 'build', 'make'])) {
                    return $className;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse(false === empty($methodNode->stmts) ? $methodNode->stmts : []);

        return $calls;
    }

    /**
     * Extract parameter types from PHPDoc comment
     *
     * @param string $docComment
     * @return array<string, string> Map of parameter names to types
     */
    private function extractParamTypesFromDocComment(string $docComment): array
    {
        $paramTypes = [];

        // Simple regex to extract @param type $name
        preg_match_all('/@param\s+([^\s]+)\s+\$([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $typeString = $match[1];
            $paramName = $match[2];

            // Extract class type
            $classType = $this->extractClassTypeFromDocType($typeString);
            if ($classType) {
                $paramTypes[$paramName] = $classType;
            }
        }

        return $paramTypes;
    }

    /**
     * Extract class name from PHPDoc type string
     */
    private function extractClassTypeFromDocType(string $typeString): ?string
    {
        // Handle union types (only take the first class type)
        $typeComponents = explode('|', $typeString);
        foreach ($typeComponents as $component) {
            // Clean up type (remove array markers, nullable markers)
            $cleanType = trim($component, '?[]<>');

            // Skip primitive types
            if (in_array(strtolower($cleanType), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed', 'null'])) {
                continue;
            }

            // If it has a namespace separator or starts with uppercase, likely a class
            if (strpos($cleanType, '\\') !== false || ctype_upper($cleanType[0] ?? '')) {
                return $cleanType;
            }
        }
        return null;
    }

    /**
     * Resolve type node to a class name
     *
     * @param mixed $typeNode The type node from PHP-Parser
     * @return string|null The resolved class name or null if not a class
     */
    private function resolveTypeNodeToClassName($typeNode): ?string
    {
        if ($typeNode instanceof Node\Name) {
            $typeName = $typeNode->toString();

            // Handle basic types vs class names
            if (in_array(strtolower($typeName), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed'])) {
                return null; // Not a class type
            }

            return $typeName;
        }

        if ($typeNode instanceof Node\NullableType) {
            return $this->resolveTypeNodeToClassName($typeNode->type);
        }

        if (isset($typeNode->types)) { // For union or intersection types
            // Attempt to find the first valid class in a union/intersection
            foreach ($typeNode->types as $subType) {
                $result = $this->resolveTypeNodeToClassName($subType);
                if ($result) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Check if a method is a singleton accessor method
     */
    public function isSingletonMethod(string $methodName): bool
    {
        foreach ($this->getSingletonMethodPatterns() as $pattern) {
            if (preg_match($pattern, $methodName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the file containing a class
     */
    private function findFileForClass(string $className): ?string
    {
        // Direct lookup if we already indexed this class
        if (isset($this->srcFileMap[$className])) {
            return $this->srcFileMap[$className];
        }

        // Try to find by PSR-4 convention
        $classPath = str_replace('\\', '/', $className) . '.php';
        $possiblePaths = [
            $this->projectRoot . '/src/' . $classPath,
            $this->projectRoot . '/lib/' . $classPath,
            $this->projectRoot . '/app/' . $classPath,
        ];

        // Also check vendor if not skipped
        if (!in_array('vendor/', $this->getSkipDirPatterns())) {
            $possiblePaths[] = $this->projectRoot . '/vendor/' . $classPath;
        }

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $this->srcFileMap[$className] = $path; // Cache the result
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a class should be skipped
     */
    public function shouldSkipClass(string $className): bool
    {
        foreach ($this->getSkipClassPatterns() as $pattern) {
            if (preg_match($pattern, $className)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a method should be skipped
     */
    public function shouldSkipMethod(string $methodName): bool
    {
        // Skip __invoke method if configured
        if ($methodName === '__invoke' && $this->isSkipInvokeMethods()) {
            return true;
        }

        // Skip __construct method if configured
        if ($methodName === '__construct' && $this->isSkipConstructors()) {
            return true;
        }

        foreach ($this->getSkipMethodPatterns() as $pattern) {
            if (preg_match($pattern, $methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate markdown output for the call stack
     */
    private function generateCallStackMarkdown(string $entryPoint, int $maxDepth): string
    {
        list($className, $methodName) = explode('::', $entryPoint);

        $content = "# Call Stack Tree for $className::$methodName\n\n";
        $content .= "```\n";
        $content .= $this->buildTreeRepresentation($entryPoint, 0, [], $maxDepth);
        $content .= "```\n\n";

        // Add statistics and configuration
        $content .= "## Statistics\n\n";
        $content .= "- Entry point: $entryPoint\n";
        $content .= "- Methods analyzed: " . count($this->analyzedMethods) . "\n";
        $content .= "- Call relationships: " . array_sum(array_map('count', $this->callMap)) . "\n\n";

        $content .= "## Configuration\n\n";
        $content .= "- Skip singleton methods (getInstance): " . ($this->isSkipSingletonMethods() ? 'Yes' : 'No') . "\n";
        $content .= "- Skip constructors: " . ($this->isSkipConstructors() ? 'Yes' : 'No') . "\n";
        $content .= "- Skip __invoke methods: " . ($this->isSkipInvokeMethods() ? 'Yes' : 'No') . "\n";
        $content .= "- Max depth: $maxDepth\n";

        // List skipped directories
        if (!empty($this->getSkipDirPatterns())) {
            $content .= "- Skipped directories: " . implode(', ', $this->getSkipDirPatterns()) . "\n";
        }

        return $content;
    }

    /**
     * Build a tree representation of the call stack
     */
    private function buildTreeRepresentation(string $node, int $depth = 0, array $visited = [], int $maxDepth = 10): string
    {
        if ($depth > $maxDepth || in_array($node, $visited, true)) {
            return str_repeat("  ", $depth) . "└── [Recursion/max depth reached]\n";
        }

        $visited[] = $node;

        // Get call information
        $callInfo = $this->getCallInfo($node);
        $isStatic = $callInfo['isStatic'] ?? true;
        $arguments = $callInfo['arguments'] ?? [];
        $returnType = $callInfo['returnType'] ?? null;

        // Format arguments for display
        $formattedArgs = empty($arguments) ? '' : '(' . implode(', ', $arguments) . ')';

        // Format return type if available
        $formattedReturnType = $returnType ? ': ' . $returnType : '';

        // Parse the node to get class and method
        list($className, $methodName) = explode('::', $node);

        // Format node display - replace :: with -> for instance methods,
        // replace self:: with $this-> for instance method calls to self
        $displayNode = $node;

        if (!$isStatic) {
            if ($methodName === 'sort' && strpos($className, 'self') !== false) {
                $displayNode = '$this->sort()' . $formattedReturnType;
            } elseif (strpos($className, 'self') !== false) {
                $displayNode = '$this->' . $methodName . $formattedArgs . $formattedReturnType;
            } elseif (strpos($className, 'parent') !== false) {
                $displayNode = 'parent::' . $methodName . $formattedArgs . $formattedReturnType;
            } else {
                $displayNode = $className . '->' . $methodName . $formattedArgs . $formattedReturnType;
            }
        } else {
            $displayNode = $className . '::' . $methodName . $formattedArgs . $formattedReturnType;
        }

        // Format the current node
        $content = $depth === 0
            ? "$displayNode\n"
            : str_repeat("  ", $depth) . "└── $displayNode\n";

        // Process children with proper sorting for consistent output
        $children = $this->callMap[$node] ?? [];
        sort($children);

        foreach ($children as $child) {
            $content .= $this->buildTreeRepresentation($child, $depth + 1, $visited, $maxDepth);
        }

        return $content;
    }
}
