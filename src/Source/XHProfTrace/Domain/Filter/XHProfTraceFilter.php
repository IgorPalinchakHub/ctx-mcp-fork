<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Filter;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallNode;
use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\MethodSignature;

final class XHProfTraceFilter
{
    private array $includeNamespaces = [];
    private array $excludeNamespaces = [];
    private array $includeFunctions = [];
    private array $excludeFunctions = [];
    private bool $functionsAsRegex = false;
    private int $minExecutionTime = 0;
    private bool $requireAllFilters = false;
    private bool $excludePhpInternals = false;
    private bool $preserveImportantPaths = true;
    private int $maxConsecutiveExcludedCalls = 3;

    /**
     * Create a filter from an options array
     */
    public static function fromOptions(array $options): self
    {
        $filter = new self();

        // Parse filter options if they exist
        if (isset($options['filters'])) {
            $filterOptions = $options['filters'];

            if (isset($filterOptions['includeNamespaces'])) {
                $filter->includeNamespaces = (array) $filterOptions['includeNamespaces'];
            }

            if (isset($filterOptions['excludeNamespaces'])) {
                $filter->excludeNamespaces = (array) $filterOptions['excludeNamespaces'];
            }

            if (isset($filterOptions['includeFunctions'])) {
                $filter->includeFunctions = (array) $filterOptions['includeFunctions'];
            }

            if (isset($filterOptions['excludeFunctions'])) {
                $filter->excludeFunctions = (array) $filterOptions['excludeFunctions'];
            }

            if (isset($filterOptions['functionsAsRegex'])) {
                $filter->functionsAsRegex = (bool) $filterOptions['functionsAsRegex'];
            }

            if (isset($filterOptions['minExecutionTime'])) {
                $filter->minExecutionTime = (int) $filterOptions['minExecutionTime'];
            }

            if (isset($filterOptions['requireAllFilters'])) {
                $filter->requireAllFilters = (bool) $filterOptions['requireAllFilters'];
            }

            if (isset($filterOptions['excludePhpInternals'])) {
                $filter->excludePhpInternals = (bool) $filterOptions['excludePhpInternals'];
            }

            if (isset($filterOptions['preserveImportantPaths'])) {
                $filter->preserveImportantPaths = (bool) $filterOptions['preserveImportantPaths'];
            }

            if (isset($filterOptions['maxConsecutiveExcludedCalls'])) {
                $filter->maxConsecutiveExcludedCalls = (int) $filterOptions['maxConsecutiveExcludedCalls'];
            }
        }

        return $filter;
    }

    /**
     * Check if a node matches the filter criteria
     */
    public function matches(CallNode $node): bool
    {
        // If no filters specified, include everything
        if ($this->isEmpty()) {
            return true;
        }

        $signature = $node->getSignature();
        $metrics = $node->getMetrics();

        // First check for PHP internal functions if they should be excluded
        if ($this->excludePhpInternals && $this->isPhpInternal($signature)) {
            return false;
        }

        // Check if node is in an included namespace - these are always shown
        if (!empty($this->includeNamespaces)) {
            if ($this->isInIncludedNamespace($signature)) {
                return true;
            }
        }

        // Check for excluded namespaces
        $isInExcludedNamespace = false;
        if (!empty($this->excludeNamespaces)) {
            foreach ($this->excludeNamespaces as $namespace) {
                if ($this->signatureMatchesNamespace($signature, $namespace)) {
                    $isInExcludedNamespace = true;
                    break;
                }
            }
        }

        // If it's in an excluded namespace, depth limiting will be handled by the CallGraph
        // We still return true here to allow path preservation to work
        if ($isInExcludedNamespace) {
            return false; // Initial exclusion, depth limiting handled separately
        }

        $matchResults = [];

        // Check function filters
        if (!empty($this->includeFunctions)) {
            $functionMatches = $this->matchesFunction($signature, $this->includeFunctions);
            $matchResults['includeFunctions'] = $functionMatches;
        }

        if (!empty($this->excludeFunctions)) {
            // If function matches an excluded pattern, exclude it regardless of other criteria
            if ($this->matchesFunction($signature, $this->excludeFunctions)) {
                return false;
            }
        }

        // Check execution time
        if ($this->minExecutionTime > 0) {
            $timeMatches = $metrics->getWallTime() >= $this->minExecutionTime;
            $matchResults['minExecutionTime'] = $timeMatches;
        }

        // Determine the final match result
        if ($this->requireAllFilters) {
            // All filters must match (if specified)
            return empty($matchResults) || !in_array(false, $matchResults, true);
        } else {
            // At least one filter must match (if specified)
            return empty($matchResults) || in_array(true, $matchResults, true);
        }
    }

