# Changelog

## [3.1.0](https://github.com/sinemacula/laravel-resource-exporter/compare/v3.0.0...v3.1.0) (2026-07-22)


### Features

* support Laravel 13 ([#62](https://github.com/sinemacula/laravel-resource-exporter/issues/62)) ([e484618](https://github.com/sinemacula/laravel-resource-exporter/commit/e484618178de5efb1ff45f01ffb0a34117d655fa))

## [3.0.0](https://github.com/sinemacula/laravel-resource-exporter/compare/v2.0.1...v3.0.0) (2026-06-30)

v3 is a ground-up rewrite around HTTP content negotiation. The package no longer
ships per-format "drivers"; a single streaming engine serves any registered
format through content negotiation or a fluent explicit-export API. See
[`UPGRADE.md`](UPGRADE.md) for the full 2.x to 3.0 migration guide.

### ⚠ BREAKING CHANGES

* **v2 driver API removed.** `Exporter::format('csv')->exportCollection()`,
  `Exporter::build()`, `exportArray()`, `exportItem()`, `withoutFields()`,
  `withoutHeaders()`, custom driver registration through `ExportManager`,
  the `Exporters\` drivers, the `Contracts\Exporter` contract, and the
  `exporter.exporters` config block are gone. Use the `RespondsWithExports`
  trait or the `Exporter` facade's `export()` / `collection()` / `query()` /
  `queue()` verbs.
* **Tabular formats require a schema.** CSV, TSV, and XLSX now need the resource
  to declare a `TabularSchema`; a resource without one returns `406 Not
  Acceptable` for a tabular format. Hierarchical formats (JSON, XML, NDJSON)
  still serialize the resource's `toArray()` shape with no extra config.
* **Environment variable renamed** from `DEFAULT_EXPORTER` to `EXPORTER_DEFAULT`
  (the `exporter.default` config key is unchanged); the old name is no longer
  read.
* **`ExporterServiceProvider` is now `final`** and its previously `protected`
  extension points were removed.
* **Runtime requirements raised** to PHP 8.3+ and Laravel 12. XLSX support is now
  optional and requires `openspout/openspout:^4.0`.
* **Public v2 classes and contracts removed** (see the removed public classes
  and contracts section in `UPGRADE.md`).

### Features

* **Content negotiation.** A `JsonResource` using `RespondsWithExports` serves
  JSON by default and streams CSV/TSV/XLSX/XML/JSON/NDJSON when the request asks
  via the `Accept` header or `?format=`, for both single resources and the
  collection or page a route returns. JSON stays first-class: `q=0` is honoured
  and every response carries `Vary: Accept`.
* **Full-query exports.** `ResourceExport::forQuery()` switches a negotiated
  endpoint from paginated JSON to the entire dataset, behind a configurable row
  cap.
* **Fluent explicit exports.** The `Exporter` facade builds an export from a
  resource, collection, or query and returns a builder whose verbs - `download()`,
  `store()`, `toString()`, `toStream()`, `toResponse()` - decide where the bytes
  go.
* **Serializable queued exports.** `Exporter::queue($model, $resource)` writes a
  full-set export to a disk and delivers it behind a signed temporary URL, with a
  re-checked full-set authorization gate and an `ExportCompleted` audit event.
* **Lifecycle events.** Queued exports fire `ExportStarting`, `RowsExported`,
  `ExportCompleted`, and `ExportFailed`; streamed-response failures after bytes
  have started are surfaced through `StreamExportFailed` with row and actor
  context.
* **Six formats split by dimensionality.** Hierarchical formats (JSON, XML,
  NDJSON) serialize `toArray()`; tabular formats (CSV, TSV, XLSX) use a
  `TabularSchema` of `Column`s with casts, has-many aggregates, and per-request
  visibility.
* **Constant-memory streaming.** Rows are pulled lazily with keyset (`lazyById`)
  pagination and textual/hierarchical writers flush progressively. XLSX finalises
  a temporary workbook before the response flushes, so large spreadsheets are
  best queued.
* **Extensible by configuration.** Register custom formats and casters in
  `config/exporter.php`; the negotiated and explicit paths resolve the same shared
  registry. The engine is stateless and Octane-safe.
* **Testing fake.** `Exporter::fake()` records downloads, disk writes, string
  exports, stream writes, queued exports, and row counts without writing bytes or
  dispatching jobs.
* **Legacy negotiation middleware.** The opt-in `exporter.negotiate` middleware
  can convert simple JSON responses to tabular exports while applications migrate
  to the `RespondsWithExports` trait.
* **Tunable negotiation.** Configure `max_rows`, `per_page`, `chunk_size`, and
  `default_format`, plus an optional audit log channel.
