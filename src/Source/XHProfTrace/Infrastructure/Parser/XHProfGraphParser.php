<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallMetrics;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\MethodSignature;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class XHProfGraphParser implements XHProfParserInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function parse(string $jsonData): CallGraph
    {
        $this->logger->debug('Parsing XHProf JSON data to build call graph');

        try {
            $data = json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to parse XHProf JSON data', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to parse XHProf JSON data: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data) || empty($data)) {
            $this->logger->error('Invalid XHProf data format: expected a non-empty array');
            throw new \RuntimeException('Invalid XHProf data format: expected a non-empty array');
        }

        return $this->buildCallGraph($data);
    }

    private function buildCallGraph(array $data): CallGraph
    {
        $graph = new CallGraph();

        // Process main() function if exists
        if (isset($data['main()'])) {
            $metrics = CallMetrics::fromXHProfMetrics($data['main()']);
            $graph->setRoot('main()');
        }

        // Process all call edges
        foreach ($data as $key => $metrics) {
            // Skip non-edge entries
            if (strpos($key, '==>') === false) {
                continue;
            }

            try {
                // Split into caller and callee
                list($caller, $callee) = explode('==>', $key);

                // Create call metrics
                $callMetrics = CallMetrics::fromXHProfMetrics($metrics);

                // Add edge to graph
                $graph->addEdge($caller, $callee, $callMetrics);
            } catch (\Exception $e) {
                $this->logger->warning("Skipping invalid edge: {$key}", [
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

        $this->logger->info('Built call graph', [
            'nodeCount' => $graph->getNodeCount(),
            'root' => $graph->getRoot(),
        ]);

        return $graph;
    }
}
