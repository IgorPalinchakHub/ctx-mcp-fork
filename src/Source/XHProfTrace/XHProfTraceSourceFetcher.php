<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace;

use Butschster\ContextGenerator\Application\Logger\LoggerPrefix;
use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Lib\Content\Block\TextBlock;
use Butschster\ContextGenerator\Modifier\ModifiersApplierInterface;
use Butschster\ContextGenerator\Source\Fetcher\SourceFetcherInterface;
use Butschster\ContextGenerator\Source\SourceInterface;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;
use Butschster\ContextGenerator\Source\XHProfTrace\Extraction\MethodExtractor;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Parser\XHProfJsonParser;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\MarkdownRenderer;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\Renderer\CallTreeMarkdownRenderer;
use Psr\Log\LoggerInterface;

final readonly class XHProfTraceSourceFetcher implements SourceFetcherInterface
{
    public function __construct(
        private string $basePath,
        private ContentBuilderFactory $builderFactory = new ContentBuilderFactory(),
        #[LoggerPrefix(prefix: 'xhprof-trace')]
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(SourceInterface $source): bool
    {
        $isSupported = $source instanceof XHProfTraceSource;
        $this->logger?->debug('Checking if source is supported', [
            'sourceType' => $source::class,
            'isSupported' => $isSupported,
        ]);
        return $isSupported;
    }

    public function fetch(SourceInterface $source, ModifiersApplierInterface $modifiersApplier): string
    {
        if (!$source instanceof XHProfTraceSource) {
            $errorMessage = 'Source must be an instance of XHProfTraceSource';
            $this->logger?->error($errorMessage, [
                'sourceType' => $source::class,
            ]);
            throw new \InvalidArgumentException($errorMessage);
        }

        $this->logger?->info('Fetching XHProf trace content', [
            'description' => $source->getDescription(),
            'renderFormat' => $source->renderFormat,
            'hasFilters' => $source->hasFilters(),
        ]);

        $builder = $this->builderFactory->create();

        if ($source->hasDescription()) {
            $builder->addDescription($source->getDescription());
        }

        try {
            $jsonData = $this->loadTraceData($source);

            if (empty($jsonData)) {
                throw new \RuntimeException('No valid XHProf trace data found in source paths');
            }

            // Create and configure filter if needed
            $filter = null;
            if ($source->hasFilters()) {
                $filter = XHProfTraceFilter::fromOptions($source->options);
                $this->logger?->info('Created filter from options', [
                    'includeNamespaces' => $filter->getIncludeNamespaces(),
                    'excludeNamespaces' => $filter->getExcludeNamespaces(),
                    'includeFunctions' => $filter->getIncludeFunctions(),
                    'excludeFunctions' => $filter->getExcludeFunctions(),
                    'excludePhpInternals' => $filter->getExcludePhpInternals(),
                    'minExecutionTime' => $filter->getMinExecutionTime(),
                ]);
            }

            // Parse JSON data
            $parser = new XHProfJsonParser($this->logger);
            if ($filter !== null) {
                $parser->setFilter($filter);
            }

            $callGraph = $parser->parse($jsonData);

            // Apply filter if provided
            if ($filter !== null) {
                $callGraph->setFilter($filter);

                // Use pruning for more aggressive filtering if specified in options
                if (isset($source->options['filters']['aggressivePruning']) &&
                    $source->options['filters']['aggressivePruning'] === true) {
                    $this->logger?->info('Applying aggressive pruning to call graph');
                    // Note: pruneByFilter method doesn't exist in the provided code
                    // $callGraph->pruneByFilter();
                }
            }

            // Configure renderer
            $maxDepth = $source->options['maxDepth'] ?? 20;
            $showMemory = $source->options['showMemory'] ?? true;
            $showCpuTime = $source->options['showCpuTime'] ?? true;

            $treeRenderer = new CallTreeMarkdownRenderer(
                maxDepth: $maxDepth,
                showMemory: $showMemory,
                showCpuTime: $showCpuTime
            );

            if ($filter !== null) {
                $treeRenderer->setFilter($filter);
            }

            // Generate markdown content
            $renderer = new MarkdownRenderer($treeRenderer);
            if ($filter !== null) {
                $renderer->setFilter($filter);
            }

            $markdownContent = $renderer->render(
                $callGraph,
                $source->options['title'] ?? 'XHProf Call Tree Analysis',
                $source->getDescription()
            );

            // Apply modifiers and add to builder
            $markdownContent = $modifiersApplier->apply($markdownContent, 'xhprof_trace.md');
            $builder->addBlock(new TextBlock($markdownContent));

            $this->logger?->info('Successfully generated call tree visualization', [
                'totalNodes' => $callGraph->getNodeCount(),
                'filteredNodes' => $callGraph->getFilteredNodeCount(),
            ]);

            // Handle method extraction if enabled
            if (isset($source->options['methodExtraction']) &&
                isset($source->options['methodExtraction']['enabled']) &&
                $source->options['methodExtraction']['enabled'] === true) {

                $this->extractMethods($source, $callGraph, $modifiersApplier);
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Error generating XHProf trace visualization', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $errorContent = "# XHProf Trace Analysis Error\n\n";
            $errorContent .= "**Error:** " . $e->getMessage() . "\n\n";
            $errorContent .= "Location: " . $e->getFile() . ":" . $e->getLine();

            $builder->addBlock(new TextBlock($errorContent));
        }

        return $builder->build();
    }

    private function loadTraceData(XHProfTraceSource $source): string
    {
        $filePaths = (array)$source->sourcePaths;

        if (empty($filePaths)) {
            throw new \RuntimeException('No source paths provided');
        }

        // First try direct file access for specific files
        foreach ($filePaths as $path) {
            if (is_file($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    $this->logger?->info('Loaded trace data from file', ['path' => $path]);
                    return $content;
                }
                $this->logger?->warning('Failed to read file', ['path' => $path]);
            }
        }

        // Then try to find files matching patterns in directories
        foreach ($filePaths as $path) {
            if (is_dir($path)) {
                $filePattern = $source->filePattern;
                $patterns = is_array($filePattern) ? $filePattern : [$filePattern];

                foreach ($patterns as $pattern) {
                    $files = glob($path . '/' . $pattern);

                    if (!empty($files)) {
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $content = file_get_contents($file);
                                if ($content !== false) {
                                    $this->logger?->info('Loaded trace data from file', ['path' => $file]);
                                    return $content;
                                }
                            }
                        }
                    }
                }

                $this->logger?->warning('No matching files found in directory', [
                    'path' => $path,
                    'patterns' => $patterns
                ]);
            }
        }

        throw new \RuntimeException('Could not find or load any valid trace files');
    }

    private function extractMethods(XHProfTraceSource $source, CallGraph $callGraph, ModifiersApplierInterface $modifiersApplier): void
    {
        try {
            // Get extraction options
            $extractionOptions = $source->options['methodExtraction'] ?? [];
            $outputPath = $extractionOptions['outputPath'] ?? null;

            if (empty($outputPath)) {
                $this->logger?->warning('Method extraction enabled but no output path specified');
                return;
            }

            $this->logger?->info('Starting method extraction', [
                'outputPath' => $outputPath,
                'options' => $extractionOptions,
            ]);


            // Configure extraction options
            $sourceDirectories = $extractionOptions['sourceDirectories'] ?? ['src', 'app'];

            // Define PSR-4 mappings from configuration or use default
            $psr4Mappings = $extractionOptions['psr4Mappings'] ?? ['App\\' => 'src/'];

            $this->logger?->info('Starting method extraction', [
                'outputPath' => $outputPath,
                'options' => $extractionOptions,
                'basePath' => $this->basePath,
                'psr4Mappings' => $psr4Mappings,
            ]);

            // Create method extractor
            $methodExtractor = new MethodExtractor($this->logger);

            // Configure extraction options
            $extractionConfig = [
                'title' => $extractionOptions['title'] ?? 'Methods Extracted from XHProf Call Tree',
                'description' => $extractionOptions['description'] ?? $source->getDescription(),
                'excludePaths' => $extractionOptions['excludePaths'] ?? ['vendor/'],
                'excludePhpInternals' => $extractionOptions['excludePhpInternals'] ?? true,
                'onlyVisibleMethods' => $extractionOptions['onlyIncludeVisibleMethods'] ??
                    ($extractionOptions['onlyVisibleMethods'] ?? true),
                'groupByNamespace' => $extractionOptions['groupByNamespace'] ?? true,
                'maxDepth' => $extractionOptions['maxDepth'] ?? ($source->options['maxDepth'] ?? 20),
                'detailedOutput' => $extractionOptions['detailedOutput'] ?? true,
                'projectRoot' => '.', // Use relative path starting from current working directory
                'sourceDirectories' => $sourceDirectories,
                'psr4Mappings' => $psr4Mappings,
            ];
            // Extract methods and generate markdown
            $methodsMarkdown = $methodExtractor->extract($callGraph, $extractionConfig);

            // Apply modifiers
            $methodsMarkdown = $modifiersApplier->apply($methodsMarkdown, 'xhprof_methods.md');

            // Write to file
            $outputFilePath = $this->basePath . '/' . $outputPath;
            $result = file_put_contents($outputFilePath, $methodsMarkdown);

            if ($result === false) {
                $this->logger?->error('Failed to write method extraction output file', [
                    'outputPath' => $outputFilePath,
                ]);
            } else {
                $this->logger?->info('Successfully wrote method extraction output file', [
                    'outputPath' => $outputFilePath,
                    'bytes' => $result,
                ]);
            }

        } catch (\Throwable $e) {
            $this->logger?->error('Error during method extraction', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
