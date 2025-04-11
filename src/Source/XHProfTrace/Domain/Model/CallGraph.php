<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;

class CallGraph
{
    /** @var array<string, array<string>> Adjacency list of caller -> callees */
    private array $edges = [];

    /** @var array<string, CallMetrics> Map of function signatures to metrics */
    private array $metrics = [];

    /** @var array<string, bool> Map to track visited nodes during traversal */
    private array $visited = [];

    /** @var string|null The root node signature */
    private ?string $root = null;

    /** @var XHProfTraceFilter|null Filter to apply during tree building */
    private ?XHProfTraceFilter $filter = null;

    /** @var array<string, MethodSignature> Cache of parsed signatures */
    private array $signatureCache = [];

    /** @var array<string, bool> Cache for path importance decisions */
    private array $pathImportanceCache = [];

    public function addEdge(string $caller, string $callee, CallMetrics $metrics): void
    {
        // Add the caller -> callee edge
        if (!isset($this->edges[$caller])) {
            $this->edges[$caller] = [];
        }

        if (!in_array($callee, $this->edges[$caller])) {
            $this->edges[$caller][] = $callee;
        }

        // Store metrics for the callee
        $this->metrics[$callee] = $metrics;
    }

    public function setRoot(string $root): void
    {
        $this->root = $root;
    }

    public function getRoot(): ?string
    {
        return $this->root;
    }

    public function setFilter(?XHProfTraceFilter $filter): void
    {
        $this->filter = $filter;

        // Clear caches when filter changes
        $this->pathImportanceCache = [];
    }

    public function getFilter(): ?XHProfTraceFilter
    {
        return $this->filter;
    }

    public function findRoot(): ?string
    {
        // First check for main() as it's the most common root
        if (isset($this->metrics['main()'])) {
            return 'main()';
        }

        // Find nodes that have no incoming edges (entry points)
        $allNodes = $this->getAllNodes();
        $entryPoints = [];

        foreach ($allNodes as $node) {
            $hasIncoming = false;
            foreach ($this->edges as $caller => $callees) {
                if (in_array($node, $callees)) {
                    $hasIncoming = true;
                    break;
                }
            }

            if (!$hasIncoming && isset($this->edges[$node])) {
                $entryPoints[] = $node;
            }
        }

        if (count($entryPoints) === 1) {
            return $entryPoints[0];
        }

        if (count($entryPoints) > 1) {
            // Find the entry point with highest wall time
            $maxTime = -1;
            $maxNode = null;

            foreach ($entryPoints as $node) {
                if (isset($this->metrics[$node])) {
                    $time = $this->metrics[$node]->getWallTime();
                    if ($time > $maxTime) {
                        $maxTime = $time;
                        $maxNode = $node;
                    }
                }
            }

            return $maxNode;
        }

        // If no entry points found, use node with highest wall time
        $maxTime = -1;
        $maxNode = null;

        foreach ($this->metrics as $node => $metrics) {
            $time = $metrics->getWallTime();
            if ($time > $maxTime) {
                $maxTime = $time;
                $maxNode = $node;
            }
        }

        return $maxNode;
    }

    public function buildCallTree(): array
    {
        if ($this->root === null) {
            $this->root = $this->findRoot();

            if ($this->root === null) {
                return [];
            }
        }

        // Reset visited nodes
        $this->visited = [];

        // Pre-compute path importance if filtering is active
        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $this->computePathImportance();
        }

