<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Definition;

use Ermtraud\XsdToPhp\Exception\InvalidConfiguration;

/**
 * Immutable metadata for a generated class and its mapped properties.
 */
final readonly class ClassDefinition
{
    /**
     * @param array<string, string> $rootNamespaces
     * @param list<PropertyDefinition> $properties
     */
    public function __construct(
        public string $className,
        public string $phpNamespace,
        public ?string $rootName,
        public ?string $rootNamespace,
        public array $rootNamespaces,
        public array $properties,
    ) {
        foreach ($this->properties as $property) {
            if (!$property instanceof PropertyDefinition) {
                throw new InvalidConfiguration('ClassDefinition::$properties must contain only PropertyDefinition instances.');
            }
        }
    }

    public function hasProperty(string $xmlName, ?bool $isAttribute = null): bool
    {
        return $this->property($xmlName, $isAttribute) instanceof PropertyDefinition;
    }

    public function property(string $xmlName, ?bool $isAttribute = null): ?PropertyDefinition
    {
        foreach ($this->properties as $property) {
            if ($property->matches($xmlName, $isAttribute)) {
                return $property;
            }
        }

        return null;
    }

    /**
     * @param list<PropertyDefinition> $properties
     */
    public function withProperties(array $properties): self
    {
        return new self(
            className: $this->className,
            phpNamespace: $this->phpNamespace,
            rootName: $this->rootName,
            rootNamespace: $this->rootNamespace,
            rootNamespaces: $this->rootNamespaces,
            properties: $properties,
        );
    }

    public function withPropertyDefaultValue(string $xmlName, ?string $defaultValueExpression, ?bool $isAttribute = null): self
    {
        $updated = false;
        $properties = [];

        foreach ($this->properties as $property) {
            if (!$updated && $property->matches($xmlName, $isAttribute)) {
                $properties[] = $property->withDefaultValueExpression($defaultValueExpression);
                $updated = true;
                continue;
            }

            $properties[] = $property;
        }

        return $updated ? $this->withProperties($properties) : $this;
    }
}
