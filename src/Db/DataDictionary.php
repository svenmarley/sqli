<?php

declare(strict_types=1);

namespace Sqli2\Db;

/**
 * A loaded Data Dictionary: a CSV documentation layer over the live MySQL schema
 * (spec §11).
 *
 * CSV shape: `Table, Field, Type, Comment, Nullable, Key, Default[, FKey]`.
 * A row with a blank Field is the table-level description; every other row is one
 * field. Column positions are detected from the header (by name, case-insensitive)
 * with sensible positional fallbacks, so minor header wording differences are
 * tolerated.
 *
 * The raw rows are kept verbatim so edits (e.g. a comment change) and {@see save()}
 * round-trip the file without disturbing anything else.
 */
final class DataDictionary
{
    /** @var list<string> the CSV header row */
    private array $header = [];
    /** @var list<list<string>> raw data rows (each padded to the column it needs) */
    private array $rows = [];
    /** @var array<string,int> logical column name => column index */
    private array $ci = [];
    /** @var list<string> table names in first-appearance order */
    private array $tableOrder = [];
    /** @var array<string,array{desc:int,fields:array<string,int>,order:list<string>,fkeys:int}> */
    private array $byTable = [];
    private string $path = '';

    /** Modification time + byte size captured at the last read, for staleness checks. */
    private int $mtime = 0;
    private int $size = 0;

    /**
     * Edits made in memory but not yet flushed, keyed by "table\0field" => comment.
     * Kept so {@see save()} can re-apply just our changed cells onto a fresh copy
     * of the file, instead of blindly overwriting a possibly-stale snapshot and
     * clobbering another editor's unrelated changes.
     * @var array<string,string>
     */
    private array $pending = [];

    /** save() outcomes (§11). */
    public const SAVE_OK = 'ok';
    public const SAVE_MERGED = 'merged';   // written, but external edits were merged in first
    public const SAVE_LOCKED = 'locked';   // file is held open elsewhere (e.g. Excel)
    public const SAVE_FAILED = 'failed';   // permissions / I/O

    /** Load a dictionary from a CSV path; null if missing/unreadable/empty. */
    public static function load(string $path): ?self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $parsed = self::parseFile($path);
        if ($parsed === null) {
            return null;
        }

