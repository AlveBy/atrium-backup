<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

/**
 * When the backup, the clean-up and the health check run.
 */
final class Schedules
{
    /**
     * @param  array<string,mixed>  $config  atrium-backup.schedule
     * @return array<string,string> command => cron expression
     */
    public static function entries(array $config): array
    {
        $frequency = $config['frequency'] ?? 'daily';

        if (! in_array($frequency, ['daily', 'weekly'], true)) {
            return [];
        }

        $time = explode(':', (string) ($config['time'] ?? '02:00'));
        $hour = max(0, min(23, (int) $time[0]));
        $minute = max(0, min(59, (int) ($time[1] ?? null)));
        $days = $frequency === 'weekly' ? max(0, min(6, (int) ($config['day'] ?? null))) : '*';

        return [
            'atrium:backup:run' => "{$minute} {$hour} * * {$days}",
            // An hour later: the clean-up must not delete what is still being
            // written, and the health check looks at the fresh archive. Both
            // every day, so a missed backup is noticed the next morning.
            'backup:clean' => sprintf('%d %d * * *', $minute, ($hour + 1) % 24),
            'backup:monitor' => sprintf('%d %d * * *', $minute, ($hour + 2) % 24),
        ];
    }
}
