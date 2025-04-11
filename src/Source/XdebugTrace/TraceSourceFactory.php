<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace;

use Butschster\ContextGenerator\Source\Registry\AbstractSourceFactory;
use Butschster\ContextGenerator\Source\SourceInterface;
use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model\TraceSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Factory for creating TraceSource instances
 */
final readonly class TraceSourceFactory extends AbstractSourceFactory
{
    /**
     * Get the source type identifier
     */
    #[\Override]
    public function getType(): string
    {
        return 'trace';
    }

    /**
     * Create a TraceSource from configuration
     */
    #[\Override]
    public function create(array $config): SourceInterface
    {
        $logger = $this->logger ?? new NullLogger();

        $logger->debug('Creating Trace source', [
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
                throw new \InvalidArgumentException('"sourcePaths" must be a string or array in trace source');
            }

            // Normalize and resolve source paths
            $sourcePaths = \is_string($sourcePaths) ? [$sourcePaths] : $sourcePaths;
            $sourcePaths = \array_map(
                fn(string $sourcePath): string => (string) $this->dirs->getRootPath()->join($sourcePath),
                $sourcePaths,
            );

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

            // If startFile is provided, ensure it exists and set it in options
            if ($startFile !== null) {
                $startFilePath = (string)$startFile;
                if (!file_exists($startFilePath)) {
                    $logger->warning("Start file does not exist: {$startFilePath}. Will attempt to locate during analysis.");
                }
                $options['startFile'] = $startFilePath;
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
                $logger->warning('Class name is required for trace analysis (specify in "class" property)');
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

            // Setup namespace mappings
            if (isset($config['namespaceMappings']) && is_array($config['namespaceMappings'])) {
                $options['namespaceMappings'] = [];

                foreach ($config['namespaceMappings'] as $namespace => $path) {
                    $resolvedPath = (string) $this->dirs->getRootPath()->join($path);
                    $options['namespaceMappings'][$namespace] = $resolvedPath;
                }
            }

            // Setup search directories
            if (isset($config['searchDirectories']) && is_array($config['searchDirectories'])) {
                $options['searchDirectories'] = [];

                foreach ($config['searchDirectories'] as $dir) {
                    $resolvedPath = (string) $this->dirs->getRootPath()->join($dir);
                    $options['searchDirectories'][] = $resolvedPath;
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
            return new TraceSource(
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
