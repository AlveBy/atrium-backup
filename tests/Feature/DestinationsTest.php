<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Backups;
use Atrium\Backup\Restore\Restorer;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real remote destinations: backup, list, download for a restore, clean up.
 * Each runs only when its server is given in the environment, e.g. with
 * containers (see docs/backup.md, "Testing"):
 *
 *   ATRIUM_TEST_S3_ENDPOINT=http://127.0.0.1:19090 (any S3-compatible server, bucket "backups")
 *   ATRIUM_TEST_SFTP=127.0.0.1:12222 (user atrium, password atrium, folder backups)
 *   ATRIUM_TEST_FTP=127.0.0.1:12121 (user atrium, password atrium; ATRIUM_TEST_FTP_ROOT if not chrooted)
 */
final class DestinationsTest extends TestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function destinations(): iterable
    {
        yield 's3' => ['s3'];
        yield 'sftp' => ['sftp'];
        yield 'ftp' => ['ftp'];
    }

    #[DataProvider('destinations')]
    public function test_a_backup_goes_to_a_remote_destination_and_comes_back(string $driver): void
    {
        $disk = $this->remoteDisk($driver);
        config(["filesystems.disks.remote-{$driver}" => $disk]);
        config(['atrium-backup.destinations' => ['backups', "remote-{$driver}"]]);
        $this->clean("remote-{$driver}");
        $this->seedSite();

        $this->assertSame('ok', app(Backups::class)->run()['status']);

        $remote = collect(app(Backups::class)->all())->firstWhere('disk', "remote-{$driver}");
        $this->assertNotNull($remote);
        $this->assertGreaterThan(0, $remote['size']);
        $this->assertTrue(collect(app(Backups::class)->health())->firstWhere('disk', "remote-{$driver}")['reachable']);

        // Restoring from it downloads the archive first.
        $this->site()->table('articles')->delete();
        $this->assertSame('ok', app(Restorer::class)->restore($remote['id'], ['files' => false, 'safety_backup' => false])['status']);
        $this->assertSame(2, $this->site()->table('articles')->count());

        $this->clean("remote-{$driver}");
    }

    private function clean(string $disk): void
    {
        if (Storage::disk($disk)->directoryExists('test')) {
            Storage::disk($disk)->deleteDirectory('test');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function remoteDisk(string $driver): array
    {
        if ($driver === 's3') {
            $endpoint = env('ATRIUM_TEST_S3_ENDPOINT') ?: $this->markTestSkipped('Set ATRIUM_TEST_S3_ENDPOINT to test S3.');

            return [
                'driver' => 's3',
                'key' => env('ATRIUM_TEST_S3_KEY', 'test'),
                'secret' => env('ATRIUM_TEST_S3_SECRET', 'test'),
                'region' => 'us-east-1',
                'bucket' => env('ATRIUM_TEST_S3_BUCKET', 'backups'),
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'throw' => true,
            ];
        }

        $server = env('ATRIUM_TEST_'.strtoupper($driver)) ?: $this->markTestSkipped('Set ATRIUM_TEST_'.strtoupper($driver).' to test '.$driver.'.');
        [$host, $port] = explode(':', (string) $server) + [1 => null];

        return [
            'driver' => $driver,
            'host' => $host,
            'port' => (int) ($port ?: ($driver === 'sftp' ? 22 : 21)),
            'username' => 'atrium',
            'password' => 'atrium',
            'root' => (string) env('ATRIUM_TEST_'.strtoupper($driver).'_ROOT', $driver === 'sftp' ? '/backups' : ''),
            'passive' => true,
            'timeout' => 10,
            'throw' => true,
        ];
    }
}
