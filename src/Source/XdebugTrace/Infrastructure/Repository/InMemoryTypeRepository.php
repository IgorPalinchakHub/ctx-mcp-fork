<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Infrastructure\Repository;

use Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository\TypeRepository;

/**
 * In-memory implementation of the TypeRepository interface
 */
class InMemoryTypeRepository implements TypeRepository
{
    /** @var array<string, array<string, string>> Method return types by class and method name */
    private array $methodReturnTypes = [];

    /** @var array<string, array<string, string>> Property types by class and property name */
    private array $propertyTypes = [];

    /** @var array<string, array<string, array>> Method parameters by class and method name */
    private array $methodParams = [];

    /** @var array<string, array<string, array<string, string>>> Variable types by class, method, and variable name */
    private array $variableTypes = [];

    /** @var array<string, bool> Tracking which classes have complete type information */
    private array $completeTypeInfo = [];

    /**
     * Set a known method return type
     */
    public function setMethodReturnType(string $className, string $methodName, string $returnType): void
    {
        if (!isset($this->methodReturnTypes[$className])) {
            $this->methodReturnTypes[$className] = [];
        }
        $this->methodReturnTypes[$className][$methodName] = $returnType;
    }

    /**
     * Get a known method return type
     */
    public function getMethodReturnType(string $className, string $methodName): ?string
    {
        return $this->methodReturnTypes[$className][$methodName] ?? null;
    }

    /**
     * Set a known property type
     */
    public function setPropertyType(string $className, string $propertyName, string $type): void
    {
        if (!isset($this->propertyTypes[$className])) {
            $this->propertyTypes[$className] = [];
        }
        $this->propertyTypes[$className][$propertyName] = $type;
    }

    /**
     * Get a known property type
     */
    public function getPropertyType(string $className, string $propertyName): ?string
    {
        return $this->propertyTypes[$className][$propertyName] ?? null;
    }

    /**
     * Store method parameter information
     */
    public function storeMethodParams(string $className, string $methodName, array $params): void
    {
        if (!isset($this->methodParams[$className])) {
            $this->methodParams[$className] = [];
        }
        $this->methodParams[$className][$methodName] = $params;
    }

    /**
     * Get method parameter information
     */
    public function getMethodParams(string $className, string $methodName): array
    {
        return $this->methodParams[$className][$methodName] ?? [];
    }

    /**
     * Track types for variables in a particular context (method)
     */
    public function trackVariableType(string $className, string $methodName, string $variableName, string $type): void
    {
        if (!isset($this->variableTypes[$className])) {
            $this->variableTypes[$className] = [];
        }

        if (!isset($this->variableTypes[$className][$methodName])) {
            $this->variableTypes[$className][$methodName] = [];
        }

        $this->variableTypes[$className][$methodName][$variableName] = $type;
    }

    /**
     * Get tracked variable type in a context
     */
    public function getVariableType(string $className, string $methodName, string $variableName): ?string
    {
        return $this->variableTypes[$className][$methodName][$variableName] ?? null;
    }

    /**
     * Check if all type information has been collected for a class
     */
    public function hasCompleteTypeInfo(string $className): bool
    {
        return isset($this->completeTypeInfo[$className]) && $this->completeTypeInfo[$className] === true;
    }

    /**
     * Mark a class as having complete type information
     */
    public function markTypeInfoComplete(string $className): void
    {
        $this->completeTypeInfo[$className] = true;
    }

    /**
     * Get all classes with known method return types
     *
     * @return array<string>
     */
    public function getAllClasses(): array
    {
        return array_keys($this->methodReturnTypes);
    }

    /**
     * Get all method return types
     *
     * @return array<string, array<string, string>>
     */
    public function getAllMethodReturnTypes(): array
    {
        return $this->methodReturnTypes;
    }

    /**
     * Get all property types
     *
     * @return array<string, array<string, string>>
     */
    public function getAllPropertyTypes(): array
    {
        return $this->propertyTypes;
    }
}
