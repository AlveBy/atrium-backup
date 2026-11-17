<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Restore;

use Atrium\Backup\Sources;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What a restore would do, worked out without changing anything: the dry
 * run shown before the confirmation.
 */
final class Plan
{
    /**
     * @return array{created_at:?string,app:?string,encrypted:bool,databases:array<int,array<string,mixed>>,folders:array<int,array<string,mixed>>,warnings:array<int,string>}
     */
    public static function make(Archive $archive): array
    {
        $manifest = $archive->manifest();
        $warnings = [];
        $databases = [];
        $folders = [];

        foreach ((array) ($manifest['databases'] ?? []) as $database) {
            $connection = (string) ($database['connection'] ?? '');
            $current = config("database.connections.{$connection}");
            $size = $archive->size((string) ($database['file'] ?? ''));
            $currentDriver = is_array($current) ? (string) ($current['driver'] ?? '') : null;

            $databases[] = [
                'connection' => $connection,
                'driver' => (string) ($database['driver'] ?? ''),
                'file' => (string) ($database['file'] ?? ''),
                'size' => $size,
                'target' => is_array($current) ? (string) ($current['database'] ?? '') : null,
                'restorable' => $size !== null && $currentDriver === ($database['driver'] ?? null),
            ];

            if ($size === null) {
                $warnings[] = __('atrium-backup::backup.plan.dump_missing', ['connection' => $connection]);
            } elseif ($currentDriver === null) {
                $warnings[] = __('atrium-backup::backup.plan.no_connection', ['connection' => $connection]);
            } elseif ($currentDriver !== ($database['driver'] ?? null)) {
                $warnings[] = __('atrium-backup::backup.plan.other_driver', ['connection' => $connection, 'from' => $database['driver'] ?? '?', 'to' => $currentDriver]);
            }
        }

        $entries = iterator_to_array($archive->entries(), false);

        foreach ((array) ($manifest['folders'] ?? []) as $folder) {
            $prefix = (string) ($folder['prefix'] ?? '');
            $root = self::target($folder);
            $inArchive = [];
            $counts = ['files' => 0, 'bytes' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'extra' => 0];

            foreach ($entries as $entry) {
                if ($prefix === '' || ! str_starts_with($entry['name'], $prefix)) {
                    continue;
                }

                $relative = substr($entry['name'], strlen($prefix));
                $inArchive[$relative] = true;
                $counts['files']++;
                $counts['bytes'] += $entry['size'];
                $counts[self::compare($root.DIRECTORY_SEPARATOR.$relative, $entry)]++;
            }

            foreach (self::currentFiles($root) as $relative) {
                if (! isset($inArchive[$relative])) {
                    $counts['extra']++;
                }
            }

            $folders[] = [...$counts, 'name' => (string) ($folder['name'] ?? $prefix), 'disk' => $folder['disk'] ?? null, 'prefix' => $prefix, 'target' => $root];
        }

        if ($databases === [] && $folders === []) {
            $warnings[] = __('atrium-backup::backup.plan.empty');
        }

        return [
            'created_at' => isset($manifest['created_at']) ? (string) $manifest['created_at'] : null,
            'app' => isset($manifest['app']) ? (string) $manifest['app'] : null,
            'encrypted' => $archive->encrypted,
            'databases' => $databases,
            'folders' => $folders,
            'warnings' => $warnings,
        ];
    }

    /**
     * Where a folder of the archive goes on this server: the same disk's
     * folder when it still exists, else the same place under the project.
     *
     * @param  array<string,mixed>  $folder
     */
    public static function target(array $folder): string
    {
        if (is_string($folder['disk'] ?? null) && ($root = Sources::diskRoot($folder['disk'])) !== null) {
            return $root;
        }

        $prefix = rtrim((string) ($folder['prefix'] ?? ''), '/');

        return str_starts_with((string) ($folder['name'] ?? ''), '/') && ! str_starts_with((string) $folder['name'], base_path())
            ? '/'.$prefix
            : base_path($prefix);
    }

    /**
     * @return iterable<int,string> paths relative to the root
     */
    public static function currentFiles(string $root): iterable
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }

    /**
     * @param  array{size:int,crc:int}  $entry
     * @return 'new'|'changed'|'unchanged'
     */
    private static function compare(string $path, array $entry): string
    {
        if (! is_file($path)) {
            return 'new';
        }

        if (filesize($path) !== $entry['size']) {
            return 'changed';
        }

        return hexdec((string) hash_file('crc32b', $path)) === $entry['crc'] ? 'unchanged' : 'changed';
    }
}
