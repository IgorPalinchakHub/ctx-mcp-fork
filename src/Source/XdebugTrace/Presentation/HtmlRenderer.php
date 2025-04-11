<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Presentation;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\SkipRulesService;

/**
 * HTML renderer for interactive call stack visualization
 */
class HtmlRenderer
{
    public function __construct(
        private SkipRulesService $skipRulesService,
    ) {}

    /**
     * Render a method call hierarchy as an interactive HTML document
     *
     * @param MethodCall $rootCall Root method call
     * @param array<string, bool> $analyzedMethods Map of method signatures that were analyzed
     * @param int $maxDepth Maximum depth to render
     * @return string HTML document
     */
    public function render(MethodCall $rootCall, array $analyzedMethods, int $maxDepth): string
    {
        $className = $rootCall->getClassName();
        $methodName = $rootCall->getMethodName();

        // Create HTML document structure
        $html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
        $html .= "<meta charset=\"UTF-8\">\n";
        $html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
        $html .= "<title>Call Stack for {$className}::{$methodName}</title>\n";
        $html .= $this->getStylesheet();
        $html .= $this->getJavaScript();
        $html .= "</head>\n<body>\n";

        // Main content container
        $html .= "<div class=\"container\">\n";

        // Header
        $html .= "<header>\n";
        $html .= "<h1>Call Stack for {$className}::{$methodName}</h1>\n";
        $html .= "<div class=\"controls\">\n";
        $html .= "<button id=\"expand-all\">Expand All</button>\n";
        $html .= "<button id=\"collapse-all\">Collapse All</button>\n";
        $html .= "<div class=\"search-box\">\n";
        $html .= "<input type=\"text\" id=\"search\" placeholder=\"Search method calls...\">\n";
        $html .= "</div>\n";
        $html .= "</div>\n";
        $html .= "</header>\n";

        // Call tree
        $html .= "<div class=\"call-tree\">\n";
        $html .= $this->renderMethodCallTree($rootCall, 0, [], $maxDepth);
        $html .= "</div>\n";

        // Statistics section
        $html .= "<div class=\"statistics\">\n";
        $html .= "<h2>Statistics</h2>\n";
        $html .= "<ul>\n";
        $html .= "<li><strong>Entry point:</strong> {$rootCall->getSignature()}</li>\n";
        $html .= "<li><strong>Methods analyzed:</strong> " . count($analyzedMethods) . "</li>\n";
        $html .= "<li><strong>Call relationships:</strong> " . $this->countCallRelationships($rootCall) . "</li>\n";
        $html .= "</ul>\n";

        $html .= "<h2>Configuration</h2>\n";
        $html .= "<ul>\n";
        $html .= "<li><strong>Skip singleton methods:</strong> " . ($this->skipRulesService->isSkipSingletonMethods() ? 'Yes' : 'No') . "</li>\n";
        $html .= "<li><strong>Skip constructors:</strong> " . ($this->skipRulesService->isSkipConstructors() ? 'Yes' : 'No') . "</li>\n";
        $html .= "<li><strong>Skip __invoke methods:</strong> " . ($this->skipRulesService->isSkipInvokeMethods() ? 'Yes' : 'No') . "</li>\n";
        $html .= "<li><strong>Max depth:</strong> {$maxDepth}</li>\n";

        // Add skipped directories
        $skipDirPatterns = $this->skipRulesService->getSkipDirPatterns();
        if (!empty($skipDirPatterns)) {
            $html .= "<li><strong>Skipped directories:</strong> " . htmlspecialchars(implode(', ', $skipDirPatterns)) . "</li>\n";
        }

        // Add skipped classes
        $skipClassPatterns = $this->skipRulesService->getSkipClassPatterns();
        if (!empty($skipClassPatterns)) {
            $html .= "<li><strong>Skipped classes:</strong> " . htmlspecialchars(implode(', ', array_map(function($pattern) {
                    return str_replace(['/^', '\\\\/', '$/'], '', $pattern);
                }, $skipClassPatterns))) . "</li>\n";
        }

        // Add skipped methods
        $skipMethodPatterns = $this->skipRulesService->getSkipMethodPatterns();
        if (!empty($skipMethodPatterns)) {
            $html .= "<li><strong>Skipped methods:</strong> " . htmlspecialchars(implode(', ', array_map(function($pattern) {
                    return str_replace(['/^', '$/'], '', $pattern);
                }, $skipMethodPatterns))) . "</li>\n";
        }

        $html .= "</ul>\n";
        $html .= "</div>\n";

        // End container
        $html .= "</div>\n";

        $html .= "</body>\n</html>";
        return $html;
    }

