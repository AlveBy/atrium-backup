<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase
{
    /** The admin's languages: every module speaks all of them. */
    public const LANGUAGES = ['be', 'de', 'en', 'es', 'fr', 'it', 'ja', 'kk', 'pl', 'pt_BR', 'ru', 'tr', 'uk', 'uz', 'zh_CN'];

    public function test_it_has_exactly_the_admin_languages(): void
    {
        $this->assertSame(self::LANGUAGES, array_map(static fn (string $file): string => basename(dirname($file)), glob(__DIR__.'/../../lang/*/backup.php') ?: []));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function languages(): iterable
    {
        foreach (glob(__DIR__.'/../../lang/*/backup.php') ?: [] as $file) {
            yield basename(dirname($file)) => [$file];
        }
    }

    #[DataProvider('languages')]
    public function test_every_language_has_the_english_keys_and_placeholders(string $file): void
    {
        $english = self::flatten(require __DIR__.'/../../lang/en/backup.php');
        $lines = self::flatten(require $file);

        $this->assertSame(array_keys($english), array_keys($lines));
        $this->assertNotContains('', $lines);

        foreach ($english as $key => $line) {
            preg_match_all('/:[a-z_]+/', $line, $expected);
            preg_match_all('/:[a-z_]+/', $lines[$key], $actual);
            $this->assertSame($expected[0], $actual[0], $key);
        }
    }

    /**
     * @param  array<string,mixed>  $lines
     * @return array<string,string>
     */
    private static function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $prefix.$key.'.')];
            } else {
                $flat[$prefix.$key] = (string) $value;
            }
        }

        return $flat;
    }
}
