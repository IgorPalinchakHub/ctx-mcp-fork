<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

/**
 * Service to handle rules for skipping methods, classes and directories
 */
class SkipRulesService
{
    /** @var array<string> */
    private array $skipMethodPatterns = [];

    /** @var array<string> */
    private array $skipClassPatterns = [];

    /** @var array<string> */
    private array $skipDirPatterns = [];

    /** @var array<string> */
    private array $singletonMethodPatterns = ['/^getInstance$/', '/^instance$/', '/^create$/', '/^singleton$/'];

    /** @var bool */
    private bool $skipSingletonMethods = true;

    /** @var bool */
    private bool $skipConstructors = false;

    /** @var bool */
    private bool $skipInvokeMethods = false;

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
        ];

        // Default class patterns to skip - empty by default
        $this->skipClassPatterns = [];

        // Default directory patterns to skip
        $this->skipDirPatterns = ['vendor/'];
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

        // Configure additional classes to skip
        if (isset($options['skipClasses']) && is_array($options['skipClasses'])) {
            $patternsToAdd = [];
            foreach ($options['skipClasses'] as $class) {
                $pattern = '/^' . preg_quote($class, '/') . '/';
                $patternsToAdd[] = $pattern;
            }
            $this->addSkipClassPatterns($patternsToAdd);
        }

        // Configure additional methods to skip
        if (isset($options['skipMethods']) && is_array($options['skipMethods'])) {
            $patternsToAdd = [];
            foreach ($options['skipMethods'] as $method) {
                $pattern = '/^' . preg_quote($method, '/') . '$/';
                $patternsToAdd[] = $pattern;
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
            ]);
        }
    }

    /**
     * Check if a class should be skipped
     */
    public function shouldSkipClass(string $className): bool
    {
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

        // Skip singleton methods if configured
        if ($this->skipSingletonMethods && $this->isSingletonMethod($methodName)) {
            return true;
        }

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
        foreach ($this->skipDirPatterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return true;
            }
        }
        return false;
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

    // Getters and setters for patterns

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
}
