<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model;

use Butschster\ContextGenerator\Modifier\ModifiersApplierInterface;
use Butschster\ContextGenerator\Source\SourceInterface;
use Butschster\ContextGenerator\SourceParserInterface;

class TraceSource implements SourceInterface
{
    private string $description = '';
    private array $tags = [];

    /**
     * @param array $sourcePaths Source paths to analyze
     * @param string $description Description of the source
     * @param string $content Additional content
     * @param array $options Configuration options for analysis
     * @param string|array $filePattern File pattern to match
     * @param array $notPath Paths to exclude
     * @param array $path Paths to include
     * @param array $contains Content to include
     * @param array $notContains Content to exclude
     * @param string $renderFormat Format to render in (markdown or plain)
     * @param string $tag Primary tag
     * @param array $tags Additional tags
     */
    public function __construct(
        private array $sourcePaths = [],
        string $description = '',
        private string $content = '',
        private array $options = [],
        private $filePattern = '*.php',
        private array $notPath = [],
        private array $path = [],
        private array $contains = [],
        private array $notContains = [],
        private string $renderFormat = 'markdown',
        string $tag = '',
        array $tags = []
    ) {
        $this->description = $description;

        // Combine tag and tags
        $this->tags = $tags;
        if (!empty($tag) && !in_array($tag, $this->tags)) {
            $this->tags[] = $tag;
        }
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function hasDescription(): bool
    {
        return !empty($this->description);
    }

    /**
     * @return array<non-empty-string>
     */
    public function getTags(): array
    {
        return array_filter($this->tags, function ($tag) {
            return !empty($tag);
        });
    }

    public function hasTags(): bool
    {
        return !empty($this->getTags());
    }

    public function parseContent(
        SourceParserInterface $parser,
        ModifiersApplierInterface $modifiersApplier,
    ): string {
        return $parser->parse($this, $modifiersApplier);
    }

    /**
     * Get source paths
     */
    public function getSourcePaths(): array
    {
        return $this->sourcePaths;
    }

    /**
     * Get additional content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Get analyzer options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get file pattern
     *
     * @return string|array
     */
    public function getFilePattern()
    {
        return $this->filePattern;
    }

    /**
     * Get paths to exclude
     */
    public function getNotPath(): array
    {
        return $this->notPath;
    }

    /**
     * Get paths to include
     */
    public function getPath(): array
    {
        return $this->path;
    }

    /**
     * Get content to include
     */
    public function getContains(): array
    {
        return $this->contains;
    }

    /**
     * Get content to exclude
     */
    public function getNotContains(): array
    {
        return $this->notContains;
    }

    /**
     * Get render format
     */
    public function getRenderFormat(): string
    {
        return $this->renderFormat;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'description' => $this->description,
            'sourcePaths' => $this->sourcePaths,
            'content' => $this->content,
            'options' => $this->options,
            'filePattern' => $this->filePattern,
            'notPath' => $this->notPath,
            'path' => $this->path,
            'contains' => $this->contains,
            'notContains' => $this->notContains,
            'renderFormat' => $this->renderFormat,
            'tags' => $this->tags,
        ];
    }
}
