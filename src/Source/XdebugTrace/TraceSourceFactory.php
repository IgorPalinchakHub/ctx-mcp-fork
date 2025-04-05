<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

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
        return 'trace';
    }

    #[\Override]
    public function create(array $config): SourceInterface
    {
        $this->logger?->debug('Creating Trace source', [
            'path' => $this->dirs->getRootPath(),
            'config' => $config,
        ]);

        try {
            // Validate required source paths
            if (!isset($config['sourcePaths'])) {
                throw new \InvalidArgumentException('Trace source must have a "sourcePaths" property');
            }

            $sourcePaths = $config['sourcePaths'];
            if (!\is_string($sourcePaths) && !\is_array($sourcePaths)) {
                throw new \InvalidArgumentException('"sourcePaths" must be a string or array in source');
            }

            // Normalize and resolve source paths
            $sourcePaths = \is_string($sourcePaths) ? [$sourcePaths] : $sourcePaths;
            $sourcePaths = \array_map(
                fn(string $sourcePath): string => (string) $this->dirs->getRootPath()->join($sourcePath),
                $sourcePaths,
            );

            // Validate source paths exist
            foreach ($sourcePaths as $path) {
                if (!file_exists($path)) {
                    throw new \InvalidArgumentException("Source path does not exist: {$path}");
                }
            }

            // Get file pattern and notPath
            $filePattern = $config['filePattern'] ?? '*.php';
            $notPath = $config['notPath'] ?? [];

            // Extract options from config
            $options = $config['options'] ?? [];

            // Process startFile (required for trace analysis)
            $startFile = null;

            // Check all possible sources for startFile
            if (isset($options['startFile'])) {
                $startFile = $this->dirs->getRootPath()->join($options['startFile']);
            } elseif (isset($config['startFile'])) {
                $startFile = $this->dirs->getRootPath()->join($config['startFile']);
            } elseif (isset($config['sourceTracePath'])) {
                if (!\is_string($config['sourceTracePath'])) {
                    throw new \InvalidArgumentException('sourceTracePath must be a string');
                }
                $startFile = $this->dirs->getRootPath()->join($config['sourceTracePath']);
            }

            // Validate startFile if provided
            if ($startFile !== null) {
                if (!$startFile->exists()) {
                    throw new \InvalidArgumentException("Start file does not exist: {$startFile}");
                }
                $options['startFile'] = $startFile;
            }

            // Check for class name (required for trace analysis)
            $className = null;
            if (isset($options['class'])) {
                $className = $options['class'];
            } elseif (isset($config['class'])) {
                $className = $config['class'];
                $options['class'] = $className;
            }

            if (empty($className)) {
                throw new \InvalidArgumentException('Class name is required for trace analysis (specify in "class" property)');
            }

            // Set method name (defaulting to "fetch" if not provided)
            if (!isset($options['method']) && isset($config['method'])) {
                $options['method'] = $config['method'];
            }

            // Set output file path (resolve if relative)
            if (!isset($options['outputFile']) && isset($config['outputFile'])) {
                $outputFilePath = $config['outputFile'];
                if (!str_starts_with($outputFilePath, '/')) {
                    $outputFilePath = (string) $this->dirs->getRootPath()->join($outputFilePath);
                }
                $options['outputFile'] = $outputFilePath;
            }

            // Ensure we have output directory created
            if (isset($options['outputFile'])) {
                $outputDir = dirname($options['outputFile']);
                if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
                    throw new \InvalidArgumentException("Unable to create output directory: {$outputDir}");
                }
            }

            // Copy configuration properties into options
            $optionProperties = [
                'showSize', 'showLastModified', 'showCharCount', 'includeFiles',
                'maxDepth', 'dirContext', 'skipVendorDir', 'skipFrameworks',
                'skipSingletonMethods', 'skipConstructors', 'skipInvokeMethods',
                'skipDirs', 'skipClasses', 'skipMethods', 'singletonMethodPatterns'
            ];

            foreach ($optionProperties as $property) {
                if (!isset($options[$property]) && isset($config[$property])) {
                    $options[$property] = $config[$property];
                }
            }

            // Validate maxDepth is a positive integer if provided
            if (isset($options['maxDepth']) && (!is_int($options['maxDepth']) || $options['maxDepth'] < 0)) {
                throw new \InvalidArgumentException('maxDepth must be a positive integer');
            }

            // Validate skipDirs, skipClasses, skipMethods are arrays if provided
            $arrayOptions = ['skipDirs', 'skipClasses', 'skipMethods', 'singletonMethodPatterns'];
            foreach ($arrayOptions as $option) {
                if (isset($options[$option]) && !is_array($options[$option])) {
                    throw new \InvalidArgumentException("{$option} must be an array");
                }
            }

            // Create and return the TraceSource
            return new \Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TraceSource(
                sourcePaths: $sourcePaths,
                description: $config['description'] ?? '',
                content: $config['content'] ?? '',
                options: $options,
                filePattern: $filePattern,
                notPath: $notPath,
                path: $config['path'] ?? [],
                contains: $config['contains'] ?? [],
                notContains: $config['notContains'] ?? [],
                renderFormat: $config['renderFormat'] ?? 'markdown',
                tag: $config['tag'] ?? 'trace',
                tags: $config['tags'] ?? [],
            );
        } catch (\Throwable $e) {
            // Wrap specific exceptions in a common factory creation exception
            throw SourceCreationException::forSourceType(
                'Trace',
                $e->getMessage(),
                $config,
                $e
            );
        }
    }
}
