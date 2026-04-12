<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * Override prepareBindings so PHP booleans are sent as SQL 'true'/'false' strings,
 * not as integers 1/0 which PostgreSQL rejects for boolean columns.
 */
final class PostgresConnection extends BasePostgresConnection
{
    public function prepareBindings(array $bindings): array
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }
}
