<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

/**
 * Exception for trace source creation errors
 */
class SourceCreationException extends \Exception
{
    private string $sourceType;
    private array $config;

    private function __construct(string $sourceType, string $message, array $config, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->sourceType = $sourceType;
        $this->config = $config;
    }

    /**
     * Create a new exception for a specific source type
     */
    public static function forSourceType(string $sourceType, string $message, array $config, ?\Throwable $previous = null): self
    {
        return new self($sourceType, $message, $config, $previous);
    }

    /**
     * Get the source type that caused the error
     */
    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * Get the configuration that was being processed
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
