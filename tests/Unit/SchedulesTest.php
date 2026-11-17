<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use Atrium\Backup\Schedules;
use Atrium\Backup\SpatieConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulesTest extends TestCase
{
    public function test_daily_weekly_and_off(): void
    {
        $this->assertSame(
            ['atrium:backup:run' => '30 1 * * *', 'backup:clean' => '30 2 * * *', 'backup:monitor' => '30 3 * * *'],
            Schedules::entries(['frequency' => 'daily', 'time' => '01:30']),
        );
        $this->assertSame('0 23 * * 5', Schedules::entries(['frequency' => 'weekly', 'time' => '23:00', 'day' => 5])['atrium:backup:run']);
        $this->assertSame([], Schedules::entries(['frequency' => 'off']));
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:array<int,string>}>
     */
    public static function times(): array
    {
        return [
            'nothing set: daily at two' => [[], ['0 2 * * *', '0 3 * * *', '0 4 * * *']],
            'midnight' => [['time' => '00:00'], ['0 0 * * *', '0 1 * * *', '0 2 * * *']],
            'the last minute of the day: the rest wraps to the next' => [['time' => '23:59'], ['59 23 * * *', '59 0 * * *', '59 1 * * *']],
            'past the end of the day: the last minute' => [['time' => '24:60'], ['59 23 * * *', '59 0 * * *', '59 1 * * *']],
            'before the start: midnight' => [['time' => '-1:-1'], ['0 0 * * *', '0 1 * * *', '0 2 * * *']],
            'an hour alone' => [['time' => '7'], ['0 7 * * *', '0 8 * * *', '0 9 * * *']],
            'an hour as a number' => [['time' => 22], ['0 22 * * *', '0 23 * * *', '0 0 * * *']],
            'weekly without a day: Sunday' => [['frequency' => 'weekly', 'time' => '05:15'], ['15 5 * * 0', '15 6 * * *', '15 7 * * *']],
            'weekly on Saturday' => [['frequency' => 'weekly', 'day' => 6], ['0 2 * * 6', '0 3 * * *', '0 4 * * *']],
            'a day after Saturday: Saturday' => [['frequency' => 'weekly', 'day' => 7], ['0 2 * * 6', '0 3 * * *', '0 4 * * *']],
            'a day before Sunday: Sunday' => [['frequency' => 'weekly', 'day' => -1], ['0 2 * * 0', '0 3 * * *', '0 4 * * *']],
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<int,string>  $expected  backup, clean-up, health check
     */
    #[DataProvider('times')]
    public function test_times_are_kept_within_the_day_and_the_week(array $config, array $expected): void
    {
        $this->assertSame(array_combine(['atrium:backup:run', 'backup:clean', 'backup:monitor'], $expected), Schedules::entries($config));
    }

    public function test_the_newest_backup_may_be_as_old_as_the_schedule_allows(): void
    {
        $this->assertSame(1, SpatieConfig::maxAgeDays(['schedule' => ['frequency' => 'daily']]));
        $this->assertSame(8, SpatieConfig::maxAgeDays(['schedule' => ['frequency' => 'weekly']]));
        $this->assertSame(3, SpatieConfig::maxAgeDays(['schedule' => ['frequency' => 'weekly'], 'health' => ['max_age_days' => 3]]));
    }
}
