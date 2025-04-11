<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Application\Service;

use Butschster\ContextGenerator\Source\XHProfTrace\Domain\Model\CallStack;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Parser\XHProfJsonParser;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\MarkdownRenderer;
use Butschster\ContextGenerator\Source\XHProfTrace\Infrastructure\Presentation\Renderer\CallTreeMarkdownRenderer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TraceVisualizerService
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function visualizeXHProfTrace(
        string $jsonData,
        string $title = 'XHProf Call Tree Analysis',
        string $description = '',
        int $maxDepth = 20,
        bool $showMemory = true,
        bool $showCpuTime = true
    ): string {
        $parser = new XHProfJsonParser($this->logger);
        $callStack = $parser->parse($jsonData);

        $treeRenderer = new CallTreeMarkdownRenderer(
            maxDepth: $maxDepth,
            showMemory: $showMemory,
            showCpuTime: $showCpuTime
        );

        $renderer = new MarkdownRenderer($treeRenderer);

        return $renderer->render($callStack, $title, $description);
    }

    public function getCallStack(string $jsonData): CallStack
    {
        $parser = new XHProfJsonParser($this->logger);
        return $parser->parse($jsonData);
    }
}
