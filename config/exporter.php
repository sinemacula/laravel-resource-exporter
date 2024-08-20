<?php

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
    */

    'default' => env('DEFAULT_EXPORTER', 'csv'),

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
            'driver' => 'csv'
        ],

        'xml' => [
            'driver' => 'xml'
        ]

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

    'alias' => env('EXPORTER_ALIAS', 'exporter')

];
