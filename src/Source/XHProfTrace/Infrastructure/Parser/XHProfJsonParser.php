<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallMetrics;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\MethodSignature;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class XHProfJsonParser implements XHProfParserInterface
{
    private ?XHProfTraceFilter $filter = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function setFilter(?XHProfTraceFilter $filter): void
    {
        $this->filter = $filter;
    }

    public function parse(string $data): CallGraph
    {
        $this->logger->debug('Parsing XHProf JSON data');

        try {
            $jsonData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to parse XHProf JSON data', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to parse XHProf JSON data: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($jsonData) || empty($jsonData)) {
            $this->logger->error('Invalid XHProf data format: expected a non-empty array');
            throw new \RuntimeException('Invalid XHProf data format: expected a non-empty array');
        }

        return $this->buildCallGraph($jsonData);
    }

    private function buildCallGraph(array $data): CallGraph
    {
        $graph = new CallGraph();

        // Apply filter if provided
        if ($this->filter !== null) {
            $graph->setFilter($this->filter);
            $this->logger->info('Applied filter to call graph', [
                'includeNamespaces' => $this->filter->getIncludeNamespaces(),
                'excludeNamespaces' => $this->filter->getExcludeNamespaces(),
                'includeFunctions' => $this->filter->getIncludeFunctions(),
                'minExecutionTime' => $this->filter->getMinExecutionTime(),
            ]);
        }

        // Check if there's a main() entry in the data
        $hasMain = isset($data['main()']);

        if ($hasMain) {
            $this->logger->info('Found main() entry point in trace data');

            // Set main() as the root
            $graph->setRoot('main()');

            // Add main metrics
            $mainMetrics = CallMetrics::fromXHProfMetrics($data['main()']);
        }

        // First pass: identify standard caller->callee entries
        $standardEntries = [];
        $nonStandardEntries = [];

        foreach ($data as $signatureString => $metrics) {
            if (strpos($signatureString, '==>') !== false) {
                $standardEntries[$signatureString] = $metrics;
            } else if ($signatureString !== 'main()') {
                // This is a function/method call without the standard format
                $nonStandardEntries[$signatureString] = $metrics;
            }
        }

        // Process standard entries first
        foreach ($standardEntries as $signatureString => $metrics) {
            try {
                // Split the call signature parts
                list($caller, $callee) = explode('==>', $signatureString);

                // Create call metrics
                $callMetrics = CallMetrics::fromXHProfMetrics($metrics);

                // Add to graph
                $graph->addEdge($caller, $callee, $callMetrics);
            } catch (\Exception $e) {
                $this->logger->warning("Skipping invalid signature: {$signatureString}", [
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Now process non-standard entries as standalone nodes
        foreach ($nonStandardEntries as $signatureString => $metrics) {
            try {
                // Create metrics
                $callMetrics = CallMetrics::fromXHProfMetrics($metrics);

                // Add as a standalone node with empty caller
                // We use a special "(root)" caller for these entries
                $graph->addEdge('(root)', $signatureString, $callMetrics);
            } catch (\Exception $e) {
                $this->logger->warning("Skipping invalid standalone signature: {$signatureString}", [
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Find and set the root node if not already set
        if ($graph->getRoot() === null) {
            $root = $graph->findRoot();
            if ($root !== null) {
                $graph->setRoot($root);
            }
        }

        // Log information about the graph
        $this->logger->info('Built call graph', [
            'nodeCount' => $graph->getNodeCount(),
            'root' => $graph->getRoot(),
            'standardEntries' => count($standardEntries),
            'nonStandardEntries' => count($nonStandardEntries),
        ]);

        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $this->logger->info('Filtered call graph', [
                'totalNodes' => $graph->getNodeCount(),
                'filteredNodes' => $graph->getFilteredNodeCount(),
            ]);
        }

        return $graph;
    }
}
