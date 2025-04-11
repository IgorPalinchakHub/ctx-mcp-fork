<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Extraction;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;
use Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Filter\MethodExtractionFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Writer\MethodListMarkdownWriter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MethodExtractor
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Extract methods from a call graph and generate markdown content
     *
     * @param CallGraph $graph The call graph to extract methods from
     * @param array $options Configuration options for extraction
     * @return string Generated markdown content
     */
    public function extract(CallGraph $graph, array $options = []): string
    {
        $this->logger->debug('Starting method extraction', [
            'options' => $options,
        ]);

        // Parse options
        $title = $options['title'] ?? 'Methods Extracted from XHProf Call Tree';
        $description = $options['description'] ?? '';
        $excludePaths = $options['excludePaths'] ?? ['vendor/'];
        $excludePhpInternals = $options['excludePhpInternals'] ?? true;
        $onlyVisibleMethods = $options['onlyVisibleMethods'] ?? true;
        $groupByNamespace = $options['groupByNamespace'] ?? true;
        $maxDepth = $options['maxDepth'] ?? 100;
        $detailedOutput = $options['detailedOutput'] ?? false;
        $projectRoot = $options['projectRoot'] ?? '';
        $sourceDirectories = $options['sourceDirectories'] ?? ['src', 'app'];
        $psr4Mappings = $options['psr4Mappings'] ?? ['App\\' => 'src/'];

        // Create filter
        $filter = new MethodExtractionFilter(
            excludePaths: $excludePaths,
            excludePhpInternals: $excludePhpInternals,
        );

        // Create visitor
        $visitor = new MethodExtractionVisitor(
            filter: $filter,
            maxDepth: $maxDepth,
        );

        // Build and traverse the tree
        $tree = $graph->buildCallTree();

        if (empty($tree)) {
            $this->logger->warning('No call tree data available for method extraction');
            return "# {$title}\n\nNo call tree data available for method extraction.";
        }

        // Visit the tree to extract methods
        $visitor->visitNode($tree, 0, $onlyVisibleMethods);

        // Get extracted methods
        $extractedMethods = $visitor->getExtractedMethods();

        $this->logger->info('Method extraction completed', [
            'classCount' => count($extractedMethods),
            'methodCount' => array_sum(array_map('count', $extractedMethods)),
            'detailedOutput' => $detailedOutput,
            'projectRoot' => $projectRoot,
            'psr4Mappings' => $psr4Mappings
        ]);

        // Write to markdown
        if ($detailedOutput) {
            $writer = new \Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Writer\DetailedMethodListMarkdownWriter(
                projectRoot: $projectRoot,
                sourceDirectories: $sourceDirectories,
                psr4MappingsConfig: $psr4Mappings,
                logger: $this->logger
            );
        } else {
            $writer = new \Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Writer\MethodListMarkdownWriter();
        }

        return $writer->write(
            extractedMethods: $extractedMethods,
            title: $title,
            description: $description,
            groupByNamespace: $groupByNamespace,
        );
    }
}
