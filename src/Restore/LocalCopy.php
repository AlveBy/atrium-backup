<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Restore;

use League\Flysystem\Local\LocalFilesystemAdapter;
use Spatie\Backup\BackupDestination\Backup;

/**
 * An archive as a file on this server. One on a remote destination is
 * downloaded once into the module's folder and reused by the dry run and
 * the restore that follows it.
 */
final class LocalCopy
{
    public static function of(Backup $backup, string $directory): string
    {
        $disk = $backup->disk();

        if (self::isLocal($disk)) {
            return $disk->path($backup->path());
        }

        $target = $directory.DIRECTORY_SEPARATOR.'downloads'.DIRECTORY_SEPARATOR.sha1($backup->path()).'.zip';

        if (is_file($target) && filesize($target) === (int) $backup->sizeInBytes()) {
            return $target;
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0750, true);
        }

        // Older downloads are not needed any more.
        foreach (glob(dirname($target).DIRECTORY_SEPARATOR.'*.zip') ?: [] as $old) {
            @unlink($old);
        }

        $in = $backup->stream();
        $out = @fopen($target.'.part', 'wb');

        if ($out === false) {
            throw new RestoreFailed(__('atrium-backup::backup.errors.write', ['path' => $target]));
        }

        stream_copy_to_stream($in, $out);
        fclose($out);

        if (is_resource($in)) {
            fclose($in);
        }

        rename($target.'.part', $target);

        return $target;
    }

    private static function isLocal(object $disk): bool
    {
        return method_exists($disk, 'getAdapter') && $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }
}
