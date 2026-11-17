<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Notifications;

use Atrium\Admin\Models\AdminUser;
use Spatie\Backup\Notifications\Notifiable as SpatieNotifiable;
use Throwable;

/**
 * Who gets the e-mail about a failed or unhealthy backup: the addresses in
 * ATRIUM_BACKUP_MAIL, else every active superadmin.
 */
final class Notifiable extends SpatieNotifiable
{
    /**
     * @return array<int,string>
     */
    public function routeNotificationForMail(): array
    {
        // spatie has checked that these are addresses.
        $to = array_values((array) $this->config()->notifications->mail->to);

        if ($to !== []) {
            return $to;
        }

        try {
            return AdminUser::query()
                ->where('is_superadmin', true)
                ->where('is_active', true)
                ->pluck('email')
                ->filter()
                ->values()
                ->all();
        } catch (Throwable) {
            // The database is down: nobody can be found to tell.
            return [];
        }
    }
}