    /**
     * Render a method call tree as collapsible HTML elements
     *
     * @param MethodCall $methodCall The method call to render
     * @param int $depth Current depth in the call tree
     * @param array<string> $visited Signatures of already visited methods
     * @param int $maxDepth Maximum depth to render
     * @return string HTML representation of the method call tree
     */
    private function renderMethodCallTree(
        MethodCall $methodCall,
        int $depth = 0,
        array $visited = [],
        int $maxDepth = 10
    ): string {
        // Check for recursion or max depth
        $signature = $methodCall->getSignature();
        if ($depth > $maxDepth || in_array($signature, $visited, true)) {
            return "<div class=\"method-call recursion\">\n"
                . "<div class=\"method-name\">[Recursion/max depth reached]</div>\n"
                . "</div>\n";
        }

        $visited[] = $signature;

        // Get method information
        $className = htmlspecialchars($methodCall->getClassName());
        $methodName = htmlspecialchars($methodCall->getMethodName());
        $isStatic = $methodCall->isStatic();
        $returnType = $methodCall->getReturnType();
        $context = $methodCall->getCallContext();
        $parameters = $methodCall->getParameters();

        // Generate unique ID for this method node
        $nodeId = 'node-' . md5($signature . $depth . microtime());

        // CSS classes for the method call
        $classes = ['method-call'];
        if ($isStatic) {
            $classes[] = 'static';
        }
        if ($depth === 0) {
            $classes[] = 'root';
        }
        if (!$methodCall->hasChildCalls()) {
            $classes[] = 'leaf';
        }

        // Format method name differently based on static/instance
        $methodDisplay = $isStatic
            ? "<span class=\"class-name\">{$className}</span>::<span class=\"method-name\">{$methodName}</span>"
            : ($className === 'self'
                ? "<span class=\"this-ref\">\$this</span>-><span class=\"method-name\">{$methodName}</span>"
                : "<span class=\"class-name\">{$className}</span>-><span class=\"method-name\">{$methodName}</span>");

        // Add file location if available
        $locationInfo = "";
        if ($context->hasFileInfo()) {
            $filePath = htmlspecialchars($context->getFilePath());
            $line = $context->getLine();
            $locationInfo = " <span class=\"location\" title=\"{$filePath}:{$line}\">[{$filePath}:{$line}]</span>";
        }

        // Add return type if available
        $returnTypeInfo = "";
        if ($returnType) {
            $returnTypeInfo = " <span class=\"return-type\">: " . htmlspecialchars($returnType) . "</span>";
        }

        // Open method call container
        $html = "<div class=\"" . implode(' ', $classes) . "\" id=\"{$nodeId}\" data-signature=\"{$signature}\">\n";

        // Method header with toggle for children
        $html .= "<div class=\"method-header\">\n";

        // Add toggle button if the method has children
        if ($methodCall->hasChildCalls()) {
            $html .= "<div class=\"toggle\" onclick=\"toggleNode('{$nodeId}')\"></div>\n";
        } else {
            $html .= "<div class=\"toggle empty\"></div>\n";
        }

        // Method signature
        $html .= "<div class=\"signature\">{$methodDisplay}{$returnTypeInfo}{$locationInfo}</div>\n";

        $html .= "</div>\n"; // End method-header

        // Method details section
        $html .= "<div class=\"method-details\">\n";

        // Add parameter and return info sections
        if (!empty($parameters) || $returnType) {
            // Add parameters section if we have parameter info
            if (!empty($parameters)) {
                $html .= "<div class=\"parameters\">\n";
                $html .= "<h4>Parameters:</h4>\n";
                $html .= "<ul>\n";

                foreach ($parameters as $parameter) {
                    $paramName = htmlspecialchars($parameter->getName());
                    $paramValue = htmlspecialchars($parameter->getValueAsString());
                    $paramType = $parameter->getType() ? ": " . htmlspecialchars($parameter->getType()) : "";
                    $sourceInfo = "";

                    // If it's a variable, show where it came from
                    if ($parameter->hasSourceVariable()) {
                        $sourceInfo = " <span class=\"source-var\">(from \${$parameter->getSourceVariable()})</span>";
                    }

                    $html .= "<li><span class=\"param-name\">\${$paramName}</span><span class=\"param-type\">{$paramType}</span> = <span class=\"param-value\">{$paramValue}</span>{$sourceInfo}</li>\n";
                }

                $html .= "</ul>\n";
                $html .= "</div>\n"; // End parameters
            }

            // Add return type info
            if ($returnType) {
                $html .= "<div class=\"return-info\">\n";
                $html .= "<h4>Return:</h4>\n";
                $html .= "<span class=\"return-type-full\">" . htmlspecialchars($returnType) . "</span>\n";
                $html .= "</div>\n"; // End return-info
            }
        }

        $html .= "</div>\n"; // End method-details

        // Child method calls
        if ($methodCall->hasChildCalls()) {
            // Sort child calls for consistent output
            $methodCall->sortChildCalls();
            $childCalls = $methodCall->getChildCalls();

            $html .= "<div class=\"children\">\n";

            foreach ($childCalls as $childCall) {
                $html .= $this->renderMethodCallTree($childCall, $depth + 1, $visited, $maxDepth);
            }

            $html .= "</div>\n"; // End children
        }

        $html .= "</div>\n"; // End method-call

        return $html;
    }

