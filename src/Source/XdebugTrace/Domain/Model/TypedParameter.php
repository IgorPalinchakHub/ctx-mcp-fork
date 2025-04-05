<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model;

/**
 * Represents a typed method parameter with value context
 */
class TypedParameter
{
    /**
     * @param string $name Parameter name
     * @param string|null $type Parameter type
     * @param mixed $value Parameter value
     * @param string|null $sourceVariable The source variable name if the parameter came from a variable
     * @param bool $hasDefaultValue Whether the parameter has a default value
     * @param mixed $defaultValue Default parameter value
     */
    public function __construct(
        private string $name,
        private ?string $type = null,
        private $value = null,
        private ?string $sourceVariable = null,
        private bool $hasDefaultValue = false,
        private $defaultValue = null
    ) {}

    /**
     * Get parameter name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get parameter type
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * Set parameter type
     */
    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /**
     * Get parameter value
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get parameter value as a string representation
     */
    public function getValueAsString(): string
    {
        $value = $this->value;

        if (is_array($value)) {
            // For arrays, show a simplified representation
            return '[' . (count($value) > 0 ? '...' : '') . ']';
        }

        if (is_object($value)) {
            // For objects, show class name
            return get_class($value) . ' instance';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            // For strings, add quotes
            return "'{$value}'";
        }

        if (is_null($value)) {
            return 'null';
        }

        // For other types, just convert to string
        return (string)$value;
    }

    /**
     * Set parameter value
     *
     * @param mixed $value
     */
    public function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * Get source variable name
     */
    public function getSourceVariable(): ?string
    {
        return $this->sourceVariable;
    }

    /**
     * Set source variable name
     */
    public function setSourceVariable(?string $sourceVariable): void
    {
        $this->sourceVariable = $sourceVariable;
    }

    /**
     * Check if parameter has source variable info
     */
    public function hasSourceVariable(): bool
    {
        return $this->sourceVariable !== null;
    }

    /**
     * Check if parameter has a default value
     */
    public function hasDefaultValue(): bool
    {
        return $this->hasDefaultValue;
    }

    /**
     * Get default value
     *
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * Check if parameter has a type
     */
    public function hasType(): bool
    {
        return $this->type !== null;
    }

    /**
     * Format the full parameter representation
     */
    public function getFormattedString(): string
    {
        $result = '$' . $this->name;

        if ($this->hasType()) {
            $result .= ': ' . $this->type;
        }

        return $result;
    }
}
