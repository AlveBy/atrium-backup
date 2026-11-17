<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Restore;

use Atrium\Backup\Backups;
use Throwable;

/**
 * Puts an archive back: the databases, the files, or both.
 *
 * Before anything is touched, a fresh backup of the current state is made
 * (unless turned off), so a wrong restore can itself be undone.
 */
final class Restorer
{
    public function __construct(
        private readonly Backups $backups,
        private readonly DatabaseImporter $importer,
    ) {}

    /**
     * What restoring this archive would do; nothing is changed.
     *
     * @return array<string,mixed>
     */
    public function plan(string $id, ?string $password = null): array
    {
        $archive = $this->open($id, $password);

        try {
            return Plan::make($archive);
        } finally {
            $archive->close();
        }
    }

    /**
     * @param  array{database?:bool,files?:bool,delete_extra?:bool,safety_backup?:bool}  $options
     * @return array{status:string,message?:string}
     */
    public function restore(string $id, array $options, ?string $password = null, string $trigger = 'manual'): array
    {
        $options = [
            'database' => (bool) ($options['database'] ?? true),
            'files' => (bool) ($options['files'] ?? true),
            'delete_extra' => (bool) ($options['delete_extra'] ?? false),
            'safety_backup' => (bool) ($options['safety_backup'] ?? true),
        ];
        $log = $this->backups->log();
        $backup = $this->backups->find($id);

        if ($backup === null) {
            return ['status' => 'failed', 'message' => (string) __('atrium-backup::backup.errors.not_found')];
        }

        if (! $log->start('restore', ['trigger' => $trigger, 'file' => basename($backup->path()), 'disk' => $this->backups->diskOf($id), 'options' => $options])) {
            return ['status' => 'busy'];
        }

        @set_time_limit(0);
        $done = ['databases' => [], 'files' => 0, 'deleted' => 0];

        try {
            if ($options['safety_backup']) {
                // Throws when no archive could be made: better not to go on.
                $this->backups->make();
                $done['safety_backup'] = true;
            }

            $archive = $this->open($id, $password);

            try {
                $plan = Plan::make($archive);

                if ($options['database']) {
                    foreach ($plan['databases'] as $database) {
                        if ($database['restorable']) {
                            $this->database($archive, $database);
                            $done['databases'][] = $database['connection'];
                        }
                    }
                }

                if ($options['files']) {
                    foreach ($plan['folders'] as $folder) {
                        [$written, $deleted] = $this->folder($archive, $folder, $options['delete_extra']);
                        $done['files'] += $written;
                        $done['deleted'] += $deleted;
                    }
                }
            } finally {
                $archive->close();
            }
        } catch (Throwable $exception) {
            report($exception);
            $log->finish('failed', [...$done, 'message' => $exception->getMessage()]);

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }

        $log->finish('ok', $done);

        return ['status' => 'ok'];
    }

    private function open(string $id, ?string $password): Archive
    {
        $backup = $this->backups->find($id) ?? throw new RestoreFailed(__('atrium-backup::backup.errors.not_found'));
        $path = LocalCopy::of($backup, $this->backups->log()->directory());

        return Archive::open($path, $password ?: (config('atrium-backup.password') ?: null));
    }

    /**
     * @param  array<string,mixed>  $database
     */
    private function database(Archive $archive, array $database): void
    {
        $dump = $this->backups->log()->directory().DIRECTORY_SEPARATOR.'restore-'.bin2hex(random_bytes(6)).'.sql';

        try {
            $archive->extract((string) $database['file'], $dump);
            $this->importer->import((string) $database['connection'], $dump);
        } finally {
            @unlink($dump);
        }
    }

    /**
     * @param  array<string,mixed>  $folder
     * @return array{int,int} files written, files deleted
     */
    private function folder(Archive $archive, array $folder, bool $deleteExtra): array
    {
        $prefix = (string) $folder['prefix'];
        $root = rtrim((string) $folder['target'], DIRECTORY_SEPARATOR);
        $written = 0;
        $kept = [];

        if ($prefix === '' || $root === '') {
            return [0, 0];
        }

        foreach ($archive->entries() as $entry) {
            if (! str_starts_with($entry['name'], $prefix)) {
                continue;
            }

            $relative = substr($entry['name'], strlen($prefix));

            // A name that climbs out of the folder is never written.
            if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative) || str_starts_with($relative, '/')) {
                continue;
            }

            $archive->extract($entry['name'], $root.DIRECTORY_SEPARATOR.$relative);
            $kept[$relative] = true;
            $written++;
        }

        $deleted = 0;

        if ($deleteExtra) {
            foreach (iterator_to_array(Plan::currentFiles($root), false) as $relative) {
                if (! isset($kept[$relative]) && @unlink($root.DIRECTORY_SEPARATOR.$relative)) {
                    $deleted++;
                }
            }
        }

        return [$written, $deleted];
    }
}
