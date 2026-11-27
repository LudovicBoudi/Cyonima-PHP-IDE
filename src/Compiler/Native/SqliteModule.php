<?php

declare(strict_types=1);

namespace Cyonima\Compiler\Native;

/**
 * Module SQLite : handles opaques en `int`, résultats scalaires.
 *
 * Le handle 0 représente une erreur d’ouverture ou un handle invalide.
 */
final class SqliteModule implements NativeModule
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function functions(): array
    {
        return [
            'sqlite_open' => new NativeFunction('sqlite_open', ['path' => 'string'], 'int'),
            'sqlite_close' => new NativeFunction('sqlite_close', ['handle' => 'int'], 'bool'),
            'sqlite_exec' => new NativeFunction(
                'sqlite_exec',
                ['handle' => 'int', 'sql' => 'string'],
                'bool',
            ),
            'sqlite_error' => new NativeFunction('sqlite_error', ['handle' => 'int'], 'string'),
            'sqlite_last_id' => new NativeFunction('sqlite_last_id', ['handle' => 'int'], 'int'),
            'sqlite_changes' => new NativeFunction('sqlite_changes', ['handle' => 'int'], 'int'),
            'sqlite_query_int' => new NativeFunction(
                'sqlite_query_int',
                ['handle' => 'int', 'sql' => 'string'],
                'int',
            ),
            'sqlite_query_float' => new NativeFunction(
                'sqlite_query_float',
                ['handle' => 'int', 'sql' => 'string'],
                'float',
            ),
            'sqlite_query_string' => new NativeFunction(
                'sqlite_query_string',
                ['handle' => 'int', 'sql' => 'string'],
                'string',
            ),
            'sqlite_query_bool' => new NativeFunction(
                'sqlite_query_bool',
                ['handle' => 'int', 'sql' => 'string'],
                'bool',
            ),
            'sqlite_query_row_string' => new NativeFunction(
                'sqlite_query_row_string',
                ['handle' => 'int', 'sql' => 'string'],
                'array<string,string>',
            ),
            'sqlite_query_column_int' => new NativeFunction(
                'sqlite_query_column_int',
                ['handle' => 'int', 'sql' => 'string'],
                'array<int>',
            ),
            'sqlite_quote' => new NativeFunction(
                'sqlite_quote',
                ['handle' => 'int', 'value' => 'string'],
                'string',
            ),
            'sqlite_prepare' => new NativeFunction(
                'sqlite_prepare',
                ['handle' => 'int', 'sql' => 'string'],
                'int',
            ),
            'sqlite_finalize' => new NativeFunction(
                'sqlite_finalize',
                ['statement' => 'int'],
                'void',
            ),
            'sqlite_reset' => new NativeFunction(
                'sqlite_reset',
                ['statement' => 'int'],
                'void',
            ),
            'sqlite_step' => new NativeFunction(
                'sqlite_step',
                ['statement' => 'int'],
                'int',
            ),
            'sqlite_bind_int' => new NativeFunction(
                'sqlite_bind_int',
                ['statement' => 'int', 'index' => 'int', 'value' => 'int'],
                'bool',
            ),
            'sqlite_bind_float' => new NativeFunction(
                'sqlite_bind_float',
                ['statement' => 'int', 'index' => 'int', 'value' => 'float'],
                'bool',
            ),
            'sqlite_bind_string' => new NativeFunction(
                'sqlite_bind_string',
                ['statement' => 'int', 'index' => 'int', 'value' => 'string'],
                'bool',
            ),
            'sqlite_bind_bool' => new NativeFunction(
                'sqlite_bind_bool',
                ['statement' => 'int', 'index' => 'int', 'value' => 'bool'],
                'bool',
            ),
            'sqlite_bind_null' => new NativeFunction(
                'sqlite_bind_null',
                ['statement' => 'int', 'index' => 'int'],
                'bool',
            ),
            'sqlite_column_int' => new NativeFunction(
                'sqlite_column_int',
                ['statement' => 'int', 'index' => 'int'],
                'int',
            ),
            'sqlite_column_float' => new NativeFunction(
                'sqlite_column_float',
                ['statement' => 'int', 'index' => 'int'],
                'float',
            ),
            'sqlite_column_string' => new NativeFunction(
                'sqlite_column_string',
                ['statement' => 'int', 'index' => 'int'],
                'string',
            ),
            'sqlite_column_bool' => new NativeFunction(
                'sqlite_column_bool',
                ['statement' => 'int', 'index' => 'int'],
                'bool',
            ),
            'sqlite_statement_row_string' => new NativeFunction(
                'sqlite_statement_row_string',
                ['statement' => 'int'],
                'array<string,string>',
            ),
            'sqlite_statement_error' => new NativeFunction(
                'sqlite_statement_error',
                ['statement' => 'int'],
                'string',
            ),
        ];
    }

    public function includes(): array
    {
        return ['#include <sqlite3.h>'];
    }

    public function source(): string
    {
        return <<<'C'
            static sqlite3 *cy_native_sqlite_db(cy_int handle)
            {
                return (sqlite3 *) (intptr_t) handle;
            }

            static sqlite3_stmt **cy_native_sqlite_statements = NULL;
            static int cy_native_sqlite_statement_count = 0;
            static int cy_native_sqlite_statement_capacity = 0;

            static cy_int cy_native_sqlite_register_statement(sqlite3_stmt *statement)
            {
                if (statement == NULL) {
                    return 0;
                }

                if (cy_native_sqlite_statement_count >= cy_native_sqlite_statement_capacity) {
                    int capacity = cy_native_sqlite_statement_capacity == 0
                        ? 8
                        : cy_native_sqlite_statement_capacity * 2;
                    sqlite3_stmt **statements = (sqlite3_stmt **) realloc(
                        cy_native_sqlite_statements,
                        sizeof(sqlite3_stmt *) * (size_t) capacity
                    );

                    if (statements == NULL) {
                        return 0;
                    }

                    cy_native_sqlite_statements = statements;
                    cy_native_sqlite_statement_capacity = capacity;
                }

                cy_native_sqlite_statements[cy_native_sqlite_statement_count] = statement;

                return ++cy_native_sqlite_statement_count;
            }

            static sqlite3_stmt *cy_native_sqlite_statement(cy_int handle)
            {
                if (handle <= 0 || handle > cy_native_sqlite_statement_count) {
                    return NULL;
                }

                return cy_native_sqlite_statements[handle - 1];
            }

            static cy_int fn_sqlite_open(const char *path)
            {
                sqlite3 *db = NULL;
                int status = sqlite3_open_v2(
                    path,
                    &db,
                    SQLITE_OPEN_READWRITE | SQLITE_OPEN_CREATE,
                    NULL
                );

                if (status != SQLITE_OK) {
                    if (db != NULL) {
                        sqlite3_close(db);
                    }
                    return 0;
                }

                return (cy_int) (intptr_t) db;
            }

            static bool fn_sqlite_close(cy_int handle)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL) {
                    return false;
                }

                return sqlite3_close(db) == SQLITE_OK;
            }

            static bool fn_sqlite_exec(cy_int handle, const char *sql)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL) {
                    return false;
                }

                char *error = NULL;
                int status = sqlite3_exec(db, sql, NULL, NULL, &error);

                if (error != NULL) {
                    sqlite3_free(error);
                }

                return status == SQLITE_OK;
            }

            static const char *fn_sqlite_error(cy_int handle)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL) {
                    return "handle SQLite invalide";
                }

                return cy_strdup(sqlite3_errmsg(db));
            }

            static cy_int fn_sqlite_last_id(cy_int handle)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL) {
                    return 0;
                }

                return (cy_int) sqlite3_last_insert_rowid(db);
            }

            static cy_int fn_sqlite_changes(cy_int handle)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL) {
                    return 0;
                }

                return (cy_int) sqlite3_changes(db);
            }

            static sqlite3_stmt *cy_native_sqlite_prepare(cy_int handle, const char *sql)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);
                sqlite3_stmt *statement = NULL;

                if (db == NULL) {
                    return NULL;
                }

                if (sqlite3_prepare_v2(db, sql, -1, &statement, NULL) != SQLITE_OK) {
                    if (statement != NULL) {
                        sqlite3_finalize(statement);
                    }
                    return NULL;
                }

                return statement;
            }

            static cy_int fn_sqlite_query_int(cy_int handle, const char *sql)
            {
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return 0;
                }

                cy_int result = 0;

                if (sqlite3_step(statement) == SQLITE_ROW) {
                    result = (cy_int) sqlite3_column_int64(statement, 0);
                }

                sqlite3_finalize(statement);

                return result;
            }

            static cy_float fn_sqlite_query_float(cy_int handle, const char *sql)
            {
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return 0.0;
                }

                cy_float result = 0.0;

                if (sqlite3_step(statement) == SQLITE_ROW) {
                    result = (cy_float) sqlite3_column_double(statement, 0);
                }

                sqlite3_finalize(statement);

                return result;
            }

            static const char *fn_sqlite_query_string(cy_int handle, const char *sql)
            {
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return "";
                }

                const char *result = "";

                if (sqlite3_step(statement) == SQLITE_ROW) {
                    const unsigned char *text = sqlite3_column_text(statement, 0);

                    if (text != NULL) {
                        result = cy_strdup((const char *) text);
                    }
                }

                sqlite3_finalize(statement);

                return result;
            }

            static bool fn_sqlite_query_bool(cy_int handle, const char *sql)
            {
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return false;
                }

                bool result = false;

                if (sqlite3_step(statement) == SQLITE_ROW) {
                    result = sqlite3_column_int(statement, 0) != 0;
                }

                sqlite3_finalize(statement);

                return result;
            }

            static CyMap *fn_sqlite_query_row_string(cy_int handle, const char *sql)
            {
                CyMap *result = cy_map_new();
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return result;
                }

                if (sqlite3_step(statement) == SQLITE_ROW) {
                    int columns = sqlite3_column_count(statement);

                    for (int index = 0; index < columns; index++) {
                        const char *name = sqlite3_column_name(statement, index);
                        const unsigned char *text = sqlite3_column_text(statement, index);
                        const char *value = "";

                        if (text != NULL) {
                            value = cy_strdup((const char *) text);
                        }

                        cy_map_set_string_string(
                            result,
                            cy_strdup(name == NULL ? "" : name),
                            value
                        );
                    }
                }

                sqlite3_finalize(statement);

                return result;
            }

            static CyArray *fn_sqlite_query_column_int(cy_int handle, const char *sql)
            {
                CyArray *result = cy_array_new();
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return result;
                }

                while (sqlite3_step(statement) == SQLITE_ROW) {
                    cy_array_push_int(
                        result,
                        (cy_int) sqlite3_column_int64(statement, 0)
                    );
                }

                sqlite3_finalize(statement);

                return result;
            }

            static const char *fn_sqlite_quote(cy_int handle, const char *value)
            {
                sqlite3 *db = cy_native_sqlite_db(handle);

                if (db == NULL || value == NULL) {
                    return "''";
                }

                char *quoted = sqlite3_mprintf("%Q", value);

                if (quoted == NULL) {
                    return "''";
                }

                const char *copy = cy_strdup(quoted);
                sqlite3_free(quoted);

                return copy;
            }

            static cy_int fn_sqlite_prepare(cy_int handle, const char *sql)
            {
                sqlite3_stmt *statement = cy_native_sqlite_prepare(handle, sql);

                if (statement == NULL) {
                    return 0;
                }

                cy_int result = cy_native_sqlite_register_statement(statement);

                if (result == 0) {
                    sqlite3_finalize(statement);
                }

                return result;
            }

            static void fn_sqlite_finalize(cy_int handle)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return;
                }

                sqlite3_finalize(statement);
                cy_native_sqlite_statements[handle - 1] = NULL;
            }

            static void fn_sqlite_reset(cy_int handle)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return;
                }

                sqlite3_reset(statement);
                sqlite3_clear_bindings(statement);
            }

            static cy_int fn_sqlite_step(cy_int handle)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return -1;
                }

                int status = sqlite3_step(statement);

                if (status == SQLITE_ROW) {
                    return 1;
                }

                if (status == SQLITE_DONE) {
                    return 0;
                }

                return -1;
            }

            static bool fn_sqlite_bind_int(cy_int handle, cy_int index, cy_int value)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL || index <= 0) {
                    return false;
                }

                return sqlite3_bind_int64(
                    statement,
                    (int) index,
                    (sqlite3_int64) value
                ) == SQLITE_OK;
            }

            static bool fn_sqlite_bind_float(cy_int handle, cy_int index, cy_float value)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL || index <= 0) {
                    return false;
                }

                return sqlite3_bind_double(
                    statement,
                    (int) index,
                    (double) value
                ) == SQLITE_OK;
            }

            static bool fn_sqlite_bind_string(cy_int handle, cy_int index, const char *value)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL || index <= 0) {
                    return false;
                }

                return sqlite3_bind_text(
                    statement,
                    (int) index,
                    value == NULL ? "" : value,
                    -1,
                    SQLITE_TRANSIENT
                ) == SQLITE_OK;
            }

            static bool fn_sqlite_bind_bool(cy_int handle, cy_int index, bool value)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL || index <= 0) {
                    return false;
                }

                return sqlite3_bind_int(
                    statement,
                    (int) index,
                    value ? 1 : 0
                ) == SQLITE_OK;
            }

            static bool fn_sqlite_bind_null(cy_int handle, cy_int index)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL || index <= 0) {
                    return false;
                }

                return sqlite3_bind_null(statement, (int) index) == SQLITE_OK;
            }

            static cy_int fn_sqlite_column_int(cy_int handle, cy_int index)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return 0;
                }

                return (cy_int) sqlite3_column_int64(statement, (int) index);
            }

            static cy_float fn_sqlite_column_float(cy_int handle, cy_int index)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return 0.0;
                }

                return (cy_float) sqlite3_column_double(statement, (int) index);
            }

            static const char *fn_sqlite_column_string(cy_int handle, cy_int index)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return "";
                }

                const unsigned char *text = sqlite3_column_text(
                    statement,
                    (int) index
                );

                if (text == NULL) {
                    return "";
                }

                return cy_strdup((const char *) text);
            }

            static bool fn_sqlite_column_bool(cy_int handle, cy_int index)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return false;
                }

                return sqlite3_column_int(statement, (int) index) != 0;
            }

            static CyMap *fn_sqlite_statement_row_string(cy_int handle)
            {
                CyMap *result = cy_map_new();
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return result;
                }

                int columns = sqlite3_column_count(statement);

                for (int index = 0; index < columns; index++) {
                    const char *name = sqlite3_column_name(statement, index);
                    const unsigned char *text = sqlite3_column_text(statement, index);
                    const char *value = "";

                    if (text != NULL) {
                        value = cy_strdup((const char *) text);
                    }

                    cy_map_set_string_string(
                        result,
                        cy_strdup(name == NULL ? "" : name),
                        value
                    );
                }

                return result;
            }

            static const char *fn_sqlite_statement_error(cy_int handle)
            {
                sqlite3_stmt *statement = cy_native_sqlite_statement(handle);

                if (statement == NULL) {
                    return "";
                }

                sqlite3 *db = sqlite3_db_handle(statement);
                const char *message = sqlite3_errmsg(db);

                return cy_strdup(message == NULL ? "" : message);
            }
            C;
    }

    public function pkgConfig(): ?string
    {
        return 'sqlite3';
    }

    public function linkFlags(): string
    {
        return '-lsqlite3';
    }

    public function flatpakLibraries(): array
    {
        return [];
    }
}
