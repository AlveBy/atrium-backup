<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Http\Controllers;

use Atrium\Backup\Backups;
use Atrium\Backup\Jobs\RunBackup;
use Atrium\Backup\Jobs\RunRestore;
use Atrium\Backup\Restore\RestoreFailed;
use Atrium\Backup\Restore\Restorer;
use Atrium\Backup\Sources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "Backups" screen: the archives on every destination, "back up now",
 * download, restore with a dry run first, and the log. Superadmins only.
 *
 * A backup or restore can take longer than a request may: it runs after the
 * response is sent, or on the queue when ATRIUM_BACKUP_QUEUE names one, and
 * the page polls the status.
 */
final class BackupController extends Controller
{
    public function __construct(
        private readonly Backups $backups,
        private readonly Restorer $restorer,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Backups/Index', [
            ...$this->state(),
            'health' => $this->backups->health(),
            'settings' => $this->settings(),
            'labels' => ['menu' => __('atrium-backup::backup.menu'), ...(array) trans('atrium-backup::backup.ui')],
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function store(Request $request): RedirectResponse
    {
        $only = $request->validate(['only' => ['nullable', 'in:database,files']])['only'] ?? null;

        if ($this->backups->log()->running() !== null) {
            return back()->with('error', __('atrium-backup::backup.ui.busy'));
        }

        $queue = config('atrium-backup.queue');
        $by = $this->admin($request);
        $this->backups->log()->by($by);

        if (is_string($queue) && $queue !== '') {
            RunBackup::dispatch($only, $by)->onQueue($queue);
        } else {
            $backups = $this->backups;
            defer(static fn () => $backups->run($only === 'database', $only === 'files', 'admin'));
        }

        return back()->with('success', __('atrium-backup::backup.ui.started'));
    }

    public function download(Request $request, string $backup): StreamedResponse
    {
        $found = $this->backups->find($backup) ?? abort(404);
        $this->backups->log()->by($this->admin($request))->add('download', 'ok', ['file' => basename($found->path()), 'disk' => $this->backups->diskOf($backup)]);

        return response()->streamDownload(static function () use ($found): void {
            $stream = $found->stream();
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($found->path()), ['Content-Type' => 'application/zip', 'Content-Length' => (string) $found->sizeInBytes()]);
    }

    /**
     * The dry run: what a restore would change. Nothing is touched.
     */
    public function plan(Request $request, string $backup): JsonResponse
    {
        $password = $request->validate(['password' => ['nullable', 'string', 'max:255']])['password'] ?? null;

        if ($this->backups->find($backup) === null) {
            abort(404);
        }

        try {
            return response()->json(['plan' => $this->restorer->plan($backup, $password)]);
        } catch (RestoreFailed $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function restore(Request $request, string $backup): RedirectResponse
    {
        $found = $this->backups->find($backup) ?? abort(404);
        $validated = $request->validate([
            // Typing the archive's name is the confirmation.
            'confirm' => ['required', 'string', 'in:'.basename($found->path())],
            'database' => ['boolean'],
            'files' => ['boolean'],
            'delete_extra' => ['boolean'],
            'safety_backup' => ['boolean'],
            'password' => ['nullable', 'string', 'max:255'],
        ], ['confirm.in' => __('atrium-backup::backup.ui.confirm_mismatch')]);

        $options = [
            'database' => $request->boolean('database', true),
            'files' => $request->boolean('files', true),
            'delete_extra' => $request->boolean('delete_extra'),
            'safety_backup' => $request->boolean('safety_backup', true),
        ];

        if (! $options['database'] && ! $options['files']) {
            return back()->with('error', __('atrium-backup::backup.ui.nothing_chosen'));
        }

        if ($this->backups->log()->running() !== null) {
            return back()->with('error', __('atrium-backup::backup.ui.busy'));
        }

        $password = $validated['password'] ?? null;
        $queue = config('atrium-backup.queue');
        $this->backups->log()->by($this->admin($request));

        if (is_string($queue) && $queue !== '') {
            RunRestore::dispatch($backup, $options, $password, $this->admin($request))->onQueue($queue);
        } else {
            $restorer = $this->restorer;
            defer(static fn () => $restorer->restore($backup, $options, $password, 'admin'));
        }

        return to_route('admin.backups.index')->with('success', __('atrium-backup::backup.ui.restore_started'));
    }

    public function destroy(Request $request, string $backup): RedirectResponse
    {
        $found = $this->backups->find($backup) ?? abort(404);
        $file = basename($found->path());
        $found->delete();
        $this->backups->log()->by($this->admin($request))->add('delete', 'ok', ['file' => $file, 'disk' => $this->backups->diskOf($backup)]);

        return back()->with('success', __('atrium-backup::backup.ui.deleted', ['file' => $file]));
    }

    /**
     * @return array<string,mixed>
     */
    private function state(): array
    {
        return [
            'backups' => array_map(fn (array $backup): array => [
                ...$backup,
                'download_url' => route('admin.backups.download', $backup['id']),
            ], $this->backups->all()),
            'running' => $this->backups->log()->running(),
            'log' => $this->backups->log()->recent(50),
        ];
    }

    /**
     * What is backed up, where to and when: read-only, it lives in config.
     *
     * @return array<string,mixed>
     */
    private function settings(): array
    {
        $config = (array) config('atrium-backup');
        $sources = Sources::current();

        return [
            'databases' => array_map(static fn (string $connection): array => [
                'connection' => $connection,
                'driver' => (string) config("database.connections.{$connection}.driver"),
            ], $sources->databases()),
            'folders' => array_map(static fn (array $folder): string => $folder['disk'] ?? $folder['root'], $sources->folders()),
            'destinations' => array_values((array) ($config['destinations'] ?? [])),
            'encrypted' => ! empty($config['password']),
            'schedule' => (array) ($config['schedule'] ?? []),
            'weekday' => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays((int) ($config['schedule']['day'] ?? 0))->locale(app()->getLocale())->dayName,
            'retention' => (array) ($config['retention'] ?? []),
            'queue' => ($config['queue'] ?? null) ?: null,
        ];
    }

    /**
     * The administrator's name, for the log.
     */
    private function admin(Request $request): ?string
    {
        $name = $request->user(config('atrium.auth.guard', 'admin'))?->name;

        return is_string($name) ? $name : null;
    }
}
