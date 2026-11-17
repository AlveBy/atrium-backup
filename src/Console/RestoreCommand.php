<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Console;

use Atrium\Backup\Backups;
use Atrium\Backup\Restore\RestoreFailed;
use Atrium\Backup\Restore\Restorer;
use Illuminate\Console\Command;

final class RestoreCommand extends Command
{
    protected $signature = 'atrium:backup:restore
        {backup? : File name of the archive (2026-11-17-02-00-00.zip); the newest when left out}
        {--disk= : Destination to take it from; the first that has it when left out}
        {--only-db : Only the databases}
        {--only-files : Only the files}
        {--delete-extra : Delete files that are not in the archive}
        {--no-safety-backup : Do not back up the current state first}
        {--password= : Archive password, when it differs from ATRIUM_BACKUP_PASSWORD}
        {--dry-run : Only show what would be done}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore the databases and files from a backup';

    public function handle(Backups $backups, Restorer $restorer): int
    {
        $backup = $this->pick($backups->all());

        if ($backup === null) {
            $this->error('No such backup. Archives: '.implode(', ', array_unique(array_column($backups->all(), 'file'))));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: null;

        try {
            $plan = $restorer->plan($backup['id'], $password);
        } catch (RestoreFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$backup['file']} on {$backup['disk']}, made ".($plan['created_at'] ?? $backup['date']));
        $this->table(['Database', 'Driver', 'Dump', 'Into'], array_map(static fn (array $db): array => [
            $db['connection'], $db['driver'], self::size((int) $db['size']), $db['restorable'] ? (string) $db['target'] : 'skipped',
        ], $plan['databases']));
        $this->table(['Folder', 'Into', 'Files', 'New', 'Changed', 'Same', 'Not in archive'], array_map(static fn (array $folder): array => [
            $folder['name'], $folder['target'], $folder['files'], $folder['new'], $folder['changed'], $folder['unchanged'], $folder['extra'],
        ], $plan['folders']));

        foreach ($plan['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Everything in the databases above will be replaced. Go on?')) {
            return self::FAILURE;
        }

        $result = $restorer->restore($backup['id'], [
            'database' => ! $this->option('only-files'),
            'files' => ! $this->option('only-db'),
            'delete_extra' => (bool) $this->option('delete-extra'),
            'safety_backup' => ! $this->option('no-safety-backup'),
        ], $password, 'console');

        if ($result['status'] !== 'ok') {
            $this->error($result['status'] === 'busy' ? 'Another backup or restore is running.' : 'Restore failed: '.($result['message'] ?? ''));

            return self::FAILURE;
        }

        $this->info('Restored.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int,array<string,mixed>>  $backups
     * @return array<string,mixed>|null
     */
    private function pick(array $backups): ?array
    {
        $file = $this->argument('backup');
        $disk = $this->option('disk');

        foreach ($backups as $backup) {
            if (($file === null || $backup['file'] === $file) && ($disk === null || $backup['disk'] === $disk)) {
                return $backup;
            }
        }

        return null;
    }

    private static function size(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }
}
