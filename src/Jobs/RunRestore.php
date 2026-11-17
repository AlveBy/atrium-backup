<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Jobs;

use Atrium\Backup\Backups;
use Atrium\Backup\Restore\Restorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A restore started in the admin, for a queue worker (ATRIUM_BACKUP_QUEUE).
 */
final class RunRestore implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    /**
     * @param  array{database?:bool,files?:bool,delete_extra?:bool,safety_backup?:bool}  $options
     */
    public function __construct(
        public readonly string $backup,
        public readonly array $options,
        public readonly ?string $password = null,
        public readonly ?string $by = null,
    ) {}

    public function handle(Restorer $restorer): void
    {
        app(Backups::class)->log()->by($this->by);
        $restorer->restore($this->backup, $this->options, $this->password, 'admin');
    }
}
