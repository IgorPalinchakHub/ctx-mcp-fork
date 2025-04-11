<?php
// Path: /src/Source/XdebugTrace/Application/TraceSourceFetcher.php

namespace Butschster\ContextGenerator\Source\XdebugTrace\Application;

use Butschster\ContextGenerator\Lib\Content\Block\TextBlock;
use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Lib\Variable\VariableResolver;
use Butschster\ContextGenerator\Modifier\ModifiersApplierInterface;
use Butschster\ContextGenerator\Source\Fetcher\SourceFetcherInterface;
use Butschster\ContextGenerator\Source\SourceInterface;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TraceSource;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\CallStackTreeService;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\MethodCallAnalyzerService;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\SkipRulesService;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\TypeInferenceService;
use Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser\PhpParserAdapter;
use Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Repository\InMemoryTypeRepository;
use Butschster\ContextGenerator\Source\XdebugTrace\Presentation\HtmlRenderer;
use Butschster\ContextGenerator\Source\XdebugTrace\Presentation\MarkdownRenderer;
use Butschster\ContextGenerator\Source\XdebugTrace\Presentation\PlainRenderer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main application service for fetching and analyzing PHP method call stacks
 * @implements SourceFetcherInterface<TraceSource>
 */
class TraceSourceFetcher implements SourceFetcherInterface
{
    /**
     * Current project root directory
     */
//    private string $projectRoot;

    /**
     * Constructor
     */
    public function __construct(
        private ContentBuilderFactory $builderFactory,
        private VariableResolver $variableResolver,
        private ?LoggerInterface $logger = null,
        private string $projectRoot = '',
    ) {
        $this->logger ??= new NullLogger();
        $this->projectRoot = $projectRoot ?: getcwd();
    }

    /**
     * Check if this fetcher supports the given source
     */
    public function supports(SourceInterface $source): bool
    {
        $isSupported = $source instanceof TraceSource;
        $this->logger->debug('Checking if source is supported', [
            'sourceType' => $source::class,
            'isSupported' => $isSupported,
        ]);
        return $isSupported;
    }

