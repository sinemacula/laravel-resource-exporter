# Laravel Resource Exporter

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)
[![Build Status](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-resource-exporter/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-resource-exporter)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-resource-exporter.svg)](https://packagist.org/packages/sinemacula/laravel-resource-exporter)

Return any API resource in the format the client asks for. Add one trait to a `JsonResource`, declare the columns that
make sense as a table, and the same endpoint that serves JSON will stream **CSV, TSV, XLSX, XML, JSON, or NDJSON** when
the request asks for it - over the `Accept` header or a `?format=` query parameter - with no second controller and no
bespoke serialization code.

Rows are pulled lazily from the source, so CSV, TSV, JSON, XML, and NDJSON exports stay flat in memory even for large
queries. XLSX is built as a temporary workbook before the response can flush, so large spreadsheets are best queued to
disk behind a signed URL. The same engine drives inline downloads, queued exports, and one-off fluent exports.

The package is registered automatically through Laravel's package auto-discovery; publish the config (see below) to
register custom formats or tune the negotiation limits.

## How It Works

There are two doors onto one streaming engine:

- **Content negotiation.** A `JsonResource` that uses the `RespondsWithExports` trait keeps serving JSON by default but
  answers `Accept: text/csv` (or `?format=csv`) by streaming the negotiated format instead - for both a single resource
  and the collection/page returned by the route. Use `ResourceExport::forQuery()` or a queued export when the export
  request should switch from paginated JSON to the full query. JSON stays first-class: a browser's default `Accept`
  always resolves to JSON, `q=0` is honoured, and every response carries `Vary: Accept`.
- **Explicit exports.** The `Exporter` facade builds an export from a resource, a collection, or a query and returns a
  fluent builder whose verbs - `download()`, `store()`, `toString()`, `toStream()`, `toResponse()`, `queue()` - decide
  where the bytes go.

Formats split by **dimensionality**:

- **Hierarchical** formats (JSON, XML, NDJSON) serialize the resource's existing `toArray()` shape, one item at a time.
  They need no extra configuration and honour the resource's field gating.
- **Tabular** formats (CSV, TSV, XLSX) are single-dimensional and cannot flatten arbitrary nesting, so the resource
  declares a `TabularSchema` - an explicit list of `Column`s with casts, aggregates, and visibility - that defines
  exactly what a row looks like. A resource without a tabular schema still negotiates the hierarchical formats and
  returns `406 Not Acceptable` for a tabular one.

A few rules hold across the surface:

- **Lazy row pipeline.** Rows are pulled from the source lazily (keyset `lazyById` pagination for queries) and passed
  straight through the engine. Textual and hierarchical writers flush progressively; XLSX finalises a temporary workbook
  before handing it to the sink.
- **Stateless and Octane-safe.** Nothing caches the request or a mutable driver between exports; the media-type registry
  is built once at boot and read-only thereafter.
- **Extensible by configuration.** Register a custom format in `config/exporter.php`; the negotiated and explicit paths
  resolve the same registry, so they always agree on the available formats.

## Installation

```bash
composer require sinemacula/laravel-resource-exporter
```

The service provider is auto-discovered. XLSX support is optional - pull it in when you need it:

```bash
composer require openspout/openspout
```

## Configuration

Publish the package configuration:

```bash
php artisan vendor:publish --provider="SineMacula\Exporter\ExporterServiceProvider"
```

This creates `config/exporter.php`, where you can control:

| Key           | Description                                                                      | Default      |
|---------------|----------------------------------------------------------------------------------|--------------|
| `default`     | Default format for the legacy `format()` API (env `EXPORTER_DEFAULT`).           | `csv`        |
| `exporters`   | Named legacy drivers (`csv`, `xml`) and their per-driver options.                | `csv`, `xml` |
| `alias`       | The container / facade accessor alias for the manager (env `EXPORTER_ALIAS`).    | `exporter`   |
| `formats`     | Custom negotiable formats registered with the shared media-type registry.        | `[]`         |
| `negotiation` | Query-endpoint limits: `max_rows` (10000), `per_page` (15), `chunk_size` (1000). | see file     |
| `audit`       | Optional log `channel` for the `ExportCompleted` audit payload.                  | `null`       |

## Usage

### Content negotiation

Add the `RespondsWithExports` trait and implement `ProvidesTabularExport` on your resource. The resource keeps returning
JSON; a `TabularSchema` describes the table the tabular formats produce:

```php
// app/Http/Resources/UserResource.php
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Schema\TabularSchema;

class UserResource extends JsonResource implements ProvidesTabularExport
{
    use RespondsWithExports;

    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'name'  => $this->name,
            'email' => $this->email,
        ];
    }

    public function tabular(Request $request): TabularSchema
    {
        return new UserExportSchema($request);
    }
}
```

```php
// app/Exports/UserExportSchema.php
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

class UserExportSchema extends TabularSchema
{
    public function columns(): array
    {
        return [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('email', 'Email'),
            Column::make('created_at', 'Joined')->date('Y-m-d'),
            Column::make('orders', 'Orders')->count(),
        ];
    }
}
```

The route is unchanged - return the resource or collection exactly as you already do:

```php
Route::get('/users', fn () => UserResource::collection(User::query()->paginate()));
```

The response format then follows the request:

```http
GET /users                              -> JSON (default)
GET /users    Accept: text/csv          -> streamed CSV download
GET /users?format=xlsx                  -> streamed XLSX download
GET /users    Accept: application/xml   -> streamed XML
```

### Supported formats

| Format   | Media type(s)                                                       | Shape                  |
|----------|---------------------------------------------------------------------|------------------------|
| `json`   | `application/json`                                                  | Hierarchical (default) |
| `csv`    | `text/csv`                                                          | Tabular                |
| `tsv`    | `text/tab-separated-values`                                         | Tabular                |
| `xlsx`   | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | Tabular                |
| `xml`    | `application/xml`, `text/xml`                                       | Hierarchical           |
| `ndjson` | `application/x-ndjson`, `application/jsonl`                         | Hierarchical           |

### Columns

`Column::make($key, $heading)` reads `$key` off each row; chainable modifiers cast, format, aggregate, and gate it:

```php
Column::make('created_at', 'Joined')->date('Y-m-d');              // date / dateTime
Column::make('total', 'Total')->number(2);                        // numeric formatting
Column::make('active', 'Active')->boolean('Yes', 'No');           // boolean labels
Column::make('status', 'Status')->enum();                         // backed enum -> value
Column::make('orders', 'Orders')->count();                        // has-many count (withCount)
Column::make('orders', 'Revenue')->sum('total');                  // has-many sum (withSum)
Column::make('tags', 'Tags')->join(', ');                         // implode a relation
Column::make('full_name', 'Name')
    ->resolveUsing(fn ($user) => "{$user->first} {$user->last}"); // computed value
```

Aggregates are folded into the query (`withCount` / `withSum`), so they add no per-row queries.

### Column visibility

Tabular exports (CSV / TSV / XLSX) resolve each column straight off the raw model attribute via `data_get`. That read is
deliberately fast and constant-memory, but it does **not** route through the resource's `toArray()` / `$hidden` /
`when()` gating - so a `$hidden` attribute is still emitted, and field visibility is not inherited from the resource.
Gating a field out of an export is the schema author's job, expressed with `->visible()` on the column:

```php
use SineMacula\Exporter\Schema\Column;
use Illuminate\Http\Request;

Column::make('salary', 'Salary')
    ->visible(static fn (Request $request): bool => $request->user()?->isAdmin() ?? false);
```

A column whose `->visible()` gate returns `false` is dropped entirely - from the heading row and from every data row -
so its value can never leak through any cell or aggregate path. The gate sees only the request (no row) and is evaluated
once when the schema is built.

### Explicit exports

When you want bytes on demand rather than over HTTP, build an export through the facade:

```php
use SineMacula\Exporter\Facades\Exporter;

// Inline download response
return Exporter::collection(UserResource::collection($users))
    ->format('csv')
    ->download('users.csv');

// Store on a disk, get the path back
$path = Exporter::query(User::query()->where('active', true), UserResource::class)
    ->format('xlsx')
    ->store('s3', 'exports/users.xlsx');

// Raw string
$csv = Exporter::export(new UserResource($user))->format('csv')->toString();
```

### Query endpoint (paginated JSON or full export)

`ResourceExport::forQuery()` serves a paginated JSON collection for a normal request and streams the full dataset for an
export request, from one route - with the safety rails a full-table stream needs:

```php
use SineMacula\Exporter\ResourceExport;

Route::get('/users/export', function () {
    return ResourceExport::forQuery(User::query(), UserResource::class)
        ->authorizeUsing(fn ($request) => $request->user()->can('export', User::class))
        ->paginatedJsonOrStreamedExport();
});
```

The full set is capped at `negotiation.max_rows` (default 10000); raise it per call with `->unlimited()` or
`->maxRows()`, or queue the export. Authorization is explicit: call `authorizeUsing()` (or `withoutAuthorization()` to
opt out deliberately), or the streamed export refuses to run.

### Queued exports

For large datasets, queue the export to a disk and hand the user a signed URL when it lands:

```php
use SineMacula\Exporter\Facades\Exporter;

Exporter::queue(User::class, UserResource::class)
    ->schema(UserExportSchema::class)
    ->format('xlsx')
    ->toDisk('s3', 'exports/users.xlsx')
    ->by($request->user())
    ->authorize('export')
    ->queue();
```

The job streams chunk by chunk into a local staging file and fires `ExportStarting`, `RowsExported`, `ExportCompleted`
(carrying a signed temporary URL), and `ExportFailed`. The query is described by a serializable specification - constrain
it with the query verbs on the builder rather than passing a live builder. HTTP streaming failures after bytes have
started are reported separately through `StreamExportFailed`, with the format, actor and number of rows written.

### Testing

`Exporter::fake()` swaps the engine for a recorder, so tests assert what would have been exported without producing
bytes or dispatching jobs - mirroring `Storage::fake()` and `Bus::fake()`:

```php
use SineMacula\Exporter\Facades\Exporter;

$exporter = Exporter::fake();

// ... exercise code that exports ...

$exporter->assertDownloaded('users.csv');
$exporter->assertStored('s3', 'exports/users.xlsx');
$exporter->assertQueued();
$exporter->assertExportedRows(42);
```

### Custom formats

Register an additional negotiable format from a service provider via the `formats` block in `config/exporter.php`; the
negotiated and explicit paths share one registry, so a custom format is reachable from both:

```php
// config/exporter.php
'formats' => [
    \App\Exports\ParquetFormat::class,
],
```

### Legacy array and string API

The original v2 surface still works for turning a single resource, a collection, or a raw array of rows into a string:

```php
$csv = Exporter::format('csv')->exportCollection(UserResource::collection($users));
$xml = Exporter::format('xml')->withoutFields(['internal_id'])->exportArray($rows);
```

## Requirements

- PHP ^8.3
- Laravel ^12.0

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
composer bench               # PHPBench suite for the hot paths
composer bench:ci            # PHPBench with CI artifact dump
composer bench:smoke         # single-rev pass to verify every subject runs
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
