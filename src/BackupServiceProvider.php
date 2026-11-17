<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

use Atrium\Admin\Modules\Modules;
use Atrium\Backup\Console\RestoreCommand;
use Atrium\Backup\Console\RunCommand;
use Atrium\Backup\Destinations\CloudDrives;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Events\UnhealthyBackupWasFound;

final class BackupServiceProvider extends ServiceProvider
{
    public const MODULE = 'alveby/atrium-backup';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-backup.php', 'atrium-backup');

        $this->app->singleton(Backups::class);
        $this->app->singleton(BackupLog::class, fn ($app): BackupLog => new BackupLog((string) $app['config']->get('atrium-backup.storage')));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'atrium-backup');
        // spatie's e-mails in the languages its package lacks (be, uz).
        $this->callAfterResolving('translation.loader', static fn (FileLoader $loader) => $loader->addPath(__DIR__.'/../resources/lang'));

        // The disks first: spatie's config below names them as destinations.
        $this->addDisks();
        CloudDrives::register();

        if ($this->app->runningInConsole()) {
            $this->commands([RunCommand::class, RestoreCommand::class]);

            $this->publishes([
                __DIR__.'/../config/atrium-backup.php' => config_path('atrium-backup.php'),
            ], 'atrium-backup-config');
        }

        if (! $this->app->make(Modules::class)->isInstalled(self::MODULE)) {
            return;
        }

        // spatie/laravel-backup reads `backup`; it is built from ours, for its
        // own commands (backup:clean, backup:monitor) too.
        $this->app['config']->set('backup', SpatieConfig::build((array) $this->app['config']->get('atrium-backup')));

        $this->listen();
        $this->schedule();
        $this->admin();
    }

    /**
     * The module's own disks, unless the application defines one of the same
     * name in config/filesystems.php.
     */
    private function addDisks(): void
    {
        $config = $this->app['config'];

        foreach ((array) $config->get('atrium-backup.disks', []) as $name => $disk) {
            if ($config->get("filesystems.disks.{$name}") === null) {
                $config->set("filesystems.disks.{$name}", $disk);
            }
        }
    }

    private function listen(): void
    {
        $log = fn (): BackupLog => $this->app->make(BackupLog::class);

        Event::listen(BackupManifestWasCreated::class, fn (BackupManifestWasCreated $event) => Manifest::addTo($event->manifest));
        Event::listen(BackupZipWasCreated::class, fn (BackupZipWasCreated $event) => $log()->zipCreated($event->pathToZip));
        Event::listen(BackupWasSuccessful::class, fn (BackupWasSuccessful $event) => $log()->copied($event->diskName));
        Event::listen(BackupHasFailed::class, fn (BackupHasFailed $event) => $log()->copyFailed($event->diskName, $event->exception->getMessage()));
        Event::listen(CleanupWasSuccessful::class, fn (CleanupWasSuccessful $event) => $log()->add('cleanup', 'ok', ['disk' => $event->diskName]));
        Event::listen(UnhealthyBackupWasFound::class, fn (UnhealthyBackupWasFound $event) => $log()->add('monitor', 'failed', [
            'disk' => $event->diskName,
            'message' => $event->failureMessages->map(fn ($failure) => $failure['message'])->implode(' '),
        ]));
    }

    /**
     * Backup, clean-up and health check, in the order they must run. Laravel
     * runs them only from `php artisan schedule:run` (cron, every minute).
     */
    private function schedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            foreach (Schedules::entries((array) $this->app['config']->get('atrium-backup.schedule')) as $command => $cron) {
                $event = $schedule->command($command)->cron($cron);

                if ($command === 'atrium:backup:run') {
                    $event->withoutOverlapping(720);
                }
            }
        });
    }

    private function admin(): void
    {
        $config = $this->app['config'];

        // Backups hold every password hash and a restore overwrites everything:
        // superadmins only, the menu entry included.
        $config->set('atrium.superadmin_routes', [...(array) $config->get('atrium.superadmin_routes', []), 'admin.backups.']);

        $menu = (array) $config->get('atrium.menu', []);

        if (! in_array('admin.backups.index', array_column($menu, 'route'), true)) {
            $menu[] = ['label' => 'atrium-backup::backup.menu', 'route' => 'admin.backups.index', 'icon' => 'package'];
            $config->set('atrium.menu', $menu);
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
    }
}
