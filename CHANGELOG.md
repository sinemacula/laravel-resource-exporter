# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The 3.0 release re-architects the package around content-negotiated API
resource exports. It targets Laravel 12 and PHP 8.3 only.

### Added

- Content negotiation engine that serves a full-dataset export and its
  paginated JSON from the same endpoint, switching cardinality by the `Accept`
  header or a `?format=` query parameter (`ResourceExport`).
- Fluent explicit-export builder `Exporter::export()` with the community verb
  vocabulary: `download()`, `toResponse()`, `store()`, `toString()`,
  `toStream()`.
- Serializable queued export door `Exporter::queue($model, $resource)` that
  streams a full dataset to a storage disk on a queue worker at constant memory.
- Pinned `ExportCompleted` audit event, fired by both the synchronous streamed
  path and the queued-to-disk pipeline, now carrying a `queued` discriminator.
- Recording test double `Exporter::fake()` (`ExporterFake`) with
  `assertDownloaded()`, `assertStored()`, `assertStringExported()`,
  `assertStreamedTo()`, `assertQueued()`, `assertExportedRows()`, and
  `assertNothingExported()`.
- `negotiation` config block (`max_rows`, `per_page`, `chunk_size`), with a
  `max_rows` default of `10000` capping a synchronous streamed export.
- `formats` config block for registering custom negotiable formats with the
  shared media type registry.

### Changed

- **BREAKING:** Renamed the default-format environment variable from
  `DEFAULT_EXPORTER` to `EXPORTER_DEFAULT`. The config key (`exporter.default`)
  is unchanged. The old `DEFAULT_EXPORTER` name is no longer read. See
  `UPGRADE.md`.
- **BREAKING:** `ExporterServiceProvider` is now `final`, and its previously
  `protected` extension points (`resolveConfigPath()`,
  `hasConfigPathFunction()`) have been removed. Customise behaviour through the
  published `config/exporter.php` (formats, negotiation, audit) instead of
  subclassing the provider. See `UPGRADE.md`.

### Migration

See [`UPGRADE.md`](UPGRADE.md) for step-by-step migration guidance from 2.x.
