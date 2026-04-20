<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Generator;

use DOMDocument;
use DOMElement;
use Ermtraud\XsdToPhp\Config\GeneratorConfig;
use Ermtraud\XsdToPhp\Contract\ClassGeneratorInterface;
use Ermtraud\XsdToPhp\Exception\GenerationException;

/**
 * Generates PHP DTO-style classes from XSD definitions.
 */
final class XsdToPhpGenerator implements ClassGeneratorInterface
{
    private const XML_SCHEMA_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';
    private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    /**
     * Traverses the configured schema graph and writes the reachable PHP classes.
     */
    public function generate(GeneratorConfig $config): GenerationResult
    {
        $schemaPath = $config->inputSchema;
        $entrypoint = $config->entrypoint;

        if (!is_file($schemaPath)) {
            throw new GenerationException(sprintf('XSD file "%s" was not found.', $schemaPath));
        }

        $schemaDocuments = $this->loadSchemaGraph($schemaPath, $config);
        $entrypointMetadata = $this->resolveEntrypoint(
            $schemaDocuments,
            $entrypoint,
            $this->schemaNamespaceDeclarations($schemaPath),
            $config->preferEntrypointNamespaceDeclarations,
        );
        $definitions = $this->collectClassDefinitions($schemaDocuments, $entrypointMetadata, $config);
        $commonNamespace = $this->commonNamespacePrefix(array_column($definitions, 'php_namespace'));

        $targetDirectory = $config->outputDirectory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new GenerationException(sprintf('Unable to create output directory "%s".', $targetDirectory));
        }

        $generatedFiles = [];
        $warnings = [];

        foreach ($definitions as $definition) {
            $targetFile = $this->buildOutputFilePath(
                targetDirectory: $targetDirectory,
                commonNamespace: $commonNamespace,
                phpNamespace: $definition['php_namespace'],
                className: $definition['class_name'],
            );

            if (is_file($targetFile) && !$config->overwriteExisting) {
                $warnings[] = sprintf('Skipped "%s" because overwrite_existing=false.', $targetFile);
                continue;
            }

            $contents = $this->buildClassFile(
                namespace: $definition['php_namespace'],
                className: $definition['class_name'],
                rootName: $definition['root_name'],
                strictTypes: $config->strictTypes,
                targetNamespace: $definition['root_namespace'],
                rootNamespaces: $definition['root_namespaces'],
                properties: $definition['properties'],
            );

            if (file_put_contents($targetFile, $contents) === false) {
                throw new GenerationException(sprintf('Unable to write generated class "%s".', $targetFile));
            }

            $generatedFiles[] = $targetFile;
        }

        $warnings[] = sprintf(
            'The generator started from the configured entrypoint "%s" and resolved its schema graph through xs:include/xs:import directives.',
            $entrypoint,
        );

