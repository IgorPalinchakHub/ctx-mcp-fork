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

        // Start analysis from the target method
        $entryPoint = "{$targetClass}::{$targetMethod}";

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
        $currentClass = $className;
        $currentMethod = $methodName;
        $chainedCalls = []; // Track chained method calls for singleton detection

        $visitor = new class($calls, $chainedCalls, $currentClass, $currentMethod, $this) extends NodeVisitorAbstract {
            /** @var array<string> */
            private array $calls = [];

            /** @var array<array{expr: Node\Expr, object: ?string, method: string}> */
            private array $chainedCalls = [];

            public function __construct(
                array &$calls,
                array &$chainedCalls,
                private readonly string $currentClass,
                private readonly string $currentMethod,
                private readonly TraceSourceFetcher $fetcher
            ) {
                $this->calls = &$calls;
                $this->chainedCalls = &$chainedCalls;
            }

            #[\Override]
            public function enterNode(Node $node): void
            {
                match (true) {
                    $node instanceof Node\Expr\MethodCall => $this->handleMethodCall($node),
                    $node instanceof Node\Expr\StaticCall => $this->handleStaticCall($node),
                    $node instanceof Node\Expr\New_ => $this->handleNewExpr($node),
                    $node instanceof Node\Expr\FuncCall => $this->handleFuncCall($node),
                    $node instanceof Node\Expr\CallLike => $this->handleOtherCall($node),
                    default => null,
                };
            }

            private function handleMethodCall(Node\Expr\MethodCall $node): void
            {
                if (!($node->name instanceof Node\Identifier)) {
                    return;
                }

                $methodName = $node->name->toString();

                // Handle direct method call
                $objClass = $this->resolveObjectClass($node->var);

                if ($objClass) {
                    // Don't add the call yet, instead track it for chained call analysis
                    $this->chainedCalls[] = [
                        'expr' => $node,
                        'object' => $objClass,
                        'method' => $methodName
                    ];

                    // Process the chained calls to handle singleton patterns
                    $this->processChainedCalls();
                } else {
                    // If we can't determine the class, just add the method name directly
                    // This is less informative but better than nothing
                    $call = "unknown::{$methodName}";
                    if (!in_array($call, $this->calls, true)) {
                        $this->calls[] = $call;
                    }
                }
            }

            /**
             * Process chained method calls to handle singleton patterns
             */
            private function processChainedCalls(): void
            {
                // Sort chained calls by depth (from parent to child calls)
                usort($this->chainedCalls, function ($a, $b) {
                    // Count depth by number of parent nodes
                    $depthA = $this->countParentNodes($a['expr']);
                    $depthB = $this->countParentNodes($b['expr']);
                    return $depthA <=> $depthB;
                });

                // Process each call in the chain
                foreach ($this->chainedCalls as $index => $call) {
                    $objClass = $call['object'];
                    $methodName = $call['method'];

                    // Skip if this method should be skipped
                    if ($this->fetcher->shouldSkipMethod($methodName)) {
                        continue;
                    }

                    // Check if this is a singleton method that should be skipped
                    $isSingletonMethod = $this->fetcher->isSingletonMethod($methodName);

                    if ($isSingletonMethod && $this->fetcher->isSkipSingletonMethods()) {
                        // Skip singleton methods like getInstance() if configured
                        continue;
                    }

                    // Add the call to our list
                    $callKey = "{$objClass}::{$methodName}";
                    if (!in_array($callKey, $this->calls, true)) {
                        $this->calls[] = $callKey;
                    }
                }

                // Clear the chained calls array after processing
                $this->chainedCalls = [];
            }

            /**
             * Count the number of parent nodes to determine the depth of a node
             */
            private function countParentNodes(Node $node): int
            {
                $count = 0;
                $current = $node;

                while ($current instanceof Node\Expr\MethodCall) {
                    $count++;
                    $current = $current->var;
                }

                return $count;
            }

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
                    // Skip singleton methods like getInstance() if configured
                    return;
                }

                $call = "{$className}::{$methodName}";
                if (!in_array($call, $this->calls, true)) {
                    $this->calls[] = $call;
                }
            }

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

                $call = "{$className}::__construct";
                if (!in_array($call, $this->calls, true)) {
                    $this->calls[] = $call;
                }
            }

            private function handleFuncCall(Node\Expr\FuncCall $node): void
            {
                // For now, we don't track function calls
                // This could be enhanced to track global functions if needed
            }

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

                    $objClass = $this->resolveObjectClass($node);
                    if ($objClass) {
                        $call = "{$objClass}::__invoke";
                        if (!in_array($call, $this->calls, true)) {
                            $this->calls[] = $call;
                        }
                    }
                }
            }

            private function resolveObjectClass(Node\Expr $expr): ?string
            {
                return match (true) {
                    // $this reference
                    $expr instanceof Node\Expr\Variable && $expr->name === 'this' => $this->currentClass,

                    // Static property access
                    $expr instanceof Node\Expr\StaticPropertyFetch &&
                    $expr->class instanceof Node\Name => $expr->class->toString(),

                    // Method call chains need special handling
                    $expr instanceof Node\Expr\MethodCall => $this->resolveMethodCallReturnType($expr),

                    default => null,
                };
            }

            /**
             * Resolve the return type of a method call for chained calls
             */
            private function resolveMethodCallReturnType(Node\Expr\MethodCall $methodCall): ?string
            {
                if (!($methodCall->name instanceof Node\Identifier)) {
                    return null;
                }

                $methodName = $methodCall->name->toString();
                $parentClass = $this->resolveObjectClass($methodCall->var);

                if (!$parentClass) {
                    return null;
                }

                // If it's a singleton method, it likely returns the class itself (fluent interface)
                if ($this->fetcher->isSingletonMethod($methodName)) {
                    return $parentClass;
                }

                // For other methods, we don't have enough information
                // A more complete solution would look up method return types
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($methodNode->stmts);

        return $calls;
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

        // Format the current node
        $content = $depth === 0
            ? "$node\n"
            : str_repeat("  ", $depth) . "└── $node\n";

        // Process children with proper sorting for consistent output
        $children = $this->callMap[$node] ?? [];
        sort($children);

        foreach ($children as $child) {
            $content .= $this->buildTreeRepresentation($child, $depth + 1, $visited, $maxDepth);
        }

        return $content;
    }
}
