<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace;

use Butschster\ContextGenerator\Source\Registry\AbstractSourceFactory;
use Butschster\ContextGenerator\Source\SourceInterface;

final readonly class XHProfTraceSourceFactory extends AbstractSourceFactory
{
    public function getType(): string
    {
        return 'xhprof_trace';
    }

    public function create(array $config): SourceInterface
    {
        $this->logger?->debug('Creating XHProf trace source', [
            'path' => $this->dirs->getRootPath(),
            'config' => $config,
        ]);

        if (!isset($config['sourcePaths'])) {
            throw new \InvalidArgumentException('XHProf trace source must have a "sourcePaths" property');
        }

        $sourcePaths = $config['sourcePaths'];
        if (!\is_string($sourcePaths) && !\is_array($sourcePaths)) {
            throw new \InvalidArgumentException('"sourcePaths" must be a string or array in source');
        }

        $sourcePaths = \is_string($sourcePaths) ? [$sourcePaths] : $sourcePaths;
        $sourcePaths = \array_map(
            fn(string $sourcePath): string => (string) $this->dirs->getRootPath()->join($sourcePath),
            $sourcePaths,
        );

        $options = $config['options'] ?? [];

        // Map common configuration options to the options array
        $optionMappings = [
            'maxDepth', 'showMemory', 'showCpuTime', 'title',
            'showHottestFunctions', 'hottestFunctionsCount'
        ];

        foreach ($optionMappings as $option) {
            if (isset($config[$option])) {
                $options[$option] = $config[$option];
            }
        }

        // Process method extraction configuration if present
        if (isset($config['methodExtraction'])) {
            $options['methodExtraction'] = $config['methodExtraction'];
        }

        return new XHProfTraceSource(
            sourcePaths: $sourcePaths,
            description: $config['description'] ?? '',
            filePattern: $config['filePattern'] ?? '*.json',
            notPath: $config['notPath'] ?? [],
            path: $config['path'] ?? [],
            contains: $config['contains'] ?? [],
            notContains: $config['notContains'] ?? [],
            renderFormat: $config['renderFormat'] ?? 'markdown',
            tags: $config['tags'] ?? [],
            options: $options,
        );
    }
}
