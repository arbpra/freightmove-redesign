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

        /*
        |----------------------------------------------------------------------
        | A note on the numbers below
        |----------------------------------------------------------------------
        |
        | 644 on a file is correct and should stay. A file has no reason to be
        | executable, and on shared hosting an executable file in a web-served
        | directory is the thing mod_security and host scanners look for.
        |
        | 755 on a DIRECTORY is what matters, and it is a different bit doing a
        | different job: on a directory the execute bit means "may traverse
        | into", so a directory at 644 cannot be entered even though everything
        | inside it is readable. That is what produces a 403 on a file that is
        | plainly there, and "Unable to create a directory" on an upload.
        |
        | So: directories 755, files 644 — never 755 on files.
        |
        | Declared explicitly rather than left to the process umask, which is
        | the host's to choose and differs between the machine that built the
        | code and the one running it.
        */

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,

            // Private: verification documents live here and are served through
            // a Laravel route, never read off disk by the web server.
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ],
        ],

        /*
         * Load photos, written straight into the document root.
         *
         * Laravel's convention is `storage/app/public` plus a `public/storage`
         * symlink, and this deployment cannot keep that standing up. The link
         * is absolute, so it broke the moment the application folder moved; it
         * is created by an artisan command, and this host has no shell to run
         * one with; and the front web server answers image requests itself
         * without asking PHP, so a missing link is a 403 on every photo with
         * nothing in any log.
         *
         * Writing to `public/storage` directly removes all three. The stored
         * paths do not change — they are relative, like `loads/212/photo.png` —
         * so nothing in the database needs rewriting, and a host that *does*
         * have the symlink simply writes through it to the same place.
         *
         * Note for deploys: `scripts/bundle-hostpapa.mjs` deliberately excludes
         * `public/storage`, so extracting an archive over the top cannot touch
         * uploaded photos.
         */
        'public' => [
            'driver' => 'local',
            'root' => public_path('storage'),
            // rtrim because APP_URL is hand-written into a .env and a trailing
            // slash is the natural way to write a URL. Concatenated raw it
            // produces "https://host//storage/...", which 404s while the file
            // is sitting there perfectly intact.
            'url' => rtrim((string) env('APP_URL'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,

            // Load photos: the web server reads these directly through the
            // public/storage symlink, so every directory on the way in needs
            // its traverse bit.
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0600],
                'dir' => ['public' => 0755, 'private' => 0700],
            ],
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
