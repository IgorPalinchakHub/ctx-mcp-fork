<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

/**
 * Enhanced service to handle rules for skipping methods, classes and directories
 */
class SkipRulesService
{
    /** @var array<string> Patterns for methods to skip */
    private array $skipMethodPatterns = [];

    /** @var array<string> Patterns for classes to skip */
    private array $skipClassPatterns = [];

    /** @var array<string> Patterns for directories to skip */
    private array $skipDirPatterns = [];

    /** @var array<string> Patterns for singleton methods */
    private array $singletonMethodPatterns = ['/^getInstance$/', '/^instance$/', '/^create$/', '/^singleton$/'];

    /** @var bool Whether to skip singleton methods */
    private bool $skipSingletonMethods = true;

    /** @var bool Whether to skip constructor methods */
    private bool $skipConstructors = false;

    /** @var bool Whether to skip __invoke methods */
    private bool $skipInvokeMethods = false;

    /** @var array<string> Full class names to explicitly skip */
    private array $explicitSkipClasses = [];

    /** @var array<string> Method names to explicitly skip */
    private array $explicitSkipMethods = [];

    /**
     * Constructor that sets up default skip patterns
     */
    public function __construct()
    {
        $this->setupDefaultPatterns();
    }

    /**
     * Setup default patterns for skipping methods, classes, and directories
     */
    private function setupDefaultPatterns(): void
    {
        // Default method patterns to skip
        $this->skipMethodPatterns = [
            '/^__debugInfo$/',
            '/^__toString$/',
            '/^__isset$/',
            '/^__get$/',
            '/^__set$/',
            '/^__call$/',
            '/^__callStatic$/',
            '/^__sleep$/',
            '/^__wakeup$/',
            '/^__clone$/',
            '/^__unset$/',
            '/^__set_state$/',
            '/^__serialize$/',
            '/^__unserialize$/',
        ];

        // Default class patterns to skip - empty by default
        $this->skipClassPatterns = [];

        // Default directory patterns to skip
        $this->skipDirPatterns = ['vendor/'];

        // Default explicit classes to skip - empty by default
        $this->explicitSkipClasses = [];

        // Default explicit methods to skip - empty by default
        $this->explicitSkipMethods = [];
    }

    /**
     * Configure the skip rules from an options array
     */
    public function configureFromOptions(array $options): void
    {
        // Configure skipping singleton methods
        $this->skipSingletonMethods = $options['skipSingletonMethods'] ?? true;

        // Configure skipping constructors
        $this->skipConstructors = $options['skipConstructors'] ?? false;

        // Configure skipping __invoke methods
        $this->skipInvokeMethods = $options['skipInvokeMethods'] ?? false;

        // Configure custom singleton method patterns
        if (isset($options['singletonMethodPatterns']) && is_array($options['singletonMethodPatterns'])) {
            $this->addSingletonMethodPatterns($options['singletonMethodPatterns']);
        }

        // Configure skipping vendor directory
        $skipVendor = $options['skipVendorDir'] ?? true;
        if (!$skipVendor && in_array('vendor/', $this->skipDirPatterns)) {
            $this->skipDirPatterns = array_diff($this->skipDirPatterns, ['vendor/']);
        }

        // Configure additional directories to skip
        if (isset($options['skipDirs']) && is_array($options['skipDirs'])) {
            $patternsToAdd = [];
            foreach ($options['skipDirs'] as $dir) {
                // Ensure directory path ends with a slash
                $dir = rtrim($dir, '/') . '/';
                $patternsToAdd[] = $dir;
            }
            $this->addSkipDirPatterns($patternsToAdd);
        }

        // Configure additional classes to skip (by pattern)
        if (isset($options['skipClasses']) && is_array($options['skipClasses'])) {
            $patternsToAdd = [];
            foreach ($options['skipClasses'] as $class) {
                if (strpos($class, '*') !== false) {
                    // It's a pattern
                    $pattern = '/^' . str_replace(['\\', '*'], ['\\\\', '.*'], $class) . '/';
                    $patternsToAdd[] = $pattern;
                } else {
                    // It's an exact class name
                    $this->explicitSkipClasses[] = $class;
                }
            }
            $this->addSkipClassPatterns($patternsToAdd);
        }

        // Configure additional methods to skip (by pattern or exact match)
        if (isset($options['skipMethods']) && is_array($options['skipMethods'])) {
            $patternsToAdd = [];
            foreach ($options['skipMethods'] as $method) {
                if (strpos($method, '*') !== false) {
                    // It's a pattern
                    $pattern = '/^' . str_replace('*', '.*', $method) . '$/';
                    $patternsToAdd[] = $pattern;
                } else {
                    // It's an exact method name
                    $this->explicitSkipMethods[] = $method;
                }
            }
            $this->addSkipMethodPatterns($patternsToAdd);
        }

        // Add framework classes to skip patterns if configured
        if (isset($options['skipFrameworks']) && $options['skipFrameworks']) {
            $this->addSkipClassPatterns([
                '/^Symfony\\\\/',
                '/^Psr\\\\/',
                '/^PhpParser\\\\/',
                '/^Doctrine\\\\/',
                '/^Monolog\\\\/',
                '/^Twig\\\\/',
                '/^Laminas\\\\/',
                '/^GuzzleHttp\\\\/',
                '/^Laravel\\\\/',
                '/^Illuminate\\\\/',
                '/^PHPUnit\\\\/',
            ]);
        }
    }

