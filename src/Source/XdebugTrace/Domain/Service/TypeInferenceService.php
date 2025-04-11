<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;

/**
 * Enhanced service for type inference and resolution
 */
class TypeInferenceService
{
    /** @var array<string, string> Known framework method return types */
    private array $knownFrameworkTypes = [
        // ContentBuilder fluent interface methods
        'Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory::create' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addTitle' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addDescription' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addCodeBlock' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addBlock' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'Butschster\ContextGenerator\Lib\Content\ContentBuilder::addSeparator' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',

        // Finder methods
        'Butschster\ContextGenerator\Source\File\SymfonyFinder::find' => 'Butschster\ContextGenerator\Source\File\TreeResult',

        // Variable resolver
        'Butschster\ContextGenerator\Lib\Variable\VariableResolver::resolve' => 'string',

        // Common Symfony components
        'Symfony\Component\HttpFoundation\Request::createFromGlobals' => 'Symfony\Component\HttpFoundation\Request',
        'Symfony\Component\HttpFoundation\Response::create' => 'Symfony\Component\HttpFoundation\Response',
        'Symfony\Component\HttpFoundation\JsonResponse::create' => 'Symfony\Component\HttpFoundation\JsonResponse',

        // PSR Interfaces
        'Psr\Http\Message\ResponseInterface::withHeader' => 'Psr\Http\Message\ResponseInterface',
        'Psr\Http\Message\RequestInterface::withHeader' => 'Psr\Http\Message\RequestInterface',
        'Psr\Http\Message\ServerRequestInterface::withAttribute' => 'Psr\Http\Message\ServerRequestInterface',

        // PSR Container
        'Psr\Container\ContainerInterface::get' => 'mixed', // Container can return any type
    ];

    /** @var array<string, string> Common variable name type mappings */
    private array $commonVariableNameMappings = [
        'builder' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'factory' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory',
        'finder' => 'Butschster\ContextGenerator\Source\File\SymfonyFinder',
        'resolver' => 'Butschster\ContextGenerator\Lib\Variable\VariableResolver',
        'logger' => 'Psr\Log\LoggerInterface',
        'container' => 'Psr\Container\ContainerInterface',
        'request' => 'Psr\Http\Message\ServerRequestInterface',
        'response' => 'Psr\Http\Message\ResponseInterface',
    ];

    /** @var array<string, string> Common container type patterns for generic collections */
    private array $containerPatterns = [
        'array' => 'array',
        'collection' => 'Collection',
        'iterator' => 'Iterator',
        'list' => 'array',
        'map' => 'array',
        'set' => 'array',
        'iterable' => 'iterable',
    ];

    /** @var array<string, array<string, string>> Common method patterns that return specific types */
    private array $methodPatterns = [
        // Common getter patterns
        '/^get([A-Z].*)/' => [
            'context' => 'Context',
            'repository' => 'Repository',
            'factory' => 'Factory',
            'service' => 'Service',
            'manager' => 'Manager',
            'provider' => 'Provider',
            'handler' => 'Handler',
            'middleware' => 'Middleware',
            'controller' => 'Controller',
            'logger' => 'Logger',
            'builder' => 'Builder',
            'client' => 'Client',
            'adapter' => 'Adapter',
            'storage' => 'Storage',
            'generator' => 'Generator',
            'validator' => 'Validator'
        ],

        // Common finder patterns
        '/^find([A-Z].*)/' => [
            'entity' => 'Entity',
            'model' => 'Model',
            'dto' => 'DTO',
        ]
    ];

    /**
     * @param TypeRepository $typeRepository Repository for storing and retrieving type information
     */
    public function __construct(
        private TypeRepository $typeRepository
    ) {
        // Register known framework types
        foreach ($this->knownFrameworkTypes as $methodKey => $returnType) {
            list($className, $methodName) = explode('::', $methodKey);
            $this->typeRepository->setMethodReturnType($className, $methodName, $returnType);
        }
    }