        return new GenerationResult(
            generatedFiles: $generatedFiles,
            warnings: array_values(array_unique($warnings)),
        );
    }

    private function resolveEntrypoint(
        array $schemaDocuments,
        string $entrypoint,
        array $entrypointNamespaceDeclarations,
        bool $preferEntrypointNamespaceDeclarations,
    ): array
    {
        foreach ($schemaDocuments as $schemaDocument) {
            foreach ($this->iterateGlobalSchemaNodes($schemaDocument['document'], 'element') as $element) {
                if ($element->getAttribute('name') !== $entrypoint) {
                    continue;
                }

                return [
                    'class_name' => $entrypoint,
                    'root_name' => $element->getAttribute('name'),
                    'target_namespace' => $schemaDocument['target_namespace'],
                    'root_namespaces' => $this->resolveRootNamespaceDeclarations(
                        $this->schemaNamespaceDeclarations($schemaDocument['path']),
                        $entrypointNamespaceDeclarations,
                        $preferEntrypointNamespaceDeclarations,
                    ),
                    'type_node' => $this->resolveElementTypeNode($schemaDocuments, $element, $schemaDocument['target_namespace']),
                    'type_namespace' => $schemaDocument['target_namespace'],
                ];
            }
        }

        foreach ($schemaDocuments as $schemaDocument) {
            foreach ($this->iterateGlobalSchemaNodes($schemaDocument['document'], 'complexType') as $complexType) {
                if ($complexType->getAttribute('name') !== $entrypoint) {
                    continue;
                }

                return [
                    'class_name' => $complexType->getAttribute('name'),
                    'root_name' => $this->findRootNameForType($schemaDocuments, $entrypoint, $schemaDocument['target_namespace'])
                        ?? $complexType->getAttribute('name'),
                    'target_namespace' => $schemaDocument['target_namespace'],
                    'root_namespaces' => $this->resolveRootNamespaceDeclarations(
                        $this->schemaNamespaceDeclarations($schemaDocument['path']),
                        $entrypointNamespaceDeclarations,
                        $preferEntrypointNamespaceDeclarations,
                    ),
                    'type_node' => $complexType,
                    'type_namespace' => $schemaDocument['target_namespace'],
                ];
            }
        }

        throw new GenerationException(sprintf(
            'Configured entrypoint "%s" was not found in the resolved schema graph. Expected a global xs:element or xs:complexType with that name.',
            $entrypoint,
        ));
    }

    private function collectClassDefinitions(
        array $schemaDocuments,
        array $entrypointMetadata,
        GeneratorConfig $config,
    ): array {
        $definitions = [];
        $building = [];

        $this->ensureClassDefinition(
            schemaDocuments: $schemaDocuments,
            config: $config,
            complexType: $entrypointMetadata['type_node'],
            typeNamespace: $entrypointMetadata['type_namespace'],
            suggestedName: $entrypointMetadata['class_name'],
            rootName: $entrypointMetadata['root_name'],
            rootNamespace: $entrypointMetadata['target_namespace'],
            rootNamespaces: $entrypointMetadata['root_namespaces'] ?? [],
            definitions: $definitions,
            building: $building,
        );

        return array_values($definitions);
    }

    private function ensureClassDefinition(
        array $schemaDocuments,
        GeneratorConfig $config,
        ?DOMElement $complexType,
        ?string $typeNamespace,
        string $suggestedName,
        ?string $rootName,
        ?string $rootNamespace,
        array $rootNamespaces,
        array &$definitions,
        array &$building,
    ): array {
        if (!$complexType instanceof DOMElement) {
            $className = $this->buildClassName($suggestedName, $config->classSuffix);
            $phpNamespace = $this->resolvePhpNamespace($config, $typeNamespace);

            return [
                'class_name' => $className,
                'php_namespace' => $phpNamespace,
                'fqn' => $this->buildFullyQualifiedClassName($phpNamespace, $className),
            ];
        }

        $definitionKey = $this->buildDefinitionKey($complexType, $typeNamespace, $suggestedName);
        if (isset($definitions[$definitionKey])) {
            $definition = $definitions[$definitionKey];

            return [
                'class_name' => $definition['class_name'],
                'php_namespace' => $definition['php_namespace'],
                'fqn' => $this->buildFullyQualifiedClassName($definition['php_namespace'], $definition['class_name']),
            ];
        }

        $classSourceName = $complexType->getAttribute('name') !== '' ? $complexType->getAttribute('name') : $suggestedName;
        $className = $this->buildClassName($classSourceName, $config->classSuffix);
        $phpNamespace = $this->resolvePhpNamespace($config, $typeNamespace);
        $this->assertUniqueClassIdentity($definitions, $definitionKey, $phpNamespace, $className, $typeNamespace);

        $definitions[$definitionKey] = [
            'class_name' => $className,
            'php_namespace' => $phpNamespace,
            'root_name' => $rootName,
            'root_namespace' => $rootNamespace,
            'root_namespaces' => $rootNamespaces,
            'properties' => [],
        ];

        if (isset($building[$definitionKey])) {
            return [
                'class_name' => $className,
                'php_namespace' => $phpNamespace,
                'fqn' => $this->buildFullyQualifiedClassName($phpNamespace, $className),
            ];
        }

        $building[$definitionKey] = true;
        $definitions[$definitionKey]['properties'] = $this->collectPropertiesForComplexType(
            schemaDocuments: $schemaDocuments,
            config: $config,
            complexType: $complexType,
            typeNamespace: $typeNamespace,
            currentClassName: $className,
            definitions: $definitions,
            building: $building,
        );
        unset($building[$definitionKey]);

        return [
            'class_name' => $className,
            'php_namespace' => $phpNamespace,
            'fqn' => $this->buildFullyQualifiedClassName($phpNamespace, $className),
        ];
    }

    private function collectPropertiesForComplexType(
        array $schemaDocuments,
        GeneratorConfig $config,
        DOMElement $complexType,
        ?string $typeNamespace,
        string $currentClassName,
        array &$definitions,
        array &$building,
    ): array {
        $properties = [];

        foreach ($complexType->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement || $childNode->namespaceURI !== self::XML_SCHEMA_NAMESPACE) {
                continue;
            }

            if ($childNode->localName === 'sequence' || $childNode->localName === 'choice' || $childNode->localName === 'all') {
                foreach ($this->collectPropertiesFromParticle(
                    schemaDocuments: $schemaDocuments,
                    config: $config,
                    particle: $childNode,
                    typeNamespace: $typeNamespace,
                    currentClassName: $currentClassName,
                    definitions: $definitions,
                    building: $building,
                ) as $property) {
                    $properties[$property['property_name']] = $property;
                }

                continue;
            }

            if ($childNode->localName === 'attribute') {
                $property = $this->describeAttributeProperty($childNode);
                $properties[$property['property_name']] = $property;
                continue;
            }

            if ($childNode->localName !== 'complexContent' && $childNode->localName !== 'simpleContent') {
                continue;
            }

            foreach ($childNode->childNodes as $contentNode) {
                if (
                    !$contentNode instanceof DOMElement
                    || $contentNode->namespaceURI !== self::XML_SCHEMA_NAMESPACE
                    || ($contentNode->localName !== 'extension' && $contentNode->localName !== 'restriction')
                ) {
                    continue;
                }

                $baseReference = $contentNode->getAttribute('base');
                if ($baseReference !== '') {
                    $baseNamespace = $this->resolveQualifiedNameNamespace($contentNode, $baseReference, $typeNamespace);
                    $baseComplexType = $this->resolveComplexTypeReference($schemaDocuments, $contentNode, $baseReference, $typeNamespace);

                    if ($baseComplexType instanceof DOMElement) {
                        $this->ensureClassDefinition(
                            schemaDocuments: $schemaDocuments,
                            config: $config,
                            complexType: $baseComplexType,
                            typeNamespace: $baseNamespace,
                            suggestedName: $this->splitQualifiedName($baseReference)[1],
                            rootName: null,
                            rootNamespace: null,
                            rootNamespaces: [],
                            definitions: $definitions,
                            building: $building,
                        );

                        foreach ($this->collectPropertiesForComplexType(
                            schemaDocuments: $schemaDocuments,
                            config: $config,
                            complexType: $baseComplexType,
                            typeNamespace: $baseNamespace,
                            currentClassName: $this->buildClassName($this->splitQualifiedName($baseReference)[1], $config->classSuffix),
                            definitions: $definitions,
                            building: $building,
                        ) as $property) {
                            $properties[$property['property_name']] = $property;
                        }
                    }
                }

                foreach ($contentNode->childNodes as $extensionChild) {
                    if (!$extensionChild instanceof DOMElement || $extensionChild->namespaceURI !== self::XML_SCHEMA_NAMESPACE) {
                        continue;
                    }

                    if ($extensionChild->localName === 'sequence' || $extensionChild->localName === 'choice' || $extensionChild->localName === 'all') {
                        foreach ($this->collectPropertiesFromParticle(
                            schemaDocuments: $schemaDocuments,
                            config: $config,
                            particle: $extensionChild,
                            typeNamespace: $typeNamespace,
                            currentClassName: $currentClassName,
                            definitions: $definitions,
                            building: $building,
                        ) as $property) {
                            $properties[$property['property_name']] = $property;
                        }

                        continue;
                    }

                    if ($extensionChild->localName === 'attribute') {
                        $property = $this->describeAttributeProperty($extensionChild);
                        $properties[$property['property_name']] = $property;
                    }
                }
            }
        }

        return array_values($properties);
    }

    private function collectPropertiesFromParticle(
        array $schemaDocuments,
        GeneratorConfig $config,
        DOMElement $particle,
        ?string $typeNamespace,
        string $currentClassName,
        array &$definitions,
        array &$building,
    ): array {
        $properties = [];

        foreach ($particle->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement || $childNode->namespaceURI !== self::XML_SCHEMA_NAMESPACE) {
                continue;
            }

            if ($childNode->localName === 'element') {
                $properties[] = $this->describeElementProperty(
                    schemaDocuments: $schemaDocuments,
                    config: $config,
                    element: $childNode,
                    typeNamespace: $typeNamespace,
                    currentClassName: $currentClassName,
                    definitions: $definitions,
                    building: $building,
                );
                continue;
            }

            if ($childNode->localName === 'sequence' || $childNode->localName === 'choice' || $childNode->localName === 'all') {
                foreach ($this->collectPropertiesFromParticle(
                    schemaDocuments: $schemaDocuments,
                    config: $config,
                    particle: $childNode,
                    typeNamespace: $typeNamespace,
                    currentClassName: $currentClassName,
                    definitions: $definitions,
                    building: $building,
                ) as $property) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }

    private function describeElementProperty(
        array $schemaDocuments,
        GeneratorConfig $config,
        DOMElement $element,
        ?string $typeNamespace,
        string $currentClassName,
        array &$definitions,
        array &$building,
    ): array {
        $reference = $element->getAttribute('ref');
        $resolvedElement = $reference !== ''
            ? $this->resolveElementReference($schemaDocuments, $element, $reference, $typeNamespace)
            : $element;

        $xmlName = $reference !== ''
            ? $this->splitQualifiedName($reference)[1]
            : $element->getAttribute('name');

        $namespace = $reference !== ''
            ? $this->resolveQualifiedNameNamespace($element, $reference, $typeNamespace)
            : $this->resolveLocalElementNamespace($element, $typeNamespace);

        $isList = $this->isListElement($element);
        $phpType = 'mixed';
        $itemTypeExpression = null;

        if ($resolvedElement instanceof DOMElement) {
            $resolvedType = $this->resolveElementPhpType(
                schemaDocuments: $schemaDocuments,
                config: $config,
                element: $resolvedElement,
                typeNamespace: $this->schemaTargetNamespace($resolvedElement),
                suggestedName: $this->buildNestedSuggestedName($currentClassName, $xmlName),
                definitions: $definitions,
                building: $building,
            );

            if ($isList) {
                $phpType = 'array';
                $itemTypeExpression = $resolvedType['item_type_expression'];
            } else {
                $phpType = $resolvedType['php_type'];
            }
        }

        return [
            'property_name' => $this->normalizePropertyName($xmlName),
            'xml_name' => $xmlName,
            'is_attribute' => false,
            'is_list' => $isList,
            'namespace' => $namespace,
            'php_type' => $phpType,
            'item_type_expression' => $itemTypeExpression,
            'default_value_expression' => $this->resolveFixedValueExpression(
                $element->getAttribute('fixed') !== '' ? $element : $resolvedElement,
                $phpType,
            ),
        ];
    }

    private function resolveElementPhpType(
        array $schemaDocuments,
        GeneratorConfig $config,
        DOMElement $element,
        ?string $typeNamespace,
        string $suggestedName,
        array &$definitions,
        array &$building,
    ): array {
        $inlineComplexType = $this->findInlineSchemaChild($element, 'complexType');
        if ($inlineComplexType instanceof DOMElement) {
            $suggestedName = $this->buildAnonymousTypeSuggestedName($element, $suggestedName);
            $classReference = $this->ensureClassDefinition(
                schemaDocuments: $schemaDocuments,
                config: $config,
                complexType: $inlineComplexType,
                typeNamespace: $typeNamespace,
                suggestedName: $suggestedName,
                rootName: null,
                rootNamespace: null,
                rootNamespaces: [],
                definitions: $definitions,
                building: $building,
            );

            return [
                'php_type' => '?' . $classReference['fqn'],
                'item_type_expression' => $classReference['fqn'] . '::class',
            ];
        }

        $typeReference = $element->getAttribute('type');
        if ($typeReference !== '') {
            if ($this->isBuiltInXmlType($typeReference, $element)) {
                $typeName = $this->mapBuiltInXmlTypeToPhpType($this->splitQualifiedName($typeReference)[1]);

                return [
                    'php_type' => '?' . $typeName,
                    'item_type_expression' => sprintf("'%s'", $typeName),
                ];
            }

            $complexType = $this->resolveComplexTypeReference($schemaDocuments, $element, $typeReference, $typeNamespace);
            if ($complexType instanceof DOMElement) {
                $classReference = $this->ensureClassDefinition(
                    schemaDocuments: $schemaDocuments,
                    config: $config,
                    complexType: $complexType,
                    typeNamespace: $this->resolveQualifiedNameNamespace($element, $typeReference, $typeNamespace),
                    suggestedName: $this->splitQualifiedName($typeReference)[1],
                    rootName: null,
                    rootNamespace: null,
                    rootNamespaces: [],
                    definitions: $definitions,
                    building: $building,
                );

                return [
                    'php_type' => '?' . $classReference['fqn'],
                    'item_type_expression' => $classReference['fqn'] . '::class',
                ];
            }

            return [
                'php_type' => '?string',
                'item_type_expression' => "'string'",
            ];
        }

        if ($this->findInlineSchemaChild($element, 'simpleType') instanceof DOMElement) {
            return [
                'php_type' => '?string',
                'item_type_expression' => "'string'",
            ];
        }

        return [
            'php_type' => 'mixed',
            'item_type_expression' => null,
        ];
    }

    private function describeAttributeProperty(DOMElement $attribute): array
    {
        $xmlName = $attribute->getAttribute('name');
        $typeReference = $attribute->getAttribute('type');
        $typeName = $typeReference !== '' && $this->isBuiltInXmlType($typeReference, $attribute)
            ? $this->mapBuiltInXmlTypeToPhpType($this->splitQualifiedName($typeReference)[1])
            : 'string';

        return [
            'property_name' => $this->normalizePropertyName($xmlName),
            'xml_name' => $xmlName,
            'is_attribute' => true,
            'is_list' => false,
            'namespace' => null,
            'php_type' => '?' . $typeName,
            'item_type_expression' => null,
            'default_value_expression' => $this->resolveFixedValueExpression($attribute, '?' . $typeName),
        ];
    }

    private function iterateGlobalSchemaNodes(DOMDocument $schema, string $localName): array
    {
        $schemaRoot = $schema->documentElement;
        if (!$schemaRoot instanceof DOMElement) {
            return [];
        }

        $nodes = [];
        foreach ($schemaRoot->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement || !$this->isSchemaNode($childNode, $localName)) {
                continue;
            }

            $nodes[] = $childNode;
        }

        return $nodes;
    }

    private function loadSchemaGraph(string $rootSchemaPath, GeneratorConfig $config): array
    {
        $documents = [];
        $this->loadSchemaDocument($rootSchemaPath, $config, $documents);

        return $documents;
    }

    private function loadSchemaDocument(string $schemaPath, GeneratorConfig $config, array &$documents): void
    {
        $normalizedPath = $this->normalizeSchemaPath($schemaPath);
        if (isset($documents[$normalizedPath])) {
            return;
        }

        if (!is_file($normalizedPath)) {
            throw new GenerationException(sprintf('Unable to resolve schema file "%s".', $schemaPath));
        }

        $schema = new DOMDocument();
        if ($schema->load($normalizedPath) === false) {
            throw new GenerationException(sprintf('Unable to load XSD file "%s".', $normalizedPath));
        }

        $schemaRoot = $schema->documentElement;
        if (!$schemaRoot instanceof DOMElement || !$this->isSchemaNode($schemaRoot, 'schema')) {
            throw new GenerationException(sprintf('File "%s" is not a valid XML Schema document.', $normalizedPath));
        }

        $targetNamespace = $schemaRoot->getAttribute('targetNamespace') ?: null;
        $documents[$normalizedPath] = [
            'document' => $schema,
            'path' => $normalizedPath,
            'target_namespace' => $targetNamespace,
        ];

        foreach ($this->iterateSchemaReferences($schemaRoot) as $reference) {
            $referencedSchemaPath = $this->resolveReferencedSchemaPath(
                currentSchemaPath: $normalizedPath,
                currentTargetNamespace: $targetNamespace,
                referenceKind: $reference['kind'],
                referencedNamespace: $reference['namespace'],
                configuredSchemaLocation: $reference['schema_location'],
                config: $config,
            );

            if ($referencedSchemaPath === null) {
                continue;
            }

            $this->loadSchemaDocument($referencedSchemaPath, $config, $documents);
        }
    }

    private function iterateSchemaReferences(DOMElement $schemaRoot): array
    {
        $references = [];

        foreach ($schemaRoot->childNodes as $childNode) {
            if (!$childNode instanceof DOMElement || $childNode->namespaceURI !== self::XML_SCHEMA_NAMESPACE) {
                continue;
            }

            if ($childNode->localName !== 'include' && $childNode->localName !== 'import') {
                continue;
            }

            $references[] = [
                'kind' => $childNode->localName,
                'namespace' => $childNode->hasAttribute('namespace') ? ($childNode->getAttribute('namespace') ?: null) : null,
                'schema_location' => $childNode->hasAttribute('schemaLocation') ? ($childNode->getAttribute('schemaLocation') ?: null) : null,
            ];
        }

        return $references;
    }

    private function resolveReferencedSchemaPath(
        string $currentSchemaPath,
        ?string $currentTargetNamespace,
        string $referenceKind,
        ?string $referencedNamespace,
        ?string $configuredSchemaLocation,
        GeneratorConfig $config,
    ): ?string {
        $relativePathCandidate = $configuredSchemaLocation !== null
            ? $this->resolvePathRelativeToSchema($currentSchemaPath, $configuredSchemaLocation)
            : null;

        if ($referenceKind === 'include') {
            if ($relativePathCandidate !== null && is_file($relativePathCandidate)) {
                return $relativePathCandidate;
            }

            $namespace = $currentTargetNamespace ?? '';
            if ($namespace !== '' && isset($config->schemaLocations[$namespace])) {
                return $this->normalizeSchemaPath($config->schemaLocations[$namespace]);
            }

            if ($configuredSchemaLocation !== null) {
                throw new GenerationException(sprintf(
                    'Unable to resolve xs:include "%s" referenced from "%s".',
                    $configuredSchemaLocation,
                    $currentSchemaPath,
                ));
            }

            return null;
        }

        $namespace = $referencedNamespace ?? '';
        if ($namespace !== '' && isset($config->schemaLocations[$namespace])) {
            return $this->normalizeSchemaPath($config->schemaLocations[$namespace]);
        }

        if ($relativePathCandidate !== null && is_file($relativePathCandidate)) {
            return $relativePathCandidate;
        }

        if ($configuredSchemaLocation !== null) {
            throw new GenerationException(sprintf(
                'Unable to resolve xs:import for namespace "%s" using schemaLocation "%s" referenced from "%s". Add a generator.schema_locations entry or fix the schemaLocation.',
                $namespace !== '' ? $namespace : '[no namespace]',
                $configuredSchemaLocation,
                $currentSchemaPath,
            ));
        }

        if ($namespace !== '') {
            throw new GenerationException(sprintf(
                'Unable to resolve xs:import for namespace "%s" referenced from "%s". Add a generator.schema_locations entry for that namespace.',
                $namespace,
                $currentSchemaPath,
            ));
        }

        return null;
    }

    private function resolveElementTypeNode(array $schemaDocuments, DOMElement $element, ?string $currentTargetNamespace): ?DOMElement
    {
        $inlineComplexType = $this->findInlineSchemaChild($element, 'complexType');
        if ($inlineComplexType instanceof DOMElement) {
            return $inlineComplexType;
        }

        $typeReference = $element->getAttribute('type');
        if ($typeReference === '') {
            return null;
        }

        return $this->resolveComplexTypeReference($schemaDocuments, $element, $typeReference, $currentTargetNamespace);
    }

    private function findRootNameForType(array $schemaDocuments, string $typeName, ?string $targetNamespace): ?string
    {
        foreach ($schemaDocuments as $schemaDocument) {
            foreach ($this->iterateGlobalSchemaNodes($schemaDocument['document'], 'element') as $element) {
                $typeReference = $element->getAttribute('type');
                if (!$this->typeReferenceMatches($element, $typeReference, $typeName, $targetNamespace)) {
                    continue;
                }

                $rootName = $element->getAttribute('name');
                if ($rootName !== '') {
                    return $rootName;
                }
            }
        }

        return null;
    }

    private function typeReferenceMatches(
        DOMElement $element,
        string $typeReference,
        string $expectedTypeName,
        ?string $expectedNamespace,
    ): bool {
        if ($typeReference === '') {
            return false;
        }

        [$prefix, $localName] = $this->splitQualifiedName($typeReference);
        if ($localName !== $expectedTypeName) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $resolvedNamespace = $element->lookupNamespaceURI($prefix);

        return $expectedNamespace === null || $resolvedNamespace === $expectedNamespace;
    }

    private function splitQualifiedName(string $qualifiedName): array
    {
        $parts = explode(':', $qualifiedName, 2);

        return count($parts) === 1 ? [null, $parts[0]] : [$parts[0], $parts[1]];
    }

    private function resolveQualifiedNameNamespace(DOMElement $contextNode, string $qualifiedName, ?string $defaultNamespace): ?string
    {
        [$prefix] = $this->splitQualifiedName($qualifiedName);

        return $prefix === null ? $defaultNamespace : $contextNode->lookupNamespaceURI($prefix);
    }

    private function resolveComplexTypeReference(
        array $schemaDocuments,
        DOMElement $contextNode,
        string $qualifiedName,
        ?string $defaultNamespace,
    ): ?DOMElement {
        $targetNamespace = $this->resolveQualifiedNameNamespace($contextNode, $qualifiedName, $defaultNamespace);
        [, $localName] = $this->splitQualifiedName($qualifiedName);

        foreach ($schemaDocuments as $schemaDocument) {
            if ($targetNamespace !== null && $schemaDocument['target_namespace'] !== $targetNamespace) {
                continue;
            }

            foreach ($this->iterateGlobalSchemaNodes($schemaDocument['document'], 'complexType') as $complexType) {
                if ($complexType->getAttribute('name') === $localName) {
                    return $complexType;
                }
            }
        }

        return null;
    }

    private function resolveElementReference(
        array $schemaDocuments,
        DOMElement $contextNode,
        string $qualifiedName,
        ?string $defaultNamespace,
    ): ?DOMElement {
        $targetNamespace = $this->resolveQualifiedNameNamespace($contextNode, $qualifiedName, $defaultNamespace);
        [, $localName] = $this->splitQualifiedName($qualifiedName);

        foreach ($schemaDocuments as $schemaDocument) {
            if ($targetNamespace !== null && $schemaDocument['target_namespace'] !== $targetNamespace) {
                continue;
            }

            foreach ($this->iterateGlobalSchemaNodes($schemaDocument['document'], 'element') as $element) {
                if ($element->getAttribute('name') === $localName) {
                    return $element;
                }
            }
        }

        return null;
    }

    private function schemaTargetNamespace(DOMElement $node): ?string
    {
        $schemaRoot = $node->ownerDocument?->documentElement;

        return $schemaRoot instanceof DOMElement ? ($schemaRoot->getAttribute('targetNamespace') ?: null) : null;
    }

    /**
     * @return array<string, string>
     */
    private function schemaNamespaceDeclarations(string $schemaPath): array
    {
        $contents = @file_get_contents($schemaPath);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $namespaces = [];

        if (preg_match_all('/xmlns:([A-Za-z_][A-Za-z0-9_.-]*)\s*=\s*([\'"])(.*?)\2/', $contents, $matches, PREG_SET_ORDER) !== 1
            && $matches === []) {
            return [];
        }

        foreach ($matches as $match) {
            $prefix = $match[1];
            $namespace = $match[3];

            if ($prefix === 'xml' || $namespace === '' || $namespace === self::XML_SCHEMA_NAMESPACE) {
                continue;
            }

            $namespaces[$prefix] = $namespace;
        }

        return $namespaces;
    }

    /**
     * @param array<string, string> $schemaNamespaceDeclarations
     * @param array<string, string> $entrypointNamespaceDeclarations
     *
     * @return array<string, string>
     */
    private function resolveRootNamespaceDeclarations(
        array $schemaNamespaceDeclarations,
        array $entrypointNamespaceDeclarations,
        bool $preferEntrypointNamespaceDeclarations,
    ): array {
        if (!$preferEntrypointNamespaceDeclarations) {
            return $schemaNamespaceDeclarations;
        }

        return $this->mergeNamespaceDeclarations(
            preferredDeclarations: $entrypointNamespaceDeclarations,
            fallbackDeclarations: $schemaNamespaceDeclarations,
        );
    }

    /**
     * @param array<string, string> $preferredDeclarations
     * @param array<string, string> $fallbackDeclarations
     *
     * @return array<string, string>
     */
    private function mergeNamespaceDeclarations(array $preferredDeclarations, array $fallbackDeclarations): array
    {
        $mergedDeclarations = $preferredDeclarations;
        $declaredNamespaces = array_fill_keys(array_values($preferredDeclarations), true);

        foreach ($fallbackDeclarations as $prefix => $namespace) {
            if (isset($mergedDeclarations[$prefix]) || isset($declaredNamespaces[$namespace])) {
                continue;
            }

            $mergedDeclarations[$prefix] = $namespace;
            $declaredNamespaces[$namespace] = true;
        }

        return $mergedDeclarations;
    }

    private function resolveLocalElementNamespace(DOMElement $element, ?string $typeNamespace): ?string
    {
        if ($element->getAttribute('form') === 'unqualified') {
            return null;
        }

        $schemaRoot = $element->ownerDocument?->documentElement;
        if ($schemaRoot instanceof DOMElement && $schemaRoot->getAttribute('elementFormDefault') === 'unqualified') {
            return null;
        }

        return $typeNamespace;
    }

    private function findInlineSchemaChild(DOMElement $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $childNode) {
            if ($childNode instanceof DOMElement && $this->isSchemaNode($childNode, $localName)) {
                return $childNode;
            }
        }

        return null;
    }

    private function isBuiltInXmlType(string $typeReference, DOMElement $contextNode): bool
    {
        [$prefix, $localName] = $this->splitQualifiedName($typeReference);
        if ($prefix === null) {
            return false;
        }

        return $contextNode->lookupNamespaceURI($prefix) === self::XML_SCHEMA_NAMESPACE
            && in_array($localName, ['string', 'token', 'normalizedString', 'anyURI', 'boolean', 'decimal', 'integer', 'int', 'float', 'double', 'date', 'dateTime'], true);
    }

    private function mapBuiltInXmlTypeToPhpType(string $xmlType): string
    {
        return match ($xmlType) {
            'boolean' => 'bool',
            'decimal', 'float', 'double' => 'float',
            'integer', 'int' => 'int',
            default => 'string',
        };
    }

    private function isListElement(DOMElement $element): bool
    {
        $maxOccurs = $element->getAttribute('maxOccurs');
        if ($maxOccurs === 'unbounded') {
            return true;
        }

        return $maxOccurs !== '' && ctype_digit($maxOccurs) && (int) $maxOccurs > 1;
    }

    private function resolveFixedValueExpression(?DOMElement $node, string $phpType): ?string
    {
        if (!$node instanceof DOMElement) {
            return null;
        }

        $fixedValue = $node->getAttribute('fixed');
        if ($fixedValue === '' || $phpType === 'array') {
            return null;
        }

        $normalizedType = ltrim($phpType, '?');

        return match ($normalizedType) {
            'bool' => $this->parseBooleanFixedValue($fixedValue),
            'int' => $this->parseIntegerFixedValue($fixedValue),
            'float' => $this->parseFloatFixedValue($fixedValue),
            'string', 'mixed' => var_export($fixedValue, true),
            default => null,
        };
    }

    private function parseBooleanFixedValue(string $fixedValue): ?string
    {
        return match (strtolower($fixedValue)) {
            'true', '1' => 'true',
            'false', '0' => 'false',
            default => null,
        };
    }

    private function parseIntegerFixedValue(string $fixedValue): ?string
    {
        if (!preg_match('/^[+-]?\d+$/', $fixedValue)) {
            return null;
        }

        return (string) (int) $fixedValue;
    }

    private function parseFloatFixedValue(string $fixedValue): ?string
    {
        if (!is_numeric($fixedValue)) {
            return null;
        }

        return var_export((float) $fixedValue, true);
    }

    private function normalizePropertyName(string $name): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? $name;
        $normalized = str_replace(' ', '', ucwords(trim($normalized)));
        $normalized = lcfirst($normalized);

        if ($normalized === '') {
            return 'value';
        }

        return ctype_digit($normalized[0]) ? 'value' . $normalized : $normalized;
    }

    private function normalizeClassName(string $name): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? $name;
        $normalized = str_replace(' ', '', ucwords(trim($normalized)));

        return $normalized !== '' ? $normalized : 'GeneratedClass';
    }

    private function buildClassName(string $entrypoint, string $classSuffix): string
    {
        $className = $this->normalizeClassName($entrypoint);
        $normalizedSuffix = $this->normalizeClassName($classSuffix);

        if ($normalizedSuffix === '' || str_ends_with($className, $normalizedSuffix)) {
            return $className;
        }

        return $className . $normalizedSuffix;
    }

    private function buildAnonymousTypeSuggestedName(DOMElement $element, string $fallback): string
    {
        $name = $element->getAttribute('name');
        $parentNode = $element->parentNode;

        if ($name !== '' && $parentNode instanceof DOMElement && $this->isSchemaNode($parentNode, 'schema')) {
            return $name;
        }

        return $fallback;
    }

    private function buildNestedSuggestedName(string $currentClassName, string $xmlName): string
    {
        return $currentClassName . '.' . $xmlName;
    }

    private function buildDefinitionKey(DOMElement $complexType, ?string $typeNamespace, string $suggestedName): string
    {
        $name = $complexType->getAttribute('name');

        return $name !== ''
            ? sprintf('named|%s|%s', $typeNamespace ?? '[no-namespace]', $name)
            : sprintf('anonymous|%s|%s', $typeNamespace ?? '[no-namespace]', $suggestedName);
    }

    private function resolvePhpNamespace(GeneratorConfig $config, ?string $typeNamespace): string
    {
        return $typeNamespace !== null && isset($config->namespaceMap[$typeNamespace])
            ? $config->namespaceMap[$typeNamespace]
            : $config->baseNamespace;
    }

    private function buildFullyQualifiedClassName(string $phpNamespace, string $className): string
    {
        return '\\' . ltrim($phpNamespace . '\\' . $className, '\\');
    }

    private function assertUniqueClassIdentity(
        array $definitions,
        string $definitionKey,
        string $phpNamespace,
        string $className,
        ?string $typeNamespace,
    ): void {
        foreach ($definitions as $existingKey => $definition) {
            if ($existingKey === $definitionKey) {
                continue;
            }

            if ($definition['php_namespace'] !== $phpNamespace || $definition['class_name'] !== $className) {
                continue;
            }

            throw new GenerationException(sprintf(
                'The generated class name "%s\\%s" is ambiguous. Adjust generator.namespace_map so XML namespace "%s" does not collide with another generated type.',
                $phpNamespace,
                $className,
                $typeNamespace ?? '[no namespace]',
            ));
        }
    }

    private function commonNamespacePrefix(array $namespaces): string
    {
        if ($namespaces === []) {
            return '';
        }

        $segments = array_map(
            static fn (string $namespace): array => array_values(array_filter(explode('\\', trim($namespace, '\\')), static fn (string $segment): bool => $segment !== '')),
            $namespaces,
        );

        $common = array_shift($segments) ?? [];
        foreach ($segments as $parts) {
            $length = min(count($common), count($parts));
            $index = 0;

            while ($index < $length && $common[$index] === $parts[$index]) {
                $index++;
            }

            $common = array_slice($common, 0, $index);
        }

        return implode('\\', $common);
    }

    private function buildOutputFilePath(
        string $targetDirectory,
        string $commonNamespace,
        string $phpNamespace,
        string $className,
    ): string {
        $relativeNamespace = $phpNamespace;
        if ($commonNamespace !== '' && str_starts_with($phpNamespace, $commonNamespace . '\\')) {
            $relativeNamespace = substr($phpNamespace, strlen($commonNamespace) + 1);
        } elseif ($phpNamespace === $commonNamespace) {
            $relativeNamespace = '';
        }

        $directory = rtrim($targetDirectory, '/\\');
        if ($relativeNamespace !== '') {
            $directory .= DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeNamespace);
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new GenerationException(sprintf('Unable to create output directory "%s".', $directory));
        }

        return $directory . DIRECTORY_SEPARATOR . $className . '.php';
    }

    private function isSchemaNode(DOMElement $element, string $localName): bool
    {
        return $element->namespaceURI === self::XML_SCHEMA_NAMESPACE && $element->localName === $localName;
    }

    private function resolvePathRelativeToSchema(string $schemaPath, string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }

        if ($this->isAbsolutePath($relativePath)) {
            return $this->normalizeSchemaPath($relativePath);
        }

        return $this->normalizeSchemaPath(dirname($schemaPath) . DIRECTORY_SEPARATOR . $relativePath);
    }

    private function normalizeSchemaPath(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $normalized = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', DIRECTORY_SEPARATOR, $normalized);

        return $normalized ?? $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\') || str_starts_with($path, '/');
    }

    private function buildClassFile(
        string $namespace,
        string $className,
        ?string $rootName,
        bool $strictTypes,
        ?string $targetNamespace,
        array $rootNamespaces,
        array $properties,
    ): string {
        $declareStrictTypes = $strictTypes ? "declare(strict_types=1);\n\n" : '';
        $imports = [];

        if ($rootName !== null) {
            $imports[] = 'use Ermtraud\\XsdToPhp\\Xml\\Attributes\\XmlRoot;';
        }

        if ($properties !== []) {
            $imports[] = 'use Ermtraud\\XsdToPhp\\Xml\\Attributes\\XmlElement;';
        }

        $rootAttribute = '';
        if ($rootName !== null) {
            $namespaceArgument = $targetNamespace !== null ? sprintf(", namespace: '%s'", addslashes($targetNamespace)) : '';
            $namespacesArgument = $rootNamespaces !== []
                ? ', namespaces: ' . $this->formatPhpArray($rootNamespaces)
                : '';
            $rootAttribute = sprintf("#[XmlRoot('%s'%s%s)]\n", addslashes($rootName), $namespaceArgument, $namespacesArgument);
        }

        $importsBlock = $imports !== [] ? $this->implodeLines($imports) . "\n\n" : '';
        $propertyDefinitions = $this->buildPropertyDefinitions($properties);
        $classBody = $propertyDefinitions !== '' ? "\n{$propertyDefinitions}\n" : "\n";

        return <<<PHP
<?php

{$declareStrictTypes}namespace {$namespace};

{$importsBlock}{$rootAttribute}final class {$className}
{{$classBody}}
PHP;
    }

    private function buildPropertyDefinitions(array $properties): string
    {
        $definitions = [];

        foreach ($properties as $property) {
            $arguments = [sprintf("'%s'", addslashes($property['xml_name']))];

            if ($property['is_attribute']) {
                $arguments[] = 'isAttribute: true';
            }

            if ($property['is_list']) {
                $arguments[] = 'isList: true';
            }

            if ($property['item_type_expression'] !== null) {
                $arguments[] = 'itemType: ' . $property['item_type_expression'];
            }

            if ($property['namespace'] !== null) {
                $arguments[] = sprintf("namespace: '%s'", addslashes($property['namespace']));
            }

            $defaultValue = match (true) {
                isset($property['default_value_expression']) && $property['default_value_expression'] !== null => ' = ' . $property['default_value_expression'],
                $property['php_type'] === 'array' => ' = []',
                default => ' = null',
            };

            $definitions[] = sprintf('    #[XmlElement(%s)]', implode(', ', $arguments));
            $definitions[] = sprintf('    public %s $%s%s;', $property['php_type'], $property['property_name'], $defaultValue);
            $definitions[] = '';
        }

        if ($definitions !== [] && end($definitions) === '') {
            array_pop($definitions);
        }

        return $this->implodeLines($definitions);
    }

    private function implodeLines(array $lines): string
    {
        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $values
     */
    private function formatPhpArray(array $values): string
    {
        $items = [];

        foreach ($values as $key => $value) {
            $items[] = sprintf("'%s' => '%s'", addslashes($key), addslashes($value));
        }

        return '[' . implode(', ', $items) . ']';
    }
}
