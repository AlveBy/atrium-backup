<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backups (alveby/atrium-backup)
|--------------------------------------------------------------------------
|
| Everything is read from here and handed to spatie/laravel-backup, which
| does the dumping, zipping, copying, cleaning up and monitoring. There is
| no config/backup.php to keep in step. Docs: docs/backup.md.
|
*/

return [

    // The folder the archives go to on every destination.
    'name' => env('ATRIUM_BACKUP_NAME', 'atrium'),

    'sources' => [
        // Database connections to dump. Empty: the default connection.
        'databases' => array_filter(explode(',', (string) env('ATRIUM_BACKUP_DATABASES', ''))),

        // Laravel disks whose files go into the archive. Only disks on this
        // server (driver "local") can be read; a bucket keeps its own copies.
        // "uploads" means the disk the admin uploads to (atrium.uploads.disk).
        'disks' => array_filter(explode(',', (string) env('ATRIUM_BACKUP_DISKS', 'uploads'))),

        // More folders, absolute paths or relative to the project.
        'folders' => array_filter(explode(',', (string) env('ATRIUM_BACKUP_FOLDERS', ''))),

        // Left out of every archive.
        'exclude' => [],
    ],

    // Laravel disks the archive is copied to, all of them every time. Disks
    // backup-s3, backup-ftp, backup-sftp, backup-google and backup-dropbox
    // are defined below; "backups" is a folder on this server.
    'destinations' => array_filter(explode(',', (string) env('ATRIUM_BACKUP_DESTINATIONS', 'backups'))),

    // One unreachable destination does not cancel the copy to the others.
    'continue_on_failure' => true,

    // Encrypts the archive (AES-256 zip). Without the password it cannot be
    // opened, restored from the admin included: keep it outside the server.
    'password' => env('ATRIUM_BACKUP_PASSWORD'),

    'schedule' => [
        // daily, weekly or off. Needs `php artisan schedule:run` in cron.
        'frequency' => env('ATRIUM_BACKUP_FREQUENCY', 'daily'),
        'time' => env('ATRIUM_BACKUP_TIME', '02:00'),
        // For weekly: 0 (Sunday) to 6.
        'day' => (int) env('ATRIUM_BACKUP_DAY', 0),
    ],

    // Which archives stay. After the "keep everything" days, one archive a
    // day is kept for N days, then one a week for N weeks, one a month for
    // N months and one a year for N years. The newest is never deleted.
    'retention' => [
        'keep_all_days' => (int) env('ATRIUM_BACKUP_KEEP_ALL_DAYS', 2),
        'daily' => (int) env('ATRIUM_BACKUP_KEEP_DAILY', 7),
        'weekly' => (int) env('ATRIUM_BACKUP_KEEP_WEEKLY', 4),
        'monthly' => (int) env('ATRIUM_BACKUP_KEEP_MONTHLY', 6),
        'yearly' => (int) env('ATRIUM_BACKUP_KEEP_YEARLY', 2),
        // Then the oldest go until the archives take no more than this.
        'max_megabytes' => env('ATRIUM_BACKUP_MAX_MEGABYTES', 5000) === null ? null : (int) env('ATRIUM_BACKUP_MAX_MEGABYTES', 5000),
    ],

    'health' => [
        // The newest archive may be this old. Null: 1 day for a daily
        // schedule, 8 for a weekly one.
        'max_age_days' => env('ATRIUM_BACKUP_MAX_AGE_DAYS') === null ? null : (int) env('ATRIUM_BACKUP_MAX_AGE_DAYS'),
        // The newest archive differs in size from the one before by more
        // than this: half the site went missing, or something swelled.
        'size_jump_percent' => (int) env('ATRIUM_BACKUP_SIZE_JUMP_PERCENT', 50),
    ],

    // Who gets the e-mail when a backup fails or turns unhealthy, comma
    // separated. Empty: every superadmin.
    'mail' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATRIUM_BACKUP_MAIL', ''))))),

    // "Back up now" and restores from the admin run after the response.
    // Name a queue connection to send them to a worker instead.
    'queue' => env('ATRIUM_BACKUP_QUEUE'),

    // Where the module keeps its log and the running marker: a folder on this
    // server, so the log survives a database restore.
    'storage' => storage_path('app/atrium-backup'),

    // Seconds after which an unfinished run no longer blocks a new one.
    'timeout' => (int) env('ATRIUM_BACKUP_TIMEOUT', 6 * 3600),

    // Disks the module adds unless config/filesystems.php has one of the same
    // name. The owner fills in the .env values; nothing is sent anywhere
    // before a disk is listed in "destinations".
    'disks' => [
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => true,
        ],

        'backup-s3' => [
            'driver' => 's3',
            'key' => env('ATRIUM_BACKUP_S3_KEY'),
            'secret' => env('ATRIUM_BACKUP_S3_SECRET'),
            'region' => env('ATRIUM_BACKUP_S3_REGION', 'us-east-1'),
            'bucket' => env('ATRIUM_BACKUP_S3_BUCKET'),
            'endpoint' => env('ATRIUM_BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('ATRIUM_BACKUP_S3_PATH_STYLE', false),
            'root' => env('ATRIUM_BACKUP_S3_ROOT', ''),
            'throw' => true,
        ],

        'backup-ftp' => [
            'driver' => 'ftp',
            'host' => env('ATRIUM_BACKUP_FTP_HOST'),
            'username' => env('ATRIUM_BACKUP_FTP_USERNAME'),
            'password' => env('ATRIUM_BACKUP_FTP_PASSWORD'),
            'port' => (int) env('ATRIUM_BACKUP_FTP_PORT', 21),
            'root' => env('ATRIUM_BACKUP_FTP_ROOT', ''),
            'ssl' => (bool) env('ATRIUM_BACKUP_FTP_SSL', true),
            'passive' => true,
            'throw' => true,
        ],

        'backup-sftp' => [
            'driver' => 'sftp',
            'host' => env('ATRIUM_BACKUP_SFTP_HOST'),
            'username' => env('ATRIUM_BACKUP_SFTP_USERNAME'),
            'password' => env('ATRIUM_BACKUP_SFTP_PASSWORD'),
            'privateKey' => env('ATRIUM_BACKUP_SFTP_PRIVATE_KEY'),
            'passphrase' => env('ATRIUM_BACKUP_SFTP_PASSPHRASE'),
            'port' => (int) env('ATRIUM_BACKUP_SFTP_PORT', 22),
            'root' => env('ATRIUM_BACKUP_SFTP_ROOT', ''),
            'throw' => true,
        ],

        // masbug/flysystem-google-drive-ext
        'backup-google' => [
            'driver' => 'google',
            'clientId' => env('ATRIUM_BACKUP_GOOGLE_CLIENT_ID'),
            'clientSecret' => env('ATRIUM_BACKUP_GOOGLE_CLIENT_SECRET'),
            'refreshToken' => env('ATRIUM_BACKUP_GOOGLE_REFRESH_TOKEN'),
            'folder' => env('ATRIUM_BACKUP_GOOGLE_FOLDER', 'atrium-backups'),
            'throw' => true,
        ],

        // spatie/flysystem-dropbox. Dropbox access tokens live four hours:
        // give the app key, secret and a refresh token, and a fresh access
        // token is fetched when needed. A fixed token works for a quick try.
        'backup-dropbox' => [
            'driver' => 'dropbox',
            'app_key' => env('ATRIUM_BACKUP_DROPBOX_APP_KEY'),
            'app_secret' => env('ATRIUM_BACKUP_DROPBOX_APP_SECRET'),
            'refresh_token' => env('ATRIUM_BACKUP_DROPBOX_REFRESH_TOKEN'),
            'token' => env('ATRIUM_BACKUP_DROPBOX_TOKEN'),
            'root' => env('ATRIUM_BACKUP_DROPBOX_ROOT', ''),
            'throw' => true,
        ],
    ],
];
