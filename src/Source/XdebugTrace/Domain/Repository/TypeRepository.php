<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Repository;

/**
 * Repository interface for storing and retrieving type information
 */
interface TypeRepository
{
    /**
     * Set a known method return type
     */
    public function setMethodReturnType(string $className, string $methodName, string $returnType): void;

    /**
     * Get a known method return type
     */
    public function getMethodReturnType(string $className, string $methodName): ?string;

    /**
     * Set a known property type
     */
    public function setPropertyType(string $className, string $propertyName, string $type): void;

    /**
     * Get a known property type
     */
    public function getPropertyType(string $className, string $propertyName): ?string;

    /**
     * Store method parameter information
     *
     * @param array<string, array> $params Parameter data indexed by parameter name
     */
    public function storeMethodParams(string $className, string $methodName, array $params): void;

    /**
     * Get method parameter information
     *
     * @return array<string, array> Parameter data indexed by parameter name
     */
    public function getMethodParams(string $className, string $methodName): array;

    /**
     * Track types for variables in a particular context (method)
     */
    public function trackVariableType(string $className, string $methodName, string $variableName, string $type): void;

    /**
     * Get tracked variable type in a context
     */
    public function getVariableType(string $className, string $methodName, string $variableName): ?string;

    /**
     * Check if all type information has been collected for a class
     */
    public function hasCompleteTypeInfo(string $className): bool;

    /**
     * Mark a class as having complete type information
     */
    public function markTypeInfoComplete(string $className): void;
}
