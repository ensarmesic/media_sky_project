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

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
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

        // Backup (spatie/laravel-backup). Lokalni disk NIJE dovoljan sam za sebe — vidi docs/PRODUCTION.md.
        'backups' => [
            'driver' => 'local',
            'root' => env('BACKUP_LOCAL_PATH', storage_path('app/backups')),
            'throw' => true,
        ],

        // Udaljeni S3-kompatibilan prostor (AWS, Backblaze, Wasabi, MinIO...). Traži: composer require league/flysystem-aws-s3-v3
        's3_backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'eu-central-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_S3_PATH_STYLE', false),
            'throw' => true,
        ],

        // Udaljeni server preko SFTP-a. Traži: composer require league/flysystem-sftp-v3
        'sftp_backups' => [
            'driver' => 'sftp',
            'host' => env('BACKUP_SFTP_HOST'),
            'port' => (int) env('BACKUP_SFTP_PORT', 22),
            'username' => env('BACKUP_SFTP_USERNAME'),
            'password' => env('BACKUP_SFTP_PASSWORD'),
            'privateKey' => env('BACKUP_SFTP_PRIVATE_KEY'),
            'root' => env('BACKUP_SFTP_ROOT', '/backups/media-sky-project'),
            'timeout' => 30,
            'throw' => true,
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
