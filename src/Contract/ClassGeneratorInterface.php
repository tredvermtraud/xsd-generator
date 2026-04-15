<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Contract;

use Ermtraud\XsdToPhp\Config\GeneratorConfig;
use Ermtraud\XsdToPhp\Generator\GenerationResult;

/**
 * Contract for services that generate PHP classes from XSD configuration.
 */
interface ClassGeneratorInterface
{
    /**
     * Generates PHP classes for the provided generator configuration.
     */
    public function generate(GeneratorConfig $config): GenerationResult;
}