    /**
     * Register additional known framework types
     *
     * @param array<string, string> $types Method signatures mapped to return types
     */
    public function registerKnownFrameworkTypes(array $types): void
    {
        foreach ($types as $methodKey => $returnType) {
            list($className, $methodName) = explode('::', $methodKey);
            $this->typeRepository->setMethodReturnType($className, $methodName, $returnType);
            $this->knownFrameworkTypes[$methodKey] = $returnType;
        }
    }

    /**
     * Infer type of a variable based on its name
     */
    public function inferTypeFromVariableName(string $variableName): ?string
    {
        foreach ($this->commonVariableNameMappings as $pattern => $className) {
            if (strpos($variableName, $pattern) !== false) {
                return $className;
            }
        }

        return null;
    }

    /**
     * Add common variable name mappings
     *
     * @param array<string, string> $mappings Variable name patterns mapped to class names
     */
    public function addCommonVariableNameMappings(array $mappings): void
    {
        $this->commonVariableNameMappings = array_merge($this->commonVariableNameMappings, $mappings);
    }

    /**
     * Extract generic type parameters from a type string
     *
     * @param string $typeString Type string potentially with generic parameters
     * @return array{0: string, 1: string|null} Base type and inner type (if any)
     */
    public function extractGenericTypes(string $typeString): array
    {
        // Check for generic/template type notation: Collection<User>
        if (preg_match('/^(.*?)<(.*?)>$/', $typeString, $matches)) {
            return [trim($matches[1]), trim($matches[2])];
        }

        // Check for array notation: User[]
        if (preg_match('/^(.*?)\[\]$/', $typeString, $matches)) {
            return ['array', trim($matches[1])];
        }

        // No generic type
        return [$typeString, null];
    }

    /**
     * Infer container element type for collection methods
     *
     * @param string $methodName Method name
     * @param string $containerType Container type
     * @return string|null Inferred type or null if can't infer
     */
    public function inferContainerTypes(string $methodName, string $containerType): ?string
    {
        [$baseType, $innerType] = $this->extractGenericTypes($containerType);

        // If no inner type, can't infer element type
        if (!$innerType) {
            return null;
        }

        // Common collection access patterns that return the inner type
        if (in_array($methodName, ['first', 'last', 'get', 'find', 'findOrFail', 'current'])) {
            return $innerType;
        }

        // Methods that return a new collection of the same type
        if (in_array($methodName, ['map', 'filter', 'each', 'reject', 'sort', 'sortBy', 'slice', 'take'])) {
            return $containerType;
        }

        // Count-like methods return integers
        if (in_array($methodName, ['count', 'size', 'length'])) {
            return 'int';
        }

        // Boolean return types
        if (in_array($methodName, ['contains', 'exists', 'has', 'isEmpty', 'isNotEmpty'])) {
            return 'bool';
        }

        return null;
    }

