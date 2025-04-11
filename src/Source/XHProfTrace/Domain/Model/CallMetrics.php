<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model;

final class CallMetrics
{
    public function __construct(
        private readonly int $callCount,
        private readonly int $wallTime,
        private readonly int $cpuTime,
        private readonly int $memoryUsage,
        private readonly int $peakMemoryUsage
    ) {
    }

    public static function fromXHProfMetrics(array $metrics): self
    {
        return new self(
            callCount: $metrics['ct'] ?? 0,
            wallTime: $metrics['wt'] ?? 0,
            cpuTime: $metrics['cpu'] ?? 0,
            memoryUsage: $metrics['mu'] ?? 0,
            peakMemoryUsage: $metrics['pmu'] ?? 0,
        );
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getWallTime(): int
    {
        return $this->wallTime;
    }

    public function getCpuTime(): int
    {
        return $this->cpuTime;
    }

    public function getMemoryUsage(): int
    {
        return $this->memoryUsage;
    }

    public function getPeakMemoryUsage(): int
    {
        return $this->peakMemoryUsage;
    }

    public function getFormattedWallTime(): string
    {
        return $this->formatTime($this->wallTime);
    }

    public function getFormattedCpuTime(): string
    {
        return $this->formatTime($this->cpuTime);
    }

    public function getFormattedMemoryUsage(): string
    {
        return $this->formatMemory($this->memoryUsage);
    }

    public function getFormattedPeakMemoryUsage(): string
    {
        return $this->formatMemory($this->peakMemoryUsage);
    }

    private function formatTime(int $time): string
    {
        if ($time < 1000) {
            return "{$time}μs";
        } elseif ($time < 1000000) {
            return round($time / 1000, 2) . 'ms';
        } else {
            return round($time / 1000000, 2) . 's';
        }
    }

    private function formatMemory(int $memory): string
    {
        if ($memory < 1024) {
            return "{$memory}B";
        } elseif ($memory < 1048576) {
            return round($memory / 1024, 2) . 'KB';
        } else {
            return round($memory / 1048576, 2) . 'MB';
        }
    }
}