        $dd = new self();
        $dd->path = $path;
        $dd->header = $parsed[0];
        $dd->rows = $parsed[1];
        $dd->reindex();
        $dd->captureStat();
        return $dd;
    }

    /**
     * Parse a CSV into [header, rows], or null if unreadable/empty. Opens, reads
     * fully, and closes — never holds a handle open (kind to a shared drive and to
     * other editors).
     *
     * @return array{0:list<string>,1:list<list<string>>}|null
     */
    private static function parseFile(string $path): ?array
    {
        $h = @fopen($path, 'r');
        if ($h === false) {
            return null;
        }
        // Pass $escape explicitly: PHP 8.4 deprecates the implicit default, and ''
        // (no backslash escaping) is RFC-4180 / spreadsheet-standard behavior.
        $header = fgetcsv($h, escape: '');
        if ($header === false || $header === null) {
            fclose($h);
            return null;
        }
        $rows = [];
        while (($row = fgetcsv($h, escape: '')) !== false) {
            if ($row === [null] || (count($row) === 1 && (string) ($row[0] ?? '') === '')) {
                continue; // blank line
            }
            $rows[] = array_map(static fn ($v): string => $v === null ? '' : (string) $v, $row);
        }
        fclose($h);

        return [
            array_map(static fn ($v): string => (string) $v, $header),
            $rows,
        ];
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return list<string> table names in CSV order */
    public function tableNames(): array
    {
        return $this->tableOrder;
    }

    public function hasTable(string $table): bool
    {
        return isset($this->byTable[$table]);
    }

    /** Table-level description ('' if none). */
    public function description(string $table): string
    {
        $i = $this->byTable[$table]['desc'] ?? -1;
        return $i >= 0 ? trim($this->rows[$i][$this->ci['comment']] ?? '') : '';
    }

    /**
     * The table-level foreign-keys summary ('' if none). This is the special
     * last-row-per-table marker (`FKeys` / `--Foreign-Keys--`); its relationships
     * live in the Comment column. Read-only here (not a real column, §11).
     */
    public function foreignKeys(string $table): string
    {
        $i = $this->byTable[$table]['fkeys'] ?? -1;
        return $i >= 0 ? trim($this->rows[$i][$this->ci['comment']] ?? '') : '';
    }

    /** @return list<string> field names of a table in CSV order */
    public function columns(string $table): array
    {
        return $this->byTable[$table]['order'] ?? [];
    }

    /**
     * @return list<array{name:string,type:string,comment:string,nullable:string,key:string,default:string}>
     */
    public function fields(string $table): array
    {
        $out = [];
        foreach ($this->byTable[$table]['order'] ?? [] as $field) {
            $r = $this->rows[$this->byTable[$table]['fields'][$field]];
            $out[] = [
                'name'     => $field,
                'type'     => trim($r[$this->ci['type']] ?? ''),
                'comment'  => trim($r[$this->ci['comment']] ?? ''),
                'nullable' => trim($r[$this->ci['nullable']] ?? ''),
                'key'      => trim($r[$this->ci['key']] ?? ''),
                'default'  => trim($r[$this->ci['default']] ?? ''),
            ];
        }
        return $out;
    }

    /** A single field's comment, or null when the field isn't in the dictionary. */
    public function comment(string $table, string $field): ?string
    {
        $idx = $this->byTable[$table]['fields'][$field] ?? null;
        return $idx === null ? null : trim($this->rows[$idx][$this->ci['comment']] ?? '');
    }

    /** Update a field's comment in memory. Returns false if the field is unknown. */
    public function setComment(string $table, string $field, string $comment): bool
    {
        $idx = $this->byTable[$table]['fields'][$field] ?? null;
        if ($idx === null) {
            return false;
        }
        $this->rows[$idx][$this->ci['comment']] = $comment;
        // Remember the change so save() can re-apply it onto a fresh read of the
        // file (merge, don't clobber).
        $this->pending[$table . "\0" . $field] = $comment;
        return true;
    }

    /** Record the file's mtime/size so later reads can tell if it changed on disk. */
    private function captureStat(): void
    {
        clearstatcache(true, $this->path);
        $this->mtime = @filemtime($this->path) ?: 0;
        $this->size = @filesize($this->path) ?: 0;
    }

    /**
     * True if the file on disk differs from what we last read (someone else saved).
     * mtime alone is unreliable across SMB (coarse resolution, host clock skew), so
     * we pair it with size. Missing file → not "stale" (there's nothing to refresh
     * to; a save will surface the real error).
     */
    public function isStale(): bool
    {
        if ($this->path === '' || !is_file($this->path)) {
            return false;
        }
        clearstatcache(true, $this->path);
        return (@filemtime($this->path) ?: 0) !== $this->mtime
            || (@filesize($this->path) ?: 0) !== $this->size;
    }

    /**
     * Re-read the file from disk, discarding any unsaved in-memory edits. Used to
     * refresh before an edit when another editor has changed the file. Returns
     * false (and leaves the current copy intact) if the file can't be read.
     */
    public function reload(): bool
    {
        if ($this->path === '') {
            return false;
        }
        $parsed = self::parseFile($this->path);
        if ($parsed === null) {
            return false;
        }
        $this->header = $parsed[0];
        $this->rows = $parsed[1];
        $this->pending = [];
        $this->reindex();
        $this->captureStat();
        return true;
    }

    /**
     * True when the file is currently held open by another program with a lock
     * that would block our write (typically Excel, which opens even a .csv with a
     * deny-write share so no one else can overwrite/rename it while it's open).
     *
     * We probe by trying to open the existing file for writing without truncating
     * ('r+'): that requests write access, which a deny-write share refuses. We
     * distinguish this from a plain read-only file: if the file is writable per its
     * ACLs yet 'r+' still fails, the failure is a share lock, not permissions.
     */
    public function isLocked(): bool
    {
        if ($this->path === '' || !is_file($this->path)) {
            return false;
        }
        clearstatcache(true, $this->path);
        $h = @fopen($this->path, 'r+');
        if ($h === false) {
            return is_writable($this->path);
        }
        fclose($h);
        return false;
    }

    /**
     * Flush the dictionary to its CSV file.
     *
     * Read-modify-write with optimistic concurrency:
     *  1. If the file is locked elsewhere, do nothing and report SAVE_LOCKED so the
     *     caller can keep the edit pending and retry once it's freed.
     *  2. If the file changed on disk since we read it, re-read it fresh and re-apply
     *     only our own edited cells on top (SAVE_MERGED), so a concurrent editor's
     *     unrelated changes survive.
     *  3. Write to a sibling temp file, then atomically replace the original, so a
     *     reader (or a crash mid-write) never sees a half-written CSV.
     */
    public function save(): string
    {
        if ($this->path === '') {
            return self::SAVE_FAILED;
        }
        if ($this->isLocked()) {
            return self::SAVE_LOCKED;
        }

        $merged = false;
        if ($this->isStale()) {
            // Re-read the current file and lay our pending edits back on top so we
            // don't overwrite whatever else changed. Keep pending across the reload.
            $parsed = self::parseFile($this->path);
            if ($parsed !== null) {
                $this->header = $parsed[0];
                $this->rows = $parsed[1];
                $this->reindex();
                $this->applyPending();
                $merged = true;
            }
        }

        if (!$this->writeAtomically()) {
            return self::SAVE_FAILED;
        }

        $this->captureStat();
        $this->pending = [];
        return $merged ? self::SAVE_MERGED : self::SAVE_OK;
    }

    /** Re-apply pending cell edits onto the (freshly reindexed) rows. */
    private function applyPending(): void
    {
        foreach ($this->pending as $key => $comment) {
            [$table, $field] = explode("\0", $key, 2) + ['', ''];
            $idx = $this->byTable[$table]['fields'][$field] ?? null;
            if ($idx !== null) {
                $this->rows[$idx][$this->ci['comment']] = $comment;
            }
            // If the field is gone from the fresh file, silently drop the edit —
            // re-adding a deleted column would be a surprise, not a merge.
        }
    }

    /**
     * Write header + rows to a temp file in the same directory, then replace the
     * original in one step. Same-directory temp keeps the rename on one volume so
     * it stays atomic. Returns false on any I/O failure (temp is cleaned up).
     */
    private function writeAtomically(): bool
    {
        $tmp = $this->path . '.tmp' . getmypid();
        $h = @fopen($tmp, 'w');
        if ($h === false) {
            return false;
        }
        fputcsv($h, $this->header, escape: '');
        foreach ($this->rows as $row) {
            fputcsv($h, $row, escape: '');
        }
        $ok = fflush($h);
        fclose($h);
        if ($ok === false) {
            @unlink($tmp);
            return false;
        }

        if (@rename($tmp, $this->path)) {
            return true;
        }
        // Windows/SMB can refuse a rename onto an existing target; fall back to a
        // replace-in-place. (The lock probe already ruled out an active holder.)
        if (@unlink($this->path) && @rename($tmp, $this->path)) {
            return true;
        }
        @unlink($tmp);
        return false;
    }

    /** Map header names to column indexes, then build the per-table index. */
    private function reindex(): void
    {
        $find = function (array $cands, int $default): int {
            foreach ($this->header as $i => $name) {
                $n = strtolower(trim($name));
                foreach ($cands as $c) {
                    if (str_contains($n, $c)) {
                        return $i;
                    }
                }
            }
            return $default;
        };
        // FKey before Key so the substring 'key' doesn't claim the FKey column;
        // both are detected by their own first matching header otherwise.
        $this->ci = [
            'table'    => $find(['table'], 0),
            'field'    => $find(['field'], 1),
            'type'     => $find(['type'], 2),
            'comment'  => $find(['comment', 'description'], 3),
            'nullable' => $find(['null'], 4),
            'key'      => $find(['key'], 5),
            'default'  => $find(['default'], 6),
            'fkey'     => $find(['fkey', 'foreign'], 7),
        ];

        $this->tableOrder = [];
        $this->byTable = [];
        foreach ($this->rows as $idx => $row) {
            $table = trim($row[$this->ci['table']] ?? '');
            // A "--- Database Server Info ---" value in column A marks the start of
            // a trailing metadata section that isn't part of the dictionary. Ignore
            // that row and everything below it (the user appends it to the DD CSV).
            if (self::isServerInfoMarker($table)) {
                break;
            }
            if ($table === '') {
                continue;
            }
            if (!isset($this->byTable[$table])) {
                $this->byTable[$table] = ['desc' => -1, 'fields' => [], 'order' => [], 'fkeys' => -1];
                $this->tableOrder[] = $table;
            }
            $field = trim($row[$this->ci['field']] ?? '');
            if ($field === '') {
                $this->byTable[$table]['desc'] = $idx;
                continue;
            }
            // The foreign-keys summary row is metadata, not a column: keep it aside
            // (read-only, viewable) so it never appears in fields()/columns() or
            // gets diffed/edited as if it were a real field (§11).
            if (self::isForeignKeyMarker($field)) {
                $this->byTable[$table]['fkeys'] = $idx;
                continue;
            }
            if (!isset($this->byTable[$table]['fields'][$field])) {
                $this->byTable[$table]['order'][] = $field;
            }
            $this->byTable[$table]['fields'][$field] = $idx;
        }
    }

    /**
     * True when a Field value is the table-level foreign-keys summary marker
     * (e.g. `FKeys` or `--Foreign-Keys--`). Matched on the alphabetic core so
     * dashes/spaces/case don't matter.
     */
    private static function isForeignKeyMarker(string $field): bool
    {
        $norm = (string) preg_replace('/[^a-z]/', '', strtolower($field));
        return $norm === 'fkeys' || $norm === 'foreignkeys';
    }

    /**
     * True when a column-A value marks the appended "--- Database Server Info ---"
     * section, which sits below the dictionary proper and is not a table. Matched
     * on the alphabetic core so dashes/spaces/case don't matter.
     */
    private static function isServerInfoMarker(string $s): bool
    {
        $norm = (string) preg_replace('/[^a-z]/', '', strtolower($s));
        return str_contains($norm, 'databaseserverinfo');
    }
}
