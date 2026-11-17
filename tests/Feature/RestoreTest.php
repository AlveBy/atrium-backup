<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Backups;
use Atrium\Backup\Restore\RestoreFailed;
use Atrium\Backup\Restore\Restorer;
use Atrium\Backup\Tests\TestCase;

final class RestoreTest extends TestCase
{
    public function test_the_dry_run_shows_what_would_change_and_touches_nothing(): void
    {
        $id = $this->backupThenChange();

        $plan = app(Restorer::class)->plan($id);

        $this->assertSame('site', $plan['databases'][0]['connection']);
        $this->assertTrue($plan['databases'][0]['restorable']);
        $this->assertGreaterThan(0, $plan['databases'][0]['size']);
        $this->assertSame(
            ['files' => 2, 'new' => 1, 'changed' => 1, 'unchanged' => 0, 'extra' => 1],
            array_intersect_key($plan['folders'][0], array_flip(['files', 'new', 'changed', 'unchanged', 'extra'])),
        );
        $this->assertSame($this->dir.'/uploads', $plan['folders'][0]['target']);
        $this->assertSame([], $plan['warnings']);

        // Nothing changed.
        $this->assertSame(['Changed'], $this->site()->table('articles')->pluck('title')->all());
        $this->assertFileExists($this->dir.'/uploads/new.txt');
    }

    public function test_a_restore_puts_back_the_database_and_the_files_after_a_safety_backup(): void
    {
        $id = $this->backupThenChange();

        $result = app(Restorer::class)->restore($id, ['delete_extra' => true]);

        $this->assertSame(['status' => 'ok'], $result);
        $this->assertSame(['First', 'Second'], $this->site()->table('articles')->orderBy('id')->pluck('title')->all());
        $this->assertSame('Hello', file_get_contents($this->dir.'/uploads/readme.txt'));
        $this->assertSame(2048, filesize($this->dir.'/uploads/photos/cat.jpg'));
        $this->assertFileDoesNotExist($this->dir.'/uploads/new.txt');

        // The safety backup holds the state from before the restore.
        $this->assertCount(4, app(Backups::class)->all());

        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['restore', 'ok'], [$entry['action'], $entry['status']]);
        $this->assertSame(['site'], $entry['databases']);
        $this->assertSame(2, $entry['files']);
        $this->assertSame(1, $entry['deleted']);
        $this->assertTrue($entry['safety_backup']);
    }

    public function test_only_the_database_and_without_a_safety_backup(): void
    {
        $id = $this->backupThenChange();

        app(Restorer::class)->restore($id, ['files' => false, 'safety_backup' => false]);

        $this->assertSame(['First', 'Second'], $this->site()->table('articles')->orderBy('id')->pluck('title')->all());
        $this->assertSame('Changed', file_get_contents($this->dir.'/uploads/readme.txt'));
        $this->assertCount(2, app(Backups::class)->all());
    }

    public function test_only_the_files_keeps_extra_files_unless_asked(): void
    {
        $id = $this->backupThenChange();

        app(Restorer::class)->restore($id, ['database' => false, 'safety_backup' => false]);

        $this->assertSame(['Changed'], $this->site()->table('articles')->pluck('title')->all());
        $this->assertSame('Hello', file_get_contents($this->dir.'/uploads/readme.txt'));
        $this->assertFileExists($this->dir.'/uploads/new.txt');
    }

    public function test_an_encrypted_archive_needs_its_password(): void
    {
        config(['atrium-backup.password' => 'correct horse']);
        $id = $this->backupThenChange();
        config(['atrium-backup.password' => null]);

        try {
            app(Restorer::class)->plan($id);
            $this->fail('No password, no plan.');
        } catch (RestoreFailed $exception) {
            $this->assertSame(__('atrium-backup::backup.errors.password_missing'), $exception->getMessage());
        }

        try {
            app(Restorer::class)->plan($id, 'wrong');
            $this->fail('A wrong password, no plan.');
        } catch (RestoreFailed $exception) {
            $this->assertSame(__('atrium-backup::backup.errors.password_wrong'), $exception->getMessage());
        }

        $this->assertSame('ok', app(Restorer::class)->restore($id, ['safety_backup' => false], 'correct horse')['status']);
        $this->assertSame(['First', 'Second'], $this->site()->table('articles')->orderBy('id')->pluck('title')->all());
    }

    public function test_a_dump_of_another_driver_is_skipped_with_a_warning(): void
    {
        $id = $this->backupThenChange();
        $other = config('database.connections.site.driver') === 'sqlite' ? 'mysql' : 'sqlite';
        config(['database.connections.site.driver' => $other]);

        $plan = app(Restorer::class)->plan($id);

        $this->assertFalse($plan['databases'][0]['restorable']);
        $this->assertCount(1, $plan['warnings']);
    }

    public function test_the_console_command_shows_the_plan_and_restores(): void
    {
        $this->backupThenChange();
        $file = app(Backups::class)->all()[0]['file'];

        $this->artisan('atrium:backup:restore', ['backup' => $file, '--dry-run' => true])
            ->expectsOutputToContain($file)
            ->assertSuccessful();
        $this->assertSame(['Changed'], $this->site()->table('articles')->pluck('title')->all());

        $this->artisan('atrium:backup:restore', ['backup' => $file, '--no-safety-backup' => true])
            ->expectsConfirmation('Everything in the databases above will be replaced. Go on?', 'no')
            ->assertFailed();
        $this->assertSame(['Changed'], $this->site()->table('articles')->pluck('title')->all());

        $this->artisan('atrium:backup:restore', ['backup' => $file, '--force' => true, '--no-safety-backup' => true])
            ->assertSuccessful();
        $this->assertSame(['First', 'Second'], $this->site()->table('articles')->orderBy('id')->pluck('title')->all());

        $this->artisan('atrium:backup:restore', ['backup' => 'nope.zip'])->assertFailed();
    }

    /**
     * Back up, then change the row, a file, and add a file.
     */
    private function backupThenChange(): string
    {
        $this->seedSite();
        $this->assertSame('ok', app(Backups::class)->run()['status']);

        $this->site()->table('articles')->delete();
        $this->site()->table('articles')->insert(['title' => 'Changed']);
        file_put_contents($this->dir.'/uploads/readme.txt', 'Changed');
        unlink($this->dir.'/uploads/photos/cat.jpg');
        file_put_contents($this->dir.'/uploads/new.txt', 'new');
        $this->travel(2)->seconds();

        return collect(app(Backups::class)->all())->firstWhere('disk', 'backups')['id'];
    }
}