    /**
     * Count total call relationships in a method call tree
     */
    private function countCallRelationships(MethodCall $methodCall): int
    {
        $childCount = count($methodCall->getChildCalls());

        foreach ($methodCall->getChildCalls() as $childCall) {
            $childCount += $this->countCallRelationships($childCall);
        }

        return $childCount;
    }

    /**
     * Get CSS styles for the HTML document
     */
    private function getStylesheet(): string
    {
        return <<<CSS
        <style>
            :root {
                --bg-color: #ffffff;
                --text-color: #333333;
                --header-bg: #f5f5f5;
                --border-color: #dddddd;
                --highlight-color: #4a86e8;
                --method-bg: #f9f9f9;
                --method-hover: #f0f0f0;
                --static-color: #9c27b0;
                --return-color: #009688;
                --param-name-color: #3367d6;
                --param-type-color: #7b1fa2;
                --param-value-color: #0d904f;
                --location-color: #666666;
            }

            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
                font-size: 14px;
                line-height: 1.6;
                color: var(--text-color);
                background-color: var(--bg-color);
                padding: 0;
                margin: 0;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }

            header {
                background-color: var(--header-bg);
                padding: 20px;
                border-radius: 5px;
                margin-bottom: 20px;
                border: 1px solid var(--border-color);
            }

            header h1 {
                margin: 0 0 15px 0;
                font-size: 24px;
            }

            .controls {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            button {
                padding: 8px 12px;
                background-color: var(--highlight-color);
                color: white;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            }

            button:hover {
                opacity: 0.9;
            }

            .search-box {
                flex-grow: 1;
                max-width: 300px;
            }

            #search {
                width: 100%;
                padding: 8px;
                border: 1px solid var(--border-color);
                border-radius: 4px;
            }

            .call-tree {
                border: 1px solid var(--border-color);
                border-radius: 5px;
                padding: 10px;
                margin-bottom: 20px;
                overflow-x: auto;
            }

            .method-call {
                margin: 5px 0;
                border-left: 2px solid var(--border-color);
                padding-left: 15px;
                position: relative;
            }

            .method-call.root {
                border-left: none;
                padding-left: 0;
            }

            .method-header {
                display: flex;
                align-items: center;
                padding: 8px;
                border-radius: 4px;
                background-color: var(--method-bg);
                cursor: pointer;
            }

            .method-header:hover {
                background-color: var(--method-hover);
            }

            .toggle {
                width: 20px;
                height: 20px;
                position: relative;
                margin-right: 8px;
                cursor: pointer;
            }

            .toggle:before, .toggle:after {
                content: '';
                position: absolute;
                background-color: var(--highlight-color);
                transition: transform 0.3s ease;
            }

            .toggle:before {
                width: 10px;
                height: 2px;
                top: 9px;
                left: 5px;
            }

            .toggle:after {
                width: 2px;
                height: 10px;
                top: 5px;
                left: 9px;
            }

            .toggle.empty:before, .toggle.empty:after {
                display: none;
            }

            .method-call.collapsed .toggle:after {
                transform: rotate(90deg);
            }

            .method-call.collapsed .children,
            .method-call.collapsed .method-details {
                display: none;
            }

            .signature {
                flex-grow: 1;
            }

            .class-name {
                font-weight: bold;
            }

            .this-ref {
                font-weight: bold;
                font-style: italic;
            }

            .method-name {
                font-weight: bold;
                color: var(--highlight-color);
            }

            .return-type {
                color: var(--return-color);
                font-style: italic;
            }

