<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model;

/**
 * Contains contextual information about a method call
 */
class CallContext
{
    /**
     * @param string $filePath File path where the method is defined
     * @param int $line Line number where the method is defined
     * @param string $callerFilePath File path where the method is called from
     * @param int $callerLine Line number where the method is called from
     */
    public function __construct(
        private string $filePath = '',
        private int $line = 0,
        private string $callerFilePath = '',
        private int $callerLine = 0
    ) {}

    /**
     * Get file path where the method is defined
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Set file path where the method is defined
     */
    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
    }

    /**
     * Get line number where the method is defined
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * Set line number where the method is defined
     */
    public function setLine(int $line): void
    {
        $this->line = $line;
    }

    /**
     * Get file path where the method is called from
     */
    public function getCallerFilePath(): string
    {
        return $this->callerFilePath;
    }

    /**
     * Set file path where the method is called from
     */
    public function setCallerFilePath(string $callerFilePath): void
    {
        $this->callerFilePath = $callerFilePath;
    }

    /**
     * Get line number where the method is called from
     */
    public function getCallerLine(): int
    {
        return $this->callerLine;
    }

    /**
     * Set line number where the method is called from
     */
    public function setCallerLine(int $callerLine): void
    {
        $this->callerLine = $callerLine;
    }

    /**
     * Check if file path information is available
     */
    public function hasFileInfo(): bool
    {
        return !empty($this->filePath) && $this->line > 0;
    }

    /**
     * Check if caller information is available
     */
    public function hasCallerInfo(): bool
    {
        return !empty($this->callerFilePath) && $this->callerLine > 0;
    }

    /**
     * Get file location as formatted string
     */
    public function getFormattedFileLocation(): string
    {
        if (!$this->hasFileInfo()) {
            return '';
        }

        return "[{$this->filePath}:{$this->line}]";
    }
}
