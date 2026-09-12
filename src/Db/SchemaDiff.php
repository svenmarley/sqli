<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * Pure comparison of a Data Dictionary table against the live MySQL schema
 * (spec §11, "schema-diff badges").
 *
 * Given a table's DD field list and its live `SHOW COLUMNS` rows, it classifies
 * every column into one of:
 *   - OK       — present in both, same (normalized) type.
 *   - CHANGED  — present in both, type differs.
 *   - EXTRA    — documented in the DD but absent from the live table.
 *   - MISSING  — present in the live table but not documented in the DD.
 *
 * No I/O here — the App feeds it the already-fetched arrays so the logic stays
 * trivially testable. Identifier matching is case-insensitive (MySQL column
 * names are); type matching normalizes away cosmetic integer display widths
 * (`int(11)` ≡ `int`, dropped by MySQL 8) and whitespace/case differences.
 */
final class SchemaDiff
{
    public const OK = 'ok';
    public const CHANGED = 'changed';
    public const EXTRA = 'extra';     // in DD, not in live table
    public const MISSING = 'missing'; // in live table, not in DD

    /**
     * @param list<array{name:string,type:string,nullable:string,key:string,default:string,comment:string}> $ddFields
     * @param list<array<string,mixed>> $liveColumns  raw SHOW COLUMNS rows (keys: Field, Type, …)
     *
     * @return array{
     *   fieldStatus: array<string,string>,
     *   missing: list<string>,
     *   changed: list<string>,
     *   extra: list<string>,
     *   hasDiff: bool
     * }
     *   fieldStatus is keyed by the DD field's lower-cased name (robust to the
     *   viewer's per-field filtering, which re-indexes the published list).
     */
    public static function compare(array $ddFields, array $liveColumns): array
    {
        // Live columns indexed by lower-cased name -> normalized type.
        $live = [];
        $liveOrder = [];
        foreach ($liveColumns as $col) {
            $name = (string) ($col['Field'] ?? '');
            if ($name === '') {
                continue;
            }
            $live[strtolower($name)] = [
                'name' => $name,
                'type' => self::normalizeType((string) ($col['Type'] ?? '')),
            ];
            $liveOrder[] = $name;
        }

        $fieldStatus = [];
        $changed = [];
        $extra = [];
        $seen = [];
        foreach ($ddFields as $f) {
            $name = (string) ($f['name'] ?? '');
            $key = strtolower($name);
            if ($key === '') {
                continue;
            }
            if (!isset($live[$key])) {
                $fieldStatus[$key] = self::EXTRA;
                $extra[] = $name;
                continue;
            }
            $seen[$key] = true;
            if (self::normalizeType((string) ($f['type'] ?? '')) !== $live[$key]['type']) {
                $fieldStatus[$key] = self::CHANGED;
                $changed[] = $name;
            } else {
                $fieldStatus[$key] = self::OK;
            }
        }

        // Anything live we never matched against a DD field is undocumented.
        $missing = [];
        foreach ($live as $key => $col) {
            if (!isset($seen[$key])) {
                $missing[] = $col['name'];
            }
        }

        return [
            'fieldStatus' => $fieldStatus,
            'missing'     => $missing,
            'changed'     => $changed,
            'extra'       => $extra,
            'hasDiff'     => $missing !== [] || $changed !== [] || $extra !== [],
        ];
    }

    /**
     * Normalize a SQL type for comparison: lower-case, collapse whitespace, and
     * drop the cosmetic display width on integer types (MySQL 8 no longer emits
     * `int(11)`, so a DD that still carries it shouldn't read as "changed").
     */
    public static function normalizeType(string $type): string
    {
        $t = strtolower(trim($type));
        $t = (string) preg_replace('/\s+/', ' ', $t);
        $t = (string) preg_replace(
            '/\b(tinyint|smallint|mediumint|int|integer|bigint|year)\s*\(\d+\)/',
            '$1',
            $t,
        );
        return $t;
    }
}
