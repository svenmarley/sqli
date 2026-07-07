<?php

declare(strict_types=1);

namespace Sqli2\Db;

/**
 * Lazy, cached view of the live schema (table names and per-table columns).
 *
 * Used by tab completion (§4) and mixed-star expansion (§5). Results are cached
 * until {@see reset()} (called on `USE`/database change) so repeated Tab presses
 * don't re-query MySQL. Errors degrade to empty lists — completion simply offers
 * nothing rather than failing.
 */
final class Schema
{
    /** @var list<string>|null */
    private ?array $tables = null;
    /** @var array<string,list<array<string,mixed>>> table => SHOW COLUMNS rows */
    private array $describe = [];
    /** @var array<string,array<string,array{table:string,column:string}>> table => col => FK ref */
    private array $foreignKeys = [];

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * True once the table list has been fetched from MySQL. Lets callers show a
     * "please wait" popup only on the *first* schema access (SHOW TABLES), not on
     * later cached ones. Does not itself trigger a fetch.
     */
    public function isLoaded(): bool
    {
        return $this->tables !== null;
    }

    /**
     * True when this table's columns are already cached, so a diff/describe won't
     * need a fresh `SHOW COLUMNS` round-trip. Lets the DD viewer decide whether to
     * show a "please wait" popup before warming its schema diffs. No fetch here.
     */
    public function isDescribed(string $table): bool
    {
        return isset($this->describe[$table]);
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $this->tables = [];
        $res = $this->database->query('SHOW TABLES');
        if (!$res->hasError()) {
            foreach ($res->rows as $row) {
                $val = reset($row); // SHOW TABLES has one column, name varies by db
                if ($val !== false && $val !== null) {
                    $this->tables[] = (string) $val;
                }
            }
        }
        return $this->tables;
    }

    /** @return list<string> column names in declaration order */
    public function columns(string $table): array
    {
        $out = [];
        foreach ($this->describe($table) as $row) {
            $field = $row['Field'] ?? null;
            if ($field !== null) {
                $out[] = (string) $field;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the cached SHOW COLUMNS rows for $table */
    public function describeRows(string $table): array
    {
        return $this->describe($table);
    }

    /**
     * The SHOW COLUMNS row for one column (case-insensitive), or null when the
     * column isn't part of this table's live schema (e.g. a joined/aliased column).
     *
     * @return array<string,mixed>|null
     */
    public function describeColumn(string $table, string $column): ?array
    {
        foreach ($this->describe($table) as $row) {
            if (strcasecmp((string) ($row['Field'] ?? ''), $column) === 0) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Foreign keys declared on $table, parsed from SHOW CREATE TABLE and cached.
     * Maps each referencing column to its target `table`.`column`. Multi-column
     * keys map positionally. Empty when the table has no foreign keys.
     *
     * @return array<string,array{table:string,column:string}>
     */
    public function foreignKeys(string $table): array
    {
        if ($table === '') {
            return [];
        }
        if (isset($this->foreignKeys[$table])) {
            return $this->foreignKeys[$table];
        }
        $map = [];
        $res = $this->database->query('SHOW CREATE TABLE `' . $table . '`');
        if (!$res->hasError() && $res->rows !== []) {
            // SHOW CREATE TABLE returns one row; the DDL is its second column
            // ("Create Table"), but its exact key varies, so take the longest cell.
            $row = $res->rows[0];
            $ddl = '';
            foreach ($row as $cell) {
                $s = (string) $cell;
                if (strlen($s) > strlen($ddl)) {
                    $ddl = $s;
                }
            }
            // CONSTRAINT ... FOREIGN KEY (`a`,`b`) REFERENCES `other` (`x`,`y`)
            if (preg_match_all(
                '/FOREIGN KEY\s*\(([^)]+)\)\s*REFERENCES\s*`([^`]+)`\s*\(([^)]+)\)/i',
                $ddl,
                $matches,
                PREG_SET_ORDER,
            ) !== false) {
                foreach ($matches as $fk) {
                    $localCols = self::backtickedList($fk[1]);
                    $refTable = $fk[2];
                    $refCols = self::backtickedList($fk[3]);
                    foreach ($localCols as $i => $local) {
                        $map[$local] = [
                            'table' => $refTable,
                            'column' => $refCols[$i] ?? ($refCols[0] ?? ''),
                        ];
                    }
                }
            }
        }
        return $this->foreignKeys[$table] = $map;
    }

    /** The FK reference for one column, or null when it isn't a foreign key. */
    public function foreignKey(string $table, string $column): ?array
    {
        foreach ($this->foreignKeys($table) as $col => $ref) {
            if (strcasecmp($col, $column) === 0) {
                return $ref;
            }
        }
        return null;
    }

    /**
     * Split a backticked column list like "`a`, `b`" into ['a', 'b'].
     *
     * @return list<string>
     */
    private static function backtickedList(string $s): array
    {
        preg_match_all('/`([^`]+)`/', $s, $m);
        return $m[1];
    }

    /** True when $table exists in the live database (case-insensitive). */
    public function hasTable(string $table): bool
    {
        foreach ($this->tables() as $name) {
            if (strcasecmp($name, $table) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> primary-key column names (empty if none/unknown) */
    public function primaryKey(string $table): array
    {
        $out = [];
        foreach ($this->describe($table) as $row) {
            if (($row['Key'] ?? '') === 'PRI') {
                $field = $row['Field'] ?? null;
                if ($field !== null) {
                    $out[] = (string) $field;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> cached SHOW COLUMNS rows */
    private function describe(string $table): array
    {
        if ($table === '') {
            return [];
        }
        if (isset($this->describe[$table])) {
            return $this->describe[$table];
        }
        $rows = [];
        $res = $this->database->query('SHOW COLUMNS FROM `' . $table . '`');
        if (!$res->hasError()) {
            $rows = $res->rows;
        }
        return $this->describe[$table] = $rows;
    }

    public function reset(): void
    {
        $this->tables = null;
        $this->describe = [];
        $this->foreignKeys = [];
    }
}
