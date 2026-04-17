<?php

declare(strict_types=1);

namespace Ermtraud\XsdToPhp\Tests\Generator;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Ermtraud\XsdToPhp\Config\GeneratorConfig;
use Ermtraud\XsdToPhp\Generator\XsdToPhpGenerator;

final class XsdToPhpGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function testItResolvesEntrypointFromIncludedSchemaUsingUnprefixedSchemaNodes(): void
    {
        $outputDirectory = $this->createTemporaryDirectory();
        $config = $this->buildConfig([
            'generator' => [
                'input_schema' => $this->schemaPath('multifile/order-root.xsd'),
                'entrypoint' => 'PurchaseOrderType',
                'output_directory' => $outputDirectory,
                'base_namespace' => 'Tests\\Generated\\Fallback',
                'namespace_map' => [
                    'http://example.org/order' => 'Tests\\Generated\\Order',
                    'http://example.org/common' => 'Tests\\Generated\\Common',
                ],
                'schema_locations' => [
                    'http://example.org/order' => $this->schemaPath('multifile/order-root.xsd'),
                    'http://example.org/common' => $this->schemaPath('multifile/common-types.xsd'),
                ],
            ],
        ]);

        $result = (new XsdToPhpGenerator())->generate($config);
        $generatedFile = $outputDirectory . DIRECTORY_SEPARATOR . 'PurchaseOrderType.php';

        self::assertFileExists($generatedFile);
        self::assertSame([$generatedFile], $result->generatedFiles);

        $contents = (string) file_get_contents($generatedFile);
        self::assertStringContainsString('namespace Tests\\Generated\\Order;', $contents);
        self::assertStringContainsString("#[XmlRoot('purchaseOrder', namespace: 'http://example.org/order'", $contents);
        self::assertStringContainsString('final class PurchaseOrderType', $contents);
        self::assertStringContainsString('resolved its schema graph through xs:include/xs:import directives', implode("\n", $result->warnings));
    }

    public function testItKeepsSchemaLocalNamespaceDeclarationsByDefault(): void
    {
        $outputDirectory = $this->createTemporaryDirectory();
        $config = $this->buildConfig([
            'generator' => [
                'input_schema' => $this->schemaPath('prefix-preference/entrypoint.xsd'),
                'entrypoint' => 'MessageType',
                'output_directory' => $outputDirectory,
                'base_namespace' => 'Tests\\Generated\\Communication',
                'namespace_map' => [
                    'http://example.org/communication' => 'Tests\\Generated\\Communication',
                ],
                'schema_locations' => [
                    'http://example.org/communication' => $this->schemaPath('prefix-preference/entrypoint.xsd'),
                ],
            ],
        ]);

        (new XsdToPhpGenerator())->generate($config);
        $contents = (string) file_get_contents($outputDirectory . DIRECTORY_SEPARATOR . 'MessageType.php');

        self::assertStringContainsString("'kom' => 'http://example.org/communication'", $contents);
        self::assertStringContainsString("'aux' => 'http://example.org/auxiliary'", $contents);
        self::assertStringNotContainsString("'komm' => 'http://example.org/communication'", $contents);
    }

    public function testItCanPreferEntrypointNamespaceDeclarationsOverSchemaLocalPrefixes(): void
    {
        $outputDirectory = $this->createTemporaryDirectory();
        $config = $this->buildConfig([
            'generator' => [
                'input_schema' => $this->schemaPath('prefix-preference/entrypoint.xsd'),
                'entrypoint' => 'MessageType',
                'output_directory' => $outputDirectory,
                'base_namespace' => 'Tests\\Generated\\Communication',
                'namespace_map' => [
                    'http://example.org/communication' => 'Tests\\Generated\\Communication',
                ],
                'schema_locations' => [
                    'http://example.org/communication' => $this->schemaPath('prefix-preference/entrypoint.xsd'),
                ],
                'prefer_entrypoint_namespace_declarations' => true,
            ],
        ]);

        (new XsdToPhpGenerator())->generate($config);
        $contents = (string) file_get_contents($outputDirectory . DIRECTORY_SEPARATOR . 'MessageType.php');

        self::assertStringContainsString("'komm' => 'http://example.org/communication'", $contents);
        self::assertStringContainsString("'aux' => 'http://example.org/auxiliary'", $contents);
        self::assertStringNotContainsString("'kom' => 'http://example.org/communication'", $contents);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildConfig(array $config): GeneratorConfig
    {
        return GeneratorConfig::fromArray(array_replace([
            'input_schema' => '',
            'entrypoint' => '',
            'output_directory' => 'generated',
            'base_namespace' => '',
            'namespace_map' => [],
            'schema_locations' => [],
            'class_suffix' => 'Type',
            'strict_types' => true,
            'overwrite_existing' => false,
            'prefer_entrypoint_namespace_declarations' => false,
        ], $config['generator'] ?? $config));
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xsd-to-php-tests-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            self::fail(sprintf('Unable to create temporary directory "%s".', $directory));
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function schemaPath(string $relativePath): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function buildMappingsForXgewerbeordnung(): array
    {
        $schemaLocations = [];
        $namespaceMap = [];

        foreach (glob($this->schemaPath('xgewerbeordnung/*.xsd')) ?: [] as $schemaPath) {
            $document = new DOMDocument();
            if ($document->load($schemaPath) === false) {
                self::fail(sprintf('Unable to load test schema "%s".', $schemaPath));
            }

            $targetNamespace = $document->documentElement?->getAttribute('targetNamespace') ?: null;
            if ($targetNamespace === null || $targetNamespace === '') {
                continue;
            }

            $schemaLocations[$targetNamespace] = $schemaPath;
            $namespaceMap[$targetNamespace] = match ($targetNamespace) {
                'http://www.xgewerbeordnung.de/spezifikation/xga/1.6' => 'Tests\\Generated\\Xga',
                'http://www.xgewerbeordnung.de/spezifikation/baukasten/1.6' => 'Tests\\Generated\\Baukasten',
                'http://www.xgewerbeordnung.de/spezifikation/erl/1.6' => 'Tests\\Generated\\Erl',
                default => 'Tests\\Generated\\Support\\' . $this->supportNamespaceSegment($targetNamespace),
            };
        }

        return [$schemaLocations, $namespaceMap];
    }

    private function supportNamespaceSegment(string $targetNamespace): string
    {
        $tokens = preg_split('/[^A-Za-z0-9]+/', strtolower($targetNamespace), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_slice($tokens ?: [], -2);

        if ($tokens !== [] && ctype_digit(implode('', $tokens))) {
            $allTokens = preg_split('/[^A-Za-z0-9]+/', strtolower($targetNamespace), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_slice($allTokens, -3);
        }

        if ($tokens === []) {
            return 'Generic';
        }

        return implode('', array_map(static fn (string $token): string => ucfirst($token), $tokens));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
