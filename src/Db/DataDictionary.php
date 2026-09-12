<?php

declare(strict_types=1);

namespace Sqli\Db;

use Sqli\Support\Path;

/**
 * A loaded Data Dictionary: a CSV documentation layer over the live MySQL schema
 * (spec §11).
 *
 * CSV shape: `Table, Field, Type, Comment, Nullable, Key, Default[, FKey][, Format]`.
 * A row with a blank Field is the table-level description; every other row is one
 * field. Column positions are detected from the header (by name, case-insensitive)
 * with sensible positional fallbacks, so minor header wording differences are
 * tolerated. The optional `Format` column marks a field's display format (e.g.
 * `epoch`) so it renders formatted without a manual toggle (§6 backlog S / §11).
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
        // A saved path may be spelled for another runtime (a WSL `/mnt/d/…` opened
        // under native Windows, or vice-versa). Resolve to the form that exists
        // here before touching it; native Linux/Windows paths pass through. The
        // resolved path is what we keep, so saves and the Archives dir land beside
        // the file we actually read.
        $path = Path::resolve($path);
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

    /**
     * Snapshot the current on-disk CSV into a sibling `Archives/` directory before
     * it is edited (§11). The copy is named `<basename>-NNN.csv` with a zero-padded
     * 3-digit counter one past the highest existing snapshot (first is `-000`).
     *
     * A **no-op returning null** when there is no `Archives/` subdir next to the
     * file (the feature is opt-in by that directory's existence), the file is
     * missing, or the copy fails. Returns the archive path on success. Callers
     * gate this to once per file per session.
     */
    public function archive(): ?string
    {
        if ($this->path === '' || !is_file($this->path)) {
            return null;
        }
        $dir = dirname($this->path) . DIRECTORY_SEPARATOR . 'Archives';
        if (!is_dir($dir)) {
            return null; // opt-in: only archive when the Archives dir exists
        }
        $base = pathinfo($this->path, PATHINFO_FILENAME); // name without .csv
        $dest = $dir . DIRECTORY_SEPARATOR . $base . '-'
            . sprintf('%03d', $this->nextArchiveIndex($dir, $base)) . '.csv';
        return @copy($this->path, $dest) ? $dest : null;
    }

    /**
     * The next archive counter for `<base>-NNN.csv` in $dir: one past the highest
     * existing number (0 when none exist). Anchored to the exact base so a base
     * that itself ends in digits can't be mismatched; `\d{3,}` tolerates a roll
     * past 999.
     */
    private function nextArchiveIndex(string $dir, string $base): int
    {
        $max = -1;
        $re = '/^' . preg_quote($base, '/') . '-(\d{3,})\.csv$/i';
        foreach ((array) @scandir($dir) as $name) {
            if (preg_match($re, (string) $name, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
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

    /**
     * A field's declared display-format id from the optional `Format` column
     * (lowercased/trimmed, e.g. `epoch`), or '' when unset or the field/column is
     * absent. Read-only documentation the app maps onto a
     * {@see \Sqli\Format\ColumnFormats} format (§6/§11).
     */
    public function format(string $table, string $field): string
    {
        $idx = $this->byTable[$table]['fields'][$field] ?? null;
        if ($idx === null) {
            return '';
        }
        return strtolower(trim($this->rows[$idx][$this->ci['format']] ?? ''));
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

    /**
     * Sync a table's structural metadata from the live schema (Ctrl+U, §11).
     *
     * Overwrites each documented field's type/nullable/key/default from the
     * matching `SHOW COLUMNS` row, **preserving comments and format**, appends any
     * live column the DD doesn't document yet (with an empty comment), and
     * **removes any DD field that no longer exists in the live table** (a field the
     * DB has dropped). The archive snapshot taken before the edit preserves the
     * removed rows if they are ever needed. Same read-modify-write concurrency
     * contract as {@see save()}: refuses when the file is locked, and re-reads a
     * changed file first so a co-worker's comment edits survive the rewrite.
     *
     * @param list<array<string,mixed>> $liveColumns raw SHOW COLUMNS rows
     * @return array{status:string,updated:int,added:list<string>,removed:list<string>}
     */
    public function sync(string $table, array $liveColumns): array
    {
        if ($this->path === '') {
            return ['status' => self::SAVE_FAILED, 'updated' => 0, 'added' => [], 'removed' => []];
        }
        if ($this->isLocked()) {
            return ['status' => self::SAVE_LOCKED, 'updated' => 0, 'added' => [], 'removed' => []];
        }

        $merged = false;
        if ($this->isStale()) {
            $parsed = self::parseFile($this->path);
            if ($parsed !== null) {
                $this->header = $parsed[0];
                $this->rows = $parsed[1];
                $this->pending = [];
                $this->reindex();
                $merged = true;
            }
        }

        $applied = $this->applySchemaSync($table, $liveColumns);

        if (!$this->writeAtomically()) {
            return ['status' => self::SAVE_FAILED, 'updated' => 0, 'added' => [], 'removed' => []];
        }
        $this->captureStat();
        $this->pending = [];
        return [
            'status'  => $merged ? self::SAVE_MERGED : self::SAVE_OK,
            'updated' => $applied['updated'],
            'added'   => $applied['added'],
            'removed' => $applied['removed'],
        ];
    }

    /**
     * Create DD entries for live tables the dictionary doesn't document yet (§11),
     * writing one row per live column with an empty comment for the user to fill in.
     * All requested tables are created in a single atomic write — one lock check,
     * one merge, one rewrite — rather than a save per table.
     *
     * Same shared-drive contract as {@see save()}/{@see sync()}: refuses when the
     * file is held open elsewhere, and re-reads + merges an externally changed file
     * first so a co-worker's edits survive. A table that turned out to be documented
     * after that merge is skipped rather than duplicated.
     *
     * @param array<string,list<array<string,mixed>>> $spec table name => raw SHOW COLUMNS rows
     * @return array{status:string,created:list<string>,fields:int,skipped:list<string>}
     */
    public function createTables(array $spec): array
    {
        if ($this->path === '') {
            return ['status' => self::SAVE_FAILED, 'created' => [], 'fields' => 0, 'skipped' => []];
        }
        if ($this->isLocked()) {
            return ['status' => self::SAVE_LOCKED, 'created' => [], 'fields' => 0, 'skipped' => []];
        }

        $merged = false;
        if ($this->isStale()) {
            $parsed = self::parseFile($this->path);
            if ($parsed !== null) {
                $this->header = $parsed[0];
                $this->rows = $parsed[1];
                $this->pending = [];
                $this->reindex();
                $merged = true;
            }
        }

        $created = [];
        $skipped = [];
        $fields = 0;
        foreach ($spec as $table => $liveColumns) {
            $name = trim((string) $table);
            if ($name === '') {
                continue;
            }
            $added = $this->applyCreateTable($name, $liveColumns);
            if ($added > 0) {
                $created[] = $name;
                $fields += $added;
            } else {
                $skipped[] = $name;
            }
        }

        if ($created === []) {
            // Nothing to write — don't touch the file (and don't report a failure;
            // the caller turns an empty 'created' into its own status).
            return ['status' => self::SAVE_OK, 'created' => [], 'fields' => 0, 'skipped' => $skipped];
        }
        if (!$this->writeAtomically()) {
            return ['status' => self::SAVE_FAILED, 'created' => [], 'fields' => 0, 'skipped' => $skipped];
        }
        $this->captureStat();
        $this->pending = [];
        return [
            'status'  => $merged ? self::SAVE_MERGED : self::SAVE_OK,
            'created' => $created,
            'fields'  => $fields,
            'skipped' => $skipped,
        ];
    }

    /**
     * Insert a block of rows documenting one previously-unknown table. Returns the
     * number of field rows added, or 0 if the table is already documented (so a
     * concurrent add merged in from disk can't produce a duplicate block) or has
     * no usable live columns.
     *
     * @param list<array<string,mixed>> $liveColumns
     */
    private function applyCreateTable(string $table, array $liveColumns): int
    {
        if (isset($this->byTable[$table])) {
            return 0;
        }
        $width = max(count($this->header), max($this->ci) + 1);
        $newRows = [];
        foreach ($liveColumns as $col) {
            $name = trim((string) ($col['Field'] ?? ''));
            if ($name === '') {
                continue;
            }
            $row = array_fill(0, $width, '');
            $row[$this->ci['table']] = $table;
            $row[$this->ci['field']] = $name;
            $this->writeSchemaCells($row, $col);
            $newRows[] = $row;
        }
        if ($newRows === []) {
            return 0;
        }
        array_splice($this->rows, $this->newTableInsertPos(), 0, $newRows);
        $this->reindex();
        return count($newRows);
    }

    /**
     * Row index at which a brand-new table's block goes: the end of the dictionary
     * proper. That is *not* simply the end of the file — the user may append a
     * "--- Database Server Info ---" metadata section below the dictionary, which
     * {@see reindex()} stops at. Rows spliced after that marker would be written to
     * the CSV but never indexed, so the new table would silently not exist.
     */
    private function newTableInsertPos(): int
    {
        foreach ($this->rows as $idx => $row) {
            if (self::isServerInfoMarker(trim($row[$this->ci['table']] ?? ''))) {
                return $idx;
            }
        }
        return count($this->rows);
    }

    /**
     * Rewrite $table's structural cells from the live columns and append any
     * undocumented live fields. Reindexes when rows are inserted so the in-memory
     * view (and the next diff) reflect the new fields.
     *
     * @param list<array<string,mixed>> $liveColumns
     * @return array{updated:int,added:list<string>}
     */
    private function applySchemaSync(string $table, array $liveColumns): array
    {
        if (!isset($this->byTable[$table])) {
            return ['updated' => 0, 'added' => []]; // viewer only offers DD tables
        }
        // Existing fields by lower-cased name → row index (MySQL names are ci).
        $existing = [];
        foreach ($this->byTable[$table]['fields'] as $name => $idx) {
            $existing[strtolower((string) $name)] = $idx;
        }
        $width = max(count($this->header), max($this->ci) + 1);

        $updated = 0;
        $added = [];
        $newRows = [];
        foreach ($liveColumns as $col) {
            $name = trim((string) ($col['Field'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = strtolower($name);
            if (isset($existing[$key])) {
                $this->writeSchemaCells($this->rows[$existing[$key]], $col);
                $updated++;
            } else {
                $row = array_fill(0, $width, '');
                $row[$this->ci['table']] = $table;
                $row[$this->ci['field']] = $name;
                $this->writeSchemaCells($row, $col);
                $newRows[] = $row;
                $added[] = $name;
            }
        }
        if ($newRows !== []) {
            array_splice($this->rows, $this->fieldInsertPos($table), 0, $newRows);
            $this->reindex();
        }

        // Prune DD fields that no longer exist in the live table (§11). The DD is
        // the documentation of what the DB actually is, so a field the DB dropped
        // is removed here rather than left flagged forever. The archive snapshot
        // taken before the edit preserves the removed rows' comments if needed.
        $liveNames = [];
        foreach ($liveColumns as $col) {
            $n = strtolower(trim((string) ($col['Field'] ?? '')));
            if ($n !== '') {
                $liveNames[$n] = true;
            }
        }
        $removeIdx = [];
        $removed = [];
        foreach ($this->byTable[$table]['fields'] as $name => $idx) {
            if (!isset($liveNames[strtolower((string) $name)])) {
                $removeIdx[] = $idx;
                $removed[] = (string) $name;
            }
        }
        if ($removeIdx !== []) {
            rsort($removeIdx); // delete high indices first so lower ones stay valid
            foreach ($removeIdx as $i) {
                array_splice($this->rows, $i, 1);
            }
            $this->reindex();
        }

        return ['updated' => $updated, 'added' => $added, 'removed' => $removed];
    }

    /**
     * Copy the structural columns (type/nullable/key/default) from a SHOW COLUMNS
     * row into a DD row in place, leaving Comment/Format untouched. Pads the row
     * if a detected column index sits past its current width.
     *
     * @param list<string> $row
     * @param array<string,mixed> $col
     */
    private function writeSchemaCells(array &$row, array $col): void
    {
        $set = static function (array &$r, int $i, string $v): void {
            while (count($r) <= $i) {
                $r[] = '';
            }
            $r[$i] = $v;
        };
        $default = $col['Default'] ?? null;
        $set($row, $this->ci['type'], (string) ($col['Type'] ?? ''));
        $set($row, $this->ci['nullable'], (string) ($col['Null'] ?? ''));
        $set($row, $this->ci['key'], (string) ($col['Key'] ?? ''));
        $set($row, $this->ci['default'], $default === null ? '' : (string) $default);
    }

    /**
     * Row index at which to insert new field rows for $table: after its last
     * documented field (or description row) but before the read-only foreign-keys
     * summary, so that marker stays the final row of the block (§11).
     */
    private function fieldInsertPos(string $table): int
    {
        $block = $this->byTable[$table];
        $last = $block['desc'];
        foreach ($block['fields'] as $idx) {
            $last = max($last, $idx);
        }
        $fk = $block['fkeys'];
        if ($fk >= 0 && $fk > $last) {
            return $fk; // splice before the FK summary → it remains last
        }
        return $last >= 0 ? $last + 1 : count($this->rows);
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
            // Optional. Absent → index 8 (past the standard columns) reads '',
            // so a DD without a Format column simply marks no formats.
            'format'   => $find(['format'], 8),
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
