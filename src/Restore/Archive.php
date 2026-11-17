<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Restore;

use Atrium\Backup\Manifest;
use ZipArchive;

/**
 * An archive opened for reading: its manifest, its entries, and the files
 * taken out of it.
 */
final class Archive
{
    /** @var array<string,mixed> */
    private array $manifest;

    private function __construct(private readonly ZipArchive $zip, public readonly string $path, public readonly bool $encrypted)
    {
        $this->manifest = $this->readManifest();
    }

    public static function open(string $path, ?string $password): self
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RestoreFailed(__('atrium-backup::backup.errors.unreadable'));
        }

        $encrypted = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if (is_array($stat) && $stat['encryption_method'] !== 0) {
                $encrypted = true;
                break;
            }
        }

        if ($encrypted) {
            if ($password === null || $password === '') {
                throw new RestoreFailed(__('atrium-backup::backup.errors.password_missing'));
            }

            $zip->setPassword($password);

            // A wrong password shows when the first file is read.
            $first = $zip->getNameIndex(0);

            if ($first !== false && ! str_ends_with($first, '/') && $zip->getFromName($first, 1) === false) {
                throw new RestoreFailed(__('atrium-backup::backup.errors.password_wrong'));
            }
        }

        return new self($zip, $path, $encrypted);
    }

    /**
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /**
     * Files in the archive (no folders).
     *
     * @return iterable<int,array{name:string,size:int,crc:int,mtime:int}>
     */
    public function entries(): iterable
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);

            if (is_array($stat) && ! str_ends_with((string) $stat['name'], '/')) {
                yield ['name' => (string) $stat['name'], 'size' => (int) $stat['size'], 'crc' => (int) $stat['crc'], 'mtime' => (int) $stat['mtime']];
            }
        }
    }

    public function size(string $name): ?int
    {
        $stat = $this->zip->statName($name);

        return is_array($stat) ? (int) $stat['size'] : null;
    }

    /**
     * Copy one entry to a file.
     */
    public function extract(string $name, string $target): void
    {
        $stream = $this->zip->getStream($name);

        if ($stream === false) {
            throw new RestoreFailed(__('atrium-backup::backup.errors.entry', ['name' => $name]));
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RestoreFailed(__('atrium-backup::backup.errors.write', ['path' => $directory]));
        }

        // Next to the target, then renamed: a file is never half written.
        $temporary = $target.'.restoring';
        $out = @fopen($temporary, 'wb');

        if ($out === false) {
            fclose($stream);

            throw new RestoreFailed(__('atrium-backup::backup.errors.write', ['path' => $target]));
        }

        // A damaged entry stops short, or fails with a warning: ours says which file.
        $copied = @stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        if ($copied === false || $copied !== $this->size($name)) {
            @unlink($temporary);

            throw new RestoreFailed(__('atrium-backup::backup.errors.entry', ['name' => $name]));
        }

        rename($temporary, $target);
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * The archive's manifest; for one made without this module, what can be
     * told from the names.
     *
     * @return array<string,mixed>
     */
    private function readManifest(): array
    {
        $json = $this->zip->getFromName(Manifest::FILE);
        $manifest = is_string($json) ? json_decode($json, true) : null;

        if (is_array($manifest)) {
            return $manifest;
        }

        $databases = [];

        foreach ($this->entries() as $entry) {
            if (preg_match('#^db-dumps/(mysql|mariadb|postgresql|sqlite)-(.+)\.sql$#', $entry['name'], $match)) {
                $databases[] = [
                    'connection' => $match[2] === 'database' ? (string) config('database.default') : preg_replace('/-database$/', '', $match[2]),
                    'driver' => ['postgresql' => 'pgsql'][$match[1]] ?? $match[1],
                    'file' => $entry['name'],
                ];
            }
        }

        return ['format' => 0, 'app' => null, 'created_at' => null, 'databases' => $databases, 'folders' => []];
    }
}
