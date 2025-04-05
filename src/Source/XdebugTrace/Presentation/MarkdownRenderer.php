<?php
// Path: /src/Source/XdebugTrace/Presentation/MarkdownRenderer.php

namespace Butschster\ContextGenerator\Source\XdebugTrace\Presentation;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service\SkipRulesService;

/**
 * Markdown renderer for call stacks
 */
class MarkdownRenderer
{
    public function __construct(
        private SkipRulesService $skipRulesService,
    ) {
    }


    public function render(MethodCall $rootCall, array $analyzedMethods, int $maxDepth): string
    {
        $className = $rootCall->getClassName();
        $methodName = $rootCall->getMethodName();

        // Create main header
        $content = "# Call Stack for {$className}::{$methodName}\n\n";

        // Generate the detailed tree
        $content .= $this->renderMethodCallTree($rootCall, 0, [], $maxDepth);

        // Add statistics section
        $content .= $this->renderStatisticsSection($rootCall, $analyzedMethods, $maxDepth);

        return $content;
    }


    private function renderMethodCallTree(
        MethodCall $methodCall,
        int $depth = 0,
        array $visited = [],
        int $maxDepth = 10,
    ): string
    {
        // Check for recursion or max depth
        $signature = $methodCall->getSignature();
        if ($depth > $maxDepth || in_array($signature, $visited, true)) {
            return str_repeat("  ", $depth) . "└─ [Recursion/max depth reached]\n";
        }

        $visited[] = $signature;

        // Format the node display
        $indent = str_repeat("  ", $depth);
        $prefix = $depth === 0 ? "- " : $indent . "├─ ";

        // Get method information
        $className = $methodCall->getClassName();
        $methodName = $methodCall->getMethodName();
        $isStatic = $methodCall->isStatic();
        $returnType = $methodCall->getReturnType();
        $context = $methodCall->getCallContext();

        // Format method name differently based on static/instance
        $methodDisplay = $isStatic
            ? "{$className}::{$methodName}"
            : ($className === 'self' ? '$this->' . $methodName : "{$className}->{$methodName}");

        // Add file location if available
        $locationInfo = "";
        if ($context->hasFileInfo()) {
            $locationInfo = " " . $context->getFormattedFileLocation();
        }

        // Add static marker for static calls
        $staticMarker = $isStatic ? " [STATIC]" : "";

        // Add return type if available
        $returnTypeInfo = $returnType ? " : {$returnType}" : "";

        // Format main method line
        $content = "{$prefix}{$methodDisplay}{$returnTypeInfo}{$locationInfo}{$staticMarker}\n";

        // Add parameter and return info sections
        $parameters = $methodCall->getParameters();
        if (!empty($parameters) || $returnType) {
            $content .= $indent . "  │\n";

            // Add parameters section if we have parameter info
            if (!empty($parameters)) {
                $content .= $indent . "  │ Parameters:\n";
                foreach ($parameters as $parameter) {
                    $paramName = $parameter->getName();
                    $paramValue = $parameter->getValueAsString();
                    $paramType = $parameter->getType() ? ": " . $parameter->getType() : "";
                    $sourceInfo = "";

                    // If it's a variable, show where it came from
                    if ($parameter->hasSourceVariable()) {
                        $sourceInfo = " (from \${$parameter->getSourceVariable()})";
                    }

                    $content .= $indent . "  │  - \${$paramName}{$paramType} = {$paramValue}{$sourceInfo}\n";
                }
            }

            // Add return type info
            if ($returnType) {
                $content .= $indent . "  │ Return: {$returnType}\n";
            }

            $content .= $indent . "  │\n";
        }

        // Sort child calls for consistent output
        $methodCall->sortChildCalls();
        $childCalls = $methodCall->getChildCalls();

        if (!empty($childCalls)) {
            $lastIndex = count($childCalls) - 1;

            // Process all children except the last one
            for ($i = 0; $i < $lastIndex; $i++) {
                $content .= $this->renderMethodCallTree($childCalls[$i], $depth + 1, $visited, $maxDepth);
            }

            // Process the last child with special formatting
            $lastChildContent = $this->renderMethodCallTree($childCalls[$lastIndex], $depth + 1, $visited, $maxDepth);

            // Replace the first occurrence of ├─ with └─ for the last child
            $lastChildContent = preg_replace(
                '/^' . preg_quote($indent . "  " . "├─", '/') . '/',
                $indent . "  " . "└─",
                $lastChildContent,
                1
            );

            $content .= $lastChildContent;
        }

        return $content;
    }


    private function renderStatisticsSection(MethodCall $rootCall, array $analyzedMethods, int $maxDepth): string
    {
        $methodCount = count($analyzedMethods);
        $callCount = $this->countCallRelationships($rootCall);

        $content = "\n## Statistics\n\n";
        $content .= "- Entry point: {$rootCall->getSignature()}\n";
        $content .= "- Methods analyzed: {$methodCount}\n";
        $content .= "- Call relationships: {$callCount}\n\n";

        $content .= "## Configuration\n\n";
        $content .= "- Skip singleton methods: " . ($this->skipRulesService->isSkipSingletonMethods() ? 'Yes' : 'No') . "\n";
        $content .= "- Skip constructors: " . ($this->skipRulesService->isSkipConstructors() ? 'Yes' : 'No') . "\n";
        $content .= "- Skip __invoke methods: " . ($this->skipRulesService->isSkipInvokeMethods() ? 'Yes' : 'No') . "\n";
        $content .= "- Max depth: {$maxDepth}\n";

        // List skipped directories
        $skipDirPatterns = $this->skipRulesService->getSkipDirPatterns();
        if (!empty($skipDirPatterns)) {
            $content .= "- Skipped directories: " . implode(', ', $skipDirPatterns) . "\n";
        }

        // List skipped classes
        $skipClassPatterns = $this->skipRulesService->getSkipClassPatterns();
        if (!empty($skipClassPatterns)) {
            $content .= "- Skipped classes: " . implode(', ', array_map(function($pattern) {
                    return str_replace(['/^', '\\\\/', '$/'], '', $pattern);
                }, $skipClassPatterns)) . "\n";
        }

        // List skipped methods
        $skipMethodPatterns = $this->skipRulesService->getSkipMethodPatterns();
        if (!empty($skipMethodPatterns)) {
            $content .= "- Skipped methods: " . implode(', ', array_map(function($pattern) {
                    return str_replace(['/^', '$/'], '', $pattern);
                }, $skipMethodPatterns)) . "\n";
        }

        return $content;
    }


    private function countCallRelationships(MethodCall $methodCall): int
    {
        $childCount = count($methodCall->getChildCalls());

        foreach ($methodCall->getChildCalls() as $childCall) {
            $childCount += $this->countCallRelationships($childCall);
        }

        return $childCount;
    }
}
