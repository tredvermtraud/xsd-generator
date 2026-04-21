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

## CI and Release Process

The repository currently uses two GitHub Actions workflows:

- `CI` runs on every pull request targeting `main` and on every push to `main`
- `Release` runs on every push to `main` and is powered by [Release Please](https://github.com/googleapis/release-please)

### CI

The CI workflow validates `composer.json`, installs dependencies, and runs the PHPUnit suite against:

- PHP 8.3
- PHP 8.4

### Releases

Releases are managed from `main` only. There is no `staging` branch or tag-driven release workflow anymore.

`release-please-action` watches commits on `main` and opens or updates a release PR based on the repository manifest and config files:

- `.release-please-config.json`
- `.release-please-manifest.json`

When the release PR is merged, Release Please creates the version tag and GitHub Release automatically.

### Commit Convention

Release Please depends on Conventional Commit-style messages to determine the next version bump and release notes. In practice:

- `fix:` triggers a patch release
- `feat:` triggers a minor release
- `feat!:` or a commit with a `BREAKING CHANGE:` footer triggers a major release

If merged commits do not follow this convention, Release Please may not produce the expected release.

### Maintainer Flow

1. Open a pull request against `main`.
2. Wait for the `CI` workflow to pass.
3. Merge using a Conventional Commit-style message.
4. Wait for Release Please to open or update the release PR.
5. Review and merge the release PR to publish the new GitHub Release.

### Repository Setup Note

The release workflow is configured to use the `RELEASE_PLEASE_TOKEN` secret. Repository maintainers must provide that secret for automated release PR creation and publishing.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
