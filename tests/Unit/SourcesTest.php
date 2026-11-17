<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use Atrium\Backup\Sources;
use Atrium\Backup\Tests\TestCase;

final class SourcesTest extends TestCase
{
    public function test_disks_are_backed_up_by_their_folder_and_uploads_means_the_uploads_disk(): void
    {
        config([
            'atrium.uploads.disk' => 'media',
            'filesystems.disks.media' => ['driver' => 'local', 'root' => $this->dir.'/media/'],
            'filesystems.disks.docs' => ['driver' => 'local', 'root' => $this->dir.'/docs'],
        ]);

        $folders = (new Sources(['disks' => ['uploads', 'docs']]))->folders();

        $this->assertSame(['media', 'docs'], array_column($folders, 'name'));
        $this->assertSame(['media', 'docs'], array_column($folders, 'disk'));
        $this->assertSame([$this->dir.'/media', $this->dir.'/docs'], array_column($folders, 'root'));
    }

    public function test_remote_disks_disks_without_a_folder_and_unknown_disks_are_left_out(): void
    {
        config([
            'filesystems.disks.cloud' => ['driver' => 's3', 'bucket' => 'media', 'root' => 'site'],
            'filesystems.disks.rootless' => ['driver' => 'local'],
        ]);

        $this->assertSame([], (new Sources(['disks' => ['cloud', 'rootless', 'nowhere']]))->folders());
        $this->assertNull(Sources::diskRoot('cloud'));
        $this->assertNull(Sources::diskRoot('rootless'));
        $this->assertNull(Sources::diskRoot('nowhere'));
    }

    public function test_disk_names_are_read_as_text(): void
    {
        mkdir($this->dir.'/five');
        // A disk named by a number, and no uploads disk at all.
        config(['filesystems.disks.5' => ['driver' => 'local', 'root' => $this->dir.'/five'], 'atrium.uploads.disk' => null]);

        $this->assertSame(['5'], array_column((new Sources(['disks' => [5, 'uploads']]))->folders(), 'name'));
    }

    public function test_a_disk_and_a_folder_with_the_same_root_are_packed_once(): void
    {
        config(['filesystems.disks.docs' => ['driver' => 'local', 'root' => $this->dir.'/uploads']]);

        $folders = (new Sources(['disks' => ['docs'], 'folders' => [$this->dir.'/uploads/']]))->folders();

        $this->assertSame([['name' => 'docs', 'disk' => 'docs', 'root' => $this->dir.'/uploads']], array_map(
            static fn (array $folder): array => array_intersect_key($folder, array_flip(['name', 'disk', 'root'])),
            $folders,
        ));
    }
}
