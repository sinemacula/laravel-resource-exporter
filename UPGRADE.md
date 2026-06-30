# Upgrade Guide

This document covers the breaking changes between major versions and the steps
required to migrate.

## Upgrading from 2.x to 3.0

Version 3 replaces the v2 driver-manager API with a content-negotiated API
resource export engine. Treat this as a major migration if your application
calls the old facade driver methods, custom driver hooks, or published
`exporter.exporters` config.

### 1. Upgrade runtime requirements

v3 targets Laravel 12 and PHP `^8.3`.

Update your application and deployment environment for the new package
requirements:

- PHP `^8.3`
- Laravel `^12.0`
- `ext-filter`
- `ext-xmlwriter`
- `league/csv`
- `openspout/openspout:^4.0` when exporting XLSX

XLSX support remains optional at install time, but requesting XLSX without
OpenSpout installed throws `MissingXlsxDependency`.

### 2. Replace the v2 driver facade API

The old driver API was removed. These calls no longer exist:

- `Exporter::format()`
- `Exporter::build()`
- `exportArray()`
- `exportItem()`
- `exportCollection()`
- `withoutFields()`
- `withoutHeaders()`
- `ExportManager::extend()`
- `ExportManager::set()`
- `ExportManager::forgetExporter()`
- `ExportManager::purge()`
- `ExportManager::setApplication()`

Use the v3 explicit export builders instead:

```php
use SineMacula\Exporter\Facades\Exporter;

// v2
$csv = Exporter::format('csv')->exportItem(new UserResource($user));

// v3
$csv = Exporter::export(new UserResource($user))
    ->format('csv')
    ->toString();
```

```php
// v2
$csv = Exporter::format('csv')->exportCollection(UserResource::collection($users));

// v3
$csv = Exporter::collection(UserResource::collection($users))
    ->format('csv')
    ->toString();
```

```php
// v3 full-query export
return Exporter::query(User::query(), UserResource::class)
    ->format('csv')
    ->download('users.csv');
```

There is no direct `exportArray()` replacement on the facade. Wrap array rows in
a `JsonResource` / resource collection with a `TabularSchema`, or use the lower
level engine and writer contracts directly for non-resource export pipelines.

### 3. Add tabular schemas for CSV, TSV, and XLSX

v2 inferred CSV columns from resolved resource arrays and let you remove fields
with `withoutFields()`. v3 requires an explicit tabular schema for tabular
formats.

Implement `ProvidesTabularExport` on resources that can be exported as CSV, TSV,
or XLSX:

```php
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use SineMacula\Exporter\Http\Concerns\RespondsWithExports;
use SineMacula\Exporter\Schema\TabularSchema;

final class UserResource extends JsonResource implements ProvidesTabularExport
{
    use RespondsWithExports;

    public function tabular(Request $request): TabularSchema
    {
        return new UserExportSchema($request);
    }
}
```

Move field inclusion, headings, casts, and visibility into the schema:

```php
use SineMacula\Exporter\Schema\Column;
use SineMacula\Exporter\Schema\TabularSchema;

final class UserExportSchema extends TabularSchema
{
    public function columns(): array
    {
        return [
            Column::make('id', 'ID'),
            Column::make('name', 'Name'),
            Column::make('email', 'Email')
                ->visible(fn ($request) => $request->user()?->can('viewEmail')),
            Column::make('created_at', 'Joined')->date('Y-m-d'),
        ];
    }
}
```

Tabular columns read raw model / array values via `data_get`; they do not inherit
resource `$hidden`, `when()`, or `toArray()` field gating. Use explicit columns
and `Column::visible()` instead of `withoutFields()`.

If you previously used `withoutHeaders()` on the CSV driver, override
`TabularSchema::headings()` and return `false`.

### 4. Migrate content negotiation endpoints

For routes returning API resources, add `RespondsWithExports` to the resource.
The existing JSON route can then respond to export requests through `Accept` or
`?format=`:

