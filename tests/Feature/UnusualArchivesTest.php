<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Backups;
use Atrium\Backup\Manifest;
use Atrium\Backup\Restore\Archive;
use Atrium\Backup\Restore\RestoreFailed;
use Atrium\Backup\Restore\Restorer;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use ZipArchive;

/**
 * Archives the module did not make itself, damaged ones, ones that try to
 * write outside their folder, and archives on a destination that is not a
 * folder of this server.
 */
final class UnusualArchivesTest extends TestCase
{
    private const FILE = 'test/2026-01-02-03-04-05.zip';

    public function test_a_file_that_is_not_a_zip_is_refused_and_a_restore_of_it_is_logged_as_failed(): void
    {
        @mkdir($this->dir.'/dest-1/test', 0777, true);
        file_put_contents($this->dir.'/dest-1/'.self::FILE, 'not a zip');
        $id = Backups::id('backups', self::FILE);
        Exceptions::fake();

        try {
            app(Restorer::class)->plan($id);
            $this->fail('A file that is not a zip has no plan.');
        } catch (RestoreFailed $e) {
            $this->assertSame(__('atrium-backup::backup.errors.unreadable'), $e->getMessage());
        }

        $result = app(Restorer::class)->restore($id, ['safety_backup' => false]);

        $this->assertSame(['status' => 'failed', 'message' => __('atrium-backup::backup.errors.unreadable')], $result);
        Exceptions::assertReported(RestoreFailed::class);
        $entry = app(Backups::class)->log()->recent(1)[0];
        $this->assertSame(['restore', 'failed', __('atrium-backup::backup.errors.unreadable')], [$entry['action'], $entry['status'], $entry['message']]);
        $this->assertNull(app(Backups::class)->log()->running());
    }

    public function test_an_archive_made_without_this_module_is_read_from_its_file_names(): void
    {
        $id = $this->archive([
            'db-dumps/sqlite-site.sql' => 'CREATE TABLE articles (id integer);',
            'db-dumps/mysql-database.sql' => '-- the default connection',
            'db-dumps/postgresql-reports-database.sql' => '-- a connection this server does not have',
            'db-dumps/notes.txt' => 'not a dump',
        ], manifest: null);

        $plan = app(Restorer::class)->plan($id);

        $this->assertSame(
            [['site', 'sqlite'], [(string) config('database.default'), 'mysql'], ['reports', 'pgsql']],
            array_map(static fn (array $db): array => [$db['connection'], $db['driver']], $plan['databases']),
        );
        $this->assertSame([], $plan['folders']);
        $this->assertNull($plan['created_at']);
        $this->assertNull($plan['app']);
        $this->assertFalse($plan['databases'][2]['restorable']);
        $this->assertNull($plan['databases'][2]['target']);
        $this->assertContains(__('atrium-backup::backup.plan.no_connection', ['connection' => 'reports']), $plan['warnings']);
    }

    public function test_an_archive_with_nothing_to_restore_says_so(): void
    {
        $plan = app(Restorer::class)->plan($this->archive(['readme.txt' => 'Hello'], manifest: null));

        $this->assertSame([__('atrium-backup::backup.plan.empty')], $plan['warnings']);
    }

    public function test_a_dump_the_manifest_lists_but_the_archive_lacks_is_skipped_with_a_warning(): void
    {
        $plan = app(Restorer::class)->plan($this->archive([], ['databases' => [['connection' => 'site', 'driver' => 'sqlite', 'file' => 'db-dumps/sqlite-site.sql']]]));

        $this->assertNull($plan['databases'][0]['size']);
        $this->assertFalse($plan['databases'][0]['restorable']);
        $this->assertSame([__('atrium-backup::backup.plan.dump_missing', ['connection' => 'site'])], $plan['warnings']);
    }

