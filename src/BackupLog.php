<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup;

use Illuminate\Support\Carbon;

/**
 * What the module did, newest last, and whether something is running now.
 *
 * Kept in files on this server rather than in the database: the log has to
 * survive restoring a database that has no idea of the last month's runs,
 * and the marker has to work when the database is the thing that broke.
 */
final class BackupLog
{
    /** Entries kept; older ones are dropped. */
    private const KEEP = 300;

    /** @var array<string,mixed>|null the run being written */
    private ?array $current = null;

    /** The administrator the next entries are written for. */
    private ?string $by = null;

    public function __construct(private readonly string $directory) {}

    /**
     * Who asked for what is logged next: an administrator's name, or null
     * for the schedule and the command line.
     */
    public function by(?string $name): self
    {
        $this->by = $name;

        return $this;
    }

    /**
     * @param  array<string,mixed>  $details
     */
    public function add(string $action, string $status, array $details = []): void
    {
        $this->write([...$details, ...$this->author(), 'action' => $action, 'status' => $status, 'at' => now()->toIso8601String()]);
    }

    /**
     * @return array<int,array<string,mixed>> newest first
     */
    public function recent(int $limit = 50): array
    {
        $file = $this->path('log.jsonl');

        if (! is_file($file)) {
            return [];
        }

        $lines = array_slice((array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
        $entries = array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines), 'is_array'));

        return array_reverse($entries);
    }

    /**
     * Mark a run as started. False when another one is still going.
     *
     * @param  array<string,mixed>  $details
     */
    public function start(string $action, array $details = []): bool
    {
        $running = $this->running();

        if ($running !== null) {
            return false;
        }

        $this->current = [...$details, ...$this->author(), 'action' => $action, 'started_at' => now()->toIso8601String(), 'disks' => [], 'failed' => []];
        $this->ensureDirectory();

        // x: fails if someone created the marker in between.
        $handle = @fopen($this->path('running.json'), 'x');

        if ($handle === false) {
            $this->current = null;

            return false;
        }

        fwrite($handle, (string) json_encode($this->current));
        fclose($handle);

        return true;
    }

    /**
     * Mark the started run as finished and write it to the log.
     *
     * @param  array<string,mixed>  $details
     */
    public function finish(string $status, array $details = []): void
    {
        $run = $this->current ?? (array) $this->running();
        $started = isset($run['started_at']) ? Carbon::parse((string) $run['started_at']) : now();

        $this->write([
            ...$run,
            ...$details,
            'status' => $status,
            'at' => now()->toIso8601String(),
            'seconds' => (int) round(abs(now()->diffInSeconds($started))),
        ]);

        $this->current = null;
        @unlink($this->path('running.json'));
    }

    /**
     * The run going on now, or null. A run older than the timeout is taken
     * for a crashed one and no longer blocks.
     *
     * @return array<string,mixed>|null
     */
    public function running(): ?array
    {
        $file = $this->path('running.json');

        if (! is_file($file)) {
            return null;
        }

        $run = json_decode((string) file_get_contents($file), true);

        if (! is_array($run) || now()->diffInSeconds(Carbon::parse((string) ($run['started_at'] ?? 'now')), true) > (int) config('atrium-backup.timeout', 21600)) {
            @unlink($file);

            if (is_array($run)) {
                $this->write([...$run, 'status' => 'failed', 'message' => __('atrium-backup::backup.errors.timeout'), 'at' => now()->toIso8601String()]);
            }

            return null;
        }

        return $run;
    }

    /**
     * The run this process is writing, with what happened so far.
     *
     * @return array<string,mixed>
     */
    public function current(): array
    {
        return $this->current ?? [];
    }

    public function zipCreated(string $path): void
    {
        if (($this->current['action'] ?? null) === 'backup') {
            $this->current['size'] = is_file($path) ? (int) filesize($path) : null;
            $this->current['file'] = basename($path);
        }
    }

    public function copied(string $disk): void
    {
        if (($this->current['action'] ?? null) === 'backup') {
            $this->current['disks'][] = $disk;
        }
    }

    public function copyFailed(?string $disk, string $message): void
    {
        if (($this->current['action'] ?? null) === 'backup') {
            $this->current['failed'][] = ['disk' => $disk, 'message' => $message];
        }
    }

    /**
     * @return array{by?:string}
     */
    private function author(): array
    {
        return $this->by === null ? [] : ['by' => $this->by];
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function write(array $entry): void
    {
        $this->ensureDirectory();
        $file = $this->path('log.jsonl');

        file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);

        $lines = (array) file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (count($lines) > self::KEEP * 2) {
            file_put_contents($file, implode(PHP_EOL, array_slice($lines, -self::KEEP)).PHP_EOL, LOCK_EX);
        }
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    private function path(string $file): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$file;
    }
}
