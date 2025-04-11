<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace;

use Butschster\ContextGenerator\Application\Bootloader\SourceFetcherBootloader;
use Butschster\ContextGenerator\Application\Logger\HasPrefixLoggerInterface;
use Butschster\ContextGenerator\DirectoriesInterface;
use Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory;
use Butschster\ContextGenerator\Source\Registry\SourceRegistryInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\FactoryInterface;

final class XHProfTraceSourceBootloader extends Bootloader
{
    public function defineSingletons(): array
    {
        return [
            XHProfTraceSourceFetcher::class => static fn(
                FactoryInterface $factory,
                DirectoriesInterface $dirs,
                ContentBuilderFactory $builderFactory,
                HasPrefixLoggerInterface $logger,
            ): XHProfTraceSourceFetcher => $factory->make(XHProfTraceSourceFetcher::class, [
                'basePath' => (string) $dirs->getRootPath(),
            ]),
        ];
    }

    public function init(
        SourceFetcherBootloader $registry,
        SourceRegistryInterface $sourceRegistry,
        XHProfTraceSourceFactory $factory,
    ): void {
        $registry->register(XHProfTraceSourceFetcher::class);
        $sourceRegistry->register($factory);
    }
}
