<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

use Butschster\ContextGenerator\Source\BaseSource;
use Butschster\ContextGenerator\Source\Fetcher\FilterableSourceInterface;

/**
 * Trace source for generating method call stack analysis
 * Builds a hierarchical visualization of method calls
 */
final class TraceSource extends BaseSource implements FilterableSourceInterface
{
    /**
     * Content to include in the output (usually empty as it will be generated)
     */
    public readonly string $content;

    /**
     * Tag to help LLM understand the content type
     */
    public readonly string $tag;

    /**
     * Configuration options for the trace analysis
     *
     * @var array<string, mixed>
     */
    public readonly array $options;

    /**
     * @param string|array<string> $sourcePaths Path(s) to analyze for method calls
     * @param string $description Human-readable description
     * @param string $content Initial content (usually empty as it will be generated)
     * @param array<string, mixed> $options Configuration options for the trace analysis
     * @param string|array<string> $filePattern Pattern(s) to match files
     * @param array<string> $notPath Patterns to exclude paths
     * @param string|array<string> $path Patterns to include only specific paths
     * @param string|array<string> $contains Patterns to include files containing specific content
     * @param string|array<string> $notContains Patterns to exclude files containing specific content
     * @param string $renderFormat Output format for the trace (markdown, ascii, etc.)
     * @param string $tag Tag to help LLM understand the content type
     * @param array<non-empty-string> $tags Metadata tags for categorization
     */
    public function __construct(
        public readonly string|array $sourcePaths,
        string $description = '',
        string $content = '',
        array $options = [],
        public readonly string|array $filePattern = '*.php',
        public readonly array $notPath = [],
        public readonly string|array $path = [],
        public readonly string|array $contains = [],
        public readonly string|array $notContains = [],
        public readonly string $renderFormat = 'markdown',
        string $tag = 'trace',
        array $tags = [],
    ) {
        parent::__construct(description: $description, tags: $tags);
        $this->content = $content;
        $this->tag = $tag;
        $this->options = $this->mergeWithDefaultOptions($options);
    }

    /**
     * Get default configuration options
     *
     * @return array<string, mixed>
     */
    private function mergeWithDefaultOptions(array $options): array
    {
        $defaultOptions = [
            // File and class options
            'startFile' => null,
            'class' => null,
            'method' => 'fetch',
            'outputFile' => 'call_stack.md',

            // Skip options
            'skipVendorDir' => true,
            'skipFrameworks' => true,
            'skipSingletonMethods' => true,
            'skipConstructors' => false,
            'skipInvokeMethods' => false,

            // Patterns
            'singletonMethodPatterns' => [],
            'skipDirs' => [],
            'skipClasses' => [],
            'skipMethods' => [],

            // Analysis options
            'maxDepth' => 20,
        ];

        return array_merge($defaultOptions, $options);
    }

    /**
     * Get default configuration options for the trace source
     *
     * @return array<string, mixed> Default configuration options
     */
    public static function getDefaultOptions(): array
    {
        return [
            // File and class options
            'startFile' => null,
            'class' => null,
            'method' => 'fetch',
            'outputFile' => 'call_stack.md',

            // Skip options
            'skipVendorDir' => true,
            'skipFrameworks' => true,
            'skipSingletonMethods' => true,
            'skipConstructors' => false,
            'skipInvokeMethods' => false,

            // Patterns
            'singletonMethodPatterns' => [],
            'skipDirs' => [],
            'skipClasses' => [],
            'skipMethods' => [],

            // Analysis options
            'maxDepth' => 20
        ];
    }

    public function name(): string|array|null
    {
        return $this->filePattern;
    }

    public function path(): string|array|null
    {
        return $this->path;
    }

    public function notPath(): string|array|null
    {
        return $this->notPath;
    }

    public function contains(): string|array|null
    {
        return $this->contains;
    }

    public function notContains(): string|array|null
    {
        return $this->notContains;
    }

    public function size(): string|array|null
    {
        return null;
    }

    public function date(): string|array|null
    {
        return null;
    }

    public function in(): array|null
    {
        $directories = [];

        foreach ((array) $this->sourcePaths as $path) {
            if (\is_dir($path)) {
                $directories[] = $path;
            }
        }

        return empty($directories) ? null : $directories;
    }

    public function files(): array|null
    {
        $files = [];

        // Include start file if specified
        if (isset($this->options['startFile']) && \is_file($this->options['startFile'])) {
            $files[] = $this->options['startFile'];
        }

        foreach ((array) $this->sourcePaths as $path) {
            if (\is_file($path)) {
                $files[] = $path;
            }
        }

        return empty($files) ? null : $files;
    }

    public function ignoreUnreadableDirs(): bool
    {
        return true;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'trace',
            ...parent::jsonSerialize(),
            'sourcePaths' => $this->sourcePaths,
            'filePattern' => $this->filePattern,
            'notPath' => $this->notPath,
            'renderFormat' => $this->renderFormat,
            'tag' => $this->tag,
            'options' => $this->options,
        ];

        // Add optional properties only if they're non-empty
        if (!empty($this->path)) {
            $result['path'] = $this->path;
        }

        if (!empty($this->contains)) {
            $result['contains'] = $this->contains;
        }

        if (!empty($this->notContains)) {
            $result['notContains'] = $this->notContains;
        }

        if (!empty($this->content)) {
            $result['content'] = $this->content;
        }

        return \array_filter($result);
    }
}
