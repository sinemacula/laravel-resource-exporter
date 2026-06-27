# Upgrade Guide

This document covers the breaking changes between major versions and the steps
required to migrate.

## Upgrading from 2.x to 3.0

Version 3 re-architects the package around content-negotiated API resource
exports (Laravel 12 and PHP 8.3 only). Most of the surface is additive, but a
small number of changes are breaking. Work through the items below.

### 1. Environment variable rename: `DEFAULT_EXPORTER` -> `EXPORTER_DEFAULT`

The environment variable that seeds the default export format was renamed for
consistency with the package's other `EXPORTER_*` variables.

- Old: `DEFAULT_EXPORTER`
- New: `EXPORTER_DEFAULT`

The config key itself (`exporter.default`) is unchanged.

For one release the legacy name is still honoured as a fallback, so an existing
`DEFAULT_EXPORTER` keeps working without immediate changes:

```php
'default' => env('EXPORTER_DEFAULT', env('DEFAULT_EXPORTER', 'csv')),
```

Migrate at your convenience - rename the variable in every `.env`, deployment
secret, and CI definition - because the legacy `DEFAULT_EXPORTER` fallback will
be removed in a future major:

```diff
-DEFAULT_EXPORTER=csv
+EXPORTER_DEFAULT=csv
```

If you publish and cache config (`php artisan config:cache`), re-cache after the
change.

### 2. `ExporterServiceProvider` is now `final`

`SineMacula\Exporter\ExporterServiceProvider` is now declared `final`, and the
`protected` extension points it previously exposed (`resolveConfigPath()` and
`hasConfigPathFunction()`) have been removed. Subclassing the provider to
override those seams is no longer supported.

If you previously extended the provider:

- Stop registering your subclass. Register the package provider directly (it is
  auto-discovered, so in most applications no registration is needed at all).
- Move any customisation out of the provider override and into the supported
  extension points instead:
  - Register additional negotiable formats through the `exporter.formats`
    config array (or from your own service provider against the shared
    `MediaTypeRegistry`).
  - Configure the default format, alias, content negotiation, and audit
    routing through the published `config/exporter.php`.

Re-publish the config to pick up the new blocks:

```bash
php artisan vendor:publish --tag=exporter-config
```

### 3. New configuration blocks

`config/exporter.php` ships two blocks the v3 engine reads. If you publish the
config, add them (or re-publish):

- `negotiation` - the synchronous, query-aware export endpoint helper
  (`ResourceExport`). Note the `max_rows` key, which caps a single synchronous
  streamed export at `10000` rows by default; exceeding it throws a
  `RowLimitExceeded`. Queue the export, or lift the cap per call with
  `->unlimited()`, for larger sets.
- `formats` - the supported extension point for registering custom negotiable
  formats with the shared media type registry.

### Notes on export behaviour

- A streamed CSV/TSV export is constant-memory, but XLSX finalises on close and
  therefore buffers the whole workbook before the response flushes. Queue large
  XLSX exports (`Exporter::queue(...)`) rather than streaming them synchronously
  over HTTP.
- An empty result set produces a header-less file by design (no rows means no
  header row is written).
