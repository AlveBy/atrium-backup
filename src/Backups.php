<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

use Exception;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Exceptions\BackupFailed;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Throwable;

/**
 * Making backups and finding them on the destinations.
 */
final class Backups
{
    public function __construct(private readonly BackupLog $log) {}

    /**
     * Make an archive and copy it to every destination, with a log entry.
     *
     * @return array{status:string,message?:string}
     */
    public function run(bool $onlyDatabase = false, bool $onlyFiles = false, string $trigger = 'manual'): array
    {
        if (! $this->log->start('backup', ['trigger' => $trigger, 'only' => $onlyDatabase ? 'database' : ($onlyFiles ? 'files' : null)])) {
            return ['status' => 'busy'];
        }

        try {
            $this->make($onlyDatabase, $onlyFiles);
        } catch (Exception $exception) {
            $this->log->finish('failed', ['message' => $exception->getMessage()]);

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }

        $status = empty($this->log->current()['failed']) ? 'ok' : 'partial';
        $this->log->finish($status);

        return ['status' => $status];
    }

    /**
     * The backup itself, without the log's running marker: also used for the
     * safety copy a restore makes first. Throws when no archive was made.
     */
    public function make(bool $onlyDatabase = false, bool $onlyFiles = false): void
    {
        @set_time_limit(0);
        $config = $this->config();

        try {
            $job = BackupJobFactory::createFromConfig($config)->disableSignals();

            if ($onlyDatabase) {
                $job->dontBackupFilesystem();
            }

            if ($onlyFiles) {
                $job->dontBackupDatabases();
            }

            $job->run();
        } catch (Exception $exception) {
            $cause = $exception instanceof BackupFailed ? $exception->getPrevious() : $exception;

            report($exception);
            // spatie's handler e-mails about it.
            event(new BackupHasFailed($cause, $exception instanceof BackupFailed ? $exception->backupDestination?->diskName() : null, $config->backup->name));

            throw $cause;
        }
    }

    /**
     * Every archive on every destination, newest first.
     *
     * @return array<int,array{id:string,disk:string,path:string,file:string,date:string,size:int}>
     */
    public function all(): array
    {
        $backups = [];

        foreach ($this->destinations() as $destination) {
            // An unreachable destination shows in health(), not as an error here.
            try {
                foreach ($destination->backups() as $backup) {
                    $backups[] = $this->describe($destination->diskName(), $backup);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $backups;
    }

    /**
     * The archive behind an id from the list, or null. Only archives a
     * destination actually lists are found: an id is no way to read an
     * arbitrary file.
     */
    public function find(string $id): ?Backup
    {
        $decoded = base64_decode(strtr($id, '-_', '+/'), true);

        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }

        [$disk, $path] = explode(':', $decoded, 2);

        foreach ($this->destinations() as $destination) {
            if ($destination->diskName() !== $disk) {
                continue;
            }

            foreach ($destination->backups() as $backup) {
                if ($backup->path() === $path) {
                    return $backup;
                }
            }
        }

        return null;
    }

    /**
     * @return array{id:string,disk:string,path:string,file:string,date:string,size:int}
     */
    public function describe(string $disk, Backup $backup): array
    {
        return [
            'id' => self::id($disk, $backup->path()),
            'disk' => $disk,
            'path' => $backup->path(),
            'file' => basename($backup->path()),
            'date' => $backup->date()->toIso8601String(),
            'size' => (int) $backup->sizeInBytes(),
        ];
    }

    public static function id(string $disk, string $path): string
    {
        return rtrim(strtr(base64_encode($disk.':'.$path), '+/', '-_'), '=');
    }

    public function diskOf(string $id): ?string
    {
        $decoded = base64_decode(strtr($id, '-_', '+/'), true);

        return $decoded === false ? null : explode(':', $decoded, 2)[0];
    }

    /**
     * Health of each destination: reachable, the newest archive, used space
     * and the failed checks.
     *
     * @return array<int,array{disk:string,reachable:bool,healthy:bool,count:int,newest:?string,used:int,problems:array<int,string>}>
     */
    public function health(): array
    {
        return BackupDestinationStatusFactory::createForMonitorConfig($this->config()->monitoredBackups)
            ->map(function (BackupDestinationStatus $status): array {
                $destination = $status->backupDestination();
                $reachable = $destination->isReachable();
                $healthy = $status->isHealthy();

                return [
                    'disk' => $destination->diskName(),
                    'reachable' => $reachable,
                    'healthy' => $healthy,
                    'count' => $reachable ? $destination->backups()->count() : 0,
                    'newest' => $reachable ? $destination->newestBackup()?->date()->toIso8601String() : null,
                    'used' => $reachable ? (int) $destination->usedStorage() : 0,
                    'problems' => $healthy ? [] : $status->failureMessages()->pluck('message')->map(fn ($message): string => (string) $message)->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,BackupDestination>
     */
    public function destinations(): array
    {
        $config = $this->config();

        return array_map(
            static fn (string $disk): BackupDestination => BackupDestination::create($disk, $config->backup->name),
            $config->backup->destination->disks,
        );
    }

    /**
     * spatie's config, built afresh from atrium-backup: its zip code reads
     * the password from the container.
     */
    public function config(): Config
    {
        return SpatieConfig::apply();
    }

    public function log(): BackupLog
    {
        return $this->log;
    }
}
