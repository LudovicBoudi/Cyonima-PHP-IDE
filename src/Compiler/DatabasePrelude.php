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

            /** @return array<int> */
            public function columnInt(string $sql): array
            {
                return sqlite_query_column_int($this->handle, $sql);
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

            public function transaction(callable():void $work): void
            {
                $this->begin();

                try {
                    $work();
                    $this->commit();
                } catch (Exception $error) {
                    $this->rollback();
                    throw $error;
                }
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

            public function store(): Table
            {
                return new Table($this->connection, $this->table);
            }

            public function belongsTo(string $table, int $id): BelongsTo
            {
                return $this->store()->belongsTo($table, $id);
            }

            public function hasMany(
                string $table,
                string $foreignKey,
                int $id
            ): HasMany {
                return $this->store()->hasMany($table, $foreignKey, $id);
            }
        }

        final class Table
        {
            public Connection $connection;
            public string $table;

            public function __construct(Connection $connection, string $table)
            {
                $this->assertIdentifier($table);
                $this->connection = $connection;
                $this->table = $table;
            }

            /** @param array<string,string> $values */
            public function insert(array $values): int
            {
                $columns = '';
                $literals = '';
                $first = true;

                foreach ($values as $column => $value) {
                    $this->assertIdentifier($column);

                    if (!$first) {
                        $columns = $columns . ', ';
                        $literals = $literals . ', ';
                    }

                    $columns = $columns . $column;
                    $literals = $literals . $this->connection->quote($value);
                    $first = false;
                }

                if ($first) {
                    throw new RuntimeException('insertion sans valeur');
                }

                $this->connection->exec(
                    'INSERT INTO ' . $this->table
                    . ' (' . $columns . ') VALUES (' . $literals . ')'
                );

                $id = $this->connection->lastInsertId();

                if ($id === 0) {
                    throw new RuntimeException(
                        'insertion sans identifiant : ' . $this->table
                    );
                }

                return $id;
            }

            /** @param array<string,string> $values */
            public function update(int $id, array $values): bool
            {
                $assignments = '';
                $first = true;

                foreach ($values as $column => $value) {
                    $this->assertIdentifier($column);

                    if (!$first) {
                        $assignments = $assignments . ', ';
                    }

                    $assignments = $assignments . $column
                        . ' = ' . $this->connection->quote($value);
                    $first = false;
                }

                if ($first) {
                    return false;
                }

                return $this->connection->exec(
                    'UPDATE ' . $this->table . ' SET ' . $assignments
                    . ' WHERE id = ' . strval($id)
                );
            }

            /** @return array<string,string> */
            public function find(int $id): array
            {
                return $this->connection->row(
                    'SELECT * FROM ' . $this->table
                    . ' WHERE id = ' . strval($id) . ' LIMIT 1'
                );
            }

            /** @return array<string,string> */
            public function findWhere(string $column, string $value): array
            {
                $this->assertIdentifier($column);

                return $this->connection->row(
                    'SELECT * FROM ' . $this->table . ' WHERE ' . $column
                    . ' = ' . $this->connection->quote($value) . ' LIMIT 1'
                );
            }

            public function delete(int $id): bool
            {
                return $this->connection->exec(
                    'DELETE FROM ' . $this->table . ' WHERE id = ' . strval($id)
                );
            }

            public function exists(int $id): bool
            {
                return $this->connection->queryBool(
                    'SELECT COUNT(*) > 0 FROM ' . $this->table
                    . ' WHERE id = ' . strval($id)
                );
            }

            public function count(): int
            {
                return $this->connection->queryInt(
                    'SELECT COUNT(*) FROM ' . $this->table
                );
            }

            /** @return array<int> */
            public function ids(): array
            {
                return $this->connection->columnInt(
                    'SELECT id FROM ' . $this->table . ' ORDER BY id'
                );
            }

            public function column(string $name): string
            {
                $this->assertIdentifier($name);

                return $name;
            }

            public function belongsTo(string $table, int $id): BelongsTo
            {
                return new BelongsTo($this->connection, $table, $id);
            }

            public function hasMany(
                string $table,
                string $foreignKey,
                int $id
            ): HasMany {
                return new HasMany(
                    $this->connection,
                    $table,
                    $foreignKey,
                    $id
                );
            }

            private function assertIdentifier(string $name): void
            {
                if (strlen($name) === 0) {
                    throw new RuntimeException('identifiant SQL vide');
                }

                if (str_contains($name, ' ')
                    || str_contains($name, "'")
                    || str_contains($name, '"')
                    || str_contains($name, ';')
                    || str_contains($name, '-')
                    || str_contains($name, '(')
                    || str_contains($name, ')')
                    || str_contains($name, ',')
                    || str_contains($name, '*')
                    || str_contains($name, '=')) {
                    throw new RuntimeException('identifiant SQL invalide : ' . $name);
                }
            }
        }

        final class BelongsTo
        {
            public Connection $connection;
            public string $table;
            public int $id;

            public function __construct(Connection $connection, string $table, int $id)
            {
                $store = new Table($connection, $table);
                $this->connection = $connection;
                $this->table = $store->table;
                $this->id = $id;
            }

            /** @return array<string,string> */
            public function row(): array
            {
                return (new Table($this->connection, $this->table))->find($this->id);
            }

            public function exists(): bool
            {
                return (new Table($this->connection, $this->table))->exists($this->id);
            }

            public function id(): int
            {
                return $this->id;
            }
        }

        final class HasMany
        {
            public Connection $connection;
            public string $table;
            public string $foreignKey;
            public int $foreignId;

            public function __construct(
                Connection $connection,
                string $table,
                string $foreignKey,
                int $foreignId
            ) {
                $store = new Table($connection, $table);
                $this->connection = $connection;
                $this->table = $store->table;
                $this->foreignKey = $store->column($foreignKey);
                $this->foreignId = $foreignId;
            }

            /** @return array<int> */
            public function ids(): array
            {
                return $this->connection->columnInt(
                    'SELECT id FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey
                    . ' = ' . strval($this->foreignId)
                    . ' ORDER BY id'
                );
            }

            public function count(): int
            {
                return $this->connection->queryInt(
                    'SELECT COUNT(*) FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey
                    . ' = ' . strval($this->foreignId)
                );
            }

            public function exists(): bool
            {
                return $this->connection->queryBool(
                    'SELECT COUNT(*) > 0 FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey
                    . ' = ' . strval($this->foreignId)
                );
            }
        }

        abstract class Entity
        {
            public int $id;

            public function __construct()
            {
                $this->id = 0;
            }

            abstract public function table(): string;

            /** @return array<string,string> */
            abstract public function values(): array;

            /** @param array<string,string> $row */
            abstract public function hydrate(array $row): void;

            public function isNew(): bool
            {
                return $this->id === 0;
            }

            public function assignId(int $id): void
            {
                $this->id = $id;
            }

            public function save(Connection $connection): int
            {
                $store = new Table($connection, $this->table());
                $values = $this->values();
                $current = $this->id;

                if ($current === 0) {
                    $id = $store->insert($values);
                    $this->id = $id;

                    return $id;
                }

                $updated = $store->update($current, $values);

                if (!$updated) {
                    throw new RuntimeException(
                        'mise à jour impossible : ' . $this->table()
                    );
                }

                return $current;
            }

            public function delete(Connection $connection): bool
            {
                $current = $this->id;

                if ($current === 0) {
                    return false;
                }

                $store = new Table($connection, $this->table());
                $deleted = $store->delete($current);

                if ($deleted) {
                    $this->id = 0;
                }

                return $deleted;
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
