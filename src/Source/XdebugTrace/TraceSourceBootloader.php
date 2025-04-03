<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

use Butschster\ContextGenerator\Application\Bootloader\SourceFetcherBootloader;
use Butschster\ContextGenerator\Application\Logger\HasPrefixLoggerInterface;
use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Lib\Variable\VariableResolver;
use Butschster\ContextGenerator\Source\Registry\SourceRegistryInterface;
use Spiral\Boot\Bootloader\Bootloader;

final class TraceSourceBootloader extends Bootloader
{
    #[\Override]
    public function defineSingletons(): array
    {
        return [
            TraceSourceFetcher::class => static fn(
                ContentBuilderFactory $builderFactory,
                VariableResolver $variables,
                HasPrefixLoggerInterface $logger,
            ): TraceSourceFetcher => new TraceSourceFetcher(
                builderFactory: $builderFactory,
                variableResolver: $variables,
                logger: $logger->withPrefix('text-source'),
            ),
        ];
    }

    public function init(
        SourceFetcherBootloader $registry,
        SourceRegistryInterface $sourceRegistry,
        TraceSourceFactory $factory,
    ): void {
        $registry->register(TraceSourceFetcher::class);
        $sourceRegistry->register($factory);
    }
}
