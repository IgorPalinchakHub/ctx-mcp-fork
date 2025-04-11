<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\CallContext;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\MethodCall;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TypedParameter;
use Psr\Log\LoggerInterface;

/**
 * Parser for Xdebug trace files to enhance static analysis with runtime data
 */
class XdebugTraceFileParser
{
    /**
     * @param LoggerInterface|null $logger Optional logger for errors
     */
    public function __construct(
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Parse an Xdebug trace file to extract method call information
     *
     * @param string $filePath Path to the Xdebug trace file
     * @return MethodCall|null Root method call or null if parsing failed
     */
    public function parseTraceFile(string $filePath): ?MethodCall
    {
        if (!file_exists($filePath)) {
            $this->logger?->error("Xdebug trace file not found: {$filePath}");
            return null;
        }

        try {
            $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                $this->logger?->error("Failed to read Xdebug trace file: {$filePath}");
                return null;
            }

            // Parse the trace file format
            return $this->processTraceLines($lines);
        } catch (\Throwable $e) {
            $this->logger?->error("Error parsing Xdebug trace file: " . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    /**
     * Process the trace file lines to build method call hierarchy
     *
     * @param array<string> $lines Lines from the trace file
     * @return MethodCall|null Root method call or null if parsing failed
     */
    private function processTraceLines(array $lines): ?MethodCall
    {
        $callStack = [];
        $rootCall = null;
        $currentLevel = -1;

        foreach ($lines as $line) {
            // Skip irrelevant lines
            if (!$this->isTraceLine($line)) {
                continue;
            }

            $callInfo = $this->parseTraceLine($line);
            if (!$callInfo) {
                continue;
            }

            [
                'level' => $level,
                'time' => $time,
                'memory' => $memory,
                'class' => $className,
                'type' => $callType,
                'function' => $methodName,
                'params' => $params,
                'file' => $filePath,
                'line' => $lineNumber
            ] = $callInfo;

            // Create call context with file and line information
            $callContext = new CallContext($filePath, $lineNumber);

            // Determine if static call
            $isStatic = $callType === '::';

            // Create method parameters
            $parameters = $this->createParameters($params);

            // Create method call
            $methodCall = new MethodCall(
                $className,
                $methodName,
                $isStatic,
                $parameters,
                null, // Return type unknown from trace
                $callContext
            );

            // Handle call stack
            if ($level > $currentLevel) {
                // Going deeper in the stack
                if ($currentLevel >= 0 && isset($callStack[$currentLevel])) {
                    $callStack[$currentLevel]->addChildCall($methodCall);
                }

                $callStack[$level] = $methodCall;

                // If this is the first method call, set it as root
                if ($rootCall === null) {
                    $rootCall = $methodCall;
                }
            } elseif ($level === $currentLevel) {
                // Same level, replace the current
                $callStack[$level] = $methodCall;

                // Add to parent if exists
                if ($level > 0 && isset($callStack[$level - 1])) {
                    $callStack[$level - 1]->addChildCall($methodCall);
                }

                // If this is the first method call, set it as root
                if ($rootCall === null) {
                    $rootCall = $methodCall;
                }
            } elseif ($level < $currentLevel) {
                // Going back up the stack
                for ($i = $currentLevel; $i > $level; $i--) {
                    unset($callStack[$i]);
                }

                $callStack[$level] = $methodCall;

                // Add to parent if exists
                if ($level > 0 && isset($callStack[$level - 1])) {
                    $callStack[$level - 1]->addChildCall($methodCall);
                } else {
                    // No parent, must be a new root
                    $rootCall = $methodCall;
                }
            }

            $currentLevel = $level;
        }

        return $rootCall;
    }

    /**
     * Check if a line is a trace entry
     */
    private function isTraceLine(string $line): bool
    {
        // Different Xdebug versions use different formats
        return (bool)preg_match('/^\s*TRACE\s+/i', $line) ||
            (bool)preg_match('/^\s*\d+\s+\d+\.\d+\s+\d+\s+/i', $line);
    }

    /**
     * Parse a trace line to extract call information
     *
     * @return array<string, mixed>|null Call information or null if line format is invalid
     */
    private function parseTraceLine(string $line): ?array
    {
        // Xdebug 2.x format with TRACE keyword
        if (preg_match('/^\s*TRACE\s+(\d+)\s+(\d+\.\d+)\s+(\d+)\s+(\S+)(?:\s+)(?:(\S+))?(?:\s+)?(.*)$/', $line, $matches)) {
            [, $level, $time, $memory, $function, $file, $params] = array_pad($matches, 7, '');

            return $this->processTraceMatch($level, $time, $memory, $function, $file, $params);
        }

        // Xdebug 3.x format without TRACE keyword
        if (preg_match('/^\s*(\d+)\s+(\d+\.\d+)\s+(\d+)\s+(\S+)(?:\s+)(?:(\S+))?(?:\s+)?(.*)$/', $line, $matches)) {
            [, $level, $time, $memory, $function, $file, $params] = array_pad($matches, 7, '');

            return $this->processTraceMatch($level, $time, $memory, $function, $file, $params);
        }

        return null;
    }

    /**
     * Process a matched trace line to extract structured data
     *
     * @return array<string, mixed> Structured call information
     */
    private function processTraceMatch(
        string $level,
        string $time,
        string $memory,
        string $function,
        string $file,
        string $params
    ): array {
        // Split function into class and method if applicable
        $class = '';
        $type = '';

        if (str_contains($function, '::')) {
            [$class, $function] = explode('::', $function);
            $type = '::';
        } elseif (str_contains($function, '->')) {
            [$class, $function] = explode('->', $function);
            $type = '->';
        }

        // Process file and line
        $line = 0;
        if (preg_match('/^(.+):(\d+)$/', $file, $matches)) {
            $file = $matches[1];
            $line = (int)$matches[2];
        }

        // Process parameters
        $parsedParams = $this->parseParameters($params);

        return [
            'level' => (int)$level,
            'time' => (float)$time,
            'memory' => (int)$memory,
            'class' => $class,
            'type' => $type,
            'function' => $function,
            'params' => $parsedParams,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * Parse parameter string into structured data
     *
     * @param string $paramsString Parameter string from trace file
     * @return array<mixed> Parsed parameters
     */
    private function parseParameters(string $paramsString): array
    {
        $params = [];

        // Simple parameter extraction - can be enhanced for more complex cases
        if (preg_match_all('/[\'"](.*?)[\'"]\s*(?:,|$)|\d+(?:\.\d+)?\s*(?:,|$)|true|false|null/i', $paramsString, $matches)) {
            foreach ($matches[0] as $param) {
                $param = trim($param, ", \t\n\r");
                if (preg_match('/^[\'"](.*)[\'"]\s*$/', $param, $strMatch)) {
                    $params[] = $strMatch[1]; // String value
                } elseif (is_numeric($param)) {
                    $params[] = strpos($param, '.') !== false ? (float)$param : (int)$param;
                } elseif ($param === 'true') {
                    $params[] = true;
                } elseif ($param === 'false') {
                    $params[] = false;
                } elseif ($param === 'null') {
                    $params[] = null;
                } else {
                    $params[] = $param; // Other values
                }
            }
        }

        return $params;
    }

    /**
     * Create TypedParameter objects from parsed parameters
     *
     * @param array<mixed> $params Parsed parameters
     * @return array<TypedParameter> TypedParameter objects
     */
    private function createParameters(array $params): array
    {
        $parameters = [];

        foreach ($params as $index => $value) {
            // Infer type from value
            $type = $this->inferTypeFromValue($value);

            // Create parameter with indexed name
            $parameter = new TypedParameter(
                'param' . ($index + 1),
                $type,
                $value
            );

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Infer type from a value
     */
    private function inferTypeFromValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return 'mixed';
    }
}
