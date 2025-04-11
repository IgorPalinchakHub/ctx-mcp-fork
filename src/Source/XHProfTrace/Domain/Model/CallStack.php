<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;

class CallStack
{
    private array $nodeMap = [];
    private ?CallNode $rootNode = null;
    private array $callMap = [];
    private array $inclusiveTime = [];
    private array $inclusiveMemory = [];
    private ?XHProfTraceFilter $filter = null;

    public function registerCall(MethodSignature $signature, CallMetrics $metrics): void
    {
        $calleeSignature = $signature->getCalleeSignature();
        $callerSignature = $signature->getCallerSignature();

        // Create the call node if it doesn't exist
        if (!isset($this->nodeMap[$calleeSignature])) {
            $this->nodeMap[$calleeSignature] = new CallNode($signature, $metrics);
            $this->inclusiveTime[$calleeSignature] = $metrics->getWallTime();
            $this->inclusiveMemory[$calleeSignature] = $metrics->getMemoryUsage();
        } else {
            // Just update the inclusive times
            $this->inclusiveTime[$calleeSignature] += $metrics->getWallTime();
            $this->inclusiveMemory[$calleeSignature] += $metrics->getMemoryUsage();
        }

        // Update call relationships
        if (!empty($callerSignature)) {
            if (!isset($this->callMap[$callerSignature])) {
                $this->callMap[$callerSignature] = [];
            }

            if (!in_array($calleeSignature, $this->callMap[$callerSignature])) {
                $this->callMap[$callerSignature][] = $calleeSignature;
            }
        }
    }

    public function setFilter(?XHProfTraceFilter $filter): void
    {
        $this->filter = $filter;
    }

    public function getFilter(): ?XHProfTraceFilter
    {
        return $this->filter;
    }

    public function buildCallTree(): void
    {
        // First always look for main() as the entry point
        if (isset($this->nodeMap['main()'])) {
            $this->rootNode = $this->nodeMap['main()'];
        } else {
            // If no main(), then look for entry points without a caller
            $entryPoints = [];

            foreach ($this->nodeMap as $signature => $node) {
                $hasIncomingCalls = false;
                foreach ($this->callMap as $caller => $callees) {
                    if (in_array($signature, $callees)) {
                        $hasIncomingCalls = true;
                        break;
                    }
                }

                if (!$hasIncomingCalls) {
                    $entryPoints[] = $signature;
                }
            }

            if (count($entryPoints) === 1) {
                $this->rootNode = $this->nodeMap[$entryPoints[0]];
            } elseif (count($entryPoints) > 1) {
                $this->createSyntheticRoot($entryPoints);
            } else {
                // Fallback: use the function with highest wall time
                $this->useHighestTimeAsRoot();
            }
        }

        // Build the tree hierarchy
        if ($this->rootNode !== null) {
            $this->buildNodeChildren($this->rootNode, []);
            $this->rootNode->sortChildren();
        }
    }

    private function useHighestTimeAsRoot(): void
    {
        $maxTime = 0;
        foreach ($this->nodeMap as $signature => $node) {
            $time = $this->inclusiveTime[$signature] ?? 0;
            if ($time > $maxTime) {
                $maxTime = $time;
                $this->rootNode = $node;
            }
        }
    }

    private function createSyntheticRoot(array $entryPoints): void
    {
        $signature = new MethodSignature("(synthetic)==>(application)");

        $totalTime = 0;
        $totalCpuTime = 0;
        $totalMemory = 0;

        foreach ($entryPoints as $entryPoint) {
            if (isset($this->nodeMap[$entryPoint])) {
                $metrics = $this->nodeMap[$entryPoint]->getMetrics();
                $totalTime += $metrics->getWallTime();
                $totalCpuTime += $metrics->getCpuTime();
                $totalMemory += $metrics->getMemoryUsage();
            }
        }

        $metrics = new CallMetrics(
            callCount: 1,
            wallTime: $totalTime,
            cpuTime: $totalCpuTime,
            memoryUsage: $totalMemory,
            peakMemoryUsage: 0
        );

        $this->rootNode = new CallNode($signature, $metrics);

        // Add all entry points as direct children
        foreach ($entryPoints as $entryPoint) {
            if (isset($this->nodeMap[$entryPoint])) {
                $this->rootNode->addChild($this->nodeMap[$entryPoint]);
            }
        }
    }

    private function buildNodeChildren(CallNode $node, array $visited): void
    {
        $signature = $node->getCalleeMethod();

        // Prevent cycles
        if (in_array($signature, $visited)) {
            return;
        }

        // Add to visited nodes to prevent recursion
        $visited[] = $signature;

        // Add children
        if (isset($this->callMap[$signature])) {
            foreach ($this->callMap[$signature] as $childSignature) {
                if (isset($this->nodeMap[$childSignature])) {
                    $childNode = $this->nodeMap[$childSignature];

                    // Check if this child should be included based on filter criteria
                    if ($this->shouldIncludeNode($childNode)) {
                        // Add the child to this node
                        $node->addChild($childNode);

                        // Process this child's children (only if not too deep to prevent stack overflow)
                        if (count($visited) < 100) {
                            $this->buildNodeChildren($childNode, $visited);
                        }
                    }
                }
            }
        }
    }

    private function shouldIncludeNode(CallNode $node): bool
    {
        // If no filter is set, include everything
        if ($this->filter === null || $this->filter->isEmpty()) {
            return true;
        }

        return $this->filter->matches($node);
    }

    public function getRootNode(): ?CallNode
    {
        return $this->rootNode;
    }

    public function getAllNodes(): array
    {
        return $this->nodeMap;
    }

    /**
     * Get filtered nodes (only nodes that match the filter)
     */
    public function getFilteredNodes(): array
    {
        if ($this->filter === null || $this->filter->isEmpty()) {
            return $this->nodeMap;
        }

        $result = [];
        foreach ($this->nodeMap as $signature => $node) {
            if ($this->shouldIncludeNode($node)) {
                $result[$signature] = $node;
            }
        }

        return $result;
    }

    public function getTotalCalls(): int
    {
        return count($this->nodeMap);
    }

    /**
     * Get total filtered calls (only calls that match the filter)
     */
    public function getTotalFilteredCalls(): int
    {
        return count($this->getFilteredNodes());
    }

    public function getTotalWallTime(): int
    {
        $rootNode = $this->getRootNode();
        return $rootNode ? $rootNode->getMetrics()->getWallTime() : 0;
    }

    public function getInclusiveTime(string $signature): int
    {
        return $this->inclusiveTime[$signature] ?? 0;
    }

    public function getInclusiveMemory(string $signature): int
    {
        return $this->inclusiveMemory[$signature] ?? 0;
    }

    public function getHottestFunctions(int $limit = 10): array
    {
        $times = $this->inclusiveTime;
        arsort($times);

        $result = [];
        $count = 0;

        foreach ($times as $signature => $time) {
            if (isset($this->nodeMap[$signature])) {
                $node = $this->nodeMap[$signature];

                // Skip nodes that don't match the filter
                if (!$this->shouldIncludeNode($node)) {
                    continue;
                }

                $result[] = [
                    'signature' => $signature,
                    'time' => $time,
                    'node' => $node,
                ];

                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $result;
    }
}
