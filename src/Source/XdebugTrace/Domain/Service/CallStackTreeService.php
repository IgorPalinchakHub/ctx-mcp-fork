<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;

/**
 * Service for building and manipulating call stack trees
 */
class CallStackTreeService
{
    /**
     * Generate markdown representation of a method call tree
     */
    public function generateMarkdownTree(MethodCall $rootCall, int $maxDepth = 10): string
    {
        list($className, $methodName) = [$rootCall->getClassName(), $rootCall->getMethodName()];

        // Create main header with entry point info
        $content = "# Call Stack for {$className}::{$methodName}\n\n";

        // Generate the detailed tree representation
        $content .= $this->renderMethodCallTree($rootCall, 0, [], $maxDepth);

        return $content;
    }

    /**
     * Render a method call tree as markdown with detailed information
     */
    private function renderMethodCallTree(MethodCall $methodCall, int $depth = 0, array $visited = [], int $maxDepth = 10): string
    {
        // Check for recursion or max depth
        $signature = $methodCall->getSignature();
        if ($depth > $maxDepth || in_array($signature, $visited, true)) {
            return str_repeat("  ", $depth) . "└─ [Recursion/max depth reached]\n";
        }

        $visited[] = $signature;

        // Format the node display
        $indent = str_repeat("  ", $depth);
        $prefix = $depth === 0 ? "" : $indent . "├─ ";

        // Get method information
        $className = $methodCall->getClassName();
        $methodName = $methodCall->getMethodName();
        $isStatic = $methodCall->isStatic();
        $returnType = $methodCall->getReturnType();
        $context = $methodCall->getCallContext();

        // Format method name differently based on static/instance
        $methodDisplay = $isStatic
            ? "{$className}::{$methodName}"
            : ($className === 'self' ? "\$this->{$methodName}" : "{$className}->{$methodName}");

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
                    $sourceInfo = "";

                    // If it's a variable, show where it came from
                    if ($parameter->hasSourceVariable()) {
                        $sourceInfo = " (from \${$parameter->getSourceVariable()})";
                    }

                    $content .= $indent . "  │  - \${$paramName} = {$paramValue}{$sourceInfo}\n";
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
            // Process all children except the last one
            $lastIndex = count($childCalls) - 1;

            for ($i = 0; $i < $lastIndex; $i++) {
                $content .= $this->renderMethodCallTree($childCalls[$i], $depth + 1, $visited, $maxDepth);
            }

            // Process the last child with special handling
            $lastChildContent = $this->renderMethodCallTree($childCalls[$lastIndex], $depth + 1, $visited, $maxDepth);

            // Replace the initial prefix '├─' with '└─' for the last child
            $replaceFrom = $indent . "  " . "├─";
            $replaceTo = $indent . "  " . "└─";
            $lastChildContent = preg_replace('/^' . preg_quote($replaceFrom, '/') . '/', $replaceTo, $lastChildContent, 1);

            $content .= $lastChildContent;
        }

        return $content;
    }

    /**
     * Generate plain text representation of a method call tree
     */
    public function generatePlainTree(MethodCall $rootCall, int $maxDepth = 10): string
    {
        list($className, $methodName) = [$rootCall->getClassName(), $rootCall->getMethodName()];

        $content = "Call Stack Tree for {$className}::{$methodName}\n\n";
        $content .= $this->renderPlainMethodCallTree($rootCall, 0, [], $maxDepth);

        return $content;
    }

    /**
     * Render a method call tree as plain text with simplified information
     */
    private function renderPlainMethodCallTree(MethodCall $methodCall, int $depth = 0, array $visited = [], int $maxDepth = 10): string
    {
        // Check for recursion or max depth
        $signature = $methodCall->getSignature();
        if ($depth > $maxDepth || in_array($signature, $visited, true)) {
            return str_repeat("  ", $depth) . "└── [Recursion/max depth reached]\n";
        }

        $visited[] = $signature;

        // Get method information
        $className = $methodCall->getClassName();
        $methodName = $methodCall->getMethodName();
        $isStatic = $methodCall->isStatic();
        $returnType = $methodCall->getReturnType();
        $parameters = $methodCall->getParameters();

        // Format arguments for display
        $formattedArgs = '';
        if (!empty($parameters)) {
            $argValues = array_map(function($param) {
                return $param->getValueAsString();
            }, $parameters);
            $formattedArgs = '(' . implode(', ', $argValues) . ')';
        }

        // Format return type if available
        $formattedReturnType = $returnType ? ': ' . $returnType : '';

        // Format method name differently based on static/instance
        $displayNode = '';
        if (!$isStatic) {
            if (strpos($className, 'self') !== false) {
                $displayNode = '$this->' . $methodName . $formattedArgs . $formattedReturnType;
            } elseif (strpos($className, 'parent') !== false) {
                $displayNode = 'parent::' . $methodName . $formattedArgs . $formattedReturnType;
            } else {
                $displayNode = $className . '->' . $methodName . $formattedArgs . $formattedReturnType;
            }
        } else {
            $displayNode = $className . '::' . $methodName . $formattedArgs . $formattedReturnType;
        }

        // Format the current node
        $content = $depth === 0
            ? "$displayNode\n"
            : str_repeat("  ", $depth) . "└── $displayNode\n";

        // Sort and process child calls
        $methodCall->sortChildCalls();
        $childCalls = $methodCall->getChildCalls();

        foreach ($childCalls as $childCall) {
            $content .= $this->renderPlainMethodCallTree($childCall, $depth + 1, $visited, $maxDepth);
        }

        return $content;
    }

    /**
     * Add statistics section to a tree representation
     */
    public function addStatisticsSection(string $treeContent, MethodCall $rootCall, array $analyzedMethods, int $maxDepth): string
    {
        $methodCount = count($analyzedMethods);
        $callCount = $this->countCallRelationships($rootCall);

        $statContent = "\n## Statistics\n\n";
        $statContent .= "- Entry point: {$rootCall->getSignature()}\n";
        $statContent .= "- Methods analyzed: {$methodCount}\n";
        $statContent .= "- Call relationships: {$callCount}\n\n";

        $statContent .= "## Configuration\n\n";
        $statContent .= "- Max depth: {$maxDepth}\n";

        return $treeContent . $statContent;
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
}
