<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Atrium\Backup\Http\Controllers\BackupController;
use Illuminate\Support\Facades\Route;

// Inside the panel: its middleware, and admin.can, which lets only superadmins
// open admin.backups.* (atrium.superadmin_routes).
Route::prefix(config('atrium.prefix', 'admin').'/backups')
    ->middleware([...config('atrium.middleware', ['web']), 'atrium.site', 'atrium.inertia', 'admin.auth', 'admin.can'])
    ->name('admin.backups.')
    ->group(function (): void {
        Route::get('/', [BackupController::class, 'index'])->name('index');
        Route::post('/', [BackupController::class, 'store'])->name('store');
        Route::get('status', [BackupController::class, 'status'])->name('status');
        Route::get('{backup}/download', [BackupController::class, 'download'])->name('download');
        // POST: the archive password must not end up in a URL or an access log.
        Route::post('{backup}/plan', [BackupController::class, 'plan'])->name('plan');
        Route::post('{backup}/restore', [BackupController::class, 'restore'])->name('restore');
        Route::delete('{backup}', [BackupController::class, 'destroy'])->name('destroy');
    });
