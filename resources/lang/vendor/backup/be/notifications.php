<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

// spatie/laravel-backup has no Belarusian: the e-mails' texts.
return [
    'exception_message' => 'Паведамленне пра памылку: :message',
    'exception_trace' => 'Трасіроўка памылкі: :trace',
    'exception_message_title' => 'Паведамленне пра памылку',
    'exception_trace_title' => 'Трасіроўка памылкі',

    'backup_failed_subject' => 'Не ўдалося зрабіць рэзервовую копію :application_name',
    'backup_failed_body' => 'Увага: пры рэзервовым капіраванні :application_name адбылася памылка',

    'backup_successful_subject' => 'Новая рэзервовая копія :application_name зроблена',
    'backup_successful_subject_title' => 'Новая рэзервовая копія зроблена!',
    'backup_successful_body' => 'Новая рэзервовая копія :application_name паспяхова захавана на дыск :disk_name.',

    'cleanup_failed_subject' => 'Не ўдалося ачысціць рэзервовыя копіі :application_name.',
    'cleanup_failed_body' => 'Пры ачыстцы рэзервовых копій :application_name адбылася памылка',

    'cleanup_successful_subject' => 'Рэзервовыя копіі :application_name ачышчаны',
    'cleanup_successful_subject_title' => 'Рэзервовыя копіі ачышчаны!',
    'cleanup_successful_body' => 'Рэзервовыя копіі :application_name на дыску :disk_name паспяхова ачышчаны.',

    'healthy_backup_found_subject' => 'Рэзервовыя копіі :application_name на дыску :disk_name у парадку',
    'healthy_backup_found_subject_title' => 'Рэзервовыя копіі :application_name у парадку',
    'healthy_backup_found_body' => 'Рэзервовыя копіі :application_name у парадку. Выдатна!',

    'unhealthy_backup_found_subject' => 'Увага: з рэзервовымі копіямі :application_name праблема',
    'unhealthy_backup_found_subject_title' => 'Увага: з рэзервовымі копіямі :application_name праблема. :problem',
    'unhealthy_backup_found_body' => 'З рэзервовымі копіямі :application_name на дыску :disk_name праблема.',
    'unhealthy_backup_found_not_reachable' => 'Месца для рэзервовых копій недаступнае. :error',
    'unhealthy_backup_found_empty' => 'У гэтага прыкладання няма ніводнай рэзервовай копіі.',
    'unhealthy_backup_found_old' => 'Апошняя рэзервовая копія, зробленая :date, занадта старая.',
    'unhealthy_backup_found_unknown' => 'На жаль, дакладную прычыну вызначыць не ўдалося.',
    'unhealthy_backup_found_full' => 'Рэзервовыя копіі займаюць занадта шмат месца: :disk_usage пры дазволеных :disk_limit.',

    'no_backups_info' => 'Рэзервовых копій яшчэ няма',
    'application_name' => 'Назва прыкладання',
    'backup_name' => 'Назва рэзервовай копіі',
    'disk' => 'Дыск',
    'newest_backup_size' => 'Памер апошняй копіі',
    'number_of_backups' => 'Колькасць копій',
    'total_storage_used' => 'Занята месца',
    'newest_backup_date' => 'Дата апошняй копіі',
    'oldest_backup_date' => 'Дата самай старой копіі',
];
