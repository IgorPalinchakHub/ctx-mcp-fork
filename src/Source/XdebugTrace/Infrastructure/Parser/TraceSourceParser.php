<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Parser;

use Butschster\ContextGenerator\Modifier\ModifiersApplierInterface;
use Butschster\ContextGenerator\Source\SourceInterface;
use Butschster\ContextGenerator\Source\XdebugTrace\Application\TraceSourceFetcher;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TraceSource;
use Butschster\ContextGenerator\SourceParserInterface;
use Psr\Log\LoggerInterface;

/**
 * Parser for XDebug trace sources
 */
class TraceSourceParser implements SourceParserInterface
{
    /**
     * @param TraceSourceFetcher $fetcher Fetcher for trace sources
     * @param LoggerInterface|null $logger Optional logger for errors
     */
    public function __construct(
        private TraceSourceFetcher $fetcher,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Check if this parser supports the given source
     */
    public function supports(SourceInterface $source): bool
    {
        return $source instanceof TraceSource;
    }

    /**
     * Parse a trace source
     */
    public function parse(SourceInterface $source, ModifiersApplierInterface $modifiersApplier): string
    {
        if (!$source instanceof TraceSource) {
            throw new \InvalidArgumentException('Source must be an instance of TraceSource');
        }

        $this->logger?->info('Parsing trace source', [
            'hasDescription' => $source->hasDescription(),
            'hasTags' => $source->hasTags(),
        ]);

        // Delegate to the fetcher
        return $this->fetcher->fetch($source, $modifiersApplier);
    }
}
