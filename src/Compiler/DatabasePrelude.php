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

            public function prepare(string $sql): Statement
            {
                $statement = sqlite_prepare($this->handle, $sql);

                if ($statement === 0) {
                    throw new RuntimeException(
                        'préparation SQL impossible : ' . $this->error()
                    );
                }

                return new Statement($this, $statement);
            }

            public function close(): void
            {
                if ($this->handle !== 0) {
                    sqlite_close($this->handle);
                    $this->handle = 0;
                }
            }
        }

        final class Statement
        {
            public Connection $connection;
            public int $handle;

            public function __construct(Connection $connection, int $handle)
            {
                $this->connection = $connection;
                $this->handle = $handle;
            }

            public function bindInt(int $index, int $value): bool
            {
                return sqlite_bind_int($this->handle, $index, $value);
            }

            public function bindFloat(int $index, float $value): bool
            {
                return sqlite_bind_float($this->handle, $index, $value);
            }

            public function bindString(int $index, string $value): bool
            {
                return sqlite_bind_string($this->handle, $index, $value);
            }

            public function bindBool(int $index, bool $value): bool
            {
                return sqlite_bind_bool($this->handle, $index, $value);
            }

            public function bindNull(int $index): bool
            {
                return sqlite_bind_null($this->handle, $index);
            }

            public function step(): int
            {
                return sqlite_step($this->handle);
            }

            public function execute(): bool
            {
                return $this->step() !== -1;
            }

            public function reset(): void
            {
                sqlite_reset($this->handle);
            }

            public function finalize(): void
            {
                if ($this->handle !== 0) {
                    sqlite_finalize($this->handle);
                    $this->handle = 0;
                }
            }

            public function columnInt(int $index): int
            {
                return sqlite_column_int($this->handle, $index);
            }

            public function columnFloat(int $index): float
            {
                return sqlite_column_float($this->handle, $index);
            }

            public function columnString(int $index): string
            {
                return sqlite_column_string($this->handle, $index);
            }

            public function columnBool(int $index): bool
            {
                return sqlite_column_bool($this->handle, $index);
            }

            /** @return array<string,string> */
            public function row(): array
            {
                return sqlite_statement_row_string($this->handle);
            }

            public function error(): string
            {
                return sqlite_statement_error($this->handle);
            }
        }

        final class QueryCondition
        {
            public string $column;
            public string $operator;
            public string $value;
            public int $kind;

            public function __construct(
                string $column,
                string $operator,
                string $value,
                int $kind
            ) {
                $this->column = $column;
                $this->operator = $operator;
                $this->value = $value;
                $this->kind = $kind;
            }
        }

        final class QueryJoin
        {
            public string $kind;
            public string $table;
            public string $left;
            public string $right;

            public function __construct(
                string $kind,
                string $table,
                string $left,
                string $right
            ) {
                $this->kind = $kind;
                $this->table = $table;
                $this->left = $left;
                $this->right = $right;
            }
        }

        final class QueryBuilder
        {
            public Connection $connection;
            public string $table;

            /** @var array<string> */
            public array $columns;

            /** @var array<QueryCondition> */
            public array $conditions;

            /** @var array<QueryJoin> */
            public array $joins;

            public string $orderColumn;
            public bool $orderDescending;
            public int $limitValue;
            public int $offsetValue;

            public function __construct(Connection $connection, string $table)
            {
                $store = new Table($connection, $table);
                $this->connection = $connection;
                $this->table = $store->table;
                $this->columns = [];
                $this->conditions = [];
                $this->joins = [];
                $this->orderColumn = '';
                $this->orderDescending = false;
                $this->limitValue = -1;
                $this->offsetValue = 0;
            }

            /** @param array<string> $columns */
            public function select(array $columns): QueryBuilder
            {
                $store = new Table($this->connection, $this->table);
                $selected = [];

                foreach ($columns as $column) {
                    $selected[] = $store->reference($column);
                }

                $this->columns = $selected;

                return $this;
            }

            public function selectAs(string $column, string $alias): QueryBuilder
            {
                $store = new Table($this->connection, $this->table);
                $columns = $this->columns;
                $columns[] = $store->reference($column)
                    . ' AS ' . $store->column($alias);
                $this->columns = $columns;

                return $this;
            }

            public function join(
                string $table,
                string $left,
                string $right
            ): QueryBuilder {
                return $this->addJoin('INNER JOIN', $table, $left, $right);
            }

            public function leftJoin(
                string $table,
                string $left,
                string $right
            ): QueryBuilder {
                return $this->addJoin('LEFT JOIN', $table, $left, $right);
            }

            public function whereString(
                string $column,
                string $operator,
                string $value
            ): QueryBuilder {
                return $this->addCondition($column, $operator, $value, 1);
            }

            public function whereInt(
                string $column,
                string $operator,
                int $value
            ): QueryBuilder {
                return $this->addCondition(
                    $column,
                    $operator,
                    strval($value),
                    2
                );
            }

            public function whereFloat(
                string $column,
                string $operator,
                float $value
            ): QueryBuilder {
                return $this->addCondition(
                    $column,
                    $operator,
                    strval($value),
                    3
                );
            }

            public function whereBool(
                string $column,
                string $operator,
                bool $value
            ): QueryBuilder {
                $stored = '0';

                if ($value) {
                    $stored = '1';
                }

                return $this->addCondition($column, $operator, $stored, 4);
            }

            public function whereNull(string $column): QueryBuilder
            {
                return $this->addCondition($column, 'IS NULL', '', 0);
            }

            public function whereNotNull(string $column): QueryBuilder
            {
                return $this->addCondition($column, 'IS NOT NULL', '', 0);
            }

            public function orderBy(string $column, bool $descending): QueryBuilder
            {
                $store = new Table($this->connection, $this->table);
                $this->orderColumn = $store->reference($column);
                $this->orderDescending = $descending;

                return $this;
            }

            public function limit(int $limit): QueryBuilder
            {
                if ($limit < 0) {
                    throw new RuntimeException('limite négative');
                }

                $this->limitValue = $limit;

                return $this;
            }

            public function offset(int $offset): QueryBuilder
            {
                if ($offset < 0) {
                    throw new RuntimeException('offset négatif');
                }

                $this->offsetValue = $offset;

                return $this;
            }

            public function get(): Statement
            {
                $columns = '*';

                if (count($this->columns) > 0) {
                    $columns = '';
                    $firstColumn = true;

                    foreach ($this->columns as $column) {
                        if (!$firstColumn) {
                            $columns = $columns . ', ';
                        }

                        $columns = $columns . $column;
                        $firstColumn = false;
                    }
                }

                $sql = 'SELECT ' . $columns . ' FROM ' . $this->table
                    . $this->joinSql()
                    . $this->whereSql();

                if ($this->orderColumn !== '') {
                    $sql = $sql . ' ORDER BY ' . $this->orderColumn;

                    if ($this->orderDescending) {
                        $sql = $sql . ' DESC';
                    } else {
                        $sql = $sql . ' ASC';
                    }
                }

                if ($this->limitValue >= 0) {
                    $sql = $sql . ' LIMIT ' . strval($this->limitValue);
                } elseif ($this->offsetValue > 0) {
                    $sql = $sql . ' LIMIT -1';
                }

                if ($this->offsetValue > 0) {
                    $sql = $sql . ' OFFSET ' . strval($this->offsetValue);
                }

                $statement = $this->connection->prepare($sql);
                $this->bindConditions($statement);

                return $statement;
            }

            public function count(): int
            {
                $statement = $this->connection->prepare(
                    'SELECT COUNT(*) FROM ' . $this->table
                    . $this->joinSql()
                    . $this->whereSql()
                );
                $this->bindConditions($statement);
                $count = 0;

                if ($statement->step() === 1) {
                    $count = $statement->columnInt(0);
                }

                $statement->finalize();

                return $count;
            }

            public function exists(): bool
            {
                return $this->count() > 0;
            }

            private function addJoin(
                string $kind,
                string $table,
                string $left,
                string $right
            ): QueryBuilder {
                $store = new Table($this->connection, $table);
                $joins = $this->joins;
                $joins[] = new QueryJoin(
                    $kind,
                    $store->table,
                    $store->reference($left),
                    $store->reference($right)
                );
                $this->joins = $joins;

                return $this;
            }

            private function addCondition(
                string $column,
                string $operator,
                string $value,
                int $kind
            ): QueryBuilder {
                if ($kind > 0
                    && $operator !== '='
                    && $operator !== '!='
                    && $operator !== '<'
                    && $operator !== '<='
                    && $operator !== '>'
                    && $operator !== '>='
                    && $operator !== 'LIKE') {
                    throw new RuntimeException(
                        'opérateur SQL invalide : ' . $operator
                    );
                }

                $store = new Table($this->connection, $this->table);
                $conditions = $this->conditions;
                $conditions[] = new QueryCondition(
                    $store->reference($column),
                    $operator,
                    $value,
                    $kind
                );
                $this->conditions = $conditions;

                return $this;
            }

            private function joinSql(): string
            {
                $sql = '';

                foreach ($this->joins as $join) {
                    $sql = $sql . ' ' . $join->kind . ' ' . $join->table
                        . ' ON ' . $join->left . ' = ' . $join->right;
                }

                return $sql;
            }

            private function whereSql(): string
            {
                if (count($this->conditions) === 0) {
                    return '';
                }

                $sql = ' WHERE ';
                $first = true;

                foreach ($this->conditions as $condition) {
                    if (!$first) {
                        $sql = $sql . ' AND ';
                    }

                    $sql = $sql . $condition->column
                        . ' ' . $condition->operator;

                    if ($condition->kind > 0) {
                        $sql = $sql . ' ?';
                    }

                    $first = false;
                }

                return $sql;
            }

            private function bindConditions(Statement $statement): void
            {
                $index = 1;

                foreach ($this->conditions as $condition) {
                    if ($condition->kind === 1) {
                        $statement->bindString($index, $condition->value);
                        $index = $index + 1;
                    } elseif ($condition->kind === 2) {
                        $statement->bindInt($index, intval($condition->value));
                        $index = $index + 1;
                    } elseif ($condition->kind === 3) {
                        $statement->bindFloat($index, floatval($condition->value));
                        $index = $index + 1;
                    } elseif ($condition->kind === 4) {
                        $statement->bindBool(
                            $index,
                            $condition->value === '1'
                        );
                        $index = $index + 1;
                    }
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

            public function query(): QueryBuilder
            {
                return $this->store()->query();
            }

            public function count(): int
            {
                return $this->store()->count();
            }

            public function exists(int $id): bool
            {
                return $this->store()->exists($id);
            }

            public function delete(int $id): bool
            {
                return $this->store()->delete($id);
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

            /** @param array<int> $ids */
            public function findMany(array $ids): Statement
            {
                return $this->store()->findMany($ids);
            }

            /** @param array<int> $ids */
            public function whereIn(string $column, array $ids): Statement
            {
                return $this->store()->whereIn($column, $ids);
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
                $placeholders = '';
                $ordered = [];
                $first = true;

                foreach ($values as $column => $value) {
                    $this->assertIdentifier($column);

                    if (!$first) {
                        $columns = $columns . ', ';
                        $placeholders = $placeholders . ', ';
                    }

                    $columns = $columns . $column;
                    $placeholders = $placeholders . '?';
                    $ordered[] = $value;
                    $first = false;
                }

                if ($first) {
                    throw new RuntimeException('insertion sans valeur');
                }

                $statement = $this->connection->prepare(
                    'INSERT INTO ' . $this->table
                    . ' (' . $columns . ') VALUES (' . $placeholders . ')'
                );
                $index = 1;

                foreach ($ordered as $value) {
                    $statement->bindString($index, $value);
                    $index = $index + 1;
                }

                $executed = $statement->execute();
                $error = $statement->error();
                $statement->finalize();

                if (!$executed) {
                    throw new RuntimeException(
                        'insertion impossible : ' . $this->table . ' — ' . $error
                    );
                }

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
                $ordered = [];
                $first = true;

                foreach ($values as $column => $value) {
                    $this->assertIdentifier($column);

                    if (!$first) {
                        $assignments = $assignments . ', ';
                    }

                    $assignments = $assignments . $column . ' = ?';
                    $ordered[] = $value;
                    $first = false;
                }

                if ($first) {
                    return false;
                }

                $statement = $this->connection->prepare(
                    'UPDATE ' . $this->table . ' SET ' . $assignments
                    . ' WHERE id = ?'
                );
                $index = 1;

                foreach ($ordered as $value) {
                    $statement->bindString($index, $value);
                    $index = $index + 1;
                }

                $statement->bindInt($index, $id);
                $executed = $statement->execute();
                $statement->finalize();

                return $executed;
            }

            /** @return array<string,string> */
            public function find(int $id): array
            {
                $statement = $this->connection->prepare(
                    'SELECT * FROM ' . $this->table
                    . ' WHERE id = ? LIMIT 1'
                );
                $statement->bindInt(1, $id);
                $row = [];

                if ($statement->step() === 1) {
                    $row = $statement->row();
                }

                $statement->finalize();

                return $row;
            }

            /** @param array<int> $ids */
            public function findMany(array $ids): Statement
            {
                return $this->whereIn('id', $ids);
            }

            /** @param array<int> $ids */
            public function whereIn(string $column, array $ids): Statement
            {
                $this->assertIdentifier($column);

                if (count($ids) === 0) {
                    return $this->connection->prepare(
                        'SELECT * FROM ' . $this->table . ' WHERE 0'
                    );
                }

                $placeholders = '';
                $first = true;

                foreach ($ids as $id) {
                    if (!$first) {
                        $placeholders = $placeholders . ', ';
                    }

                    $placeholders = $placeholders . '?';
                    $first = false;
                }

                $statement = $this->connection->prepare(
                    'SELECT * FROM ' . $this->table . ' WHERE ' . $column
                    . ' IN (' . $placeholders . ') ORDER BY id'
                );
                $index = 1;

                foreach ($ids as $id) {
                    $statement->bindInt($index, $id);
                    $index = $index + 1;
                }

                return $statement;
            }

            /** @return array<string,string> */
            public function findWhere(string $column, string $value): array
            {
                $this->assertIdentifier($column);

                $statement = $this->connection->prepare(
                    'SELECT * FROM ' . $this->table . ' WHERE ' . $column
                    . ' = ? LIMIT 1'
                );
                $statement->bindString(1, $value);
                $row = [];

                if ($statement->step() === 1) {
                    $row = $statement->row();
                }

                $statement->finalize();

                return $row;
            }

            public function delete(int $id): bool
            {
                $statement = $this->connection->prepare(
                    'DELETE FROM ' . $this->table . ' WHERE id = ?'
                );
                $statement->bindInt(1, $id);
                $deleted = $statement->execute();
                $statement->finalize();

                return $deleted;
            }

            public function exists(int $id): bool
            {
                $statement = $this->connection->prepare(
                    'SELECT COUNT(*) > 0 FROM ' . $this->table
                    . ' WHERE id = ?'
                );
                $statement->bindInt(1, $id);
                $exists = false;

                if ($statement->step() === 1) {
                    $exists = $statement->columnBool(0);
                }

                $statement->finalize();

                return $exists;
            }

            public function count(): int
            {
                $statement = $this->connection->prepare(
                    'SELECT COUNT(*) FROM ' . $this->table
                );
                $count = 0;

                if ($statement->step() === 1) {
                    $count = $statement->columnInt(0);
                }

                $statement->finalize();

                return $count;
            }

            /** @return array<int> */
            public function ids(): array
            {
                $statement = $this->connection->prepare(
                    'SELECT id FROM ' . $this->table . ' ORDER BY id'
                );
                $ids = [];

                while ($statement->step() === 1) {
                    $ids[] = $statement->columnInt(0);
                }

                $statement->finalize();

                return $ids;
            }

            public function column(string $name): string
            {
                $this->assertIdentifier($name);

                return $name;
            }

            public function reference(string $name): string
            {
                $this->assertIdentifier($name);

                return $name;
            }

            public function query(): QueryBuilder
            {
                return new QueryBuilder($this->connection, $this->table);
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
                $statement = $this->connection->prepare(
                    'SELECT id FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey . ' = ?'
                    . ' ORDER BY id'
                );
                $statement->bindInt(1, $this->foreignId);
                $ids = [];

                while ($statement->step() === 1) {
                    $ids[] = $statement->columnInt(0);
                }

                $statement->finalize();

                return $ids;
            }

            public function statement(): Statement
            {
                $statement = $this->connection->prepare(
                    'SELECT * FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey . ' = ?'
                    . ' ORDER BY id'
                );
                $statement->bindInt(1, $this->foreignId);

                return $statement;
            }

            public function count(): int
            {
                $statement = $this->connection->prepare(
                    'SELECT COUNT(*) FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey . ' = ?'
                );
                $statement->bindInt(1, $this->foreignId);
                $count = 0;

                if ($statement->step() === 1) {
                    $count = $statement->columnInt(0);
                }

                $statement->finalize();

                return $count;
            }

            public function exists(): bool
            {
                $statement = $this->connection->prepare(
                    'SELECT COUNT(*) > 0 FROM ' . $this->table
                    . ' WHERE ' . $this->foreignKey . ' = ?'
                );
                $statement->bindInt(1, $this->foreignId);
                $exists = false;

                if ($statement->step() === 1) {
                    $exists = $statement->columnBool(0);
                }

                $statement->finalize();

                return $exists;
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
