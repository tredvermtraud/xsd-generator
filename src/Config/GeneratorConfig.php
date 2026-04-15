<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Config;

use Ermtraud\XsdToPhp\Exception\InvalidConfiguration;

/**
 * Immutable generator settings used to turn an XSD graph into PHP classes.
 */
final readonly class GeneratorConfig
{
    /**
     * @param array<string, string> $namespaceMap
     * @param array<string, string> $schemaLocations
     */
    public function __construct(
        public string $inputSchema,
        public string $entrypoint,
        public string $outputDirectory,
        public string $baseNamespace,
        public array $namespaceMap,
        public array $schemaLocations,
        public string $classSuffix,
        public bool $strictTypes,
        public bool $overwriteExisting,
    ) {
    }

    /**
     * Normalizes and validates generator configuration values.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $inputSchema = self::requireString($config, 'input_schema');
        $entrypoint = self::requireString($config, 'entrypoint');
        $outputDirectory = self::requireString($config, 'output_directory');
        $baseNamespace = self::requireString($config, 'base_namespace');
        $namespaceMap = $config['namespace_map'] ?? [];
        $schemaLocations = $config['schema_locations'] ?? [];
        $classSuffix = (string) ($config['class_suffix'] ?? 'Type');
        $strictTypes = (bool) ($config['strict_types'] ?? true);
        $overwriteExisting = (bool) ($config['overwrite_existing'] ?? false);

        if (!is_array($namespaceMap)) {
            throw new InvalidConfiguration('The "generator.namespace_map" value must be an array.');
        }

        if (!is_array($schemaLocations)) {
            throw new InvalidConfiguration('The "generator.schema_locations" value must be an array.');
        }

        foreach ($namespaceMap as $xmlNamespace => $phpNamespace) {
            if (!is_string($xmlNamespace) || !is_string($phpNamespace)) {
                throw new InvalidConfiguration('The "generator.namespace_map" array must contain string keys and values.');
            }
        }

        foreach ($schemaLocations as $targetNamespace => $schemaPath) {
            if (!is_string($targetNamespace) || !is_string($schemaPath)) {
                throw new InvalidConfiguration('The "generator.schema_locations" array must contain string keys and values.');
            }
        }

        return new self(
            inputSchema: $inputSchema,
            entrypoint: $entrypoint,
            outputDirectory: $outputDirectory,
            baseNamespace: $baseNamespace,
            namespaceMap: $namespaceMap,
            schemaLocations: $schemaLocations,
            classSuffix: $classSuffix,
            strictTypes: $strictTypes,
            overwriteExisting: $overwriteExisting,
        );
    }

    /**
     * Reads a required non-empty string value from the generator config.
     *
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidConfiguration(sprintf('The "generator.%s" value is required and must be a non-empty string.', $key));
        }

        return $value;
    }
}
