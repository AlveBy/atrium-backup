<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Destinations;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use RuntimeException;

/**
 * Disk drivers "google" and "dropbox" for backup destinations.
 *
 * Laravel has none of its own. Each is built from a maintained Flysystem
 * adapter the application installs itself; until then a disk with that driver
 * fails with a message naming the package, and nothing else is affected.
 *
 * The classes are named as strings on purpose: neither adapter is a
 * dependency of this package.
 */
final class CloudDrives
{
    public const GOOGLE_PACKAGE = 'masbug/flysystem-google-drive-ext';

    public const DROPBOX_PACKAGE = 'spatie/flysystem-dropbox';

    public static function register(): void
    {
        // Laravel rebinds these closures to its manager: `self` would be the manager.
        Storage::extend('google', fn ($app, array $config): FilesystemAdapter => CloudDrives::google($config));
        Storage::extend('dropbox', fn ($app, array $config): FilesystemAdapter => CloudDrives::dropbox($config));
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public static function google(array $config): FilesystemAdapter
    {
        $adapterClass = 'Masbug\\Flysystem\\GoogleDriveAdapter';

        if (! class_exists($adapterClass) || ! class_exists('Google\\Client') || ! class_exists('Google\\Service\\Drive')) {
            throw new RuntimeException('The "google" disk needs '.self::GOOGLE_PACKAGE.': composer require '.self::GOOGLE_PACKAGE);
        }

        $clientClass = 'Google\\Client';
        $client = new $clientClass;
        $client->setClientId((string) ($config['clientId'] ?? ''));
        $client->setClientSecret((string) ($config['clientSecret'] ?? ''));
        $client->setAccessType('offline');
        $client->refreshToken((string) ($config['refreshToken'] ?? ''));

        $serviceClass = 'Google\\Service\\Drive';
        $adapter = new $adapterClass(new $serviceClass($client), (string) ($config['folder'] ?? '/'), ['useDisplayPaths' => true]);

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public static function dropbox(array $config): FilesystemAdapter
    {
        $adapterClass = 'Spatie\\FlysystemDropbox\\DropboxAdapter';

        if (! class_exists($adapterClass) || ! class_exists('Spatie\\Dropbox\\Client')) {
            throw new RuntimeException('The "dropbox" disk needs '.self::DROPBOX_PACKAGE.': composer require '.self::DROPBOX_PACKAGE);
        }

        $clientClass = 'Spatie\\Dropbox\\Client';
        $adapter = new $adapterClass(new $clientClass(self::dropboxToken($config)), (string) ($config['root'] ?? ''));

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    }

    /**
     * A short-lived access token from the refresh token, or the fixed one.
     *
     * @param  array<string,mixed>  $config
     */
    public static function dropboxToken(array $config): string
    {
        if (empty($config['refresh_token'])) {
            return (string) ($config['token'] ?? '');
        }

        $response = Http::asForm()
            ->withBasicAuth((string) ($config['app_key'] ?? ''), (string) ($config['app_secret'] ?? ''))
            ->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $config['refresh_token'],
            ]);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('Dropbox did not give an access token: '.$response->status().' '.$response->body());
        }

        return (string) $response->json('access_token');
    }
}
