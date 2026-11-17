<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Console;

use Atrium\Backup\Backups;
use Illuminate\Console\Command;

final class RunCommand extends Command
{
    protected $signature = 'atrium:backup:run
        {--only-db : Only the databases}
        {--only-files : Only the files}';

    protected $description = 'Back up the databases and files to every destination';

    public function handle(Backups $backups): int
    {
        if ($this->option('only-db') && $this->option('only-files')) {
            $this->error('Choose --only-db or --only-files, not both.');

            return self::INVALID;
        }

        $result = $backups->run((bool) $this->option('only-db'), (bool) $this->option('only-files'), $this->input->isInteractive() ? 'console' : 'schedule');

        return match ($result['status']) {
            'ok' => $this->done('Backup made and copied to: '.implode(', ', $backups->config()->backup->destination->disks)),
            'partial' => $this->failed('Backup made, but some destinations failed: see the log in the admin.'),
            'busy' => $this->failed('Another backup or restore is running.'),
            default => $this->failed('Backup failed: '.($result['message'] ?? '')),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
