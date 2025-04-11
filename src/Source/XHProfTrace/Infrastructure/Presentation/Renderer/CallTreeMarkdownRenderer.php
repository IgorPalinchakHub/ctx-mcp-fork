<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\Renderer;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;

class CallTreeMarkdownRenderer implements TreeRenderer
{
    private ?XHProfTraceFilter $filter = null;

    public function __construct(
        private readonly int $maxDepth = 20,
        private readonly bool $showMemory = true,
        private readonly bool $showCpuTime = true,
    ) {
    }

    public function setFilter(?XHProfTraceFilter $filter): void
    {
        $this->filter = $filter;
    }

    public function render(CallGraph $graph): string
    {
        // Build the tree structure from the graph
        $tree = $graph->buildCallTree();

        if (empty($tree)) {
            return "No calls found in the trace data.\n";
        }

        $content = "";

        // If we have filters, add info about what's being filtered
        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $content .= $this->renderFilterInfo();
        }

        // Add summary statistics
        $totalNodes = $graph->getNodeCount();
        $filteredNodes = $graph->getFilteredNodeCount();

        $content .= "Total unique calls: " . $totalNodes;

        if ($filteredNodes !== $totalNodes) {
            $content .= " (showing " . $filteredNodes . " after filtering)\n";
        } else {
            $content .= "\n";
        }

        // Generate the tree visualization
        $content .= $this->renderNode($tree, "", true);

        return $content;
    }

    private function renderFilterInfo(): string
    {
        $lines = ["Applied filters:\n"];

        if (!empty($this->filter->getIncludeNamespaces())) {
            $lines[] = "- Including namespaces: " . implode(", ", $this->filter->getIncludeNamespaces());
        }

        if (!empty($this->filter->getExcludeNamespaces())) {
            $lines[] = "- Excluding namespaces: " . implode(", ", $this->filter->getExcludeNamespaces());
            $lines[] = "  (max consecutive excluded calls: " . $this->filter->getMaxConsecutiveExcludedCalls() . ")";
        }

        if (!empty($this->filter->getIncludeFunctions())) {
            $suffix = $this->filter->getFunctionsAsRegex() ? " (as regex)" : "";
            $lines[] = "- Including functions: " . implode(", ", $this->filter->getIncludeFunctions()) . $suffix;
        }

        if (!empty($this->filter->getExcludeFunctions())) {
            $suffix = $this->filter->getFunctionsAsRegex() ? " (as regex)" : "";
            $lines[] = "- Excluding functions: " . implode(", ", $this->filter->getExcludeFunctions()) . $suffix;
        }

        if ($this->filter->getMinExecutionTime() > 0) {
            $lines[] = "- Minimum execution time: " . $this->formatTime($this->filter->getMinExecutionTime());
        }

        $lines[] = "- Match criteria: " . ($this->filter->getRequireAllFilters() ? "All filters must match" : "Any filter can match");
        $lines[] = "\n";

        return implode("\n", $lines);
    }

    private function renderNode(array $node, string $prefix, bool $isRoot = false): string
    {
        $nodeName = $node['node'];
        $depth = $node['depth'];
        $isRecursive = $node['recursive'] ?? false;
        $isCollapsed = $node['collapsed'] ?? false;
        $metrics = $node['metrics'];

        // Skip rendering if too deep
        if ($depth > $this->maxDepth) {
            return $prefix . "[max depth reached]\n";
        }

        // Skip rendering if recursive
        if ($isRecursive) {
            return $prefix . "[recursive call to {$nodeName}]\n";
        }

        // Calculate hotspot indicators
        $indicator = '';
        if ($metrics !== null) {
            $wallTime = $metrics->getWallTime();
            if ($wallTime > 10000) { // More than 10ms
                $indicator = "🔥 ";
            } elseif ($wallTime > 5000) { // More than 5ms
                $indicator = "⚠️ ";
            }
        }

        // Add metrics if requested
        $metricsInfo = "";
        if ($this->showMemory || $this->showCpuTime) {
            if ($metrics !== null) {
                $metricsInfo = " (";

                if ($this->showCpuTime) {
                    $metricsInfo .= "CPU: " . $metrics->getFormattedCpuTime();
                }

                if ($this->showMemory && $this->showCpuTime) {
                    $metricsInfo .= ", ";
                }

                if ($this->showMemory) {
                    $metricsInfo .= "Mem: " . $metrics->getFormattedMemoryUsage();
                }

                $metricsInfo .= ")";
            }
        }

        // Add collapsed indicator if needed
        $collapsedIndicator = $isCollapsed ? " [...]" : "";

        // Format the node line
        $output = $prefix . $indicator . $nodeName . $metricsInfo . $collapsedIndicator . "\n";

        // Process children
        $children = $node['children'];
        if (!empty($children)) {
            $childCount = count($children);

            for ($i = 0; $i < $childCount; $i++) {
                $child = $children[$i];
                $isLast = ($i === $childCount - 1);
                $childPrefix = $isRoot ? "  " : $prefix . ($isLast ? "  " : "│ ");
                $connector = $isLast ? "└─ " : "├─ ";

                // If the current node is collapsed, add a special prefix for its children
                if ($isCollapsed) {
                    $childPrefix = $isRoot ? "  " : $prefix . ($isLast ? "  " : "│ ");
                    $connector = "↪ ";  // Use a special connector to indicate path skipping
                }

                $output .= $this->renderNode(
                    $child,
                    $childPrefix . $connector,
                    false
                );
            }
        }

        return $output;
    }

    private function formatTime(int $time): string
    {
        if ($time < 1000) {
            return "{$time}μs";
        } elseif ($time < 1000000) {
            return round($time / 1000, 2) . 'ms';
        } else {
            return round($time / 1000000, 2) . 's';
        }
    }
}
