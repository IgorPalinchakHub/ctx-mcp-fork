<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter\XHProfTraceFilter;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallGraph;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\Renderer\TreeRenderer;

class MarkdownRenderer
{
    private ?XHProfTraceFilter $filter = null;

    public function __construct(
        private readonly TreeRenderer $treeRenderer
    ) {
    }

    public function setFilter(?XHProfTraceFilter $filter): void
    {
        $this->filter = $filter;
        $this->treeRenderer->setFilter($filter);
    }

    public function render(CallGraph $graph, string $title = '', string $description = ''): string
    {
        $content = "# " . ($title ?: "XHProf Call Tree Analysis") . "\n\n";

        if (!empty($description)) {
            $content .= "{$description}\n\n";
        }

        // Add summary info
        $content .= "## Summary\n\n";

        // Add filter information if applicable
        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $content .= "This report contains filtered data based on specific criteria.\n\n";
        }

        $totalNodes = $graph->getNodeCount();
        $filteredNodes = $graph->getFilteredNodeCount();

        $content .= "- Total unique calls: {$totalNodes}\n";

        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $content .= "- Calls matching filter: {$filteredNodes}\n";
            $percentage = $totalNodes > 0 ? round(($filteredNodes / $totalNodes) * 100, 1) : 0;
            $content .= "- Filter impact: {$percentage}% of calls displayed\n";
        }

        $content .= "\n";

        // Add the call tree visualization
        $content .= "## Call Tree\n\n";
        $content .= "```\n";
        $content .= $this->treeRenderer->render($graph);
        $content .= "```\n";

        // Add footer with information about how to read the visualization
        $content .= "\n\n## How to Read This Report\n\n";
        $content .= "- 🔥 indicates functions consuming significant time (hot spots)\n";
        $content .= "- ⚠️ indicates functions consuming moderate time\n";
        $content .= "- Tree depth might be limited to prevent excessive output\n";

        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $content .= "- [...]  indicates consecutive calls from excluded namespaces have been collapsed\n";
            $content .= "- ↪  indicates a call that follows after a collapsed section\n";
        }

        if ($this->filter !== null && !$this->filter->isEmpty()) {
            $content .= "\n## Applied Filters\n\n";

            if (!empty($this->filter->getIncludeNamespaces())) {
                $content .= "- **Including namespaces**: " . implode(", ", $this->filter->getIncludeNamespaces()) . "\n";
            }

            if (!empty($this->filter->getExcludeNamespaces())) {
                $content .= "- **Excluding namespaces**: " . implode(", ", $this->filter->getExcludeNamespaces()) . "\n";
                $content .= "- **Max consecutive excluded calls**: " . $this->filter->getMaxConsecutiveExcludedCalls() . "\n";
                $content .= "  (Calls to excluded namespaces are collapsed after this limit, while preserving important paths)\n";
            }

            if (!empty($this->filter->getIncludeFunctions())) {
                $suffix = $this->filter->getFunctionsAsRegex() ? " (as regex)" : "";
                $content .= "- **Including functions**: " . implode(", ", $this->filter->getIncludeFunctions()) . $suffix . "\n";
            }

            if (!empty($this->filter->getExcludeFunctions())) {
                $suffix = $this->filter->getFunctionsAsRegex() ? " (as regex)" : "";
                $content .= "- **Excluding functions**: " . implode(", ", $this->filter->getExcludeFunctions()) . $suffix . "\n";
            }

            if ($this->filter->getExcludePhpInternals()) {
                $content .= "- **Excluding PHP internal functions**: Yes\n";
            }

            if ($this->filter->getMinExecutionTime() > 0) {
                $content .= "- **Minimum execution time**: " . $this->formatTime($this->filter->getMinExecutionTime()) . "\n";
            }

            $content .= "- **Match criteria**: " . ($this->filter->getRequireAllFilters() ? "All filters must match" : "Any filter can match") . "\n";

            // Path preservation note
            $content .= "- **Path preservation**: ";
            if ($this->filter->getPreserveImportantPaths()) {
                $content .= "Enabled - calls to excluded namespaces are still shown when they lead to included namespaces\n";
            } else {
                $content .= "Disabled - strict filtering applies to all calls\n";
            }
        }

        return $content;
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
