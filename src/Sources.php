<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

/**
 * What goes into an archive: database connections, the folders of local
 * disks and extra folders.
 */
final class Sources
{
    /**
     * @param  array<string,mixed>  $config  atrium-backup.sources
     */
    public function __construct(public readonly array $config) {}

    /**
     * @param  array<string,mixed>  $config  atrium-backup
     */
    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['sources'] ?? []));
    }

    public static function current(): self
    {
        return self::fromConfig((array) config('atrium-backup'));
    }

    /**
     * @return array<int,string>
     */
    public function databases(): array
    {
        $databases = array_values(array_filter((array) ($this->config['databases'] ?? [])));

        return $databases === [] ? [(string) config('database.default')] : $databases;
    }

    /**
     * Folders in the archive, by what they are: disk name or "folder".
     *
     * @return array<int,array{name:string,disk:?string,root:string,prefix:string}>
     */
    public function folders(): array
    {
        $folders = [];

        foreach ((array) ($this->config['disks'] ?? []) as $disk) {
            $name = $disk === 'uploads' ? (string) config('atrium.uploads.disk', 'public') : (string) $disk;
            $root = self::diskRoot($name);

            if ($root !== null) {
                $folders[] = ['name' => $name, 'disk' => $name, 'root' => $root, 'prefix' => self::prefix($root)];
            }
        }

        foreach ((array) ($this->config['folders'] ?? []) as $folder) {
            $root = self::absolute((string) $folder);
            $folders[] = ['name' => $root, 'disk' => null, 'root' => $root, 'prefix' => self::prefix($root)];
        }

        // One folder inside another would be packed twice.
        $unique = [];

        foreach ($folders as $folder) {
            $unique[$folder['root']] ??= $folder;
        }

        return array_values($unique);
    }

    /**
     * @return array<int,string>
     */
    public function paths(): array
    {
        return array_values(array_filter(array_column($this->folders(), 'root'), 'is_dir'));
    }

    /**
     * The folder of a disk on this server, or null for a remote one.
     */
    public static function diskRoot(string $disk): ?string
    {
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'local' || ! isset($config['root'])) {
            return null;
        }

        return rtrim((string) $config['root'], DIRECTORY_SEPARATOR);
    }

    public static function absolute(string $path): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $path) ? $path : base_path($path);
    }

    /**
     * Where a folder's files sit in the archive. spatie/laravel-backup keeps
     * paths from the project root, and absolute ones without the first slash
     * for folders outside it.
     */
    public static function prefix(string $root): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($root, $base) ? substr($root, strlen($base)) : ltrim($root, DIRECTORY_SEPARATOR);
    }
}
