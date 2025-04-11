<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

/**
 * Graph-based structure for dependency analysis and cycle detection
 * Used to detect circular dependencies in method call chains
 */
class DependencyGraph
{
    /**
     * Nodes in the graph (methods)
     *
     * @var array<string, bool>
     */
    private array $nodes = [];

    /**
     * Edges in the graph (method calls)
     *
     * @var array<string, array<string>>
     */
    private array $edges = [];

    /**
     * Add a method node to the graph
     */
    public function addNode(string $node): void
    {
        if (!isset($this->nodes[$node])) {
            $this->nodes[$node] = true;
            $this->edges[$node] = [];
        }
    }

    /**
     * Add a method call edge to the graph
     */
    public function addEdge(string $from, string $to): void
    {
        $this->addNode($from);
        $this->addNode($to);

        // Avoid duplicate edges
        if (!in_array($to, $this->edges[$from], true)) {
            $this->edges[$from][] = $to;
        }
    }

    /**
     * Detect circular dependencies in the method call graph
     *
     * @return array<array<string>> List of circular dependency paths
     */
    public function detectCircularDependencies(): array
    {
        $visited = [];
        $recStack = [];
        $circularDeps = [];

        foreach (array_keys($this->nodes) as $node) {
            if (!isset($visited[$node])) {
                $this->isCyclicUtil($node, $visited, $recStack, $circularDeps);
            }
        }

        return $circularDeps;
    }

    /**
     * Utility method for cycle detection using DFS (Depth-First Search)
     *
     * @param string $node Current node being processed
     * @param array<string, bool> $visited Nodes already visited
     * @param array<string, bool> $recStack Recursion stack to track current path
     * @param array<array<string>> $circularDeps Detected circular dependencies
     * @param array<string> $currentPath Current path being explored
     * @return bool Whether a cycle was detected
     */
    private function isCyclicUtil(
        string $node,
        array &$visited,
        array &$recStack,
        array &$circularDeps,
        array $currentPath = []
    ): bool {
        // Mark current node as visited and add to recursion stack
        $visited[$node] = true;
        $recStack[$node] = true;
        $currentPath[] = $node;

        // Check all adjacent vertices
        foreach ($this->edges[$node] as $adjacent) {
            // Skip if not visited and recursively check
            if (!isset($visited[$adjacent])) {
                if ($this->isCyclicUtil($adjacent, $visited, $recStack, $circularDeps, $currentPath)) {
                    return true;
                }
            }
            // If already in recursion stack, we found a cycle
            elseif (isset($recStack[$adjacent])) {
                // Find start of cycle in the current path
                $cycleStart = array_search($adjacent, $currentPath);
                if ($cycleStart !== false) {
                    $cycle = array_slice($currentPath, $cycleStart);
                    $cycle[] = $adjacent; // Complete the cycle
                    $circularDeps[] = $cycle;
                }
                return true;
            }
        }

        // Remove from recursion stack when backtracking
        unset($recStack[$node]);
        return false;
    }

    /**
     * Get all nodes in the graph
     *
     * @return array<string>
     */
    public function getNodes(): array
    {
        return array_keys($this->nodes);
    }

    /**
     * Get all edges for a specific node
     *
     * @return array<string>
     */
    public function getEdges(string $node): array
    {
        return $this->edges[$node] ?? [];
    }

    /**
     * Get the entire edge map
     *
     * @return array<string, array<string>>
     */
    public function getAllEdges(): array
    {
        return $this->edges;
    }

    /**
     * Clear the graph
     */
    public function clear(): void
    {
        $this->nodes = [];
        $this->edges = [];
    }
}