    /**
     * Check if the node is in an included namespace
     */
    public function isInIncludedNamespace(MethodSignature $signature): bool
    {
        if (empty($this->includeNamespaces)) {
            return false; // No included namespaces specified
        }

        foreach ($this->includeNamespaces as $namespace) {
            if ($this->signatureMatchesNamespace($signature, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the node is in an excluded namespace
     */
    public function isInExcludedNamespace(MethodSignature $signature): bool
    {
        if (empty($this->excludeNamespaces)) {
            return false; // No excluded namespaces specified
        }

        foreach ($this->excludeNamespaces as $namespace) {
            if ($this->signatureMatchesNamespace($signature, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any filters are specified
     */
    public function isEmpty(): bool
    {
        return empty($this->includeNamespaces)
            && empty($this->excludeNamespaces)
            && empty($this->includeFunctions)
            && empty($this->excludeFunctions)
            && $this->minExecutionTime <= 0
            && !$this->excludePhpInternals;
    }

    /**
     * Check if a signature represents a PHP internal function
     */
    private function isPhpInternal(MethodSignature $signature): bool
    {
        $class = $signature->getCalleeClass();
        $method = $signature->getCalleeMethod();

        // If there's no class, it could be a PHP internal function
        if (empty($class) && !empty($method)) {
            // Skip checks for very common PHP functions to improve performance
            static $commonPhpFunctions = [
                'count' => true,
                'array_map' => true,
                'str_replace' => true,
                'substr' => true,
                'strpos' => true,
                'json_encode' => true,
                'json_decode' => true,
                'implode' => true,
                'explode' => true,
                'array_filter' => true,
                'microtime' => true,
                'print_r' => true,
                'var_dump' => true,
                'in_array' => true,
                'array_key_exists' => true,
                'is_array' => true,
                'is_string' => true,
                'is_int' => true,
                'is_object' => true,
                'array_values' => true,
                'array_keys' => true,
                'preg_match' => true,
                'preg_replace' => true,
                'preg_split' => true,
                'trim' => true,
                'rtrim' => true,
                'ltrim' => true,
                'strlen' => true,
                'strtolower' => true,
                'strtoupper' => true,
                'ucfirst' => true,
                'method_exists' => true,
                'property_exists' => true,
                'class_exists' => true,
                'interface_exists' => true,
                'defined' => true,
                'define' => true,
                'date' => true,
                'time' => true,
                'mktime' => true,
                'error_reporting' => true,
                'ini_set' => true,
                'file_exists' => true,
                'file_get_contents' => true,
                'file_put_contents' => true,
                'dirname' => true,
                'basename' => true,
                'realpath' => true,
                'getcwd' => true,
                'serialize' => true,
                'unserialize' => true,
                'md5' => true,
                'sha1' => true,
                'intval' => true,
                'floatval' => true,
                'strval' => true,
                'boolval' => true,
                'call_user_func' => true,
                'call_user_func_array' => true,
                'usort' => true,
                'sort' => true,
                'asort' => true,
                'ksort' => true,
                'natsort' => true,
                'rsort' => true,
                'shuffle' => true,
                'array_push' => true,
                'array_pop' => true,
                'array_shift' => true,
                'array_unshift' => true,
                'array_slice' => true,
                'array_splice' => true,
                'array_merge' => true,
                'array_combine' => true,
                'array_reverse' => true,
                'array_unique' => true,
                'array_search' => true,
                'array_sum' => true,
                'array_diff' => true,
                'array_intersect' => true,
                'isset' => true,
                'empty' => true,
                'unset' => true,
                'is_null' => true,
                'is_bool' => true,
                'is_float' => true,
                'is_numeric' => true,
                'is_callable' => true,
                'is_dir' => true,
                'is_file' => true,
                'is_readable' => true,
                'is_writable' => true,
                'is_executable' => true,
                'abs' => true,
                'round' => true,
                'ceil' => true,
                'floor' => true,
                'min' => true,
                'max' => true,
                'rand' => true,
                'mt_rand' => true
            ];

            if (isset($commonPhpFunctions[$method])) {
                return true;
            }

            // For less common functions, use reflection (more expensive)
            try {
                return function_exists($method) && (new \ReflectionFunction($method))->isInternal();
            } catch (\Exception $e) {
                return false;
            }
        }

        // Check if it's a built-in PHP class
        if (!empty($class) && class_exists($class)) {
            try {
                $reflectionClass = new \ReflectionClass($class);
                return $reflectionClass->isInternal();
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Check if the signature matches a namespace
     */
    private function signatureMatchesNamespace(MethodSignature $signature, string $namespace): bool
    {
        $calleeClass = $signature->getCalleeClass();

        // If class is empty, it can't match a namespace
        if (empty($calleeClass)) {
            return false;
        }

        // Exact match
        if ($calleeClass === $namespace) {
            return true;
        }

        // Namespace prefix match (handle both with and without trailing slash)
        $namespace = rtrim($namespace, '\\') . '\\';
        return strpos($calleeClass, $namespace) === 0;
    }

    /**
     * Check if the signature matches any function in the list
     */
    private function matchesFunction(MethodSignature $signature, array $functions): bool
    {
        if (empty($functions)) {
            return false;
        }

        $calleeMethod = $signature->getCalleeMethod();
        $calleeClass = $signature->getCalleeClass();

        // For an empty method, use the class name (might be a global function)
        $methodToCheck = empty($calleeMethod) ? $calleeClass : $calleeMethod;

        // Also check the fully qualified method name (Class::method)
        $fullMethodName = $calleeClass;
        if (!empty($calleeMethod)) {
            $separator = $signature->isStaticCallee() ? '::' : '->';
            $fullMethodName .= $separator . $calleeMethod;
        }

        foreach ($functions as $functionPattern) {
            if ($this->functionsAsRegex) {
                // Use regex pattern matching
                $pattern = '/' . str_replace('/', '\/', $functionPattern) . '/';
                if (preg_match($pattern, $methodToCheck) || preg_match($pattern, $fullMethodName)) {
                    return true;
                }
            } else {
                // Use simple string comparison
                if ($methodToCheck === $functionPattern || $fullMethodName === $functionPattern) {
                    return true;
                }
            }
        }

        return false;
    }

    // Getters
    public function getIncludeNamespaces(): array
    {
        return $this->includeNamespaces;
    }

    public function getExcludeNamespaces(): array
    {
        return $this->excludeNamespaces;
    }

    public function getIncludeFunctions(): array
    {
        return $this->includeFunctions;
    }

    public function getExcludeFunctions(): array
    {
        return $this->excludeFunctions;
    }

    public function getFunctionsAsRegex(): bool
    {
        return $this->functionsAsRegex;
    }

    public function getMinExecutionTime(): int
    {
        return $this->minExecutionTime;
    }

    public function getRequireAllFilters(): bool
    {
        return $this->requireAllFilters;
    }

    public function getExcludePhpInternals(): bool
    {
        return $this->excludePhpInternals;
    }

    public function getPreserveImportantPaths(): bool
    {
        return $this->preserveImportantPaths;
    }

    public function getMaxConsecutiveExcludedCalls(): int
    {
        return $this->maxConsecutiveExcludedCalls;
    }
}
