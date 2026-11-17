<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Admin\Models\AdminRole;
use Atrium\Backup\BackupLog;
use Atrium\Backup\Backups;
use Atrium\Backup\Jobs\RunBackup;
use Atrium\Backup\Jobs\RunRestore;
use Atrium\Backup\Restore\Restorer;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

final class AdminTest extends TestCase
{
    public function test_a_superadmin_sees_the_archives_destinations_settings_and_log(): void
    {
        $this->seedSite();
        app(Backups::class)->run();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Backups/Index', false)
                ->has('backups', 2)
                ->where('backups.0.download_url', fn (string $url): bool => str_contains($url, '/admin/backups/'))
                ->has('health', 2)
                ->where('health.0.healthy', true)
                ->where('settings.databases.0.connection', 'site')
                ->where('settings.destinations', ['backups', 'backups-copy'])
                ->where('settings.encrypted', false)
                ->where('log.0.action', 'backup')
                ->where('running', null)
                ->where('labels.menu', 'Backups')
                ->where('menu', fn ($menu): bool => collect($menu)->contains('route', 'admin.backups.index')));
    }

    public function test_other_administrators_cannot_open_it_even_with_every_permission(): void
    {
        $editor = $this->admin(superadmin: false);
        $role = AdminRole::query()->create(['name' => 'Everything', 'permissions' => ['backups.view', 'backups.create', 'backups.update', 'backups.delete']]);
        $editor->roles()->attach($role);

        $this->actingAs($editor, 'admin');
        $this->get('/admin/backups')->assertForbidden();
        $this->post('/admin/backups')->assertForbidden();
        $this->get('/admin/backups/status')->assertForbidden();

        $this->get('/admin')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('menu', fn ($menu): bool => ! collect($menu)->contains('route', 'admin.backups.index')));
    }

    public function test_back_up_now_runs_after_the_response(): void
    {
        $this->seedSite();
        $this->withoutDefer();

        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/backups')
            ->post('/admin/backups', ['only' => 'database'])
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('success');

        $this->assertCount(2, app(Backups::class)->all());
        $this->assertSame('admin', app(Backups::class)->log()->recent(1)[0]['trigger']);
        $this->assertSame('Owner', app(Backups::class)->log()->recent(1)[0]['by']);

        $this->getJson('/admin/backups/status')->assertOk()->assertJsonCount(2, 'backups')->assertJsonPath('running', null);
    }

    public function test_back_up_now_goes_to_the_queue_when_one_is_set(): void
    {
        Queue::fake();
        config(['atrium-backup.queue' => 'backups']);

        $this->actingAs($this->admin(), 'admin')->post('/admin/backups')->assertSessionHas('success');

        Queue::assertPushedOn('backups', RunBackup::class);
        Queue::assertPushed(RunBackup::class, fn (RunBackup $job): bool => $job->by === 'Owner');
    }

    public function test_a_second_run_is_refused_while_one_is_going(): void
    {
        app(Backups::class)->log()->start('backup');

        $this->actingAs($this->admin(), 'admin')->post('/admin/backups')->assertSessionHas('error');
    }

    public function test_download_dry_run_and_restore(): void
    {
        $this->seedSite();
        app(Backups::class)->run();
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups');
        $this->site()->table('articles')->delete();
        $this->withoutDefer();
        $this->actingAs($this->admin(), 'admin');

        $download = $this->get("/admin/backups/{$backup['id']}/download")->assertOk();
        $this->assertSame('application/zip', $download->headers->get('Content-Type'));
        $this->assertSame($backup['size'], strlen($download->streamedContent()));

        $this->postJson("/admin/backups/{$backup['id']}/plan")
            ->assertOk()
            ->assertJsonPath('plan.databases.0.connection', 'site')
            ->assertJsonPath('plan.folders.0.unchanged', 2);

        // The archive's name is the confirmation.
        $this->from('/admin/backups')
            ->post("/admin/backups/{$backup['id']}/restore", ['confirm' => 'yes', 'safety_backup' => false])
            ->assertSessionHasErrors('confirm');
        $this->assertSame(0, $this->site()->table('articles')->count());

        $this->post("/admin/backups/{$backup['id']}/restore", ['confirm' => $backup['file'], 'files' => false, 'safety_backup' => false])
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('success');
        $this->assertSame(2, $this->site()->table('articles')->count());

        $this->get('/admin/backups/'.str_repeat('x', 12).'/download')->assertNotFound();
        $this->postJson('/admin/backups/'.str_repeat('x', 12).'/plan')->assertNotFound();
    }

    public function test_a_restore_of_nothing_or_during_another_run_is_refused(): void
    {
        $this->seedSite();
        app(Backups::class)->run();
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups');
        $this->site()->table('articles')->delete();
        $this->withoutDefer();
        $this->actingAs($this->admin(), 'admin')->from('/admin/backups');

        $this->post("/admin/backups/{$backup['id']}/restore", ['confirm' => $backup['file'], 'database' => false, 'files' => false])
            ->assertRedirect('/admin/backups')
            ->assertSessionHas('error', __('atrium-backup::backup.ui.nothing_chosen'));

        app(Backups::class)->log()->start('backup');
        $this->post("/admin/backups/{$backup['id']}/restore", ['confirm' => $backup['file'], 'files' => false, 'safety_backup' => false])
            ->assertSessionHas('error', __('atrium-backup::backup.ui.busy'));

        $this->assertSame(0, $this->site()->table('articles')->count());
    }

    public function test_a_restore_goes_to_the_queue_when_one_is_set_and_the_job_restores(): void
    {
        $this->seedSite();
        app(Backups::class)->run();
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups');
        $this->site()->table('articles')->delete();
        Queue::fake();
        config(['atrium-backup.queue' => 'backups']);

        $this->actingAs($this->admin(), 'admin')
            ->post("/admin/backups/{$backup['id']}/restore", ['confirm' => $backup['file'], 'files' => false, 'safety_backup' => false, 'password' => 'secret'])
            ->assertSessionHas('success');

        $this->assertSame(0, $this->site()->table('articles')->count());
        Queue::assertPushedOn('backups', RunRestore::class);
        $job = Queue::pushed(RunRestore::class)->first();
        $this->assertSame([$backup['id'], ['database' => true, 'files' => false, 'delete_extra' => false, 'safety_backup' => false], 'secret', 'Owner'], [$job->backup, $job->options, $job->password, $job->by]);

        // The worker: in a process of its own, nobody signed in.
        app()->forgetInstance(BackupLog::class);
        app()->forgetInstance(Backups::class);
        app()->forgetInstance(Restorer::class);
        app()->call([new RunRestore($job->backup, $job->options, null, $job->by), 'handle']);

        $this->assertSame(2, $this->site()->table('articles')->count());
        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['restore', 'ok', 'admin', 'Owner'], [$entry['action'], $entry['status'], $entry['trigger'], $entry['by']]);
    }

    public function test_the_backup_job_backs_up_what_it_was_asked_for_by_whom(): void
    {
        $this->seedSite();

        app()->call([new RunBackup('files', 'Owner'), 'handle']);

        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['backup', 'ok', 'admin', 'Owner'], [$entry['action'], $entry['status'], $entry['trigger'], $entry['by']]);
        $this->assertSame([], app(Restorer::class)->plan(app(Backups::class)->all()[0]['id'])['databases']);

        $this->travel(2)->seconds();
        app()->call([new RunBackup('database'), 'handle']);

        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertArrayNotHasKey('by', $entry);
        $this->assertSame([], app(Restorer::class)->plan(collect(app(Backups::class)->all())->firstWhere('disk', 'backups')['id'])['folders']);
    }

    public function test_a_wrong_password_comes_back_as_a_message(): void
    {
        config(['atrium-backup.password' => 'correct horse']);
        $this->seedSite();
        app(Backups::class)->run();
        config(['atrium-backup.password' => null]);
        $id = app(Backups::class)->all()[0]['id'];

        $this->actingAs($this->admin(), 'admin')
            ->postJson("/admin/backups/{$id}/plan", ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('atrium-backup::backup.errors.password_wrong'));
    }

    public function test_an_archive_can_be_deleted(): void
    {
        $this->seedSite();
        app(Backups::class)->run();
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups-copy');

        $this->actingAs($this->admin(), 'admin')->delete("/admin/backups/{$backup['id']}")->assertSessionHas('success');

        $this->assertSame(['backups'], collect(app(Backups::class)->all())->pluck('disk')->all());
        $this->assertSame('delete', app(Backups::class)->log()->recent(1)[0]['action']);
        $this->assertSame('Owner', app(Backups::class)->log()->recent(1)[0]['by']);
    }
}