    /**
     * Infer the return type of a method
     */
    public function inferMethodReturnType(string $className, string $methodName): ?string
    {
        // First check in the repository
        $returnType = $this->typeRepository->getMethodReturnType($className, $methodName);
        if ($returnType) {
            return $returnType;
        }

        // Check known framework types
        $methodKey = "{$className}::{$methodName}";
        if (isset($this->knownFrameworkTypes[$methodKey])) {
            return $this->knownFrameworkTypes[$methodKey];
        }

        // Check for container methods (collections, iterators, etc.)
        foreach ($this->containerPatterns as $pattern => $containerType) {
            if (strpos(strtolower($className), strtolower($pattern)) !== false) {
                $elementType = $this->inferContainerTypes($methodName, $className);
                if ($elementType) {
                    return $elementType;
                }
            }
        }

        // Try to infer based on method name patterns

        // For fluent interfaces, methods often return $this
        $fluentPrefixes = ['add', 'set', 'with', 'build', 'create', 'register', 'configure'];
        $methodNameLower = strtolower($methodName);
        foreach ($fluentPrefixes as $prefix) {
            if (strpos($methodNameLower, $prefix) === 0) {
                return $className; // Likely returns $this
            }
        }

        // Check method patterns for common conventions
        foreach ($this->methodPatterns as $pattern => $suffixMap) {
            if (preg_match($pattern, $methodName, $matches) && isset($matches[1])) {
                $entityName = $matches[1]; // The part after 'get', 'find', etc.

                // Check if suffix matches known entity types
                foreach ($suffixMap as $suffix => $type) {
                    if (strpos(strtolower($entityName), strtolower($suffix)) !== false) {
                        // Try to construct a fully qualified class name
                        $nameParts = explode('\\', $className);
                        array_pop($nameParts); // Remove last part (current class name)

                        // Create namespace + entity name
                        $namespaceName = implode('\\', $nameParts);
                        $possibleClassName = $namespaceName . '\\' . $entityName;

                        return $possibleClassName;
                    }
                }
            }
        }

        // Factory methods often return a specific type
        if (strpos($className, 'Factory') !== false || strpos($className, 'Builder') !== false) {
            $baseName = str_replace(['Factory', 'Builder'], '', $className);
            if (preg_match('/^(create|build|make|get)([A-Z].*?)$/', $methodName)) {
                $namespaceParts = explode('\\', $className);
                array_pop($namespaceParts); // Remove the last part (class name)
                $namespace = implode('\\', $namespaceParts);
                return $namespace . '\\' . $baseName;
            }
        }

        // For factory/singleton methods, often return an instance of their class
        if (in_array($methodName, ['create', 'getInstance', 'instance', 'factory', 'build', 'make'])) {
            return $className;
        }

        // Try to guess boolean return types
        if (preg_match('/^(is|has|can|should|will|does|exists|contains|supports)/i', $methodName)) {
            return 'bool';
        }

        // Common method suffixes
        $intReturningSuffixes = ['Count', 'Size', 'Length', 'Position', 'Index'];
        foreach ($intReturningSuffixes as $suffix) {
            if (str_ends_with($methodName, $suffix)) {
                return 'int';
            }
        }

        return null;
    }

    /**
     * Extract class type from PHPDoc type string
     */
    public function extractClassTypeFromDocType(string $typeString): ?string
    {
        // Handle union types (only take the first class type)
        $typeComponents = explode('|', $typeString);
        foreach ($typeComponents as $component) {
            // Clean up type (remove array markers, nullable markers)
            $cleanType = trim($component, '?[]<>');

            // Skip primitive types
            if (in_array(strtolower($cleanType), ['string', 'int', 'bool', 'array', 'float', 'object', 'mixed', 'null'])) {
                continue;
            }

            // If it has a namespace separator or starts with uppercase, likely a class
            if (strpos($cleanType, '\\') !== false || ctype_upper($cleanType[0] ?? '')) {
                return $cleanType;
            }
        }
        return null;
    }

    /**
     * Extract parameter types from PHPDoc comment
     *
     * @param string $docComment
     * @return array<string, string> Map of parameter names to types
     */
    public function extractParamTypesFromDocComment(string $docComment): array
    {
        $paramTypes = [];

        // Enhanced regex to extract @param type $name [description]
        preg_match_all('/@param\s+([^\s]+)\s+\$([^\s]+)(?:\s+.*)?/m', $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $typeString = $match[1];
            $paramName = $match[2];

            // Extract class type
            $classType = $this->extractClassTypeFromDocType($typeString);
            if ($classType) {
                $paramTypes[$paramName] = $classType;
            }
        }

        return $paramTypes;
    }

    /**
     * Extract return type from PHPDoc comment
     */
    public function extractReturnTypeFromDocComment(string $docComment): ?string
    {
        // Enhanced regex to extract @return type [description]
        if (preg_match('/@return\s+([^\s]+)(?:\s+.*)?/m', $docComment, $matches)) {
            $typeString = $matches[1];
            return $this->extractClassTypeFromDocType($typeString);
        }
        return null;
    }

