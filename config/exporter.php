<?php

declare(strict_types = 1);

return [

    /*
    |---------------------------------------------------------------------------
    | Default Exporter
    |---------------------------------------------------------------------------
    |
    | This option controls the default exporter format that will be used when no
    | specific format is requested. You can set this to any of the supported
    | formats provided in the 'exporters' configuration below.
    |
    | The environment variable was renamed from DEFAULT_EXPORTER to
    | EXPORTER_DEFAULT in v3. The legacy DEFAULT_EXPORTER name is still honoured
    | as a fallback for one release to ease the upgrade; migrate to
    | EXPORTER_DEFAULT, as the legacy name will be removed in a future major.
    |
    */

    'default' => env('EXPORTER_DEFAULT', env('DEFAULT_EXPORTER', 'csv')),

    /*
    |---------------------------------------------------------------------------
    | Exporter Configurations
    |---------------------------------------------------------------------------
    |
    | Here you may define all of the exporters that your application supports.
    | Each exporter corresponds to a specific driver that handles the conversion
    | of resources to the desired format. You can customize each exporter by
    | setting additional options like whether to include sub-resources in the
    | output.
    |
    | Supported Drivers: "csv", "xml"
    |
    | Available Options:
    |
    | CSV Driver:
    |   - 'delimiter' (string): The delimiter used to separate values.
    |                           Default is ','.
    |   - 'enclosure' (string): The enclosure character used to wrap values.
    |                           Default is '"'.
    |
    | XML Driver:
    |   - 'root_element' (string|null): The name of the root XML element.
    |                                   Default is null, which uses the resource
    |                                   name as the root element.
    |   - 'pretty_print' (bool): Whether to pretty-print the XML output.
    |                            Default is true.
    |   - 'include_sub_resources' (bool): Whether to include sub-resources in
    |                                     the output. Default is true.
    |
    */

    'exporters' => [

        'csv' => [
            'driver' => 'csv',
        ],

        'xml' => [
            'driver' => 'xml',
        ],

    ],

    /*
    |---------------------------------------------------------------------------
    | Exporter Alias
    |---------------------------------------------------------------------------
    |
    | This option controls the alias name used for the Exporter facade in your
    | application. By default, it is set to 'exporter', but you can change this
    | value to any alias that suits your application's needs.
    |
    */

    'alias' => env('EXPORTER_ALIAS', 'exporter'),

    /*
    |---------------------------------------------------------------------------
    | Negotiable Formats
    |---------------------------------------------------------------------------
    |
    | The content-negotiation engine ships its own first-class formats (json,
    | csv, tsv, xlsx, xml, ndjson) seeded into a single boot-time, shared media
    | type registry. This block is the supported extension point: each entry
    | registers an additional format with that shared registry, so a custom
    | format becomes negotiable over the Accept header and a ?format= query
    | parameter, and reachable through the explicit Exporter::export() builder -
    | both resolve the same registry, so they always agree on the available
    | formats.
    |
    | Each entry may be a SineMacula\Exporter\Http\ExportFormat instance, a
    | callable returning one, or the class name of a binding that resolves to a
    | SineMacula\Exporter\Contracts\Format through the container. Because a
    | tabular format carries a writer factory (a closure), register such formats
    | from a service provider rather than relying on `config:cache`. The
    | registry is built once at boot and never mutated per request, so it is
    | Octane-safe.
    |
    */

    'formats' => [],

    /*
    |---------------------------------------------------------------------------
    | Content Negotiation
    |---------------------------------------------------------------------------
    |
    | These options govern the query-aware export endpoint helper
    | (SineMacula\Exporter\ResourceExport), which serves a paginated JSON
    | collection for a JSON request and streams the full dataset for an export
    | request from the same route.
    |
    | Available Options:
    |
    |   - 'max_rows' (int|null): The hard cap on how many rows a single
    |                            synchronous, streamed export may emit. The
    |                            full set is a different, larger response than
    |                            the visible page, so this guards a route from
    |                            streaming an unbounded result over HTTP. The
    |                            default is 10000; exceeding it throws a
    |                            RowLimitExceeded (queue the export, or lift the
    |                            cap per call with ->unlimited()). Set to null
    |                            to disable the cap globally (not recommended).
    |   - 'per_page' (int): The page size used for the paginated JSON response
    |                       when the request is not an export. Default is 15.
    |   - 'chunk_size' (int): The keyset chunk size used while streaming the
    |                         full set. Larger chunks mean fewer queries but
    |                         more memory per chunk. Default is 1000.
    |
    */

    'negotiation' => [
        'max_rows'   => env('EXPORTER_MAX_ROWS', 10000),
        'per_page'   => env('EXPORTER_PER_PAGE', 15),
        'chunk_size' => env('EXPORTER_CHUNK_SIZE', 1000),
    ],

    /*
    |---------------------------------------------------------------------------
    | Audit Logging
    |---------------------------------------------------------------------------
    |
    | Every full-set and queued export fires an ExportCompleted event carrying
    | the actor, row count, filename, format and completion time. Set a log
    | channel here to additionally route that audit payload to a dedicated log;
    | leave it null to rely on the event alone. Routing is optional and never
    | gates an export.
    |
    */

    'audit' => [
        'channel' => env('EXPORTER_AUDIT_CHANNEL'),
    ],

];
