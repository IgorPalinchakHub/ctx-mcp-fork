<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

use Butschster\ContextGenerator\Lib\TreeBuilder\TreeViewConfig;
use Butschster\ContextGenerator\Source\Registry\AbstractSourceFactory;
use Butschster\ContextGenerator\Source\SourceInterface;

/**
 * Factory for creating TextSource instances
 */
final readonly class TraceSourceFactory extends AbstractSourceFactory
{
    #[\Override]
    public function getType(): string
    {
        return 'xdebug_trace';
    }

    #[\Override]
    public function create(array $config): SourceInterface
    {
        $this->logger?->debug('Creating Tree source', [
            'path' => $this->dirs->getRootPath(),
            'config' => $config,
        ]);


        if (!isset($config['sourcePaths'])) {
            throw new \RuntimeException('Tree source must have a "sourcePaths" property');
        }

        $sourcePaths = $config['sourcePaths'];
        if (!\is_string($sourcePaths) && !\is_array($sourcePaths)) {
            throw new \RuntimeException('"sourcePaths" must be a string or array in source');
        }

        $sourcePaths = \is_string($sourcePaths) ? [$sourcePaths] : $sourcePaths;
        $sourcePaths = \array_map(
            fn(string $sourcePaths): string => (string) $this->dirs->getRootPath()->join($sourcePaths),
            $sourcePaths,
        );

        $filePattern = $config['filePattern'] ?? '*';

        // Convert notPath
        $notPath = $config['notPath'] ?? [];

        if (!isset($config['sourceTracePath'])) {
            if (!\is_string($config['sourceTracePath']) || empty($config['sourceTracePath'])) {
                throw new \RuntimeException('sourceTracePath must be a string');
            }
        }

        $sourceTracePath = $config['sourceTracePath'];

        return new TraceSource(
            sourcePaths: $sourcePaths,
            sourceTracePath: $sourceTracePath,
            description: $config['description'] ?? '',
            filePattern: $filePattern,
            notPath: $notPath,
            path: $config['path'] ?? [],
            contains: $config['contains'] ?? [],
            notContains: $config['notContains'] ?? [],
            renderFormat: $config['renderFormat'] ?? 'ascii',
            treeView: new TreeViewConfig(
                showSize: $config['showSize'] ?? false,
                showLastModified: $config['showLastModified'] ?? false,
                showCharCount: $config['showCharCount'] ?? false,
                includeFiles: $config['includeFiles'] ?? true,
                maxDepth: $config['maxDepth'] ?? 0,
                dirContext: $config['dirContext'] ?? [],
            ),
            tags: $config['tags'] ?? [],
        );
    }
}
