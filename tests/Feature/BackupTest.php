<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Admin\Models\AdminUser;
use Atrium\Backup\Backups;
use Atrium\Backup\Notifications\Notifiable;
use Atrium\Backup\SpatieConfig;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Support\BackupLogger;
use ZipArchive;

final class BackupTest extends TestCase
{
    public function test_a_backup_holds_the_database_the_folders_and_a_manifest_on_every_destination(): void
    {
        $this->seedSite();

        $this->artisan('atrium:backup:run')->assertSuccessful();

        $backups = app(Backups::class)->all();
        $this->assertSame(['backups', 'backups-copy'], collect($backups)->pluck('disk')->sort()->values()->all());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->dir.'/dest-1/'.$backups[0]['path']));
        $driver = config('database.connections.site.driver');
        $type = ['pgsql' => 'postgresql'][$driver] ?? $driver;
        $dump = (string) $zip->getFromName("db-dumps/{$type}-site.sql");
        $this->assertStringContainsString('Second', $dump);
        $prefix = ltrim($this->dir, '/').'/uploads/';
        $this->assertSame('Hello', $zip->getFromName($prefix.'readme.txt'));
        $this->assertSame(2048, $zip->statName($prefix.'photos/cat.jpg')['size']);

        $manifest = json_decode((string) $zip->getFromName('atrium-backup.json'), true);
        $this->assertSame(1, $manifest['format']);
        $this->assertSame([['connection' => 'site', 'driver' => $driver, 'file' => "db-dumps/{$type}-site.sql"]], $manifest['databases']);
        $this->assertSame($prefix, $manifest['folders'][0]['prefix']);
        $zip->close();

        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['backup', 'ok'], [$entry['action'], $entry['status']]);
        $this->assertEqualsCanonicalizing(['backups', 'backups-copy'], $entry['disks']);
    }

    public function test_every_archive_is_opened_and_counted_after_it_is_packed(): void
    {
        $this->seedSite();
        $messages = [];
        app(BackupLogger::class)->onMessage(function (string $level, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->artisan('atrium:backup:run')->assertSuccessful();

        $this->assertNotEmpty(preg_grep('/^Backup verified: \d+ files in archive\.$/', $messages));
    }

    public function test_the_env_files_never_go_into_an_archive(): void
    {
        $this->seedSite();
        $secret = base_path('.env.atrium-backup-test');
        file_put_contents($secret, 'APP_KEY=base64:secret');
        // Someone backs up the whole project folder. Less what tests running
        // next to this one write while it is being packed.
        config([
            'atrium-backup.sources.folders' => [base_path()],
            'atrium-backup.sources.exclude' => [base_path('storage'), base_path('bootstrap/cache')],
        ]);

        try {
            $this->artisan('atrium:backup:run --only-files')->assertSuccessful();
        } finally {
            unlink($secret);
        }

        $zip = new ZipArchive;
        $zip->open($this->dir.'/dest-1/'.app(Backups::class)->all()[0]['path']);
        $names = array_map(static fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();

        $this->assertContains('artisan', $names);
        $this->assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_contains($name, '.env'))));
    }

    public function test_a_relative_sqlite_path_is_found_from_the_project_like_laravel_does(): void
    {
        // DB_DATABASE=database/….sqlite, from the project folder.
        $relative = 'database/atrium-backup-relative-'.bin2hex(random_bytes(4)).'.sqlite';
        (new \PDO('sqlite:'.base_path($relative)))->exec("CREATE TABLE notes (body TEXT); INSERT INTO notes VALUES ('Relative')");
        config([
            'database.connections.relative' => ['driver' => 'sqlite', 'database' => $relative, 'prefix' => ''],
            'atrium-backup.sources.databases' => ['relative'],
        ]);
        $cwd = (string) getcwd();
        // Like a web server, which runs from public/.
        chdir($this->dir.'/uploads');

        try {
            $this->artisan('atrium:backup:run --only-db')->assertSuccessful();
        } finally {
            chdir($cwd);
            unlink(base_path($relative));
        }

        $zip = new ZipArchive;
        $zip->open($this->dir.'/dest-1/'.app(Backups::class)->all()[0]['path']);
        $this->assertStringContainsString('Relative', (string) $zip->getFromName('db-dumps/sqlite-relative.sql'));
        $zip->close();
    }

    public function test_sqlite_paths_that_are_absolute_in_memory_or_nowhere_are_left_as_they_are(): void
    {
        config([
            'database.connections.absolute' => ['driver' => 'sqlite', 'database' => $this->dir.'/site.sqlite'],
            'database.connections.memory' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'database.connections.nowhere' => ['driver' => 'sqlite', 'database' => 'database/no-such-file.sqlite'],
            'database.connections.other' => ['driver' => 'pgsql', 'database' => 'atrium'],
            'atrium-backup.sources.databases' => ['absolute', 'memory', 'nowhere', 'other'],
        ]);

        SpatieConfig::apply();

        $this->assertSame($this->dir.'/site.sqlite', config('database.connections.absolute.database'));
        $this->assertSame(':memory:', config('database.connections.memory.database'));
        $this->assertSame('database/no-such-file.sqlite', config('database.connections.nowhere.database'));
        $this->assertSame('atrium', config('database.connections.other.database'));
    }

    public function test_only_the_database_or_only_the_files(): void
    {
        $this->seedSite();

        $this->artisan('atrium:backup:run --only-db')->assertSuccessful();
        $names = $this->entries(app(Backups::class)->all()[0]['path']);
        $this->assertNotEmpty(preg_grep('#^db-dumps/#', $names));
        $this->assertEmpty(preg_grep('#readme\.txt$#', $names));

        $this->travel(2)->seconds();
        $this->artisan('atrium:backup:run --only-files')->assertSuccessful();
        $names = $this->entries(app(Backups::class)->all()[0]['path']);
        $this->assertEmpty(preg_grep('#^db-dumps/#', $names));
        $this->assertNotEmpty(preg_grep('#readme\.txt$#', $names));
    }

    public function test_with_a_password_the_archive_is_encrypted(): void
    {
        config(['atrium-backup.password' => 'correct horse']);
        $this->seedSite();

        $this->artisan('atrium:backup:run')->assertSuccessful();

        $zip = new ZipArchive;
        $zip->open($this->dir.'/dest-1/'.app(Backups::class)->all()[0]['path']);
        $this->assertNotSame(ZipArchive::EM_NONE, $zip->statIndex(0)['encryption_method']);
        $this->assertFalse($zip->getFromIndex(0));
        $zip->setPassword('correct horse');
        $this->assertNotFalse($zip->getFromIndex(0));
        $zip->close();
    }

    public function test_a_destination_that_fails_does_not_stop_the_others_and_the_owner_is_told(): void
    {
        Notification::fake();
        $this->seedSite();
        // A file where the folder should be: nothing can be written there.
        file_put_contents($this->dir.'/blocked', '');
        config(['filesystems.disks.backups-copy.root' => $this->dir.'/blocked/inside']);

        $this->artisan('atrium:backup:run')->assertFailed();

        $this->assertSame(['backups'], collect(app(Backups::class)->all())->pluck('disk')->unique()->values()->all());
        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame('partial', $entry['status']);
        $this->assertSame('backups-copy', $entry['failed'][0]['disk']);
        Notification::assertSentTo(new Notifiable, BackupHasFailedNotification::class);
    }

    public function test_a_failed_backup_is_logged_and_mailed(): void
    {
        Notification::fake();
        config(['atrium-backup.sources.databases' => ['missing']]);
        config(['database.connections.missing' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'nobody', 'password' => '']]);

        $this->artisan('atrium:backup:run')->assertFailed();

        $this->assertSame([], app(Backups::class)->all());
        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['backup', 'failed'], [$entry['action'], $entry['status']]);
        $this->assertNotEmpty($entry['message']);
        Notification::assertSentTo(new Notifiable, BackupHasFailedNotification::class);
    }

    public function test_the_command_refuses_both_halves_at_once_and_a_second_run(): void
    {
        $this->artisan('atrium:backup:run --only-db --only-files')
            ->expectsOutput('Choose --only-db or --only-files, not both.')
            ->assertExitCode(2);
        $this->assertSame([], app(Backups::class)->log()->recent());

        app(Backups::class)->log()->start('restore');
        $this->artisan('atrium:backup:run')
            ->expectsOutput('Another backup or restore is running.')
            ->assertFailed();
    }

    public function test_the_command_logs_a_run_without_a_terminal_as_the_schedule(): void
    {
        $this->seedSite();

        $this->artisan('atrium:backup:run')->assertSuccessful();
        $this->assertSame('console', app(Backups::class)->log()->recent(1)[0]['trigger']);

        $this->travel(2)->seconds();
        $this->artisan('atrium:backup:run', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame('schedule', app(Backups::class)->log()->recent(1)[0]['trigger']);
    }

    public function test_the_mail_goes_to_the_addresses_set_else_to_every_active_superadmin(): void
    {
        config(['atrium-backup.mail' => ['ops@example.com', 'it@example.com']]);
        SpatieConfig::apply();
        $this->assertSame(['ops@example.com', 'it@example.com'], (new Notifiable)->routeNotificationForMail());

        config(['atrium-backup.mail' => []]);
        SpatieConfig::apply();
        $this->admin();
        $this->admin(superadmin: false);
        AdminUser::query()->create(['name' => 'Gone', 'email' => 'gone@example.com', 'password' => 'secret-password', 'is_active' => false])
            ->forceFill(['is_superadmin' => true])->save();

        $this->assertSame(['owner@example.com'], (new Notifiable)->routeNotificationForMail());
    }

    public function test_with_the_database_down_nobody_is_found_to_mail(): void
    {
        config(['atrium-backup.mail' => []]);
        SpatieConfig::apply();
        $default = config('database.default');
        config(['database.connections.down' => ['driver' => 'sqlite', 'database' => $this->dir.'/no/such/folder/db.sqlite'], 'database.default' => 'down']);

        try {
            $this->assertSame([], (new Notifiable)->routeNotificationForMail());
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_one_run_at_a_time(): void
    {
        $log = app(Backups::class)->log();
        $this->assertTrue($log->start('restore'));

        $this->assertSame(['status' => 'busy'], app(Backups::class)->run());

        $log->finish('ok');
        $this->seedSite();
        $this->assertSame('ok', app(Backups::class)->run()['status']);
    }

    public function test_an_id_only_finds_an_archive_a_destination_lists(): void
    {
        $this->seedSite();
        $this->artisan('atrium:backup:run')->assertSuccessful();
        file_put_contents($this->dir.'/secret.txt', 'no');

        $backups = app(Backups::class);
        $this->assertNotNull($backups->find($backups->all()[0]['id']));
        $this->assertNull($backups->find(Backups::id('backups', '../secret.txt')));
        $this->assertNull($backups->find(Backups::id('local', 'secret.txt')));
        $this->assertNull($backups->find('not base64 at all!'));
    }

    /**
     * @return array<int,string>
     */
    private function entries(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($this->dir.'/dest-1/'.$path);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }
}
