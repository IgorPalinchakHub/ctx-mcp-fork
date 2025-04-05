<?php

declare(strict_types=1);

namespace Butschster\ContextGenerator\Source\XdebugTrace\Domain\Model;

/**
 * Represents a method call in the call stack
 */
class MethodCall
{
    /**
     * @param string $className The class containing the method
     * @param string $methodName The name of the method
     * @param bool $isStatic Whether the method is called statically
     * @param TypedParameter[] $parameters Method parameters
     * @param string|null $returnType Method return type
     * @param CallContext $callContext Context information about the call
     * @param MethodCall[] $childCalls Calls made from this method
     */
    public function __construct(
        private string $className,
        private string $methodName,
        private bool $isStatic,
        private array $parameters = [],
        private ?string $returnType = null,
        private CallContext $callContext = new CallContext(),
        private array $childCalls = []
    ) {}

    /**
     * Get the full method signature in className::methodName format
     */
    public function getSignature(): string
    {
        return "{$this->className}::{$this->methodName}";
    }

    /**
     * Get the class name
     */
    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * Get the method name
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * Check if this is a static method call
     */
    public function isStatic(): bool
    {
        return $this->isStatic;
    }

    /**
     * Get method parameters
     *
     * @return TypedParameter[]
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Set method parameters
     *
     * @param TypedParameter[] $parameters
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * Add a method parameter
     */
    public function addParameter(TypedParameter $parameter): void
    {
        $this->parameters[] = $parameter;
    }

    /**
     * Get the return type of the method
     */
    public function getReturnType(): ?string
    {
        return $this->returnType;
    }

    /**
     * Set the return type of the method
     */
    public function setReturnType(?string $returnType): void
    {
        $this->returnType = $returnType;
    }

    /**
     * Get call context information
     */
    public function getCallContext(): CallContext
    {
        return $this->callContext;
    }

    /**
     * Set call context information
     */
    public function setCallContext(CallContext $callContext): void
    {
        $this->callContext = $callContext;
    }

    /**
     * Get child method calls
     *
     * @return MethodCall[]
     */
    public function getChildCalls(): array
    {
        return $this->childCalls;
    }

    /**
     * Add a child method call
     */
    public function addChildCall(MethodCall $childCall): void
    {
        $this->childCalls[] = $childCall;
    }

    /**
     * Check if this method has any child calls
     */
    public function hasChildCalls(): bool
    {
        return !empty($this->childCalls);
    }

    /**
     * Sort child calls for consistent output
     */
    public function sortChildCalls(): void
    {
        usort($this->childCalls, function (MethodCall $a, MethodCall $b) {
            return $a->getSignature() <=> $b->getSignature();
        });
    }
}
