<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Contract;

use Ermtraud\XsdToPhp\Definition\ClassDefinition;

/**
 * Allows consumers to mutate resolved class definitions before PHP files are rendered.
 */
interface DefinitionTransformerInterface
{
    public function transform(ClassDefinition $definition): ClassDefinition;
}
