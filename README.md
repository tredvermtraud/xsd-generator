# XSD Generator

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3%7C%5E8.4-blue.svg)](https://php.net/)
![AI Assisted](https://img.shields.io/badge/AI-Assisted-firebrick?logo=githubcopilot)

A PHP library for generating DTO-style classes from XSD (XML Schema Definition) files. This generator creates attribute-mapped XML DTOs that can be used with the [ermtraud/xml-runtime](https://github.com/ermtraud/xml-runtime) library for seamless XML serialization and deserialization.

## Features

- **XSD to PHP Conversion**: Automatically generates PHP classes from XSD schemas
- **Multi-file Schema Support**: Handles complex schemas with `xs:include` and `xs:import` directives
- **Namespace Mapping**: Configurable mapping from XML namespaces to PHP namespaces
- **Attribute-mapped DTOs**: Generates classes compatible with attribute-based XML mapping
- **Configurable Output**: Customizable class suffixes, strict types, and overwrite behavior
- **Validation**: Built-in validation of generated classes and schema resolution

## Installation

Install via Composer:

```bash
composer require ermtraud/xsd-generator
```

## Requirements

- PHP 8.3 or 8.4
- `ext-dom` extension
- `ext-libxml` extension
- [ermtraud/xml-runtime](https://github.com/ermtraud/xml-runtime) for runtime XML handling

## Usage

### Basic Example

```php
use Ermtraud\XsdToPhp\Config\GeneratorConfig;
use Ermtraud\XsdToPhp\Generator\XsdToPhpGenerator;

$config = GeneratorConfig::fromArray([
    'input_schema' => 'path/to/your/schema.xsd',
    'entrypoint' => 'RootElement',
    'output_directory' => 'generated',
    'base_namespace' => 'Your\\Namespace',
    'namespace_map' => [
        'http://example.com/schema' => 'Your\\Schema',
    ],
    'schema_locations' => [
        'http://example.com/schema' => 'path/to/schema.xsd',
    ],
]);

$generator = new XsdToPhpGenerator();
$result = $generator->generate($config);

echo "Generated files:\n";
foreach ($result->generatedFiles as $file) {
    echo "- $file\n";
}

if (!empty($result->warnings)) {
    echo "Warnings:\n";
    foreach ($result->warnings as $warning) {
        echo "- $warning\n";
    }
}
```

## Configuration

The `GeneratorConfig` accepts the following options:

- `input_schema` (string, required): Path to the main XSD file
- `entrypoint` (string, required): Name of the root element or complex type to start generation from
- `output_directory` (string, required): Directory where generated classes will be saved
- `base_namespace` (string, required): Base PHP namespace for generated classes
- `namespace_map` (array, optional): Map XML namespaces to PHP namespaces
- `schema_locations` (array, optional): Map namespace URIs to schema file paths
- `class_suffix` (string, optional, default: 'Type'): Suffix to append to generated class names
- `strict_types` (bool, optional, default: true): Enable strict types in generated classes
- `overwrite_existing` (bool, optional, default: false): Whether to overwrite existing files
- `prefer_entrypoint_namespace_declarations` (bool, optional, default: false): Prefer `xmlns:*` prefixes from the configured `input_schema` when generating root namespace declarations, while still falling back to schema-local declarations for namespaces the entrypoint does not declare

## Testing

Run the test suite using PHPUnit:

```bash
composer test
```

## Release Process

This repository uses a tag-based release model with a stable `main` branch and a continuously testable `staging` branch.

- `main`: production-ready history only
- `staging`: release-candidate integration branch
- `feature/*`: feature work merged into `staging`
- `hotfix/*`: urgent fixes branched from `main`, merged back to `main`, then back-merged into `staging`

### Prereleases From `staging`

Every push to `staging` runs CI and, if it passes, publishes a GitHub prerelease with an auto-managed `staging-<short-sha>` tag. Older auto-generated staging prereleases are cleaned up first so the repository keeps one current prerelease instead of accumulating noise.

Developer flow:

1. Merge feature branches into `staging`.
2. Push `staging`.
3. Wait for the `Prerelease On Staging` workflow to pass.
4. Test the generated GitHub prerelease artifacts and notes.

### Production Releases From `main`

Production releases are created only from SemVer tags such as `v1.2.0` or `v2.0.1`. The release workflow verifies that the tagged commit is reachable from `main`, reruns CI, packages the library, and creates a full GitHub Release.

Developer flow:

1. Merge the validated release candidate from `staging` into `main`.
2. Create and push a production tag from `main`, for example:

```bash
git checkout main
git pull --ff-only
git tag v1.2.0
git push origin v1.2.0
```

3. Wait for the `Release On Version Tag` workflow to publish the official GitHub Release.

### Hotfix Flow

Hotfixes branch from `main`, not `staging`.

1. Create `hotfix/<name>` from `main`.
2. Merge the hotfix into `main`.
3. Tag the merge commit on `main` with the next production version and push the tag.
4. Back-merge the hotfix changes into `staging` so the next prerelease includes them.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