        // Build tree starting from the root
        return $this->buildTree($this->root, 0);
    }

    /**
     * Pre-compute which paths lead to important nodes (included namespaces)
     * This helps us maintain visibility of nested allowed calls
     */
    private function computePathImportance(): void
    {
        if ($this->filter === null) {
            return;
        }

        // First identify all important nodes (explicitly included)
        $importantNodes = [];

        foreach ($this->getAllNodes() as $node) {
            if ($this->isNodeExplicitlyIncluded($node)) {
                $importantNodes[$node] = true;
            }
        }

        // Now mark all nodes that lead to important nodes
        $changed = true;
        while ($changed) {
            $changed = false;

            foreach ($this->edges as $caller => $callees) {
                // If caller is already marked as important, continue
                if (isset($importantNodes[$caller])) {
                    continue;
                }

                // Check if this caller leads to any important node
                foreach ($callees as $callee) {
                    if (isset($importantNodes[$callee])) {
                        $importantNodes[$caller] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }

        // Store results in cache
        $this->pathImportanceCache = $importantNodes;
    }

    /**
     * Check if a node is explicitly included by the filter criteria
     */
    private function isNodeExplicitlyIncluded(string $nodeSignature): bool
    {
        if ($this->filter === null || $this->filter->isEmpty()) {
            return true;
        }

        // Get the signature object
        if (!isset($this->signatureCache[$nodeSignature])) {
            $this->signatureCache[$nodeSignature] = new MethodSignature($nodeSignature);
        }

        $signature = $this->signatureCache[$nodeSignature];

        return $this->filter->isInIncludedNamespace($signature);
    }

    private function buildTree(string $node, int $depth, array $path = [], int $consecutiveExcludedCount = 0): array
    {
        // Check for recursion
        if (in_array($node, $path)) {
            return [
                'node' => node,
                'depth' => $depth,
                'metrics' => $this->metrics[$node] ?? null,
                'recursive' => true,
                'children' => [],
                'collapsed' => false
            ];
        }

        // Add to path for cycle detection
        $path[] = $node;

        // Check if this node is in an excluded namespace
        $isInExcludedNamespace = false;
        if ($this->filter !== null) {
            $nodeSignature = $this->getOrCreateSignature($node);
            $isInExcludedNamespace = $this->filter->isInExcludedNamespace($nodeSignature);
        }

        // Update consecutive excluded count
        if ($isInExcludedNamespace) {
            $consecutiveExcludedCount++;
        } else {
            $consecutiveExcludedCount = 0;
        }

        // Create tree node
        $treeNode = [
            'node' => $node,
            'depth' => $depth,
            'metrics' => $this->metrics[$node] ?? null,
            'recursive' => false,
            'children' => [],
            'collapsed' => false
        ];

        // Check if we need to indicate collapsed state due to consecutive excluded namespaces
        $maxConsecutiveExcluded = $this->filter !== null ? $this->filter->getMaxConsecutiveExcludedCalls() : 3;
        $shouldCheckChildren = true;

        if ($isInExcludedNamespace && $consecutiveExcludedCount > $maxConsecutiveExcluded) {
            // Mark as collapsed but continue processing to look for included namespaces further down
            $treeNode['collapsed'] = true;

            // Optimization: only continue if this node leads to an important node
            if (!isset($this->pathImportanceCache[$node])) {
                $shouldCheckChildren = false;
            }
        }

        // Process children if depth not too great and we should check children
        if ($depth < 100 && isset($this->edges[$node]) && $shouldCheckChildren) {
            foreach ($this->edges[$node] as $child) {
                // Check if we should include this child based on filter
                if ($this->shouldIncludeNodeInTree($child)) {
                    $childTree = $this->buildTree($child, $depth + 1, $path, $consecutiveExcludedCount);

                    // Only add the child if:
                    // 1. It has non-collapsed children (indicating important nodes deeper in the tree), OR
                    // 2. It's directly included in the filter, OR
                    // 3. It's a collapsed node but contains important paths
                    if ($this->hasNonCollapsedChildren($childTree) ||
                        $this->isNodeAllowedIndividually($child) ||
                        ($childTree['collapsed'] && isset($this->pathImportanceCache[$child]))) {

                        $treeNode['children'][] = $childTree;
                    }
                }
            }
        }

        return $treeNode;
    }

    /**
     * Check if a tree node has any non-collapsed children (indicating important paths)
     */
    private function hasNonCollapsedChildren(array $treeNode): bool
    {
        if (empty($treeNode['children'])) {
            return false;
        }

        foreach ($treeNode['children'] as $child) {
            if (!$child['collapsed'] || $this->hasNonCollapsedChildren($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get or create a MethodSignature object for a node
     */
    private function getOrCreateSignature(string $nodeSignature): MethodSignature
    {
        if (!isset($this->signatureCache[$nodeSignature])) {
            $this->signatureCache[$nodeSignature] = new MethodSignature($nodeSignature);
        }

        return $this->signatureCache[$nodeSignature];
    }

    /**
     * Check if a node should be included in the tree
     * This incorporates both individual node filtering and path preservation
     */
    private function shouldIncludeNodeInTree(string $nodeSignature): bool
    {
        // If no filtering, include everything
        if ($this->filter === null || $this->filter->isEmpty()) {
            return true;
        }

        // Check if this node is part of an important path
        if (isset($this->pathImportanceCache[$nodeSignature])) {
            return true;
        }

        // Otherwise, apply individual node filtering
        return $this->isNodeAllowedIndividually($nodeSignature);
    }

    /**
     * Check if an individual node is allowed based on filter criteria
     */
    private function isNodeAllowedIndividually(string $nodeSignature): bool
    {
        if ($this->filter === null) {
            return true;
        }

        // Create a temporary CallNode to check against the filter
        if (isset($this->metrics[$nodeSignature])) {
            // Use cached signature object if available
            if (!isset($this->signatureCache[$nodeSignature])) {
                $this->signatureCache[$nodeSignature] = new MethodSignature($nodeSignature);
            }

            $signatureObj = $this->signatureCache[$nodeSignature];
            $metrics = $this->metrics[$nodeSignature];
            $node = new CallNode($signatureObj, $metrics);

            return $this->filter->matches($node);
        }

        return false;
    }

    public function getMetrics(string $node): ?CallMetrics
    {
        return $this->metrics[$node] ?? null;
    }

    public function getAllNodes(): array
    {
        $nodes = array_keys($this->edges);

        // Also include leaf nodes (callees with no outgoing edges)
        foreach ($this->edges as $caller => $callees) {
            foreach ($callees as $callee) {
                if (!in_array($callee, $nodes)) {
                    $nodes[] = $callee;
                }
            }
        }

        return $nodes;
    }

    public function getNodeCount(): int
    {
        return count($this->getAllNodes());
    }

    /**
     * Get filtered node count (only nodes that match the filter)
     */
    public function getFilteredNodeCount(): int
    {
        if ($this->filter === null || $this->filter->isEmpty()) {
            return $this->getNodeCount();
        }

        // If we have path importance data, use it
        if (!empty($this->pathImportanceCache)) {
            return count($this->pathImportanceCache);
        }

        // Otherwise count nodes that match individually
        $count = 0;
        foreach ($this->getAllNodes() as $nodeSignature) {
            if ($this->isNodeAllowedIndividually($nodeSignature)) {
                $count++;
            }
        }

        return $count;
    }
}
