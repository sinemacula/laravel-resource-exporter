# Laravel Resource Exporter

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)
[![Build Status](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/845093401/shield?style=flat&branch=master)](https://github.styleci.io/repos/845093401)
[![Maintainability](https://api.codeclimate.com/v1/badges/4d8d29ba8b6a0a51920e/maintainability)](https://codeclimate.com/github/sinemacula/laravel-resource-exporter/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/4d8d29ba8b6a0a51920e/test_coverage)](https://codeclimate.com/github/sinemacula/laravel-resource-exporter/test_coverage)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)

Laravel Resource Exporter is a versatile package that enables seamless conversion of Laravel JsonResource and
JsonResourceCollection objects into various exportable formats. The package is designed with extensibility in mind,
allowing you to easily add custom export formats via a driver-based architecture.

## Features

- **Format Flexibility**: Export your resources to various formats using customizable drivers, making it easy to extend
  or adapt to new formats.
- **Driver-Based Architecture**: Each export format is handled by a dedicated driver class, ensuring clean,
  maintainable, and extendable code.
- **Facade Support**: Utilize an intuitive facade for easy access to export functionality, streamlining the process of
  converting resources.

## Supported Drivers

- *CSV*
- *XML*

## Installation

To install the Laravel Resource Exporter, run the following command in your project directory:

```bash
composer require sinemacula/laravel-resource-exporter
```

## Configuration

After installation, publish the package configuration to customize it according to your needs:

```bash
php artisan vendor:publish --provider="SineMacula\Exporter\ExporterServiceProvider"
```

This command publishes the package configuration file to your application's config directory, allowing you to modify
aspects such as the available export formats, driver configurations, and more.

## Usage

The Laravel Resource Exporter provides an easy-to-use facade for exporting resources. Below is an example of how to
export a resource or a collection:

```php
use SineMacula\Exporter\Facades\Exporter;
use App\Http\Resources\YourResource;

// Export an item as CSV
$csv = Exporter::format('csv')->exportItem(new YourResource($item));

// Export a collection as XML
$xml = Exporter::format('xml')->exportCollection(YourResource::collection($collection));
```

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security related issues, please email instead of using the issue tracker.

## License

The Laravel Resource Exporter repository is open-sourced software licensed under
the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