    /**
     * Extract property type from PHPDoc comment
     */
    public function extractPropertyTypeFromDocComment(string $docComment, string $propertyName = ''): ?string
    {
        // First try to match @var type for specific property
        if (!empty($propertyName) && preg_match('/@var\s+([^\s]+)\s+\$' . preg_quote($propertyName, '/') . '(?:\s+.*)?/m', $docComment, $matches)) {
            $typeString = $matches[1];
            return $this->extractClassTypeFromDocType($typeString);
        }

        // Then try for generic @var
        if (preg_match('/@var\s+([^\s]+)(?:\s+.*)?/m', $docComment, $matches)) {
            $typeString = $matches[1];
            return $this->extractClassTypeFromDocType($typeString);
        }

        return null;
    }

    /**
     * Infer possible types from method name and parent class/interface
     *
     * @param string $className Parent class name
     * @param string $methodName Method name
     * @return string|null Inferred return type
     */
    public function inferTypeFromMethodNameAndParent(string $className, string $methodName): ?string
    {
        // Check for repository pattern
        if (str_ends_with($className, 'Repository') || str_contains($className, 'Repository\\')) {
            // Try to extract entity name from class name
            $repositoryName = basename(str_replace('\\', '/', $className));
            $entityName = preg_replace('/Repository$/', '', $repositoryName);

            // Common repository methods that return entities or collections
            if (in_array($methodName, ['find', 'findOneBy', 'get', 'findById', 'load'])) {
                // Find parent namespace
                $namespaceParts = explode('\\', $className);
                array_pop($namespaceParts); // Remove the Repository class name

                // Replace "Repository" namespace with "Entity" if exists
                $lastNsIndex = count($namespaceParts) - 1;
                if ($lastNsIndex >= 0 && $namespaceParts[$lastNsIndex] === 'Repository') {
                    $namespaceParts[$lastNsIndex] = 'Entity';
                }

                $namespace = implode('\\', $namespaceParts);
                return $namespace . '\\' . $entityName;
            }

            // Methods that return collections
            if (in_array($methodName, ['findAll', 'findBy', 'getAll', 'list'])) {
                return 'array';
            }
        }

        // Check for factory pattern
        if (str_ends_with($className, 'Factory')) {
            $factoryName = basename(str_replace('\\', '/', $className));
            $targetName = preg_replace('/Factory$/', '', $factoryName);

            // Common factory methods
            if (preg_match('/^create|make|build|get/', $methodName)) {
                // Find parent namespace
                $namespaceParts = explode('\\', $className);
                array_pop($namespaceParts); // Remove the Factory class name
                $namespace = implode('\\', $namespaceParts);

                return $namespace . '\\' . $targetName;
            }
        }

        // Check for service pattern
        if (str_ends_with($className, 'Service')) {
            // Services often return DTOs
            if (preg_match('/^get([A-Z][a-zA-Z0-9]*)/', $methodName, $matches)) {
                $dtoName = $matches[1];

                // Find parent namespace
                $namespaceParts = explode('\\', $className);
                array_pop($namespaceParts); // Remove the Service class name

                // Check for DTO or Model namespace
                $baseNamespace = implode('\\', $namespaceParts);
                $possibleDtoNamespace = $baseNamespace . '\\DTO';
                $possibleModelNamespace = $baseNamespace . '\\Model';

                if (class_exists($possibleDtoNamespace . '\\' . $dtoName)) {
                    return $possibleDtoNamespace . '\\' . $dtoName;
                }

                if (class_exists($possibleModelNamespace . '\\' . $dtoName)) {
                    return $possibleModelNamespace . '\\' . $dtoName;
                }

                // If specific DTO class not found, return generic DTO namespace
                return $possibleDtoNamespace . '\\' . $dtoName;
            }
        }

        return null;
    }

    /**
     * Register additional container patterns
     *
     * @param array<string, string> $patterns Pattern map from collection name pattern to container type
     */
    public function registerContainerPatterns(array $patterns): void
    {
        $this->containerPatterns = array_merge($this->containerPatterns, $patterns);
    }

    /**
     * Register additional method patterns
     *
     * @param array<string, array<string, string>> $patterns Pattern map
     */
    public function registerMethodPatterns(array $patterns): void
    {
        $this->methodPatterns = array_merge($this->methodPatterns, $patterns);
    }
}
