<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Models\AdminUser;
use Atrium\Backup\BackupServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Backup\BackupServiceProvider as SpatieBackupServiceProvider;
use Tighten\Ziggy\ZiggyServiceProvider;

/**
 * Every test backs up a database of its own, connection "site": an SQLite
 * file, or a throwaway database on the MySQL or PostgreSQL server CI runs the
 * suite against. The panel's tables stay on the default connection, inside
 * RefreshDatabase's transaction, so a restore never touches them.
 *
 * Folders and destinations are temporary directories.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const SITE_DATABASE = 'atrium_backup_site';

    protected string $dir;

    /** The site connection's driver when the test began: a test may change it. */
    private string $siteDriver = 'sqlite';

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            McpServiceProvider::class,
            SpatieBackupServiceProvider::class,
            AtriumServiceProvider::class,
            BackupServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->dir = sys_get_temp_dir().'/atrium-backup-'.bin2hex(random_bytes(5));
        (new Filesystem)->ensureDirectoryExists($this->dir.'/uploads/photos');

        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('database.connections.site', $this->siteConnection($config->get('database.connections.'.$config->get('database.default'))));
        $config->set('atrium.demo', false);
        $config->set('atrium.modules', ['alveby/atrium-backup']);
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);

        $config->set('atrium-backup.name', 'test');
        $config->set('atrium-backup.sources.databases', ['site']);
        $config->set('atrium-backup.sources.disks', []);
        $config->set('atrium-backup.sources.folders', [$this->dir.'/uploads']);
        $config->set('atrium-backup.destinations', ['backups', 'backups-copy']);
        $config->set('atrium-backup.storage', $this->dir.'/state');
        $config->set('atrium-backup.password', null);
        $config->set('atrium-backup.queue', null);
        $config->set('atrium-backup.mail', ['owner@example.com']);
        $config->set('filesystems.disks.backups', ['driver' => 'local', 'root' => $this->dir.'/dest-1']);
        $config->set('filesystems.disks.backups-copy', ['driver' => 'local', 'root' => $this->dir.'/dest-2']);
    }

    /**
     * On MySQL and PostgreSQL the whole suite shares one database, migrated
     * once by whichever package came first: each class migrates afresh.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        RefreshDatabaseState::$migrated = false;
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createSiteDatabase();
    }

    protected function tearDown(): void
    {
        DB::purge('site');
        $this->dropSiteDatabase();
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function site(): Connection
    {
        return DB::connection('site');
    }

    protected function admin(bool $superadmin = true): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => $superadmin ? 'Owner' : 'Editor',
            'email' => $superadmin ? 'owner@example.com' : 'editor@example.com',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $admin->forceFill(['is_superadmin' => $superadmin])->save();

        return $admin;
    }

    /**
     * A table with two articles, and two files in the folder.
     */
    protected function seedSite(): void
    {
        $this->site()->getSchemaBuilder()->create('articles', function ($table): void {
            $table->id();
            $table->string('title');
        });
        $this->site()->table('articles')->insert([['title' => 'First'], ['title' => 'Second']]);

        file_put_contents($this->dir.'/uploads/readme.txt', 'Hello');
        file_put_contents($this->dir.'/uploads/photos/cat.jpg', str_repeat('c', 2048));
    }

    /**
     * @param  array<string,mixed>  $default
     * @return array<string,mixed>
     */
    private function siteConnection(array $default): array
    {
        return match ($default['driver']) {
            'mysql', 'mariadb', 'pgsql' => [...$default, 'database' => self::SITE_DATABASE],
            default => ['driver' => 'sqlite', 'database' => $this->dir.'/site.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
        };
    }

    private function createSiteDatabase(): void
    {
        $this->siteDriver = (string) config('database.connections.site.driver');

        if ($this->siteDriver === 'sqlite') {
            touch((string) config('database.connections.site.database'));

            return;
        }

        $this->dropSiteDatabase();
        $this->maintenance()->statement('CREATE DATABASE '.self::SITE_DATABASE);
    }

    private function dropSiteDatabase(): void
    {
        if ($this->siteDriver !== 'sqlite') {
            $this->maintenance()->statement('DROP DATABASE IF EXISTS '.self::SITE_DATABASE);
        }
    }

    /**
     * A connection of its own for CREATE/DROP DATABASE: the default one is
     * inside RefreshDatabase's transaction, which those statements would end.
     */
    private function maintenance(): Connection
    {
        config(['database.connections.maintenance' => config('database.connections.'.config('database.default'))]);

        return DB::connection('maintenance');
    }
}
