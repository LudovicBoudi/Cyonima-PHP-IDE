<?php

declare(strict_types=1);

namespace Cyonima\Compiler;

/**
 * Mini-framework base de données : connexion SQLite, repository, migrations.
 */
final class DatabasePrelude
{
    public const SOURCE = <<<'PHP'
        <?php

        namespace Cyonima\Database;

        use Exception;
        use RuntimeException;

        final class Connection
        {
            public int $handle;

            public function __construct(string $path)
            {
                $this->handle = sqlite_open($path);

                if ($this->handle === 0) {
                    throw new RuntimeException('ouverture SQLite impossible : ' . $path);
                }
            }

            public function exec(string $sql): bool
            {
                return sqlite_exec($this->handle, $sql);
            }

            public function queryInt(string $sql): int
            {
                return sqlite_query_int($this->handle, $sql);
            }

            public function queryFloat(string $sql): float
            {
                return sqlite_query_float($this->handle, $sql);
            }

            public function queryBool(string $sql): bool
            {
                return sqlite_query_bool($this->handle, $sql);
            }

            public function queryString(string $sql): string
            {
                return sqlite_query_string($this->handle, $sql);
            }

            /** @return array<string,string> */
            public function row(string $sql): array
            {
                return sqlite_query_row_string($this->handle, $sql);
            }

            public function quote(string $value): string
            {
                return sqlite_quote($this->handle, $value);
            }

            public function lastInsertId(): int
            {
                return sqlite_last_id($this->handle);
            }

            public function changes(): int
            {
                return sqlite_changes($this->handle);
            }

            public function error(): string
            {
                return sqlite_error($this->handle);
            }

            public function begin(): bool
            {
                return $this->exec('BEGIN');
            }

            public function commit(): bool
            {
                return $this->exec('COMMIT');
            }

            public function rollback(): bool
            {
                return $this->exec('ROLLBACK');
            }

            public function close(): void
            {
                if ($this->handle !== 0) {
                    sqlite_close($this->handle);
                    $this->handle = 0;
                }
            }
        }

        abstract class Repository
        {
            public Connection $connection;
            public string $table;

            public function __construct(Connection $connection, string $table)
            {
                $this->connection = $connection;
                $this->table = $table;
            }

            public function tableName(): string
            {
                return $this->table;
            }

            public function count(): int
            {
                return $this->connection->queryInt(
                    'SELECT COUNT(*) FROM ' . $this->table
                );
            }

            public function exists(int $id): bool
            {
                return $this->connection->queryBool(
                    'SELECT COUNT(*) > 0 FROM ' . $this->table
                    . ' WHERE id = ' . strval($id)
                );
            }

            public function delete(int $id): bool
            {
                return $this->connection->exec(
                    'DELETE FROM ' . $this->table . ' WHERE id = ' . strval($id)
                );
            }

            /** @return array<string,string> */
            public function row(string $sql): array
            {
                return $this->connection->row($sql);
            }

            public function execute(string $sql): bool
            {
                return $this->connection->exec($sql);
            }

            public function lastId(): int
            {
                return $this->connection->lastInsertId();
            }

            public function changes(): int
            {
                return $this->connection->changes();
            }
        }

        abstract class Migration
        {
            abstract public function version(): int;

            abstract public function up(Connection $connection): void;
        }

        final class Migrator
        {
            public Connection $connection;

            /** @var array<Migration> */
            public array $migrations;

            public function __construct(Connection $connection)
            {
                $this->connection = $connection;
            }

            public function add(Migration $migration): void
            {
                $migrations = $this->migrations;
                $migrations[] = $migration;
                $this->migrations = $migrations;
            }

            public function run(): int
            {
                $this->connection->exec(
                    'CREATE TABLE IF NOT EXISTS cyonima_migrations ('
                    . 'version INTEGER PRIMARY KEY, applied_at TEXT)'
                );
                $applied = 0;
                $migrations = $this->migrations;

                foreach ($migrations as $migration) {
                    $version = $migration->version();

                    if (!$this->isApplied($version)) {
                        $this->connection->begin();

                        try {
                            $migration->up($this->connection);
                            $this->connection->exec(
                                'INSERT INTO cyonima_migrations (version, applied_at) '
                                . 'VALUES (' . strval($version) . ', datetime(\'now\'))'
                            );
                            $this->connection->commit();
                            $applied = $applied + 1;
                        } catch (Exception $error) {
                            $this->connection->rollback();
                            throw $error;
                        }
                    }
                }

                return $applied;
            }

            private function isApplied(int $version): bool
            {
                return $this->connection->queryBool(
                    'SELECT COUNT(*) > 0 FROM cyonima_migrations '
                    . 'WHERE version = ' . strval($version)
                );
            }
        }
        PHP;
}