            .location {
                color: var(--location-color);
                font-size: 0.9em;
                margin-left: 8px;
            }

            .method-call.static .method-name {
                color: var(--static-color);
            }

            .method-details {
                padding: 10px 10px 10px 28px;
                border-bottom: 1px solid var(--border-color);
            }

            .parameters h4, .return-info h4 {
                margin: 5px 0;
                font-size: 14px;
            }

            .parameters ul {
                list-style-type: none;
                margin-left: 15px;
            }

            .parameters li {
                margin: 3px 0;
            }

            .param-name {
                color: var(--param-name-color);
                font-weight: bold;
            }

            .param-type {
                color: var(--param-type-color);
            }

            .param-value {
                color: var(--param-value-color);
            }

            .source-var {
                color: var(--location-color);
                font-style: italic;
            }

            .children {
                padding-left: 20px;
            }

            .recursion {
                opacity: 0.7;
                font-style: italic;
            }

            .statistics, .configuration {
                border: 1px solid var(--border-color);
                border-radius: 5px;
                padding: 15px;
                margin-bottom: 20px;
            }

            .statistics h2, .configuration h2 {
                margin: 0 0 10px 0;
                font-size: 18px;
            }

            .statistics ul, .configuration ul {
                list-style-type: none;
                margin-left: 15px;
            }

            .statistics li, .configuration li {
                margin: 5px 0;
            }

            /* Highlighting for search results */
            .highlight {
                background-color: yellow;
            }

            /* Responsive adjustments */
            @media (max-width: 768px) {
                .container {
                    padding: 10px;
                }

                header {
                    padding: 15px;
                }

                .controls {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .search-box {
                    max-width: 100%;
                    width: 100%;
                }
            }
        </style>
CSS;
    }

    /**
     * Get JavaScript for interactive features
     */
    private function getJavaScript(): string
    {
        return <<<JS
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Initial collapse of all nodes except root
                const rootNode = document.querySelector('.method-call.root');
                if (rootNode) {
                    Array.from(rootNode.querySelectorAll('.method-call'))
                        .forEach(node => {
                            if (!node.classList.contains('root')) {
                                node.classList.add('collapsed');
                            }
                        });
                }

                // Set up expand/collapse all buttons
                document.getElementById('expand-all').addEventListener('click', expandAll);
                document.getElementById('collapse-all').addEventListener('click', collapseAll);

                // Set up search functionality
                document.getElementById('search').addEventListener('input', performSearch);

                // Add click handlers to method headers
                document.querySelectorAll('.method-header').forEach(header => {
                    header.addEventListener('click', function(e) {
                        // Ignore clicks on the toggle button as it has its own handler
                        if (!e.target.classList.contains('toggle')) {
                            const methodCall = this.closest('.method-call');
                            toggleNode(methodCall.id);
                        }
                    });
                });
            });

            function toggleNode(nodeId) {
                const node = document.getElementById(nodeId);
                if (node) {
                    node.classList.toggle('collapsed');
                }
            }

            function expandAll() {
                document.querySelectorAll('.method-call').forEach(node => {
                    node.classList.remove('collapsed');
                });
            }

            function collapseAll() {
                document.querySelectorAll('.method-call').forEach(node => {
                    if (!node.classList.contains('root')) {
                        node.classList.add('collapsed');
                    }
                });
            }

            function performSearch() {
                const searchTerm = document.getElementById('search').value.toLowerCase();

                // Remove existing highlights
                document.querySelectorAll('.highlight').forEach(el => {
                    el.classList.remove('highlight');
                });

                if (searchTerm.length < 2) return; // Only search for terms of 2+ chars

                let foundAny = false;

                // Search and highlight matches
                document.querySelectorAll('.method-call').forEach(node => {
                    const signature = node.getAttribute('data-signature').toLowerCase();

                    // Check if the search term is in the signature
                    if (signature.includes(searchTerm)) {
                        // Mark the node
                        node.classList.add('highlight');

                        // Expand the node and all its parents
                        expandNodeAndParents(node);

                        foundAny = true;
                    }
                });

                if (foundAny) {
                    // Scroll to the first match
                    const firstMatch = document.querySelector('.highlight');
                    if (firstMatch) {
                        firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            }

            function expandNodeAndParents(node) {
                // Remove collapsed state from this node
                node.classList.remove('collapsed');

                // Find parent node and expand it too
                const parent = node.closest('.children')?.closest('.method-call');
                if (parent) {
                    expandNodeAndParents(parent);
                }
            }
        </script>
JS;
    }
}
