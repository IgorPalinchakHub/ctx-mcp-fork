<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model;

class CallNode
{
    /** @var array<CallNode> Child call nodes */
    private array $children = [];

    /** @var CallNode|null Parent call node */
    private ?CallNode $parent = null;

    public function __construct(
        private readonly MethodSignature $signature,
        private readonly CallMetrics $metrics,
        private int $depth = 0
    ) {
    }

    public function addChild(CallNode $child): void
    {
        $child->setParent($this);
        $child->setDepth($this->depth + 1);
        $this->children[] = $child;
    }

    public function setParent(?CallNode $parent): void
    {
        $this->parent = $parent;
    }

    public function setDepth(int $depth): void
    {
        $this->depth = $depth;
    }

    public function getSignature(): MethodSignature
    {
        return $this->signature;
    }

    public function getMetrics(): CallMetrics
    {
        return $this->metrics;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function getParent(): ?CallNode
    {
        return $this->parent;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function hasChildren(): bool
    {
        return !empty($this->children);
    }

    public function sortChildren(): void
    {
        // Primary sort by wall time descending
        usort($this->children, function (CallNode $a, CallNode $b) {
            $timeComparison = $b->getMetrics()->getWallTime() <=> $a->getMetrics()->getWallTime();

            // If times are equal (rare), sort by memory usage
            if ($timeComparison === 0) {
                return $b->getMetrics()->getMemoryUsage() <=> $a->getMetrics()->getMemoryUsage();
            }

            return $timeComparison;
        });

        // Recursively sort descendants
        foreach ($this->children as $child) {
            $child->sortChildren();
        }
    }

    public function getChildrenBySignature(string $signature): array
    {
        $result = [];

        foreach ($this->children as $child) {
            if ($child->getCalleeMethod() === $signature) {
                $result[] = $child;
            }
        }

        return $result;
    }

    public function getCalleeMethod(): string
    {
        return $this->signature->getCalleeSignature();
    }

    public function isStatic(): bool
    {
        return $this->signature->isStaticCallee();
    }

    public function isRecursive(): bool
    {
        return $this->signature->isRecursive();
    }

    public function getRecursionLevel(): int
    {
        return $this->signature->getRecursionLevel();
    }

    public function getTotalChildrenCount(): int
    {
        $count = count($this->children);

        foreach ($this->children as $child) {
            $count += $child->getTotalChildrenCount();
        }

        return $count;
    }
}
