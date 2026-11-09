<?php

// Album images (the `public` disk) are either on the server's own disk or in
// an S3 bucket. The two drivers need different keys: S3 takes a bucket plus an
// optional key prefix, the local driver takes a path. STORAGE_DISK_ROOT means
// "where the files live" in both cases — the bucket name for S3, the path for
// local — and STORAGE_DISK_PREFIX is the S3-only key prefix.
$albumDiskIsS3 = env('STORAGE_DISK', 'local') === 's3';

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

        // Album images. `throw` is on: a failed write must fail the request
        // instead of leaving an album row that points at missing files.
        'public' => $albumDiskIsS3 ? [
            // STORAGE_DISK=s3: bucket in STORAGE_DISK_ROOT (or AWS_BUCKET),
            // optional key prefix in STORAGE_DISK_PREFIX, public base URL in
            // STORAGE_DISK_URL.
            'driver' => 's3',
            'bucket' => env('STORAGE_DISK_ROOT') ?: env('AWS_BUCKET'),
            'root' => (string) env('STORAGE_DISK_PREFIX', ''),
            'url' => env('STORAGE_DISK_URL'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ] : [
            // STORAGE_DISK=local (default): storage/app/public + storage:link.
            'driver' => 'local',
            'root' => env('STORAGE_DISK_ROOT', storage_path('app/public')),
            'url' => env('STORAGE_DISK_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/storage'),
            'visibility' => 'public',
            'throw' => true,
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
