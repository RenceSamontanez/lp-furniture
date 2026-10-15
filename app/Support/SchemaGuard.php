<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read-only information_schema lookups used by the custom-order
 * migrations so every up()/down() can be guarded and re-run safely
 * against a database that already has some of these objects (the live
 * furniture_db has columns that no earlier migration created).
 *
 * Deliberately tiny and stable: old migrations depend on it.
 */
class SchemaGuard
{
    public static function foreignKeyExists(string $table, string $constraintName): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraintName)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    public static function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    /** Full column type, e.g. "varchar(255)" or "enum('a','b')"; null if the column doesn't exist. */
    public static function columnType(string $table, string $column): ?string
    {
        $row = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first(['COLUMN_TYPE']);

        return $row ? $row->COLUMN_TYPE : null;
    }

    public static function enumHasValue(string $table, string $column, string $value): bool
    {
        $type = self::columnType($table, $column);

        return $type !== null && str_contains($type, "'" . $value . "'");
    }
}
