<?php

declare(strict_types=1);

namespace Sqli2\Db;

/**
 * Validates a proposed cell value against a column's SQL type before an edit is
 * staged into an UPDATE (§9). Driven by the `SHOW COLUMNS` row for the column
 * (keys: Field, Type, Null, Key, Default, Extra), so it needs no extra queries.
 *
 * The goal is to catch the obvious "that won't fit / that's the wrong shape"
 * mistakes at edit time rather than after the UPDATE bounces off MySQL:
 *   - numeric columns reject non-numeric text;
 *   - tinyint(1) is treated as a bool (only 0 or 1);
 *   - date/datetime/time reject malformed values;
 *   - char/varchar reject over-length values;
 *   - json rejects invalid JSON;
 *   - enum/set reject values outside the declared members.
 *
 * Returns a human-readable error string, or null when the value is acceptable.
 * The empty string and the literal `NULL` (which the writer turns into SQL NULL)
 * are always allowed — clearing a cell is never a type error here; a NOT NULL
 * column that truly can't be null is left for MySQL to reject.
 */
final class FieldValidator
{
    /**
     * @param array<string,mixed> $describeRow one SHOW COLUMNS row
     * @return string|null error message, or null when $value is acceptable
     */
    public static function validate(array $describeRow, string $value): ?string
    {
        // Empty / explicit NULL are handled by the writer, not typed here.
        if ($value === '' || $value === 'NULL') {
            return null;
        }

        $type = strtolower(trim((string) ($describeRow['Type'] ?? '')));
        if ($type === '') {
            return null; // unknown type — don't get in the user's way
        }

        preg_match('/^(\w+)/', $type, $m);
        $base = $m[1] ?? '';
        $unsigned = str_contains($type, 'unsigned');
        $len = self::lengthOf($type);

        switch ($base) {
            case 'tinyint':
                // tinyint(1) is the conventional boolean.
                if ($len === 1) {
                    return in_array($value, ['0', '1'], true)
                        ? null
                        : 'Boolean column — enter 0 or 1';
                }
                return self::checkInt($value, $unsigned);
            case 'smallint':
            case 'mediumint':
            case 'int':
            case 'integer':
            case 'bigint':
                return self::checkInt($value, $unsigned);
            case 'bit':
                return preg_match('/^[01]+$/', $value) === 1
                    ? null
                    : 'Bit column — enter binary digits (0/1)';
            case 'decimal':
            case 'numeric':
            case 'dec':
            case 'fixed':
            case 'float':
            case 'double':
            case 'real':
                return is_numeric($value) ? null : 'Numeric column — enter a number';
            case 'date':
                return self::checkDate($value);
            case 'datetime':
            case 'timestamp':
                return self::checkDateTime($value);
            case 'time':
                return preg_match('/^-?\d{1,3}:[0-5]\d(:[0-5]\d)?$/', $value) === 1
                    ? null
                    : 'Time column — use HH:MM or HH:MM:SS';
            case 'year':
                return preg_match('/^\d{4}$/', $value) === 1
                    ? null
                    : 'Year column — enter a 4-digit year';
            case 'char':
            case 'varchar':
            case 'binary':
            case 'varbinary':
                if ($len > 0 && mb_strlen($value) > $len) {
                    return 'Too long — ' . $base . '(' . $len . ') holds at most '
                        . $len . ' chars (got ' . mb_strlen($value) . ')';
                }
                return null;
            case 'json':
                json_decode($value);
                return json_last_error() === JSON_ERROR_NONE
                    ? null
                    : 'Invalid JSON — ' . json_last_error_msg();
            case 'enum':
                $members = self::enumMembers($type);
                return in_array($value, $members, true)
                    ? null
                    : 'Not an allowed value — expected one of: ' . implode(', ', $members);
            case 'set':
                $members = self::enumMembers($type);
                foreach (($value === '' ? [] : explode(',', $value)) as $part) {
                    if (!in_array($part, $members, true)) {
                        return 'Not an allowed value — SET members are: ' . implode(', ', $members);
                    }
                }
                return null;
            default:
                return null; // text/blob/geometry/etc. — no client-side check
        }
    }

    /** Numeric length/precision inside the first (...) of a type, or 0 if none. */
    private static function lengthOf(string $type): int
    {
        return preg_match('/\((\d+)/', $type, $m) === 1 ? (int) $m[1] : 0;
    }

    private static function checkInt(string $value, bool $unsigned): ?string
    {
        $pattern = $unsigned ? '/^\d+$/' : '/^-?\d+$/';
        if (preg_match($pattern, $value) !== 1) {
            return $unsigned
                ? 'Integer column (unsigned) — enter a whole number ≥ 0'
                : 'Integer column — enter a whole number';
        }
        return null;
    }

    private static function checkDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return 'Date column — use YYYY-MM-DD';
        }
        return null;
    }

    private static function checkDateTime(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):([0-5]\d):([0-5]\d)(\.\d+)?$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            || (int) $m[4] > 23) {
            return 'Datetime column — use YYYY-MM-DD HH:MM:SS';
        }
        return null;
    }

    /**
     * The declared members of an enum/set type, e.g. enum('a','b') -> [a, b].
     *
     * @return list<string>
     */
    private static function enumMembers(string $type): array
    {
        if (preg_match('/^(?:enum|set)\((.*)\)/', $type, $m) !== 1) {
            return [];
        }
        $out = [];
        // Members are single-quoted; a literal quote is doubled inside MySQL.
        if (preg_match_all("/'((?:[^']|'')*)'/", $m[1], $mm) !== false) {
            foreach ($mm[1] as $lit) {
                $out[] = str_replace("''", "'", $lit);
            }
        }
        return $out;
    }
}
