<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Writer;

final class MethodListMarkdownWriter
{
    /**
     * Write extracted methods to a Markdown file
     *
     * @param array<string, array<string>> $extractedMethods Classes and their methods
     * @param string $title Document title
     * @param string $description Document description
     * @param bool $groupByNamespace Whether to group classes by namespace
     * @return string Generated markdown content
     */
    public function write(
        array $extractedMethods,
        string $title = 'XHProf Method List',
        string $description = '',
        bool $groupByNamespace = true
    ): string {
        $content = "# {$title}\n\n";

        if (!empty($description)) {
            $content .= "{$description}\n\n";
        }

        if (empty($extractedMethods)) {
            $content .= "No methods were extracted from the call tree.\n";
            return $content;
        }

        // Add summary info
        $classCount = count($extractedMethods);
        $methodCount = 0;

        foreach ($extractedMethods as $methods) {
            $methodCount += count($methods);
        }

        $content .= "## Summary\n\n";
        $content .= "- Total classes/namespaces: {$classCount}\n";
        $content .= "- Total methods: {$methodCount}\n\n";

        if ($groupByNamespace) {
            $content .= $this->renderGroupedByNamespace($extractedMethods);
        } else {
            $content .= $this->renderAlphabetically($extractedMethods);
        }

        return $content;
    }

    /**
     * Render methods grouped by namespace
     */
    private function renderGroupedByNamespace(array $extractedMethods): string
    {
        $content = "## Methods by Namespace\n\n";

        // Sort classes
        ksort($extractedMethods);

        // Group by namespace
        $namespaces = [];

        foreach ($extractedMethods as $class => $methods) {
            // Handle special case for global functions
            if ($class === 'Global Functions') {
                $namespaces['Global']['Functions'] = $methods;
                continue;
            }

            // Split by namespace
            $parts = explode('\\', $class);
            $className = array_pop($parts);
            $namespace = implode('\\', $parts);

            if (empty($namespace)) {
                $namespace = 'Global';
            }

            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = [];
            }

            $namespaces[$namespace][$className] = $methods;
        }

        // Sort namespaces
        ksort($namespaces);

        // Render each namespace
        foreach ($namespaces as $namespace => $classes) {
            $content .= "### {$namespace}\n\n";

            // Sort classes within namespace
            ksort($classes);

            foreach ($classes as $className => $methods) {
                // Special case for 'Functions' in Global namespace
                if ($namespace === 'Global' && $className === 'Functions') {
                    $content .= "* **Global Functions**\n";
                } else {
                    $content .= "* **{$className}**\n";
                }

                // Sort methods
                sort($methods);

                foreach ($methods as $method) {
                    $content .= "  * `{$method}`\n";
                }

                $content .= "\n";
            }
        }

        return $content;
    }

    /**
     * Render methods alphabetically by full class name
     */
    private function renderAlphabetically(array $extractedMethods): string
    {
        $content = "## Methods by Class\n\n";

        // Sort classes
        ksort($extractedMethods);

        foreach ($extractedMethods as $class => $methods) {
            $content .= "### {$class}\n\n";

            // Sort methods
            sort($methods);

            foreach ($methods as $method) {
                $content .= "* `{$method}`\n";
            }

            $content .= "\n";
        }

        return $content;
    }
}
