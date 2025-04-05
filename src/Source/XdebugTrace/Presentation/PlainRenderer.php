<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Presentation;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;

/**
 * Plain text renderer for call stacks
 */
class PlainRenderer
{
    /**
     * Render a method call hierarchy as a plain text document
     */
    public function render(MethodCall $rootCall, array $analyzedMethods, int $maxDepth): string
    {
        $className = $rootCall->getClassName();
        $methodName = $rootCall->getMethodName();

        // Create header
        $content = "Call Stack Tree for {$className}::{$methodName}\n\n";

        // Generate the simplified tree
        $content .= $this->renderMethodCallTree($rootCall, 0, [], $maxDepth);

        // Add basic statistics
        $content .= "\nMethods analyzed: " . count($analyzedMethods) . "\n";
        $content .= "Max depth: {$maxDepth}\n";

        return $content;
    }

    /**
     * Render a method call tree with simplified format
     */
    private function renderMethodCallTree(MethodCall $methodCall, int $depth = 0, array $visited = [], int $maxDepth = 10): string
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

        // Format node display
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
            $content .= $this->renderMethodCallTree($childCall, $depth + 1, $visited, $maxDepth);
        }

        return $content;
    }
}
