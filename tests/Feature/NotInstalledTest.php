<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Required with Composer but not installed with atrium:module:install.
 */
final class NotInstalledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('atrium.modules', []);
    }

    public function test_no_screen_no_menu_entry_and_no_schedule(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/backups')->assertNotFound();

        $this->assertNotContains('admin.backups.index', array_column((array) config('atrium.menu'), 'route'));
        $this->assertEmpty(collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'backup')));
    }
}
