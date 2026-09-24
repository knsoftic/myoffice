<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // phase-19-23 §6.3 binds "disk `private` is storage/app/private", and every
        // storage_disk column in phases 19-23 defaults to 'private'. Laravel 12 already
        // roots `local` there, so this is the same directory under the name five phase
        // contracts use — not a second location. It differs from `local` in two ways that
        // matter: `serve` is off, so no framework route can ever hand out one of these
        // files (§6.2 [D-19-4] — a private file is served only by a controller that
        // re-runs the permission chain), and `throw` is on, so a failed write is a loud
        // 500 rather than a silent false that would surface as a validation error.
        // Phases 4, 5 and 14 keep writing 'local'; both names resolve to the same bytes,
        // and storage_disk records which one wrote each row.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
        | phase-24-25 5.1 - where an archive lands.
        |
        | `serve` is off and `throw` is on, for the same two reasons `private` sets them: no
        | framework route may ever hand out a database dump, and a backup write that fails must be
        | a loud 500 rather than a silent false that gets reported as a successful run. The
        | directory is also named in `backup.exclude_paths`, so a file backup never contains the
        | backups - which is how an archive quietly doubles in size every week.
        */
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
