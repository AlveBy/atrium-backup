<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

use Atrium\Backup\Health\SizeJump;
use Atrium\Backup\Notifications\Notifiable;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use ZipArchive;

/**
 * spatie/laravel-backup's `backup` config, built from `atrium-backup`.
 */
final class SpatieConfig
{
    /**
     * @param  array<string,mixed>  $config  atrium-backup
     * @return array<string,mixed>
     */
    public static function build(array $config): array
    {
        $sources = Sources::fromConfig($config);
        $destinations = array_values((array) ($config['destinations'] ?? []));
        $retention = (array) ($config['retention'] ?? []);
        $health = (array) ($config['health'] ?? []);

        return [
            'backup' => [
                'name' => (string) ($config['name'] ?? 'atrium'),
                'source' => [
                    'files' => [
                        'include' => $sources->paths(),
                        'exclude' => array_values(array_filter([
                            ...array_map(Sources::absolute(...), (array) ($sources->config['exclude'] ?? [])),
                            // The module's own log and downloaded archives.
                            (string) ($config['storage'] ?? ''),
                            // APP_KEY and the other secrets never travel with the
                            // data, even when the project folder is backed up
                            // (.env, .env.production…).
                            base_path('.env*'),
                        ])),
                        'follow_links' => false,
                        'ignore_unreadable_directories' => true,
                        // Paths in the archive start from the project:
                        // storage/app/public/uploads/photo.jpg.
                        'relative_path' => base_path(),
                    ],
                    'databases' => $sources->databases(),
                ],
                'database_dump_compressor' => null,
                'database_dump_file_timestamp_format' => null,
                'database_dump_filename_base' => 'connection',
                'database_dump_file_extension' => '',
                'destination' => [
                    'compression_method' => ZipArchive::CM_DEFAULT,
                    'compression_level' => 6,
                    'filename_prefix' => '',
                    'disks' => $destinations,
                    'continue_on_failure' => (bool) ($config['continue_on_failure'] ?? true),
                ],
                'temporary_directory' => storage_path('app/backup-temp'),
                'password' => ($config['password'] ?? null) ?: null,
                'encryption' => 'default',
                'verify_backup' => true,
                'tries' => 1,
                'retry_delay' => 0,
            ],
            'notifications' => [
                // Only what needs someone's attention gets an e-mail.
                'notifications' => [
                    BackupHasFailedNotification::class => ['mail'],
                    UnhealthyBackupWasFoundNotification::class => ['mail'],
                    CleanupHasFailedNotification::class => ['mail'],
                    BackupWasSuccessfulNotification::class => [],
                    HealthyBackupWasFoundNotification::class => [],
                    CleanupWasSuccessfulNotification::class => [],
                ],
                'notifiable' => Notifiable::class,
                'mail' => [
                    'to' => array_values((array) ($config['mail'] ?? [])),
                    'from' => [
                        'address' => config('mail.from.address') ?: 'hello@example.com',
                        'name' => config('mail.from.name') ?: config('app.name'),
                    ],
                ],
                'slack' => ['webhook_url' => '', 'channel' => null, 'username' => null, 'icon' => null],
                'discord' => ['webhook_url' => '', 'username' => '', 'avatar_url' => ''],
                'webhook' => ['url' => ''],
            ],
            'log_channel' => null,
            'monitor_backups' => [[
                'name' => (string) ($config['name'] ?? 'atrium'),
                'disks' => $destinations,
                'health_checks' => array_filter([
                    MaximumAgeInDays::class => self::maxAgeDays($config),
                    MaximumStorageInMegabytes::class => ($retention['max_megabytes'] ?? null) ? (int) $retention['max_megabytes'] : null,
                    SizeJump::class => ($health['size_jump_percent'] ?? 0) > 0 ? (int) $health['size_jump_percent'] : null,
                ], static fn (?int $value): bool => $value !== null),
            ]],
            'cleanup' => [
                'strategy' => DefaultStrategy::class,
                'default_strategy' => [
                    'keep_all_backups_for_days' => (int) ($retention['keep_all_days'] ?? 2),
                    'keep_daily_backups_for_days' => (int) ($retention['daily'] ?? 7),
                    'keep_weekly_backups_for_weeks' => (int) ($retention['weekly'] ?? 4),
                    'keep_monthly_backups_for_months' => (int) ($retention['monthly'] ?? 6),
                    'keep_yearly_backups_for_years' => (int) ($retention['yearly'] ?? 2),
                    'delete_oldest_backups_when_using_more_megabytes_than' => ($retention['max_megabytes'] ?? null) ? (int) $retention['max_megabytes'] : null,
                ],
                'tries' => 1,
                'retry_delay' => 0,
            ],
        ];
    }

    /**
     * Build `backup` from `atrium-backup` as it is now and give it to spatie:
     * the config, and the Config object its classes take from the container.
     *
     * MySQL and MariaDB connections that are backed up get a consistent dump
     * (--single-transaction) unless their own `dump` settings say otherwise.
     * pg_dump and the SQLite dump are consistent by themselves.
     */
    public static function apply(): Config
    {
        $atrium = (array) config('atrium-backup');

        foreach (Sources::fromConfig($atrium)->databases() as $connection) {
            $driver = config("database.connections.{$connection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true) && config("database.connections.{$connection}.dump.use_single_transaction") === null) {
                config(["database.connections.{$connection}.dump.use_single_transaction" => true]);
            }

            // Laravel finds a relative SQLite file from the project folder too;
            // sqlite3 only from where it runs, which under a web server is public/.
            $database = config("database.connections.{$connection}.database");

            if ($driver === 'sqlite' && is_string($database) && $database !== ':memory:' && ! str_starts_with($database, DIRECTORY_SEPARATOR)
                && ($absolute = realpath($database) ?: realpath(base_path($database))) !== false) {
                config(["database.connections.{$connection}.database" => $absolute]);
            }
        }

        config(['backup' => self::build($atrium)]);
        $config = Config::fromArray((array) config('backup'));
        app()->instance(Config::class, $config);

        return $config;
    }

    /**
     * How old the newest archive may be: set, or worked out from the schedule.
     *
     * @param  array<string,mixed>  $config
     */
    public static function maxAgeDays(array $config): int
    {
        $set = $config['health']['max_age_days'] ?? null;

        if (is_numeric($set) && (int) $set > 0) {
            return (int) $set;
        }

        return ($config['schedule']['frequency'] ?? 'daily') === 'weekly' ? 8 : 1;
    }
}
