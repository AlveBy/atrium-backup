<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Feature;

use Atrium\Backup\Tests\TestCase;
use Atrium\Backup\Tests\Unit\TranslationsTest;
use Spatie\Backup\BackupServiceProvider;

/**
 * The e-mails come from spatie's texts. They exist in every admin language,
 * the ones spatie lacks from this package, without falling back to English.
 */
final class NotificationTranslationsTest extends TestCase
{
    public function test_every_admin_language_has_every_e_mail_line(): void
    {
        $english = require dirname((string) (new \ReflectionClass(BackupServiceProvider::class))->getFileName(), 2).'/resources/lang/en/notifications.php';

        foreach (TranslationsTest::LANGUAGES as $language) {
            foreach ($english as $key => $line) {
                $translated = trans()->get("backup::notifications.{$key}", [], $language, false);

                $this->assertIsString($translated, "{$language}: {$key}");
                $this->assertNotSame("backup::notifications.{$key}", $translated, "{$language}: {$key}");
                $this->assertNotSame('', $translated, "{$language}: {$key}");

                preg_match_all('/:[a-z_]+/', $line, $expected);
                preg_match_all('/:[a-z_]+/', $translated, $actual);
                $this->assertEqualsCanonicalizing($expected[0], $actual[0], "{$language}: {$key}");
            }
        }

        $this->assertSame('Не ўдалося зрабіць рэзервовую копію Atrium', __('backup::notifications.backup_failed_subject', ['application_name' => 'Atrium'], 'be'));
    }
}
