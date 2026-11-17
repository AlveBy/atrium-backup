<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

use Spatie\Backup\Tasks\Backup\Manifest as SpatieManifest;

/**
 * `atrium-backup.json` at the top of every archive: which dump belongs to
 * which connection and where each folder came from. A restore reads it, so
 * an archive made on one server can be put back on another with a
 * different project path.
 */
final class Manifest
{
    public const FILE = 'atrium-backup.json';

    public const FORMAT = 1;

    /** Dump file name by the dumper class, as spatie/laravel-backup names it. */
    private const DUMP_TYPES = ['mysql' => 'mysql', 'mariadb' => 'mariadb', 'pgsql' => 'postgresql', 'sqlite' => 'sqlite'];

    public static function addTo(SpatieManifest $manifest): void
    {
        $directory = dirname($manifest->path());
        $hasDumps = false;
        $hasFolders = false;

        foreach ($manifest->files() as $file) {
            $hasDumps = $hasDumps || str_starts_with((string) $file, $directory.DIRECTORY_SEPARATOR.'db-dumps'.DIRECTORY_SEPARATOR);
            $hasFolders = $hasFolders || ! str_starts_with((string) $file, $directory.DIRECTORY_SEPARATOR);
        }

        $sources = Sources::current();
        $path = $directory.DIRECTORY_SEPARATOR.self::FILE;

        file_put_contents($path, (string) json_encode(self::describe($sources, $hasDumps, $hasFolders), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $manifest->addFiles([$path]);
    }

    /**
     * @return array<string,mixed>
     */
    public static function describe(Sources $sources, bool $databases = true, bool $folders = true): array
    {
        return [
            'format' => self::FORMAT,
            'app' => (string) config('app.name'),
            'created_at' => now()->toIso8601String(),
            'databases' => $databases ? array_map(static function (string $connection): array {
                $driver = (string) config("database.connections.{$connection}.driver");

                return [
                    'connection' => $connection,
                    'driver' => $driver,
                    'file' => 'db-dumps/'.(self::DUMP_TYPES[$driver] ?? $driver).'-'.$connection.'.sql',
                ];
            }, $sources->databases()) : [],
            'folders' => $folders ? array_map(static fn (array $folder): array => [
                'name' => $folder['name'],
                'disk' => $folder['disk'],
                'prefix' => $folder['prefix'],
            ], array_values(array_filter($sources->folders(), static fn (array $folder): bool => is_dir($folder['root'])))) : [],
        ];
    }
}
