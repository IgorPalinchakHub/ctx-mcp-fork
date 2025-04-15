<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace;

use Butschster\ContextGenerator\Source\BaseSource;
use Butschster\ContextGenerator\Source\Fetcher\FilterableSourceInterface;

final class XHProfTraceSource extends BaseSource implements FilterableSourceInterface
{
    public function __construct(
        public readonly string|array $sourcePaths,
        string $description = '',
        public readonly string|array $filePattern = '*.json',
        public readonly array $notPath = [],
        public readonly string|array $path = [],
        public readonly string|array $contains = [],
        public readonly string|array $notContains = [],
        public readonly string $renderFormat = 'markdown',
        array $tags = [],
        public readonly array $options = [],
    ) {
        parent::__construct(description: $description, tags: $tags);
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

    /**
     * Get filter options if defined
     */
    public function getFilterOptions(): ?array
    {
        return $this->options['filters'] ?? null;
    }

    /**
     * Check if filtering is enabled
     */
    public function hasFilters(): bool
    {
        return isset($this->options['filters']) && !empty($this->options['filters']);
    }

    /**
     * Check if method extraction is enabled
     */
    public function hasMethodExtraction(): bool
    {
        return isset($this->options['methodExtraction']) &&
            isset($this->options['methodExtraction']['enabled']) &&
            $this->options['methodExtraction']['enabled'] === true;
    }

    /**
     * Get method extraction options if defined
     */
    public function getMethodExtractionOptions(): ?array
    {
        return $this->options['methodExtraction'] ?? null;
    }

    /**
     * Get the output path for method extraction
     */
    public function getMethodExtractionOutputPath(): ?string
    {
        return $this->options['methodExtraction']['outputPath'] ?? null;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'xhprof_trace',
            ...parent::jsonSerialize(),
            'sourcePaths' => $this->sourcePaths,
            'filePattern' => $this->filePattern,
            'notPath' => $this->notPath,
            'renderFormat' => $this->renderFormat,
            'options' => $this->options,
        ];

        if (!empty($this->path)) {
            $result['path'] = $this->path;
        }

        if (!empty($this->contains)) {
            $result['contains'] = $this->contains;
        }

        if (!empty($this->notContains)) {
            $result['notContains'] = $this->notContains;
        }

        return \array_filter($result);
    }

    public function maxFiles(): int
    {
        return 0;
    }
}
