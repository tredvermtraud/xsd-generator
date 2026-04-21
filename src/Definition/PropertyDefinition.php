<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Definition;

/**
 * Immutable metadata for a generated property.
 */
final readonly class PropertyDefinition
{
    public function __construct(
        public string $propertyName,
        public string $xmlName,
        public bool $isAttribute,
        public bool $isList,
        public ?string $namespace,
        public string $phpType,
        public ?string $itemTypeExpression,
        public ?string $defaultValueExpression,
    ) {
    }

    public function matches(string $xmlName, ?bool $isAttribute = null): bool
    {
        if ($this->xmlName !== $xmlName) {
            return false;
        }

        return $isAttribute === null || $this->isAttribute === $isAttribute;
    }

    public function withDefaultValueExpression(?string $defaultValueExpression): self
    {
        return new self(
            propertyName: $this->propertyName,
            xmlName: $this->xmlName,
            isAttribute: $this->isAttribute,
            isList: $this->isList,
            namespace: $this->namespace,
            phpType: $this->phpType,
            itemTypeExpression: $this->itemTypeExpression,
            defaultValueExpression: $defaultValueExpression,
        );
    }
}