```php
Route::get('/users', fn () => UserResource::collection(User::query()->paginate()));
```

Use `ResourceExport::forQuery()` when an export request should stream the full
query instead of the current JSON page:

```php
use SineMacula\Exporter\ResourceExport;

Route::get('/users/export', function () {
    return ResourceExport::forQuery(User::query(), UserResource::class)
        ->authorizeUsing(fn ($request) => $request->user()->can('export', User::class))
        ->paginatedJsonOrStreamedExport();
});
```

Full-query streamed exports now require an explicit authorization decision:
register `authorizeUsing()` or deliberately call `withoutAuthorization()`.

For routes you cannot yet move to `RespondsWithExports`, v3 ships an opt-in
legacy middleware alias:

```php
Route::get('/legacy-users', LegacyUsersController::class)
    ->middleware('exporter.negotiate');
```

The middleware inspects the already-rendered JSON response and blind-flattens a
simple object, list, or paginator `data` envelope into CSV, TSV, or XLSX when an
export format is negotiated. It is deliberately limited: it cannot use a
`TabularSchema`, cannot stream the original query, and returns the original JSON
response when the body cannot be flattened. Prefer the trait for new or actively
migrated resources.

### 5. Migrate queued exports

Queued exports use a dedicated serializable builder:

```php
Exporter::queue(User::class, UserResource::class)
    ->schema(UserExportSchema::class)
    ->format('xlsx')
    ->toDisk('s3', 'exports/users.xlsx')
    ->by($request->user())
    ->authorize('export')
    ->queue();
```

Queued exports write tabular formats only: CSV, TSV, and XLSX. Use synchronous
explicit exports or `ResourceExport` for hierarchical JSON, XML, and NDJSON
streams.

Queued exports cannot accept a live query builder or closure. Express filters
through the queued builder's serializable query verbs, such as `where()`,
`whereIn()`, `whereNull()`, `whereNotNull()`, `orderBy()`, `limit()`, or a named
scope.

Queued exports now expose lifecycle events:

- `ExportStarting`
- `RowsExported`
- `ExportCompleted`
- `ExportFailed`

Listen to `ExportCompleted` for post-export work such as sending an email with
the signed URL:

```php
use SineMacula\Exporter\Events\ExportCompleted;

Event::listen(ExportCompleted::class, function (ExportCompleted $event): void {
    // $event->url contains the temporary URL for queued exports when available.
});
```

Synchronous streamed-response failures after bytes have already started are
reported separately through `StreamExportFailed`.

### 6. Republish and update configuration

Republish the config:

```bash
php artisan vendor:publish --tag=exporter-config
```

The old `exporter.exporters` block is no longer read. Remove v2 driver config
like:

```php
'exporters' => [
    'csv' => ['driver' => 'csv', 'delimiter' => ';'],
    'xml' => ['driver' => 'xml', 'root_element' => 'Users'],
],
```

v3 reads these top-level blocks instead:

- `default` - default format for explicit builders and queued exports.
- `alias` - container / facade accessor alias.
- `formats` - custom `ExportFormat` registrations.
- `negotiation` - `ResourceExport` defaults: `default_format`, `max_rows`,
  `per_page`, and `chunk_size`.
- `audit` - optional log channel for completed export audit payloads.

The old CSV options (`delimiter`, `enclosure`) and XML options (`root_element`,
`pretty_print`, `include_sub_resources`) are not read from config. If you need a
customized built-in format, register a replacement `ExportFormat` with your own
writer factory.

### 7. Environment variable rename: `DEFAULT_EXPORTER` -> `EXPORTER_DEFAULT`

The environment variable that seeds the default export format was renamed for
consistency with the package's other `EXPORTER_*` variables.

- Old: `DEFAULT_EXPORTER`
- New: `EXPORTER_DEFAULT`

The config key itself (`exporter.default`) is unchanged.

v3 does not read the old name, so rename the variable in every `.env`,
deployment secret, and CI definition before deploying:

