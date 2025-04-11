<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Extraction;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\MethodSignature;
use Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Filter\MethodExtractionFilter;

final class MethodExtractionVisitor
{
    /**
     * @var array<string, array<string, bool>> Map of classes and their methods
     */
    private array $extractedMethods = [];

    /**
     * @var array<string> Paths we've already visited to prevent recursion
     */
    private array $visitedPaths = [];

    public function __construct(
        private readonly MethodExtractionFilter $filter,
        private readonly int $maxDepth = 100,
    ) {
    }

    /**
     * Extract methods from a call tree node
     *
     * @param array $node The call tree node from the graph
     * @param int $depth Current depth in the tree
     * @param bool $onlyVisibleMethods Whether to extract only methods that are visible (not collapsed)
     * @return void
     */
    public function visitNode(array $node, int $depth = 0, bool $onlyVisibleMethods = false): void
    {
        if ($depth > $this->maxDepth) {
            return;
        }

        $nodeName = $node['node'];
        $isRecursive = $node['recursive'] ?? false;
        $isCollapsed = $node['collapsed'] ?? false;

        // Skip recursive calls to avoid infinite loops
        if ($isRecursive || in_array($nodeName, $this->visitedPaths)) {
            return;
        }

        // If we only want visible methods and this node is collapsed, skip it
        if ($onlyVisibleMethods && $isCollapsed) {
            return;
        }

        // Add to visited paths to prevent recursion
        $this->visitedPaths[] = $nodeName;

        // Extract the method from this node
        $this->extractMethod(new MethodSignature($nodeName));

        // Process children
        $children = $node['children'] ?? [];
        foreach ($children as $child) {
            $this->visitNode($child, $depth + 1, $onlyVisibleMethods);
        }

        // Remove from visited paths when we're done with this branch
        array_pop($this->visitedPaths);
    }

    /**
     * Extract a method from a signature
     */
    private function extractMethod(MethodSignature $signature): void
    {
        // Skip if doesn't pass filter
        if (!$this->filter->shouldInclude($signature)) {
            return;
        }

        $class = $signature->getCalleeClass();
        $method = $signature->getCalleeMethod();

        // Handle different types of signatures
        if (empty($class) && !empty($method)) {
            // Global function
            $this->extractedMethods['Global Functions'][$method] = true;
        } elseif (!empty($class) && empty($method)) {
            // Class without specific method (might be a constructor or invocation)
            $this->extractedMethods[$class]['__invoke'] = true;
        } elseif (!empty($class) && !empty($method)) {
            // Standard class method
            $this->extractedMethods[$class][$method] = true;
        }
    }

    /**
     * Get all extracted methods organized by class
     *
     * @return array<string, array<string>> Classes and their methods
     */
    public function getExtractedMethods(): array
    {
        $result = [];

        foreach ($this->extractedMethods as $class => $methods) {
            $result[$class] = array_keys($methods);
        }

        return $result;
    }

    /**
     * Reset the visitor state
     */
    public function reset(): void
    {
        $this->extractedMethods = [];
        $this->visitedPaths = [];
    }
}