    /**
     * Check if a class should be skipped
     */
    public function shouldSkipClass(string $className): bool
    {
        // First check explicit class list
        if (in_array($className, $this->explicitSkipClasses, true)) {
            return true;
        }

        // Then check patterns
        foreach ($this->skipClassPatterns as $pattern) {
            if (preg_match($pattern, $className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a method should be skipped
     */
    public function shouldSkipMethod(string $methodName): bool
    {
        // Skip __invoke method if configured
        if ($methodName === '__invoke' && $this->skipInvokeMethods) {
            return true;
        }

        // Skip __construct method if configured
        if ($methodName === '__construct' && $this->skipConstructors) {
            return true;
        }

        // Check explicit method list
        if (in_array($methodName, $this->explicitSkipMethods, true)) {
            return true;
        }

        // Skip singleton methods if configured
        if ($this->skipSingletonMethods && $this->isSingletonMethod($methodName)) {
            return true;
        }

        // Check method patterns
        foreach ($this->skipMethodPatterns as $pattern) {
            if (preg_match($pattern, $methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a directory path should be skipped
     */
    public function shouldSkipDirectory(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);

        foreach ($this->skipDirPatterns as $pattern) {
            if (stripos($normalizedPath, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file path should be skipped based on directory patterns
     */
    public function shouldSkipFile(string $path): bool
    {
        return $this->shouldSkipDirectory(dirname($path));
    }

    /**
     * Check if a method is a singleton accessor method
     */
    public function isSingletonMethod(string $methodName): bool
    {
        foreach ($this->singletonMethodPatterns as $pattern) {
            if (preg_match($pattern, $methodName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the singleton method patterns
     *
     * @return array<string>
     */
    public function getSingletonMethodPatterns(): array
    {
        return $this->singletonMethodPatterns;
    }

    /**
     * Set the singleton method patterns
     *
     * @param array<string> $patterns
     */
    public function setSingletonMethodPatterns(array $patterns): void
    {
        $this->singletonMethodPatterns = $patterns;
    }

    /**
     * Add singleton method patterns to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSingletonMethodPatterns(array $patterns): void
    {
        $this->singletonMethodPatterns = array_merge($this->singletonMethodPatterns, $patterns);
    }

    /**
     * Get the method patterns to skip
     *
     * @return array<string>
     */
    public function getSkipMethodPatterns(): array
    {
        return $this->skipMethodPatterns;
    }

    /**
     * Set the method patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipMethodPatterns(array $patterns): void
    {
        $this->skipMethodPatterns = $patterns;
    }

    /**
     * Add method patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipMethodPatterns(array $patterns): void
    {
        $this->skipMethodPatterns = array_merge($this->skipMethodPatterns, $patterns);
    }

    /**
     * Get the class patterns to skip
     *
     * @return array<string>
     */
    public function getSkipClassPatterns(): array
    {
        return $this->skipClassPatterns;
    }

    /**
     * Set the class patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipClassPatterns(array $patterns): void
    {
        $this->skipClassPatterns = $patterns;
    }

    /**
     * Add class patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipClassPatterns(array $patterns): void
    {
        $this->skipClassPatterns = array_merge($this->skipClassPatterns, $patterns);
    }

    /**
     * Get the directory patterns to skip
     *
     * @return array<string>
     */
    public function getSkipDirPatterns(): array
    {
        return $this->skipDirPatterns;
    }

    /**
     * Set the directory patterns to skip
     *
     * @param array<string> $patterns
     */
    public function setSkipDirPatterns(array $patterns): void
    {
        $this->skipDirPatterns = $patterns;
    }

    /**
     * Add directory patterns to skip to the existing patterns
     *
     * @param array<string> $patterns
     */
    public function addSkipDirPatterns(array $patterns): void
    {
        $this->skipDirPatterns = array_merge($this->skipDirPatterns, $patterns);
    }

    /**
     * Get whether singleton methods should be skipped
     */
    public function isSkipSingletonMethods(): bool
    {
        return $this->skipSingletonMethods;
    }

    /**
     * Set whether singleton methods should be skipped
     */
    public function setSkipSingletonMethods(bool $skip): void
    {
        $this->skipSingletonMethods = $skip;
    }

    /**
     * Get whether constructor methods should be skipped
     */
    public function isSkipConstructors(): bool
    {
        return $this->skipConstructors;
    }

    /**
     * Set whether constructor methods should be skipped
     */
    public function setSkipConstructors(bool $skip): void
    {
        $this->skipConstructors = $skip;
    }

    /**
     * Get whether __invoke methods should be skipped
     */
    public function isSkipInvokeMethods(): bool
    {
        return $this->skipInvokeMethods;
    }

    /**
     * Set whether __invoke methods should be skipped
     */
    public function setSkipInvokeMethods(bool $skip): void
    {
        $this->skipInvokeMethods = $skip;
    }

    /**
     * Get the full list of explicitly skipped class names
     *
     * @return array<string>
     */
    public function getExplicitSkipClasses(): array
    {
        return $this->explicitSkipClasses;
    }

    /**
     * Add class names to explicitly skip
     *
     * @param array<string> $classNames
     */
    public function addExplicitSkipClasses(array $classNames): void
    {
        $this->explicitSkipClasses = array_merge($this->explicitSkipClasses, $classNames);
    }

    /**
     * Get the full list of explicitly skipped method names
     *
     * @return array<string>
     */
    public function getExplicitSkipMethods(): array
    {
        return $this->explicitSkipMethods;
    }

    /**
     * Add method names to explicitly skip
     *
     * @param array<string> $methodNames
     */
    public function addExplicitSkipMethods(array $methodNames): void
    {
        $this->explicitSkipMethods = array_merge($this->explicitSkipMethods, $methodNames);
    }
}
