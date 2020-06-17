<?php

return [
    'routes' => [
        'api' => [
            'plugin_csv_import_api' => [
                'path' => '/custom/importCsv',
                'controller' => 'CsvImportBundle:Api:importCsv',
                'method' => 'POST',
            ],
        ],
    ],
];