    public function test_names_that_climb_out_of_their_folder_are_never_written(): void
    {
        $prefix = ltrim($this->dir.'/uploads', '/').'/';
        $id = $this->archive([
            $prefix.'safe.txt' => 'safe',
            $prefix.'../climbed.txt' => 'no',
            $prefix.'photos/../../climbed-too.txt' => 'no',
            $prefix.'/rooted.txt' => 'no',
            'elsewhere/other.txt' => 'not in this folder',
        ], ['folders' => [
            ['name' => $this->dir.'/uploads', 'disk' => null, 'prefix' => $prefix],
            // No prefix: it would match every file of the archive. Its disk
            // keeps whatever a broken check writes out of the project.
            ['name' => 'nothing', 'disk' => 'nothing', 'prefix' => ''],
        ]]);
        config(['filesystems.disks.nothing' => ['driver' => 'local', 'root' => $this->dir.'/nothing']]);
        mkdir($this->dir.'/nothing');

        $result = app(Restorer::class)->restore($id, ['database' => false, 'safety_backup' => false]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('safe', file_get_contents($this->dir.'/uploads/safe.txt'));
        $this->assertFileDoesNotExist($this->dir.'/climbed.txt');
        $this->assertFileDoesNotExist($this->dir.'/climbed-too.txt');
        $this->assertSame(['.', '..'], scandir($this->dir.'/nothing'));
        $this->assertSame(1, app(Backups::class)->log()->recent(1)[0]['files']);
    }

    public function test_a_disk_folder_goes_back_into_that_disk_even_when_it_is_gone(): void
    {
        config(['filesystems.disks.media' => ['driver' => 'local', 'root' => $this->dir.'/media/']]);
        $id = $this->archive(['old/server/media/cat.jpg' => 'meow'], ['folders' => [['name' => 'media', 'disk' => 'media', 'prefix' => 'old/server/media/']]]);

        $plan = app(Restorer::class)->plan($id);

        $this->assertSame($this->dir.'/media', $plan['folders'][0]['target']);
        $this->assertSame([1, 1, 0], [$plan['folders'][0]['files'], $plan['folders'][0]['new'], $plan['folders'][0]['extra']]);

        app(Restorer::class)->restore($id, ['database' => false, 'safety_backup' => false]);

        $this->assertSame('meow', file_get_contents($this->dir.'/media/cat.jpg'));
    }

    public function test_an_unknown_archive_or_a_running_job_stops_a_restore(): void
    {
        $this->assertSame(['status' => 'failed', 'message' => __('atrium-backup::backup.errors.not_found')], app(Restorer::class)->restore('nope', []));

        $id = $this->archive(['readme.txt' => 'Hello']);
        app(Backups::class)->log()->start('backup');

        $this->assertSame(['status' => 'busy'], app(Restorer::class)->restore($id, ['safety_backup' => false]));
    }

    public function test_the_restore_command_shows_warnings_and_says_why_it_stopped(): void
    {
        $this->archive(['readme.txt' => 'Hello'], manifest: null);
        $file = basename(self::FILE);

        $this->artisan('atrium:backup:restore', ['backup' => $file, '--dry-run' => true])
            ->expectsOutput(__('atrium-backup::backup.plan.empty'))
            ->assertSuccessful();

        app(Backups::class)->log()->start('backup');
        $this->artisan('atrium:backup:restore', ['backup' => $file, '--force' => true, '--no-safety-backup' => true])
            ->expectsOutput('Another backup or restore is running.')
            ->assertFailed();
        app(Backups::class)->log()->finish('ok');

        // A dump that does not run.
        $this->archive(['db-dumps/sqlite-site.sql' => 'CREATE TABLE (;'], ['databases' => [['connection' => 'site', 'driver' => 'sqlite', 'file' => 'db-dumps/sqlite-site.sql']]]);
        config(['database.connections.site.driver' => 'sqlite', 'database.connections.site.database' => $this->dir.'/site.sqlite']);
        $this->artisan('atrium:backup:restore', ['backup' => $file, '--force' => true, '--no-safety-backup' => true])
            ->expectsOutputToContain('Restore failed: ')
            ->assertFailed();
    }

    public function test_the_restore_command_stops_at_an_archive_it_cannot_read(): void
    {
        @mkdir($this->dir.'/dest-1/test', 0777, true);
        file_put_contents($this->dir.'/dest-1/'.self::FILE, 'not a zip');

        $this->artisan('atrium:backup:restore', ['backup' => basename(self::FILE), '--force' => true])
            ->expectsOutput(__('atrium-backup::backup.errors.unreadable'))
            ->assertFailed();
        $this->assertSame([], app(Backups::class)->log()->recent());
    }

    public function test_entries_that_cannot_be_written_or_read_fail_with_a_message(): void
    {
        $this->archive(['a.txt' => str_repeat('a', 50_000)]);
        $archive = Archive::open($this->dir.'/dest-1/'.self::FILE, null);

        try {
            $this->assertRestoreFails(__('atrium-backup::backup.errors.entry', ['name' => 'missing.txt']), fn () => $archive->extract('missing.txt', $this->dir.'/out/missing.txt'));

            // A file where the folder should be.
            file_put_contents($this->dir.'/file', '');
            $this->assertRestoreFails(__('atrium-backup::backup.errors.write', ['path' => $this->dir.'/file/sub']), fn () => $archive->extract('a.txt', $this->dir.'/file/sub/a.txt'));

            // A folder where the temporary file should be.
            mkdir($this->dir.'/out/a.txt.restoring', 0755, true);
            $this->assertRestoreFails(__('atrium-backup::backup.errors.write', ['path' => $this->dir.'/out/a.txt']), fn () => $archive->extract('a.txt', $this->dir.'/out/a.txt'));
        } finally {
            $archive->close();
        }
    }

    public function test_a_damaged_entry_is_never_left_half_written(): void
    {
        $this->archive(['a.txt' => str_repeat(random_bytes(1000), 50)]);
        $path = $this->dir.'/dest-1/'.self::FILE;
        $bytes = (string) file_get_contents($path);
        // Zero a stretch of the compressed data, after the 30-byte header and the name.
        file_put_contents($path, substr_replace($bytes, str_repeat("\0", 1000), 100, 1000));
        $archive = Archive::open($path, null);

        try {
            $this->assertRestoreFails(__('atrium-backup::backup.errors.entry', ['name' => 'a.txt']), fn () => $archive->extract('a.txt', $this->dir.'/out/a.txt'));
        } finally {
            $archive->close();
        }

        $this->assertFileDoesNotExist($this->dir.'/out/a.txt');
        $this->assertFileDoesNotExist($this->dir.'/out/a.txt.restoring');
    }

    public function test_an_archive_on_a_remote_destination_is_downloaded_once_and_reused(): void
    {
        $this->remoteDestination();
        $this->seedSite();
        $this->assertSame('ok', app(Backups::class)->run()['status']);
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups-copy');
        $downloads = app(Backups::class)->log()->directory().'/downloads';
        $copy = $downloads.'/'.sha1($backup['path']).'.zip';
        $this->assertDirectoryDoesNotExist($downloads);

        app(Restorer::class)->plan($backup['id']);

        $this->assertSame($backup['size'], filesize($copy));
        $this->assertFileDoesNotExist($copy.'.part');

        // A copy of another size is downloaded again, and older downloads go.
        file_put_contents($copy, 'cut short');
        file_put_contents($downloads.'/older.zip', 'an earlier download');
        app(Restorer::class)->plan($backup['id']);
        clearstatcache();
        $this->assertSame($backup['size'], filesize($copy));
        $this->assertFileDoesNotExist($downloads.'/older.zip');

        // The restore after the dry run uses the same copy.
        touch($copy, 1_000_000_000);
        clearstatcache();
        $this->site()->table('articles')->delete();
        $this->assertSame('ok', app(Restorer::class)->restore($backup['id'], ['files' => false, 'safety_backup' => false])['status']);
        clearstatcache();
        $this->assertSame(1_000_000_000, filemtime($copy));
        $this->assertSame(2, $this->site()->table('articles')->count());
    }

    public function test_a_download_that_cannot_be_written_fails_with_a_message(): void
    {
        $this->remoteDestination();
        $this->seedSite();
        app(Backups::class)->run();
        $backup = collect(app(Backups::class)->all())->firstWhere('disk', 'backups-copy');
        $copy = app(Backups::class)->log()->directory().'/downloads/'.sha1($backup['path']).'.zip';
        mkdir($copy.'.part', 0750, true);

        $this->assertRestoreFails(__('atrium-backup::backup.errors.write', ['path' => $copy]), fn () => app(Restorer::class)->plan($backup['id']));
    }

    public function test_a_destination_that_fails_while_listing_hides_only_its_own_archives(): void
    {
        $this->seedSite();
        app(Backups::class)->run();
        Exceptions::fake();
        Storage::extend('sizeless', static function ($app, array $config): FilesystemAdapter {
            $adapter = new class(new LocalFilesystemAdapter($config['root'])) extends DecoratedAdapter
            {
                public function fileSize(string $path): FileAttributes
                {
                    throw UnableToRetrieveMetadata::fileSize($path, 'the server went away');
                }
            };

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
        config(['filesystems.disks.backups-copy' => ['driver' => 'sizeless', 'root' => $this->dir.'/dest-2']]);
        Storage::forgetDisk('backups-copy');

        $this->assertSame(['backups'], array_column(app(Backups::class)->all(), 'disk'));
        Exceptions::assertReported(UnableToRetrieveMetadata::class);
    }

    /**
     * The second destination as a disk that is not a folder of this server
     * to the module, as S3 or SFTP would be.
     */
    private function remoteDestination(): void
    {
        Storage::extend('remote-folder', static function ($app, array $config): FilesystemAdapter {
            $adapter = new class(new LocalFilesystemAdapter($config['root'])) extends DecoratedAdapter {};

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
        config(['filesystems.disks.backups-copy' => ['driver' => 'remote-folder', 'root' => $this->dir.'/dest-2']]);
    }

    private function assertRestoreFails(string $message, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the restore to fail with: '.$message);
        } catch (RestoreFailed $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * An archive on the first destination, with a manifest unless null.
     *
     * @param  array<string,string>  $files
     * @param  array<string,mixed>|null  $manifest
     */
    private function archive(array $files, ?array $manifest = []): string
    {
        $path = $this->dir.'/dest-1/'.self::FILE;
        @mkdir(dirname($path), 0777, true);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }

        if ($manifest !== null) {
            $zip->addFromString(Manifest::FILE, (string) json_encode(['format' => 1, 'app' => 'Test', 'created_at' => '2026-01-02T03:04:05+00:00', 'databases' => [], 'folders' => [], ...$manifest]));
        }

        $zip->close();

        return Backups::id('backups', self::FILE);
    }
}
