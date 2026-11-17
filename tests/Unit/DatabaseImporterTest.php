<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Backup\Tests\Unit;

use Atrium\Backup\Restore\DatabaseImporter;
use Atrium\Backup\Restore\RestoreFailed;
use Atrium\Backup\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The MySQL and PostgreSQL paths without a server: the client is a script
 * that writes down how it was called, the schema builder writes down what it
 * dropped. The real clients run in the MySQL and PostgreSQL legs of CI.
 */
final class DatabaseImporterTest extends TestCase
{
    /** @var array<int,string> */
    private array $dropped = [];

    /** Whether the schema builder cannot drop views, as some servers refuse. */
    private bool $viewsFail = false;

    private string $bin;

    private string $dump;

    private string|false $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bin = $this->dir.'/bin';
        mkdir($this->bin);
        $this->dump = $this->dir.'/dump.sql';
        file_put_contents($this->dump, 'CREATE TABLE articles (id int);');
        $this->path = getenv('PATH');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.($this->path === false ? '' : $this->path));

        parent::tearDown();
    }

    public function test_a_mysql_dump_goes_to_the_mysql_client_after_everything_is_dropped(): void
    {
        $this->client('mysql');
        $this->connection('mysql', ['unix_socket' => '/run/mysqld.sock', 'username' => 'atrium', 'password' => 'p@ss', 'charset' => 'utf8', 'database' => 'site']);
        $before = DB::connection('fake');

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame(['views', 'tables'], $this->dropped);
        $this->assertSame(['--user=atrium', '--default-character-set=utf8', '--socket=/run/mysqld.sock', 'site'], $this->calledWith());
        $this->assertSame('CREATE TABLE articles (id int);', file_get_contents($this->bin.'/stdin'));
        $this->assertSame('p@ss', trim((string) file_get_contents($this->bin.'/password')));
        // The client wrote behind PDO's back: the next query gets a fresh connection.
        $this->assertNotSame($before, DB::connection('fake'));
    }

    public function test_a_mariadb_connection_uses_the_mysql_client_too_and_views_it_cannot_drop_do_not_stop_it(): void
    {
        $this->client('mysql');
        $this->viewsFail = true;
        $this->connection('mariadb', ['host' => 'db.test', 'port' => 3307, 'database' => 'site']);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame(['tables'], $this->dropped);
        $this->assertSame(['--user=', '--default-character-set=utf8mb4', '--host=db.test', '--port=3307', 'site'], $this->calledWith());
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:array<int,string>}>
     */
    public static function mysqlHosts(): array
    {
        return [
            'no host: this machine' => [[], ['--host=127.0.0.1', '--port=3306']],
            'an empty socket is no socket, an empty name no database' => [['unix_socket' => '', 'host' => 'db.test', 'database' => ''], ['--host=db.test', '--port=3306']],
            'read and write hosts: the first' => [['host' => ['primary.test', 'replica.test'], 'port' => '3310'], ['--host=primary.test', '--port=3310']],
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<int,string>  $expected
     */
    #[DataProvider('mysqlHosts')]
    public function test_the_mysql_client_finds_the_server_like_laravel_does(array $config, array $expected): void
    {
        $this->client('mysql');
        $this->connection('mysql', $config);

        (new DatabaseImporter)->import('fake', $this->dump);

        // No database name: the empty argument is left out.
        $this->assertSame(['--user=', '--default-character-set=utf8mb4', ...$expected], $this->calledWith());
    }

    public function test_a_postgresql_dump_goes_to_psql_and_types_are_dropped_too(): void
    {
        $this->client('psql');
        $this->connection('pgsql', ['host' => ['pg.test'], 'port' => 5433, 'username' => 'atrium', 'password' => 'secret', 'database' => 'site']);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame(['views', 'tables', 'types'], $this->dropped);
        $this->assertSame(
            ['--no-psqlrc', '--quiet', '--set=ON_ERROR_STOP=1', '--file='.$this->dump, '--host=pg.test', '--port=5433', '--username=atrium', '--dbname=site'],
            $this->calledWith(),
        );
        $this->assertSame('', file_get_contents($this->bin.'/stdin'));
        $this->assertSame('secret', trim((string) file_get_contents($this->bin.'/password')));
    }

    public function test_psql_finds_a_server_by_its_name(): void
    {
        $this->client('psql');
        $this->connection('pgsql', ['host' => 'pg.test', 'database' => 'site']);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame(['--host=pg.test', '--port=5432', '--username=', '--dbname=site'], array_slice($this->calledWith(), 4));
    }

    public function test_the_client_folder_may_be_written_without_a_trailing_slash(): void
    {
        $this->client('mysql');
        $this->connection('mysql', ['database' => 'site']);
        config(['database.connections.fake.dump.dump_binary_path' => $this->bin]);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame('site', $this->calledWith()[4]);
    }

    public function test_psql_defaults_to_this_machine(): void
    {
        $this->client('psql');
        $this->connection('pgsql', []);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame(['--no-psqlrc', '--quiet', '--set=ON_ERROR_STOP=1', '--file='.$this->dump, '--host=127.0.0.1', '--port=5432', '--username=', '--dbname='], $this->calledWith());
    }

    public function test_without_a_folder_the_client_is_looked_up_on_the_path(): void
    {
        $this->client('mysql');
        $this->connection('mysql', ['database' => 'site'], folder: null);
        putenv('PATH='.$this->bin);

        (new DatabaseImporter)->import('fake', $this->dump);

        $this->assertSame('site', $this->calledWith()[4]);
    }

    public function test_a_missing_client_stops_the_restore_before_anything_is_dropped(): void
    {
        $this->connection('mysql', ['database' => 'site']);

        try {
            (new DatabaseImporter)->import('fake', $this->dump);
            $this->fail('A missing client must stop the restore.');
        } catch (RestoreFailed $e) {
            $this->assertSame(__('atrium-backup::backup.errors.client', ['client' => 'mysql']), $e->getMessage());
        }

        $this->assertSame([], $this->dropped);
    }

    public function test_a_client_that_is_on_no_path_is_missing_too(): void
    {
        $this->connection('pgsql', [], folder: null);
        putenv('PATH='.$this->bin);

        $this->expectExceptionObject(new RestoreFailed(__('atrium-backup::backup.errors.client', ['client' => 'psql'])));

        (new DatabaseImporter)->import('fake', $this->dump);
    }

    public function test_a_file_that_cannot_be_run_is_no_client(): void
    {
        file_put_contents($this->bin.'/mysql', '#!/bin/sh');
        chmod($this->bin.'/mysql', 0644);
        $this->connection('mysql', []);

        $this->expectException(RestoreFailed::class);

        (new DatabaseImporter)->import('fake', $this->dump);
    }

    public function test_a_driver_without_a_client_is_refused(): void
    {
        config(['database.connections.fake' => ['driver' => 'sqlsrv', 'database' => 'site']]);

        $this->expectExceptionObject(new RestoreFailed(__('atrium-backup::backup.errors.driver', ['driver' => 'sqlsrv'])));

        (new DatabaseImporter)->import('fake', $this->dump);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function failures(): array
    {
        return [
            'its error output' => ['echo "ERROR 1064 near CREATE" >&2; exit 1', 'ERROR 1064 near CREATE'],
            'its output when the error output is empty' => ['echo "  syntax error  "; exit 1', 'syntax error'],
            'its exit code when it says nothing' => ['exit 3', 'exit code 3'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failing_client_fails_the_restore_with_what_it_said(string $script, string $message): void
    {
        $this->client('mysql', $script);
        $this->connection('mysql', []);

        try {
            (new DatabaseImporter)->import('fake', $this->dump);
            $this->fail('A failing client must fail the restore.');
        } catch (RestoreFailed $e) {
            $this->assertSame($message === 'exit code 3' ? __('atrium-backup::backup.errors.exit_code', ['code' => '3']) : $message, $e->getMessage());
        }
    }

    public function test_a_broken_sqlite_dump_fails_with_the_database_error(): void
    {
        file_put_contents($this->dump, 'CREATE TABLE (;');

        $this->expectException(RuntimeException::class);

        (new DatabaseImporter)->import('site', $this->dump);
    }

    /**
     * A script that writes down its arguments, its input and the password.
     */
    private function client(string $name, string $body = ''): void
    {
        $variable = $name === 'mysql' ? 'MYSQL_PWD' : 'PGPASSWORD';
        $dir = escapeshellarg($this->bin);
        file_put_contents($this->bin.'/'.$name, <<<SH
            #!/bin/sh
            for arg in "\$@"; do printf '[%s]\\n' "\$arg"; done > {$dir}/args
            cat > {$dir}/stdin
            printf '%s' "\${$variable}" > {$dir}/password
            {$body}
            SH);
        chmod($this->bin.'/'.$name, 0755);
    }

    /**
     * @return array<int,string>
     */
    private function calledWith(): array
    {
        // Every argument, empty ones too: one per line, in brackets.
        $lines = explode("\n", rtrim((string) file_get_contents($this->bin.'/args'), "\n"));

        return array_map(static fn (string $line): string => substr($line, 1, -1), $lines);
    }

    /**
     * A MySQL or PostgreSQL connection that never connects: its schema
     * builder writes down what it would drop.
     *
     * @param  array<string,mixed>  $config
     */
    private function connection(string $driver, array $config, ?string $folder = 'bin'): void
    {
        config(['database.connections.fake' => [
            'driver' => $driver,
            ...$config,
            ...($folder === null ? [] : ['dump' => ['dump_binary_path' => $this->dir.'/'.$folder.'/']]),
        ]]);

        $dropped = &$this->dropped;
        $viewsFail = $this->viewsFail;

        DB::extend('fake', static function (array $config) use (&$dropped, $viewsFail): Connection {
            $pdo = static fn () => throw new LogicException('The test connection never connects.');
            $class = $config['driver'] === 'pgsql' ? PostgresConnection::class : MySqlConnection::class;

            return new class($pdo, (string) ($config['database'] ?? ''), '', $config, $dropped, $class, $viewsFail) extends Connection
            {
                /** @param array<int,string> $dropped */
                public function __construct($pdo, string $database, string $prefix, array $config, private array &$dropped, private string $as, private bool $viewsFail)
                {
                    parent::__construct($pdo, $database, $prefix, $config);
                }

                public function getDriverName(): string
                {
                    return $this->config['driver'];
                }

                public function getSchemaBuilder(): Builder
                {
                    $dropped = &$this->dropped;
                    $pgsql = $this->as === PostgresConnection::class;

                    return new class($this, $dropped, $pgsql, $this->viewsFail) extends Builder
                    {
                        /** @param array<int,string> $dropped */
                        public function __construct(Connection $connection, private array &$dropped, private bool $pgsql, private bool $viewsFail)
                        {
                            parent::__construct($connection);
                        }

                        public function dropAllViews(): void
                        {
                            if ($this->viewsFail) {
                                throw new RuntimeException('Views are not supported.');
                            }

                            $this->dropped[] = 'views';
                        }

                        public function dropAllTables(): void
                        {
                            $this->dropped[] = 'tables';
                        }

                        public function dropAllTypes(): void
                        {
                            if (! $this->pgsql) {
                                throw new LogicException('Only PostgreSQL has types to drop.');
                            }

                            $this->dropped[] = 'types';
                        }
                    };
                }
            };
        });
    }
}
