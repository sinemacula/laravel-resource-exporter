<?php

declare(strict_types = 1);

return [

    /*
    |---------------------------------------------------------------------------
    | Default Format
    |---------------------------------------------------------------------------
    |
    | The format an explicit export emits when no format is requested - the
    | Exporter::export(), Exporter::query() and Exporter::queue() entry points.
    | Set it to any built-in format (json, csv, tsv, xlsx, xml, ndjson) or a
    | custom format registered in the 'formats' block below.
    |
    */

    'default' => env('EXPORTER_DEFAULT', 'csv'),

    /*
    |---------------------------------------------------------------------------
    | Exporter Alias
    |---------------------------------------------------------------------------
    |
    | The container alias the Exporter facade resolves. Change it only if the
    | default 'exporter' binding name clashes with another package.
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
    | callable returning one, or the name of a container binding resolving to a
    | SineMacula\Exporter\Http\ExportFormat. Because a tabular format carries a
    | writer factory (a closure), config-cache friendly applications should bind
    | those formats in a service provider and list the binding name here.
    | The registry is built once at boot and never mutated per request, so it is
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
    |   - 'default_format' (string|null): The format the negotiator falls
    |                            back to when neither the Accept header nor a
    |                            ?format= query parameter matches. Null defers
    |                            to the registry's built-in default (json).
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
        'default_format' => env('EXPORTER_NEGOTIATION_DEFAULT'),
        'max_rows'       => env('EXPORTER_MAX_ROWS', 10000),
        'per_page'       => env('EXPORTER_PER_PAGE', 15),
        'chunk_size'     => env('EXPORTER_CHUNK_SIZE', 1000),
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
