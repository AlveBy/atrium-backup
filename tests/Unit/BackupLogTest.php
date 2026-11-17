<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use Atrium\Backup\BackupLog;
use Atrium\Backup\Tests\TestCase;

final class BackupLogTest extends TestCase
{
    public function test_an_empty_log_has_no_entries_and_nothing_running(): void
    {
        $log = new BackupLog($this->dir.'/log');

        $this->assertSame([], $log->recent());
        $this->assertNull($log->running());
    }

    public function test_a_run_that_cannot_leave_its_marker_does_not_start(): void
    {
        // A folder where the marker goes: nothing is running, and the marker cannot be created.
        mkdir($this->dir.'/log/running.json', 0755, true);
        $log = new BackupLog($this->dir.'/log');

        $this->assertFalse($log->start('backup'));
        $this->assertSame([], $log->current());
    }

    public function test_a_run_older_than_the_timeout_is_logged_as_failed_and_no_longer_blocks(): void
    {
        config(['atrium-backup.timeout' => 60]);
        $log = new BackupLog($this->dir.'/log');
        $log->by('Ada')->start('backup');
        $this->travel(61)->seconds();

        $this->assertNull($log->running());
        $this->assertFileDoesNotExist($this->dir.'/log/running.json');
        $entry = $log->recent(1)[0];
        $this->assertSame(
            ['backup', 'failed', 'Ada', __('atrium-backup::backup.errors.timeout'), now()->toIso8601String()],
            [$entry['action'], $entry['status'], $entry['by'], $entry['message'], $entry['at']],
        );
        $this->assertTrue((new BackupLog($this->dir.'/log'))->start('restore'));
    }

    public function test_a_run_within_the_timeout_still_blocks(): void
    {
        config(['atrium-backup.timeout' => 60]);
        $log = new BackupLog($this->dir.'/log');
        $log->start('backup');
        // The marker keeps whole seconds, the clock does not: one second short.
        $this->travel(59)->seconds();

        $this->assertSame('backup', $log->running()['action'] ?? null);
        $this->assertFalse((new BackupLog($this->dir.'/log'))->start('restore'));
        $this->assertSame([], $log->recent());
    }

    public function test_an_unreadable_marker_is_removed_without_an_entry(): void
    {
        mkdir($this->dir.'/log');
        file_put_contents($this->dir.'/log/running.json', 'not json');
        $log = new BackupLog($this->dir.'/log');

        $this->assertNull($log->running());
        $this->assertFileDoesNotExist($this->dir.'/log/running.json');
        $this->assertSame([], $log->recent());
    }

    public function test_entries_name_the_administrator_only_when_there_is_one(): void
    {
        $log = new BackupLog($this->dir.'/log');
        $log->add('download', 'ok');
        $log->by('Ada')->add('delete', 'ok');

        [$delete, $download] = $log->recent();
        $this->assertSame('Ada', $delete['by']);
        $this->assertArrayNotHasKey('by', $download);
    }

    public function test_the_log_keeps_the_last_three_hundred_entries(): void
    {
        $log = new BackupLog($this->dir.'/log');

        // Trimmed once it doubles, so it is not rewritten on every entry.
        for ($i = 1; $i <= 600; $i++) {
            $log->add('backup', 'ok', ['n' => $i]);
        }

        $this->assertCount(600, file($this->dir.'/log/log.jsonl'));

        $log->add('backup', 'ok', ['n' => 601]);

        $this->assertCount(300, file($this->dir.'/log/log.jsonl'));
        $this->assertSame(601, $log->recent(1)[0]['n']);
        $this->assertSame(302, $log->recent(300)[299]['n']);

        // The trimmed file still ends a line: the next entry is one of its own.
        $log->add('backup', 'ok', ['n' => 602]);
        $this->assertCount(301, file($this->dir.'/log/log.jsonl'));
        $this->assertSame([602, 601], array_column($log->recent(2), 'n'));
    }
}
