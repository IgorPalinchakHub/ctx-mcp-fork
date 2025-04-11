<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model;

final class MethodSignature
{
    private string $callerClass = '';
    private string $callerMethod = '';
    private string $calleeClass = '';
    private string $calleeMethod = '';
    private bool $isRecursive = false;
    private int $recursionLevel = 0;

    public function __construct(private readonly string $signature)
    {
        $this->parseSignature($signature);
    }

    private function parseSignature(string $signature): void
    {
        // Handle simple main() signature as a special case
        if ($signature === 'main()') {
            $this->callerClass = '';
            $this->callerMethod = '';
            $this->calleeClass = 'main()';
            $this->calleeMethod = '';
            return;
        }

        // Check if this is a caller->callee format with ==>
        if (strpos($signature, '==>') !== false) {
            $parts = explode('==>', $signature);

            if (count($parts) === 2) {
                list($caller, $callee) = $parts;

                // Parse caller
                list($this->callerClass, $this->callerMethod) = $this->parseFunctionSignature($caller);

                // Parse callee
                list($this->calleeClass, $this->calleeMethod) = $this->parseFunctionSignature($callee);
                return;
            }
        }

        // If we reach here, it's a simple function/method call without a caller-callee relationship
        // In this case, just treat it as a callee with no caller
        $this->callerClass = '';
        $this->callerMethod = '';
        list($this->calleeClass, $this->calleeMethod) = $this->parseFunctionSignature($signature);
    }

    private function parseFunctionSignature(string $signature): array
    {
        // Handle recursive functions (with @N suffix)
        if (preg_match('/^(.+)@(\d+)$/', $signature, $matches)) {
            $signature = $matches[1];
            $this->isRecursive = true;
            $this->recursionLevel = (int)$matches[2];
        }

        // Handle static method calls
        if (strpos($signature, '::') !== false) {
            list($class, $method) = explode('::', $signature, 2);
            return [$class, $method];
        }

        // Handle instance method calls
        if (strpos($signature, '->') !== false) {
            list($class, $method) = explode('->', $signature, 2);
            return [$class, $method];
        }

        // Handle closures/anonymous functions
        if (preg_match('/^([^{]+)::{closure}/', $signature, $matches)) {
            return [$matches[1], '{closure}'];
        }

        // If no method specification but has slashes or backslashes,
        // assume it's a class name without method or namespace
        if (strpos($signature, '\\') !== false || strpos($signature, '/') !== false) {
            return [$signature, ''];
        }

        // Handle global functions (no class)
        return ['', $signature];
    }

    public function getCallerClass(): string
    {
        return $this->callerClass;
    }

    public function getCallerMethod(): string
    {
        return $this->callerMethod;
    }

    public function getCalleeClass(): string
    {
        return $this->calleeClass;
    }

    public function getCalleeMethod(): string
    {
        return $this->calleeMethod;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getCallerSignature(): string
    {
        if (empty($this->callerClass)) {
            return '';
        }

        if (empty($this->callerMethod)) {
            return $this->callerClass;
        }

        $base = $this->callerClass . ($this->isStaticCaller() ? '::' : '->') . $this->callerMethod;
        return $this->isRecursive && $this->recursionLevel > 0 ? $base . '@' . $this->recursionLevel : $base;
    }

    public function getCalleeSignature(): string
    {
        if (empty($this->calleeMethod)) {
            return $this->calleeClass;
        }

        $base = $this->calleeClass . ($this->isStaticCallee() ? '::' : '->') . $this->calleeMethod;
        return $this->isRecursive && $this->recursionLevel > 0 ? $base . '@' . $this->recursionLevel : $base;
    }

    public function isStaticCaller(): bool
    {
        return strpos($this->signature, '::') !== false && strpos($this->signature, '==>') !== false &&
            strpos($this->signature, '::') < strpos($this->signature, '==>');
    }

    public function isStaticCallee(): bool
    {
        if (strpos($this->signature, '==>') === false) {
            // If no ==>, check if there's a :: in the signature
            return strpos($this->signature, '::') !== false;
        }

        return strpos($this->signature, '::') !== false &&
            strpos($this->signature, '==>') !== false &&
            strpos($this->signature, '::') > strpos($this->signature, '==>');
    }

    public function isRecursive(): bool
    {
        return $this->isRecursive;
    }

    public function getRecursionLevel(): int
    {
        return $this->recursionLevel;
    }
}
