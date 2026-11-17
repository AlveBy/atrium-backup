<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Backups;
use Atrium\Backup\Notifications\Notifiable;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;

final class HealthTest extends TestCase
{
    public function test_retention_keeps_daily_weekly_and_monthly_archives(): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        config(['atrium-backup.retention' => ['keep_all_days' => 1, 'daily' => 3, 'weekly' => 2, 'monthly' => 1, 'yearly' => 0, 'max_megabytes' => null]]);
        $this->travelTo('2026-11-17 12:00:00');

        // One archive a day for 60 days.
        for ($day = 0; $day < 60; $day++) {
            $this->archive(now()->subDays($day)->setTime(2, 0));
        }

        app(Backups::class)->config();
        $this->artisan('backup:clean')->assertSuccessful();

        $left = collect(app(Backups::class)->all())->pluck('file')->all();
        $this->assertContains('2026-11-17-02-00-00.zip', $left);
        $this->assertContains('2026-11-14-02-00-00.zip', $left);
        // 11-13 is the newest of its week in the weekly period; 11-12 is not.
        $this->assertNotContains('2026-11-12-02-00-00.zip', $left);
        $this->assertLessThan(12, count($left));
        $this->assertGreaterThan(5, count($left));
        $this->assertSame('cleanup', app(Backups::class)->log()->recent(1)[0]['action']);
    }

    public function test_an_old_newest_backup_is_unhealthy_and_mailed(): void
    {
        Notification::fake();
        config(['atrium-backup.destinations' => ['backups']]);
        $this->archive(now()->subDays(3));

        app(Backups::class)->config();
        $this->artisan('backup:monitor')->assertFailed();

        $health = app(Backups::class)->health()[0];
        $this->assertFalse($health['healthy']);
        $this->assertNotEmpty($health['problems']);
        Notification::assertSentTo(new Notifiable, UnhealthyBackupWasFoundNotification::class);
        $this->assertSame(['monitor', 'failed'], [app(Backups::class)->log()->recent(1)[0]['action'], app(Backups::class)->log()->recent(1)[0]['status']]);
    }

    public function test_a_jump_in_size_is_unhealthy(): void
    {
        config(['atrium-backup.destinations' => ['backups'], 'atrium-backup.health.size_jump_percent' => 50]);
        $this->archive(now()->subHours(5), 400_000);
        $this->archive(now()->subHours(1), 100_000);

        $health = app(Backups::class)->health()[0];

        $this->assertFalse($health['healthy']);
        $this->assertSame([__('atrium-backup::backup.health.size_jump', ['percent' => 75, 'before' => '0.4', 'after' => '0.1'])], $health['problems']);
    }

    public function test_a_small_change_in_size_is_fine(): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        $this->archive(now()->subHours(5), 400_000);
        $this->archive(now()->subHours(1), 410_000);

        $this->assertTrue(app(Backups::class)->health()[0]['healthy']);
    }

    public function test_small_archives_may_change_size_as_they_like(): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        // Under 64 KB each: five times bigger is still nothing to worry about.
        $this->archive(now()->subHours(5), 10_000);
        $this->archive(now()->subHours(1), 50_000);

        $this->assertTrue(app(Backups::class)->health()[0]['healthy']);
    }

    public function test_a_small_archive_after_a_big_one_is_a_jump(): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        $this->archive(now()->subHours(5), 400_000);
        $this->archive(now()->subHours(1), 10_000);

        $this->assertFalse(app(Backups::class)->health()[0]['healthy']);
    }

    /**
     * @return array<string,array{0:int,1:int}>
     */
    public static function jumpsAtTheSmallSizeLimit(): array
    {
        return [
            'from exactly 64 KB to nothing' => [65_536, 1_000],
            'from nothing to exactly 64 KB' => [1_000, 65_536],
        ];
    }

    #[DataProvider('jumpsAtTheSmallSizeLimit')]
    public function test_archives_of_64_kb_are_compared(int $before, int $after): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        $this->archive(now()->subHours(5), $before);
        $this->archive(now()->subHours(1), $after);

        $this->assertFalse(app(Backups::class)->health()[0]['healthy']);
    }

    public function test_an_archive_after_an_empty_one_is_a_full_jump(): void
    {
        config(['atrium-backup.destinations' => ['backups']]);
        $this->archive(now()->subHours(5), 0);
        $this->archive(now()->subHours(1), 100_000);

        $this->assertSame([__('atrium-backup::backup.health.size_jump', ['percent' => 100, 'before' => '0.0', 'after' => '0.1'])], app(Backups::class)->health()[0]['problems']);
    }

    public function test_a_change_of_exactly_the_limit_is_fine(): void
    {
        config(['atrium-backup.destinations' => ['backups'], 'atrium-backup.health.size_jump_percent' => 50]);
        $this->archive(now()->subHours(5), 131_072);
        $this->archive(now()->subHours(1), 196_608);

        $this->assertTrue(app(Backups::class)->health()[0]['healthy']);
    }

    /**
     * @return array<string,array{0:int,1:int,2:string}>
     */
    public static function roundedChanges(): array
    {
        return [
            '62.4% is 62' => [1_624_000, 62, '1.5'],
            '62.6% is 63' => [1_626_000, 63, '1.6'],
        ];
    }

    #[DataProvider('roundedChanges')]
    public function test_the_change_is_told_rounded_to_a_whole_percent(int $after, int $percent, string $megabytes): void
    {
        config(['atrium-backup.destinations' => ['backups'], 'atrium-backup.health.size_jump_percent' => 50]);
        $this->archive(now()->subHours(5), 1_000_000);
        $this->archive(now()->subHours(1), $after);

        $this->assertSame([__('atrium-backup::backup.health.size_jump', ['percent' => $percent, 'before' => '1.0', 'after' => $megabytes])], app(Backups::class)->health()[0]['problems']);
    }

    public function test_sizes_are_told_in_mebibytes(): void
    {
        config(['atrium-backup.destinations' => ['backups'], 'atrium-backup.health.size_jump_percent' => 50]);
        // Each a hair from the next tenth: 1000.0499 and 2000.0501 MiB.
        $before = 1_048_628_328;
        $after = 2_097_204_528;
        $this->archive(now()->subHours(5), $before);
        $this->archive(now()->subHours(1), $after);

        $this->assertSame(
            [__('atrium-backup::backup.health.size_jump', ['percent' => round(($after - $before) / $before * 100), 'before' => '1,000.0', 'after' => '2,000.1'])],
            app(Backups::class)->health()[0]['problems'],
        );
    }

    public function test_an_unreachable_destination_is_reported_not_thrown(): void
    {
        config(['filesystems.disks.backups-copy' => ['driver' => 'ftp', 'host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y', 'timeout' => 1]]);

        $health = collect(app(Backups::class)->health())->keyBy('disk');

        $this->assertFalse($health['backups-copy']['reachable']);
        $this->assertSame([], app(Backups::class)->all());
    }

    public function test_the_schedule_runs_backup_clean_up_and_check(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [trim(str_replace(["'", '"'], '', (string) preg_replace('#^.*artisan\S*\s#', '', (string) $event->command))) => $event->expression]);

        $this->assertSame('0 2 * * *', $commands['atrium:backup:run']);
        $this->assertSame('0 3 * * *', $commands['backup:clean']);
        $this->assertSame('0 4 * * *', $commands['backup:monitor']);

        // A backup still running after twelve hours no longer blocks the next one.
        $overlaps = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event): array => [$event->expression => [$event->withoutOverlapping, $event->expiresAt]]);
        $this->assertSame([true, 720], $overlaps['0 2 * * *']);
        $this->assertSame([false, 1440], $overlaps['0 3 * * *']);
        $this->assertSame([false, 1440], $overlaps['0 4 * * *']);
    }

    private function archive(\DateTimeInterface $date, int $bytes = 1000): void
    {
        $path = $this->dir.'/dest-1/test/'.$date->format('Y-m-d-H-i-s').'.zip';
        @mkdir(dirname($path), 0777, true);
        // Sparse: gigabytes take no room.
        $handle = fopen($path, 'w');
        ftruncate($handle, $bytes);
        fclose($handle);
        touch($path, $date->getTimestamp());
    }
}
