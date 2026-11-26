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