    /**
     * Fetch and analyze the source
     */
    public function fetch(SourceInterface $source, ModifiersApplierInterface $modifiersApplier): string
    {
        if (!$source instanceof TraceSource) {
            $errorMessage = 'Source must be an instance of TraceSource';
            $this->logger->error($errorMessage, [
                'sourceType' => get_class($source),
            ]);
            throw new \InvalidArgumentException($errorMessage);
        }

        $description = $this->variableResolver->resolve($source->getDescription());

        $this->logger->info('Fetching trace source content', [
            'description' => $source->getDescription(),
            'renderFormat' => $source->getRenderFormat(),
            'hasTags' => $source->hasTags(),
            'sourcePaths' => $source->getSourcePaths(),
        ]);

        // Create builder
        $this->logger->debug('Creating content builder');
        $builder = $this->builderFactory->create();

        if ($source->hasDescription()) {
            $builder->addDescription($description);
        }

        // Extract parameters from options
        $options = $source->getOptions();
        $startFile = $options['startFile'] ?? null;
        $targetClass = $options['class'] ?? null;
        $targetMethod = $options['method'] ?? 'fetch';
        $outputFile = $options['outputFile'] ?? 'call_stack.md';
        $maxDepth = (int)($options['maxDepth'] ?? 20);
        $renderFormat = $source->getRenderFormat();
        $additionalNamespaceMappings = $options['namespaceMappings'] ?? [];
        $searchDirectories = $options['searchDirectories'] ?? null;

        // Log detailed configuration
        $this->logger->debug('Configuration for analysis', [
            'startFile' => $startFile,
            'targetClass' => $targetClass,
            'targetMethod' => $targetMethod,
            'maxDepth' => $maxDepth,
            'skipRules' => [
                'skipVendorDir' => $options['skipVendorDir'] ?? true,
                'skipFrameworks' => $options['skipFrameworks'] ?? true,
                'skipSingletonMethods' => $options['skipSingletonMethods'] ?? true,
            ],
            'sourcePaths' => $source->getSourcePaths(),
            'additionalNamespaceMappings' => $additionalNamespaceMappings,
        ]);

        // Validate required parameters
        if (empty($startFile) || empty($targetClass) || empty($targetMethod)) {
            $errorMessage = 'Missing required parameters: startFile, class, and method must be provided';
            $this->logger->error($errorMessage, [
                'startFile' => $startFile,
                'targetClass' => $targetClass,
                'targetMethod' => $targetMethod,
            ]);

            $markdownContent = "# Method Call Stack Analysis Error\n\n";
            $markdownContent .= "**Error:** {$errorMessage}\n\n";

            if (empty($startFile)) {
                $markdownContent .= "- `startFile` not provided. This should point to the PHP file containing the entry point method.\n";
            }

            if (empty($targetClass)) {
                $markdownContent .= "- `class` not provided. This should specify the fully qualified class name to analyze.\n";
            }

            if (empty($targetMethod)) {
                $markdownContent .= "- `method` not provided. This should specify the method name to analyze.\n";
            }

            $builder->addBlock(new TextBlock($markdownContent));
            return $builder->build();
        }

        // Resolve start file path if it's relative
        $startFilePath = $this->resolvePath($startFile);

        if (!file_exists($startFilePath)) {
            $errorMessage = "Start file not found: {$startFilePath}";
            $this->logger->error($errorMessage);

            $markdownContent = "# Method Call Stack Analysis Error\n\n";
            $markdownContent .= "**Error:** {$errorMessage}\n\n";
            $markdownContent .= "Please check that the file path is correct and accessible.\n";

            $builder->addBlock(new TextBlock($markdownContent));
            return $builder->build();
        }

        // Process source paths to build search directories if not explicitly provided
        if ($searchDirectories === null) {
            $searchDirectories = [];
            $sourcePaths = $source->getSourcePaths();

            if (!empty($sourcePaths)) {
                foreach ((array)$sourcePaths as $path) {
                    $resolvedPath = $this->resolvePath($path);
                    if (is_dir($resolvedPath)) {
                        $searchDirectories[] = $resolvedPath;
                    } else if (is_dir(dirname($resolvedPath))) {
                        $searchDirectories[] = dirname($resolvedPath);
                    }
                }
            }

            // Add default paths if no valid paths were found
            if (empty($searchDirectories)) {
                $searchDirectories = ['src', 'lib', 'app', 'vendor'];
            }
        }

        // Initialize services
        $typeRepository = new InMemoryTypeRepository();
        $typeInferenceService = new TypeInferenceService($typeRepository);
        $skipRulesService = new SkipRulesService();
        $skipRulesService->configureFromOptions($options);

        // Create PHP parser adapter with namespace mappings
        $parserAdapter = new PhpParserAdapter(
            typeRepository: $typeRepository,
            typeInferenceService: $typeInferenceService,
            logger: $this->logger,
            additionalNamespacePaths: $additionalNamespaceMappings,
            searchDirectories: $searchDirectories
        );

        // Create analyzer service
        $methodCallAnalyzer = new MethodCallAnalyzerService(
            $typeRepository,
            $typeInferenceService,
            $skipRulesService,
            $parserAdapter,
            $this->logger
        );

        // Create tree service and renderers
        $callStackTreeService = new CallStackTreeService();
        $markdownRenderer = new MarkdownRenderer($skipRulesService);
        $plainRenderer = new PlainRenderer();
        $htmlRenderer = new HtmlRenderer($skipRulesService);

        // Perform the analysis
        $this->logger->info("Starting analysis of {$targetClass}::{$targetMethod} in {$startFilePath}");

        try {
            // Analyze the entry point
            $rootMethodCall = $methodCallAnalyzer->analyzeMethod(
                $targetClass,
                $targetMethod,
                $startFilePath,
                $maxDepth
            );

            if ($rootMethodCall === null) {
                $errorMessage = "Analysis failed: Could not analyze method {$targetClass}::{$targetMethod}";
                $this->logger->error($errorMessage);

                $markdownContent = "# Method Call Stack Analysis Error\n\n";
                $markdownContent .= "**Error:** {$errorMessage}\n\n";
                $markdownContent .= "Please check that:\n";
                $markdownContent .= "- The class and method exist in the specified file\n";
                $markdownContent .= "- The file path is correctly specified and can be accessed\n";
                $markdownContent .= "- The file can be parsed correctly\n";
                $markdownContent .= "- The class name includes the full namespace\n";

                // Check for potential namespace mismatch
                if (strpos($targetClass, '\\') === false) {
                    $markdownContent .= "\nThe class name `{$targetClass}` does not include a namespace. " .
                        "Make sure you're using the fully qualified class name with namespace.\n";
                }

                $builder->addBlock(new TextBlock($markdownContent));
                return $builder->build();
            }

            // Generate the call stack tree content based on render format
            $analyzedMethods = $methodCallAnalyzer->getAnalyzedMethods();
            $circularDependencies = $methodCallAnalyzer->getCircularDependencies();

            $this->logger->info("Analysis completed. Analyzed " . count($analyzedMethods) . " methods.");

            // Select renderer based on format
            switch ($renderFormat) {
                case 'plain':
                    $treeContent = $plainRenderer->render($rootMethodCall, $analyzedMethods, $maxDepth);
                    break;
                case 'html':
                    $treeContent = $htmlRenderer->render($rootMethodCall, $analyzedMethods, $maxDepth);
                    break;
                case 'markdown':
                default:
                    $treeContent = $markdownRenderer->render($rootMethodCall, $analyzedMethods, $maxDepth);
            }

            // Add circular dependencies section if any were found
            if (!empty($circularDependencies)) {
                $treeContent .= "\n\n## Circular Dependencies\n\n";
                foreach ($circularDependencies as $index => $cycle) {
                    $treeContent .= "**Cycle " . ($index + 1) . ":**\n\n";
                    $treeContent .= implode(" → \n", $cycle) . " → " . $cycle[0] . "\n\n";
                }
            }

            // Apply modifiers
            $modifiedContent = $modifiersApplier->apply($treeContent, 'trace.md');

            // Save to output file if specified
            if (!empty($outputFile)) {
                $outputDir = dirname($outputFile);
                if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
                    $this->logger->warning("Could not create output directory: {$outputDir}");
                } else {
                    file_put_contents($outputFile, $modifiedContent);
                    $this->logger->info("Call stack trace written to {$outputFile}");
                }
            }

            // Add any extra content from the source
            $extraContent = $source->getContent();
            if (!empty($extraContent)) {
                $modifiedContent .= "\n\n" . $extraContent;
            }

            // Add the content to the builder
            $builder->addBlock(new TextBlock($modifiedContent));

            $this->logger->info('Call stack trace generated successfully');
        } catch (\Throwable $e) {
            $this->logger->error('Error during method call analysis: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $markdownContent = "# Method Call Stack Analysis Error\n\n";
            $markdownContent .= "**Error:** " . $e->getMessage() . "\n\n";
            $markdownContent .= "Location: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
            $markdownContent .= "```\n" . $e->getTraceAsString() . "\n```\n\n";
            $markdownContent .= "Please check logs for more details.\n";

            $builder->addBlock(new TextBlock($markdownContent));
        }

        return $builder->build();
    }

    /**
     * Resolve a potentially relative path
     */
    private function resolvePath(string $path): string
    {
        // Check if already absolute
        if (file_exists($path)) {
            return $path;
        }

        // Try relative to project root
        $absolutePath = $this->projectRoot . DIRECTORY_SEPARATOR . $path;
        if (file_exists($absolutePath)) {
            return $absolutePath;
        }

        // Normalize path
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $absolutePath = $this->projectRoot . DIRECTORY_SEPARATOR . $normalizedPath;
        if (file_exists($absolutePath)) {
            return $absolutePath;
        }

        // Return original if we couldn't resolve it
        return $path;
    }

    /**
     * Get a path relative to the project root
     */
    private function getRelativePath(string $path): string
    {
        if (str_starts_with($path, $this->projectRoot)) {
            return substr($path, strlen($this->projectRoot) + 1);
        }
        return $path;
    }
}
