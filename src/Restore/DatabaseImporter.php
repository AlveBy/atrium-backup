<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Restore;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Puts a dump made by spatie/db-dumper back: everything in the database is
 * dropped first, then the dump is run.
 *
 * MySQL and PostgreSQL dumps are fed to the `mysql` and `psql` clients (the
 * same folder as `dump.dump_binary_path` of the connection, if set), SQLite
 * ones are run through PDO.
 */
final class DatabaseImporter
{
    public function import(string $connectionName, string $dump): void
    {
        /** @var Connection $connection */
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $client = match ($driver) {
            'mysql', 'mariadb' => $this->binary($connectionName, 'mysql'),
            'pgsql' => $this->binary($connectionName, 'psql'),
            'sqlite' => null,
            default => throw new RestoreFailed(__('atrium-backup::backup.errors.driver', ['driver' => $driver])),
        };

        // Checked before anything is dropped.
        if ($client !== null && ! is_executable($client)) {
            throw new RestoreFailed(__('atrium-backup::backup.errors.client', ['client' => basename($client)]));
        }

        $this->wipe($connection);

        if ($client === null) {
            $this->sqlite($connection, $dump);

            return;
        }

        if ($driver === 'pgsql') {
            $this->run([$client, '--no-psqlrc', '--quiet', '--set=ON_ERROR_STOP=1', '--file='.$dump, ...$this->pgsqlOptions($connection)], ['PGPASSWORD' => self::password($connection)]);
        } else {
            $this->run([$client, ...$this->mysqlOptions($connection)], ['MYSQL_PWD' => self::password($connection)], $dump);
        }

        // The client wrote behind PDO's back: start from a fresh connection.
        DB::purge($connectionName);
    }

    private function wipe(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();

        try {
            $schema->dropAllViews();
        } catch (Throwable) {
            // Not supported by every driver.
        }

        $schema->dropAllTables();

        if ($connection->getDriverName() === 'pgsql') {
            $schema->dropAllTypes();
        }
    }

    private function sqlite(Connection $connection, string $dump): void
    {
        // PDO throws on a broken dump: Laravel sets ERRMODE_EXCEPTION.
        $connection->getPdo()->exec((string) file_get_contents($dump));
    }

    /**
     * @param  array<int,string>  $command
     * @param  array<string,string>  $env
     */
    private function run(array $command, array $env, ?string $input = null): void
    {
        $process = new Process($command, null, $env, null, null);

        if ($input !== null) {
            $handle = fopen($input, 'rb');
            $process->setInput($handle === false ? null : $handle);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RestoreFailed(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: __('atrium-backup::backup.errors.exit_code', ['code' => $process->getExitCode()]));
        }
    }

    /**
     * @return array<int,string>
     */
    private function mysqlOptions(Connection $connection): array
    {
        $config = $connection->getConfig();
        $options = ['--user='.($config['username'] ?? ''), '--default-character-set='.($config['charset'] ?? 'utf8mb4')];

        if (! empty($config['unix_socket'])) {
            $options[] = '--socket='.$config['unix_socket'];
        } else {
            $options[] = '--host='.(is_array($config['host'] ?? null) ? $config['host'][0] : ($config['host'] ?? '127.0.0.1'));
            $options[] = '--port='.($config['port'] ?? 3306);
        }

        // An empty database name is no argument at all.
        $database = $config['database'] ?? null;

        if (is_string($database) && $database !== '') {
            $options[] = $database;
        }

        return $options;
    }

    /**
     * @return array<int,string>
     */
    private function pgsqlOptions(Connection $connection): array
    {
        $config = $connection->getConfig();

        return [
            '--host='.(is_array($config['host'] ?? null) ? $config['host'][0] : ($config['host'] ?? '127.0.0.1')),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
        ];
    }

    private static function password(Connection $connection): string
    {
        $password = $connection->getConfig('password');

        return is_string($password) ? $password : '';
    }

    private function binary(string $connection, string $name): string
    {
        $folder = config("database.connections.{$connection}.dump.dump_binary_path");

        // A trailing slash or not: a doubled one does no harm.
        if (is_string($folder) && $folder !== '') {
            return $folder.DIRECTORY_SEPARATOR.$name;
        }

        return (new ExecutableFinder)->find($name) ?? $name;
    }
}
