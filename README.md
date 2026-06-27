# Laravel Resource Exporter

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)
[![Build Status](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)

A Laravel package for exporting API resources into other formats. It turns a `JsonResource`, a `ResourceCollection`, or
a raw array of rows into a CSV or XML string through a small, driver-based API, so resource payloads can be downloaded
or shipped elsewhere without bespoke serialization code.

The package is registered automatically through Laravel's package auto-discovery; publish the config (see below) to
customise drivers or register your own.

## How It Works

The package registers an `ExportManager` - bound to the `exporter` container alias and reachable through the `Exporter`
facade - that resolves named exporters from `config/exporter.php`. Each named exporter uses a driver (`csv` or `xml` out
of the box) to serialize a single resource, a resource collection, or a plain array of rows.

A few rules hold:

- **Driver-agnostic API.** Every driver implements the same `Exporter` contract (`exportItem`, `exportCollection`,
  `exportArray`), so switching `csv` for `xml` needs no other code change.
- **Consistent field exclusion.** `withoutFields()` strips the same keys across every driver before serialization.
- **Extensible by composition.** Register your own driver with `ExportManager::extend()` without touching package code.
  The built-in `csv` and `xml` drivers are `final`; custom formats are added as new drivers, not by subclassing.

## Installation

```bash
composer require sinemacula/laravel-resource-exporter
```

The service provider is auto-discovered.

## Configuration

Publish the package configuration:

```bash
php artisan vendor:publish --provider="SineMacula\Exporter\ExporterServiceProvider"
```

This creates `config/exporter.php`, where you can control:

| Key         | Description                                                        | Default      |
|-------------|--------------------------------------------------------------------|--------------|
| `default`   | The default exporter name used when none is given to `format()`.   | `csv`        |
| `exporters` | Named exporters, each with a `driver` (`csv` / `xml`) and options. | `csv`, `xml` |
| `alias`     | The container / facade accessor alias for the manager.             | `exporter`   |

Per-driver options (delimiter, enclosure, XML root element, pretty printing, and so on) live alongside each entry in the
`exporters` array.

## Usage

### Basic usage

```php
use SineMacula\Exporter\Facades\Exporter;
use App\Http\Resources\YourResource;

// Export a single resource as CSV
$csv = Exporter::format('csv')->exportItem(new YourResource($item));

// Export a collection as XML
$xml = Exporter::format('xml')->exportCollection(YourResource::collection($collection));
```

### Field exclusion

```php
$csv = Exporter::format('csv')
    ->withoutFields(['internal_id', 'debug'])
    ->exportCollection(YourResource::collection($collection));
```

### On-demand exporters

```php
use SineMacula\Exporter\Facades\Exporter;

// Build an exporter from ad-hoc config without a named entry
$exporter = Exporter::build([
    'driver'    => 'csv',
    'delimiter' => ';',
]);
```

### Custom drivers

```php
use SineMacula\Exporter\ExportManager;

app(ExportManager::class)->extend('json', function ($app, array $config) {
    return new App\Exporters\JsonExporter($config);
});
```

## Requirements

- PHP ^8.3
- Laravel ^12.0

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 95)
composer test:mutation:full  # full mutation suite without thresholds
composer bench               # PHPBench benchmarks for the exporter hot paths
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
