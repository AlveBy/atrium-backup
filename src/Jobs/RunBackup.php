<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Jobs;

use Atrium\Backup\Backups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * "Back up now" for a queue worker (ATRIUM_BACKUP_QUEUE).
 */
final class RunBackup implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    /**
     * @param  'database'|'files'|null  $only
     */
    public function __construct(public readonly ?string $only = null, public readonly ?string $by = null) {}

    public function handle(Backups $backups): void
    {
        $backups->log()->by($this->by);
        $backups->run($this->only === 'database', $this->only === 'files', 'admin');
    }
}
