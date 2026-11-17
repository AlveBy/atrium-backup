<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Health;

use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Tasks\Monitor\HealthCheck;

/**
 * The newest archive differs in size from the one before it by more than the
 * given percentage. A site rarely halves or doubles overnight: usually a
 * folder stopped being readable, a table was emptied, or logs swelled.
 */
final class SizeJump extends HealthCheck
{
    /** Archives this small are compared by nothing: a few bytes swing a lot. */
    private const MIN_BYTES = 64 * 1024;

    public function __construct(private readonly int $percent = 50) {}

    public function checkHealth(BackupDestination $backupDestination): void
    {
        $backups = $backupDestination->backups()->values();

        if ($backups->count() < 2) {
            return;
        }

        $newest = $backups[0]->sizeInBytes();
        $previous = $backups[1]->sizeInBytes();

        if ($previous < self::MIN_BYTES && $newest < self::MIN_BYTES) {
            return;
        }

        $change = $previous > 0 ? abs($newest - $previous) / $previous * 100 : 100.0;

        $this->failIf($change > $this->percent, (string) __('atrium-backup::backup.health.size_jump', [
            'percent' => round($change),
            'before' => self::megabytes($previous),
            'after' => self::megabytes($newest),
        ]));
    }

    public function name(): string
    {
        return 'Size jump';
    }

    private static function megabytes(float $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }
}
