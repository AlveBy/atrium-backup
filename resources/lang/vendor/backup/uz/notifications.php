<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

// spatie/laravel-backup has no Uzbek: the e-mails' texts.
return [
    'exception_message' => 'Xato xabari: :message',
    'exception_trace' => 'Xato izi: :trace',
    'exception_message_title' => 'Xato xabari',
    'exception_trace_title' => 'Xato izi',

    'backup_failed_subject' => ':application_name zaxira nusxasini yaratib bo‘lmadi',
    'backup_failed_body' => 'Diqqat: :application_name zaxira nusxasini yaratishda xato yuz berdi',

    'backup_successful_subject' => ':application_name ning yangi zaxira nusxasi yaratildi',
    'backup_successful_subject_title' => 'Yangi zaxira nusxasi yaratildi!',
    'backup_successful_body' => ':application_name ning yangi zaxira nusxasi :disk_name diskiga muvaffaqiyatli saqlandi.',

    'cleanup_failed_subject' => ':application_name zaxira nusxalarini tozalab bo‘lmadi.',
    'cleanup_failed_body' => ':application_name zaxira nusxalarini tozalashda xato yuz berdi',

    'cleanup_successful_subject' => ':application_name zaxira nusxalari tozalandi',
    'cleanup_successful_subject_title' => 'Zaxira nusxalari tozalandi!',
    'cleanup_successful_body' => ':disk_name diskidagi :application_name zaxira nusxalari muvaffaqiyatli tozalandi.',

    'healthy_backup_found_subject' => ':disk_name diskidagi :application_name zaxira nusxalari joyida',
    'healthy_backup_found_subject_title' => ':application_name zaxira nusxalari joyida',
    'healthy_backup_found_body' => ':application_name zaxira nusxalari joyida. Ajoyib!',

    'unhealthy_backup_found_subject' => 'Diqqat: :application_name zaxira nusxalarida muammo bor',
    'unhealthy_backup_found_subject_title' => 'Diqqat: :application_name zaxira nusxalarida muammo bor. :problem',
    'unhealthy_backup_found_body' => ':disk_name diskidagi :application_name zaxira nusxalarida muammo bor.',
    'unhealthy_backup_found_not_reachable' => 'Zaxira nusxalari joyiga ulanib bo‘lmadi. :error',
    'unhealthy_backup_found_empty' => 'Bu ilovaning birorta ham zaxira nusxasi yo‘q.',
    'unhealthy_backup_found_old' => ':date da yaratilgan oxirgi zaxira nusxasi juda eski.',
    'unhealthy_backup_found_unknown' => 'Afsuski, aniq sababini aniqlab bo‘lmadi.',
    'unhealthy_backup_found_full' => 'Zaxira nusxalari juda ko‘p joy egallaydi: :disk_usage, ruxsat etilgani :disk_limit.',

    'no_backups_info' => 'Hali zaxira nusxalari yo‘q',
    'application_name' => 'Ilova nomi',
    'backup_name' => 'Zaxira nusxasi nomi',
    'disk' => 'Disk',
    'newest_backup_size' => 'Oxirgi nusxa hajmi',
    'number_of_backups' => 'Nusxalar soni',
    'total_storage_used' => 'Band qilingan joy',
    'newest_backup_date' => 'Oxirgi nusxa sanasi',
    'oldest_backup_date' => 'Eng eski nusxa sanasi',
];
