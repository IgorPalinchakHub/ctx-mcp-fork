<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Filter;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\MethodSignature;

final class MethodExtractionFilter
{
    /**
     * @param array<string> $excludePaths Path patterns to exclude (e.g., "vendor/")
     * @param bool $excludePhpInternals Whether to exclude PHP internal functions
     */
    public function __construct(
        private readonly array $excludePaths = [],
        private readonly bool $excludePhpInternals = true,
    ) {
    }

    /**
     * Determine if a method signature should be included in extraction
     */
    public function shouldInclude(MethodSignature $signature): bool
    {
        $class = $signature->getCalleeClass();
        $method = $signature->getCalleeMethod();

        // If both class and method are empty, or method is empty but class looks like a function name,
        // it's likely a global function and we'll include it unless it's a PHP internal
        if (empty($class) || (empty($method) && !$this->looksLikeClassName($class))) {
            // Skip PHP internal functions if configured
            if ($this->excludePhpInternals && $this->isPhpInternal($signature)) {
                return false;
            }

            return true;
        }

        // Check against excluded paths
        foreach ($this->excludePaths as $excludePath) {
            $normalizedClass = str_replace('\\', '/', $class);
            $normalizedExclude = str_replace('\\', '/', $excludePath);

            if (str_starts_with($normalizedClass, $normalizedExclude)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a signature looks like a class name (contains namespace separators)
     */
    private function looksLikeClassName(string $name): bool
    {
        return str_contains($name, '\\') || str_contains($name, '/');
    }

    /**
     * Check if a signature represents a PHP internal function
     */
    private function isPhpInternal(MethodSignature $signature): bool
    {
        $class = $signature->getCalleeClass();
        $method = $signature->getCalleeMethod();

        // Handle global functions
        if (empty($class) && !empty($method)) {
            // Check if it's a common PHP function
            static $commonPhpFunctions = [
                'count' => true, 'array_map' => true, 'str_replace' => true,
                'substr' => true, 'strpos' => true, 'json_encode' => true,
                // This is a subset - the full list would be much longer
            ];

            if (isset($commonPhpFunctions[$method])) {
                return true;
            }

            // Use reflection for more accurate detection (more expensive)
            try {
                return function_exists($method) && (new \ReflectionFunction($method))->isInternal();
            } catch (\Exception $e) {
                return false;
            }
        }

        // Check if it's a built-in PHP class
        if (!empty($class) && class_exists($class)) {
            try {
                return (new \ReflectionClass($class))->isInternal();
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }
}
