<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XHProfTrace\Extraction\Writer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DetailedMethodListMarkdownWriter
{
    private array $sourceCodeCache = [];
    private array $filePathCache = [];
    private array $psr4Mappings;

    public function __construct(
        private readonly string $projectRoot = '',
        private readonly array $sourceDirectories = ['src', 'app'],
        private readonly ?array $psr4MappingsConfig = null,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        // Initialize PSR-4 mappings with defaults or provided config
        $this->psr4Mappings = $psr4MappingsConfig ?? ['App\\' => 'src/'];
    }

    public function write(
        array $extractedMethods,
        string $title = 'Detailed Methods Extracted from XHProf Call Tree',
        string $description = '',
        bool $groupByNamespace = true
    ): string {
        $content = "# {$title}\n\n";

        if (!empty($description)) {
            $content .= "{$description}\n\n";
        }

        if (empty($extractedMethods)) {
            $content .= "No methods were extracted from the call tree.\n";
            return $content;
        }

        // Add summary info
        $classCount = count($extractedMethods);
        $methodCount = 0;

        foreach ($extractedMethods as $methods) {
            $methodCount += count($methods);
        }

        $content .= "## Summary\n\n";
        $content .= "- Total classes/namespaces: {$classCount}\n";
        $content .= "- Total methods: {$methodCount}\n\n";

        if ($groupByNamespace) {
            $content .= $this->renderGroupedByNamespace($extractedMethods);
        } else {
            $content .= $this->renderAlphabetically($extractedMethods);
        }

        return $content;
    }

    private function renderGroupedByNamespace(array $extractedMethods): string
    {
        $content = "## Methods by Namespace\n\n";

        // Sort classes
        ksort($extractedMethods);

        // Group by namespace
        $namespaces = [];

        foreach ($extractedMethods as $class => $methods) {
            // Handle special case for global functions
            if ($class === 'Global Functions') {
                $namespaces['Global']['Functions'] = $methods;
                continue;
            }

            // Split by namespace
            $parts = explode('\\', $class);
            $className = array_pop($parts);
            $namespace = implode('\\', $parts);

            if (empty($namespace)) {
                $namespace = 'Global';
            }

            if (!isset($namespaces[$namespace])) {
                $namespaces[$namespace] = [];
            }

            $namespaces[$namespace][$className] = $methods;
        }

        // Sort namespaces
        ksort($namespaces);

        // Render each namespace
        foreach ($namespaces as $namespace => $classes) {
            $content .= "### {$namespace}\n\n";

            // Sort classes within namespace
            ksort($classes);

            foreach ($classes as $className => $methods) {
                // Special case for 'Functions' in Global namespace
                if ($namespace === 'Global' && $className === 'Functions') {
                    $content .= "#### Global Functions\n\n";
                    $fullClassName = '';
                } else {
                    $content .= "#### {$className}\n\n";
                    $fullClassName = $namespace === 'Global' ? $className : "{$namespace}\\{$className}";
                }

                // Sort methods
                sort($methods);

                foreach ($methods as $method) {
                    $content .= "##### `{$method}`\n\n";

                    // Try to fetch method implementation if not a global function
                    if (!empty($fullClassName)) {
                        $methodCode = $this->getMethodImplementation($fullClassName, $method);
                        if (!empty($methodCode)) {
                            $content .= "```php\n{$methodCode}\n```\n\n";
                        } else {
                            $content .= "*Method implementation not available*\n\n";
                            $this->logger->debug("Method implementation not found", [
                                'class' => $fullClassName,
                                'method' => $method,
                                'searchDirs' => $this->sourceDirectories,
                                'projectRoot' => $this->projectRoot
                            ]);
                        }
                    }
                }

                $content .= "\n";
            }
        }

        return $content;
    }

    private function renderAlphabetically(array $extractedMethods): string
    {
        $content = "## Methods by Class\n\n";

        // Sort classes
        ksort($extractedMethods);

        foreach ($extractedMethods as $class => $methods) {
            $content .= "### {$class}\n\n";

            // Sort methods
            sort($methods);

            foreach ($methods as $method) {
                $content .= "#### `{$method}`\n\n";

                // Try to fetch method implementation if not a global function
                if ($class !== 'Global Functions') {
                    $methodCode = $this->getMethodImplementation($class, $method);
                    if (!empty($methodCode)) {
                        $content .= "```php\n{$methodCode}\n```\n\n";
                    } else {
                        $content .= "*Method implementation not available*\n\n";
                    }
                }
            }

            $content .= "\n";
        }

        return $content;
    }

    private function getMethodImplementation(string $className, string $methodName): string
    {
        // Special case for closures
        if ($methodName === '{closure}') {
            return '';
        }

        // Check cache first
        $cacheKey = "{$className}::{$methodName}";
        if (isset($this->sourceCodeCache[$cacheKey])) {
            return $this->sourceCodeCache[$cacheKey];
        }

        // Try to find the class file
        $classPath = $this->findClassFile($className);
        if (empty($classPath)) {
            $this->logger->debug("Class file not found", ['class' => $className]);
            return '';
        }

        $this->logger->debug("Found class file, extracting method", [
            'class' => $className,
            'method' => $methodName,
            'path' => $classPath
        ]);

        // Read the class file
        $classContent = file_get_contents($classPath);
        if ($classContent === false) {
            $this->logger->debug("Failed to read class file", ['path' => $classPath]);
            return '';
        }

        // Try a more direct approach first, looking for the method declaration line
        $methodCode = $this->extractMethodByDirectSearch($classContent, $methodName);
        if (!empty($methodCode)) {
            // Cache result
            $this->sourceCodeCache[$cacheKey] = $methodCode;
            return $methodCode;
        }

        // Parse the file to get the method implementation using regex
        $methodCode = $this->extractMethodFromClassContent($classContent, $methodName);

        // Cache result even if empty
        $this->sourceCodeCache[$cacheKey] = $methodCode;

        return $methodCode;
    }

    private function extractMethodByDirectSearch(string $classContent, string $methodName): string
    {
        $lines = explode("\n", $classContent);
        $methodFound = false;
        $braceCount = 0;
        $methodCode = '';

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Look for the method declaration
            if (!$methodFound) {
                // Match lines like:
                // public function execute(UseCaseRequestDto $requestUseCase): CreateEmailResponse
                if (preg_match('/(?:public|protected|private)(?:\s+static)?\s+function\s+' . preg_quote($methodName, '/') . '\s*\(/', $line)) {
                    $methodFound = true;
                    $this->logger->debug("Found method declaration", ['line' => $i, 'content' => $line]);
                    $methodCode .= $line . "\n";
                    $braceCount += substr_count($line, '{');
                    $braceCount -= substr_count($line, '}');
                    continue;
                }
            }

            // If we found the method, keep collecting lines until we balance braces
            if ($methodFound) {
                $methodCode .= $line . "\n";
                $braceCount += substr_count($line, '{');
                $braceCount -= substr_count($line, '}');

                // If braces are balanced and we've seen at least one opening brace, we're done
                if ($braceCount === 0 && strpos($methodCode, '{') !== false) {
                    $this->logger->debug("Found complete method", ['lines' => $i - ($methodFound ? 1 : 0)]);
                    return trim($methodCode);
                }
            }
        }

        return '';
    }

    private function findClassFile(string $className): string
    {
        if (isset($this->filePathCache[$className])) {
            return $this->filePathCache[$className];
        }

        // Normalize project root path
        $rootPath = $this->projectRoot;
        if (empty($rootPath) || $rootPath === '.' || $rootPath === './') {
            $rootPath = getcwd();
        }

        // Use configured PSR-4 mappings
        $psr4Mappings = $this->psr4Mappings;

        // Try PSR-4 mapping first - this should be the most reliable method
        foreach ($psr4Mappings as $namespace => $directory) {
            if (strpos($className, $namespace) === 0) {
                $relativePath = substr($className, strlen($namespace));
                $relativePath = str_replace('\\', '/', $relativePath);
                $path = rtrim($rootPath, '/') . '/' . rtrim($directory, '/') . '/' . $relativePath . '.php';

                $this->logger->debug("Trying PSR-4 path", [
                    'class' => $className,
                    'namespace' => $namespace,
                    'directory' => $directory,
                    'relativePath' => $relativePath,
                    'fullPath' => $path
                ]);

                if (file_exists($path)) {
                    $this->filePathCache[$className] = $path;
                    $this->logger->debug("Found class file using PSR-4 mapping", ['class' => $className, 'path' => $path]);
                    return $path;
                }
            }
        }

        // If PSR-4 mapping didn't work, try direct file match
        $classBaseName = basename(str_replace('\\', '/', $className));

        // Try a recursive search as a last resort
        foreach ($this->sourceDirectories as $dir) {
            $baseDir = rtrim($this->projectRoot, '/') . '/' . $dir;
            if (!is_dir($baseDir)) {
                continue;
            }

            $searchPattern = $classBaseName . '.php';
            $this->logger->debug("Searching recursively for file", [
                'directory' => $baseDir,
                'pattern' => $searchPattern
            ]);

            $foundFiles = $this->findFileRecursively($baseDir, $searchPattern);

            // Filter the results to find the most likely match
            $bestMatches = [];
            foreach ($foundFiles as $file) {
                // Check if the file path contains parts of the namespace
                $namespaceParts = explode('\\', $className);
                $matchScore = 0;

                foreach ($namespaceParts as $part) {
                    if (strpos($file, '/' . $part . '/') !== false ||
                        strpos($file, '\\' . $part . '\\') !== false) {
                        $matchScore++;
                    }
                }

                $bestMatches[$file] = $matchScore;
            }

            // Sort by match score, highest first
            arsort($bestMatches);

            $this->logger->debug("Search results", [
                'matches' => $bestMatches
            ]);

            // Use the best match if any
            if (!empty($bestMatches)) {
                $bestMatch = array_key_first($bestMatches);
                $this->filePathCache[$className] = $bestMatch;
                $this->logger->debug("Using best match file", ['class' => $className, 'path' => $bestMatch]);
                return $bestMatch;
            }
        }

        // No implementation found
        $this->filePathCache[$className] = '';
        $this->logger->debug("Class file not found after all attempts", ['class' => $className]);
        return '';
    }

    private function findFileRecursively(string $directory, string $targetFile): array
    {
        $results = [];
        if (!is_dir($directory)) {
            return $results;
        }

        // Get path parts to check against directory structure
        $pathParts = explode('/', str_replace('\\', '/', $targetFile));
        $fileName = end($pathParts);

        // Search recursively for a file with the given name
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $fileName) {
                $this->logger->debug("Found potential class file", ['path' => $file->getPathname()]);
                $results[] = $file->getPathname();
            }
        }

        return $results;
    }

    private function extractMethodFromClassContent(string $classContent, string $methodName): string
    {
        // Debug method content
        $this->logger->debug("Attempting to extract method", [
            'method' => $methodName,
            'content_length' => strlen($classContent),
            'content_sample' => substr($classContent, 0, 200) . '...'
        ]);

        // First try: Method with specific visibility and optional static modifier
        $pattern = '/\s*(public|protected|private)(?:\s+static)?\s+function\s+'
            . preg_quote($methodName, '/')
            . '\s*\([^{]*\)(?:\s*:\s*[^{]+)?\s*\{((?:[^{}]|(?R))*)\}/s';

        if (preg_match($pattern, $classContent, $matches)) {
            $fullMethod = $matches[0];
            $this->logger->debug("Method found with first pattern", ['method' => $methodName]);
            return trim($fullMethod);
        }

        // Second try: More relaxed pattern for magic methods and constructors
        if (strpos($methodName, '__') === 0) {
            $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^{]*\)\s*\{((?:[^{}]|(?R))*)\}/s';

            if (preg_match($pattern, $classContent, $matches)) {
                $fullMethod = $matches[0];
                $this->logger->debug("Method found with magic method pattern", ['method' => $methodName]);
                return trim($fullMethod);
            }
        }

        // Third try: Most basic pattern - just try to match the method name and body
        $basicPattern = '/function\s+' . preg_quote($methodName, '/') . '.*?\{(.*?)\}/s';
        if (preg_match($basicPattern, $classContent, $matches)) {
            $this->logger->debug("Method found with basic pattern", ['method' => $methodName]);
            return 'function ' . $methodName . '(...) {' . "\n    " . trim($matches[1]) . "\n}";
        }

        // Last resort: manually look for method in the file
        $lines = explode("\n", $classContent);
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/function\s+' . preg_quote($methodName, '/') . '\s*\(/', $lines[$i])) {
                // Found the method declaration, now extract the full body
                $this->logger->debug("Method declaration found on line", ['line' => $i, 'content' => $lines[$i]]);

                // Extract the method body
                $methodBody = '';
                $braceLevel = 0;
                $startedMethod = false;

                for ($j = $i; $j < count($lines); $j++) {
                    $methodBody .= $lines[$j] . "\n";

                    // Count opening braces
                    $braceLevel += substr_count($lines[$j], '{');

                    // Start counting after we see the first opening brace
                    if ($braceLevel > 0) {
                        $startedMethod = true;
                    }

                    // Count closing braces
                    $braceLevel -= substr_count($lines[$j], '}');

                    // If we've balanced our braces and we've started the method, we're done
                    if ($startedMethod && $braceLevel === 0) {
                        $this->logger->debug("Method body extracted", ['length' => strlen($methodBody)]);
                        return trim($methodBody);
                    }
                }
            }
        }

        $this->logger->debug("Method not found in class content", [
            'method' => $methodName,
            'content_length' => strlen($classContent)
        ]);

        return '';
    }
}
