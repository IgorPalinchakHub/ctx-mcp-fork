<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Service;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;

/**
 * Service for type inference and resolution
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
    ];

    /** @var array<string, array<string>> Common variable name type mappings */
    private array $commonVariableNameMappings = [
        'builder' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilder',
        'factory' => 'Butschster\ContextGenerator\Lib\Content\ContentBuilderFactory',
        'finder' => 'Butschster\ContextGenerator\Source\File\SymfonyFinder',
        'resolver' => 'Butschster\ContextGenerator\Lib\Variable\VariableResolver',
        'logger' => 'Psr\Log\LoggerInterface',
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

        // Try to infer based on patterns

        // For fluent interfaces, methods often return $this
        $fluentPrefixes = ['add', 'set', 'with', 'build', 'create', 'register', 'configure'];
        $methodNameLower = strtolower($methodName);
        foreach ($fluentPrefixes as $prefix) {
            if (strpos($methodNameLower, $prefix) === 0) {
                return $className; // Likely returns $this
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

        // Simple regex to extract @param type $name
        preg_match_all('/@param\s+([^\s]+)\s+\$([^\s]+)/', $docComment, $matches, PREG_SET_ORDER);

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
        // Simple regex to extract @return type
        if (preg_match('/@return\s+([^\s]+)/', $docComment, $matches)) {
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
        if (!empty($propertyName) && preg_match('/@var\s+([^\s]+)\s+\$' . preg_quote($propertyName, '/') . '/', $docComment, $matches)) {
            $typeString = $matches[1];
            return $this->extractClassTypeFromDocType($typeString);
        }

        // Then try for generic @var
        if (preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
            $typeString = $matches[1];
            return $this->extractClassTypeFromDocType($typeString);
        }

        return null;
    }
}
