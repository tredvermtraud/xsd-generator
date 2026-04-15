<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Generator;

/**
 * Captures the files produced by a generation run and any non-fatal warnings.
 */
final readonly class GenerationResult
{
    /**
     * @param list<string> $generatedFiles
     * @param list<string> $warnings
     */
    public function __construct(
        public array $generatedFiles,
        public array $warnings = [],
    ) {
    }
}