```diff
-DEFAULT_EXPORTER=csv
+EXPORTER_DEFAULT=csv
```

If you publish and cache config (`php artisan config:cache`), re-cache after the
change.

### 8. Replace custom drivers with custom formats

v2 custom drivers registered through `ExportManager::extend()` are no longer
supported. v3 custom formats are registered through `exporter.formats` and
described by `SineMacula\Exporter\Http\ExportFormat`.

For config-cache friendly tabular formats, bind the format in a service provider
and list the binding key in config:

```php
use App\Exports\Writers\ReportWriter;
use SineMacula\Exporter\Http\ExportFormat;

$this->app->singleton('exports.formats.report', static fn (): ExportFormat => new ExportFormat(
    'report',
    'report',
    'application/x-report',
    ['application/x-report'],
    true,
    static fn (): ReportWriter => new ReportWriter,
));
```

```php
'formats' => [
    'exports.formats.report',
],
```

Tabular custom formats need a writer implementing
`SineMacula\Exporter\Contracts\Writer`; hierarchical custom formats need a
writer implementing `SineMacula\Exporter\Contracts\HierarchicalWriter`.

### 9. Update export tests

v3 adds `Exporter::fake()` for tests that should assert export intent without
writing files, streaming bytes, or dispatching jobs:

```php
use SineMacula\Exporter\Facades\Exporter;

$exporter = Exporter::fake();

// Exercise code that exports...

$exporter->assertDownloaded('users.csv');
$exporter->assertStored('s3', 'exports/users.xlsx');
$exporter->assertQueued();
$exporter->assertStringExported('csv');
$exporter->assertStreamedTo('csv');
$exporter->assertExportedRows(42);
```

Queued export fakes reuse an existing `Bus::fake()` when one is already active.

### 10. `ExporterServiceProvider` is now `final`

`SineMacula\Exporter\ExporterServiceProvider` is now declared `final`, and the
`protected` extension points it previously exposed (`resolveConfigPath()` and
`hasConfigPathFunction()`) have been removed. Subclassing the provider to
override those seams is no longer supported.

If you previously extended the provider:

- Stop registering your subclass. Register the package provider directly; it is
  auto-discovered, so in most applications no registration is needed at all.
- Move customisation into supported config and service-provider registration
  points: `exporter.formats`, `MediaTypeRegistry`, and the published
  `config/exporter.php`.

### 11. Removed public classes and contracts

Remove imports and type hints for these v2 classes and contracts:

- `SineMacula\Exporter\Contracts\Exporter`
- `SineMacula\Exporter\Exporters\Exporter`
- `SineMacula\Exporter\Exporters\Csv`
- `SineMacula\Exporter\Exporters\Xml`

The v2 driver-manager extension methods were also removed:

- `Exporter::build()`
- `ExportManager::extend()`
- `ExportManager::set()`
- `ExportManager::forgetExporter()`
- `ExportManager::purge()`
- `ExportManager::setApplication()`

Use the v3 public surface instead:

- `SineMacula\Exporter\Contracts\ExportFactory`
- `SineMacula\Exporter\ExportBuilder`
- `SineMacula\Exporter\Export\QueuedExport`
- `SineMacula\Exporter\Contracts\ProvidesTabularExport`
- `SineMacula\Exporter\Schema\TabularSchema`
- `SineMacula\Exporter\Schema\Column`
- `SineMacula\Exporter\Http\ExportFormat`
- `SineMacula\Exporter\Testing\ExporterFake`

### 12. Notes on export behaviour

- CSV and TSV stream at constant memory.
- XML, JSON, and NDJSON stream hierarchical resource shapes.
- XLSX finalises a temporary workbook before the response flushes; queue large
  XLSX exports instead of streaming them synchronously over HTTP.
- The synchronous full-query helper is capped by `negotiation.max_rows`
  (`10000` by default). Raise it per call with `->maxRows()`, lift it with
  `->unlimited()`, or queue large tabular exports.
- An empty result set produces a header-less file by design; no rows means no
  header row is written.
