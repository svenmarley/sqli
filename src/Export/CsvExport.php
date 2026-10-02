<?php

declare(strict_types=1);

namespace Sqli\Export;

/**
 * Writes result rows out as CSV (spec Section 19, and `-e` scripting mode).
 *
 * Deliberately pure: it is handed a header row and already-rendered string
 * cells, so every decision about *what* to export — column order, hidden
 * columns, the active sort, display formats — stays in App/State where the
 * arrangement lives. That keeps this class headlessly testable and means the
 * exporter can never disagree with what is on screen.
 *
 * It is also the one place CSV is spelled, for both the grid's export and
 * `-e`, so the two can never disagree about quoting.
 *
 * **NULL.** CSV has no NULL, and an empty field cannot also mean `''`. A PHP
 * `null` cell is written as the bare word `NULL` — what the grid shows — and a
 * *string* that happens to read `NULL` is force-quoted as `"NULL"`, so the two
 * stay apart in the file. A reader that treats every field as text sees the
 * same four letters either way; one that cares can tell them apart.
 */
final class CsvExport
{
    /** Characters allowed in a generated file name; everything else folds to `_`. */
    private const SAFE = '/[^A-Za-z0-9._-]+/';

    /** How a SQL NULL is written: the bare word, never quoted. */
    public const NULL_TOKEN = 'NULL';

    /**
     * Auto-generated name for an export: `<db>.<table>-YYYYmmdd-HHMMSS.csv`.
     *
     * The timestamp is what makes this safe to fire off a keystroke at a time —
     * a second export never silently overwrites the first. Either part may be
     * empty (a join or a non-SELECT has no single table; there may be no
     * database selected), so the stem degrades gracefully rather than producing
     * a name that starts or ends with a stray dot.
     *
     * @param int|null $now unix timestamp, for tests; defaults to the wall clock
     */
    public static function fileName(string $db, string $table, ?int $now = null): string
    {
        $parts = [];
        foreach ([$db, $table] as $part) {
            $clean = trim(preg_replace(self::SAFE, '_', $part) ?? '', '._-');
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
        $stem = $parts === [] ? 'sqli-export' : implode('.', $parts);
        return $stem . '-' . date('Ymd-His', $now ?? time()) . '.csv';
    }

    /**
     * One CSV record, newline included.
     *
     * Quoting follows what `fputcsv()` did here before (with `escape: ''`), so
     * existing exports are byte-for-byte unchanged: a field is enclosed when it
     * holds a comma, a quote, CR, LF, a tab or a space, and quotes inside are
     * doubled. The one addition is NULL — see the class comment. No backslash
     * escaping: a value ending in `\` is written as it is.
     *
     * @param list<string|int|float|null> $fields
     */
    public static function line(array $fields): string
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f === null) {
                $out[] = self::NULL_TOKEN;
                continue;
            }
            $f = (string) $f;
            if ($f === self::NULL_TOKEN || strpbrk($f, ",\"\r\n\t ") !== false) {
                $f = '"' . str_replace('"', '""', $f) . '"';
            }
            $out[] = $f;
        }
        return implode(',', $out) . "\n";
    }

    /**
     * Write one block — an optional header row, then the rows — to an open
     * stream. Returns false as soon as a write fails (a closed pipe, a full
     * disk), so the caller can stop instead of writing into nothing.
     *
     * @param list<string>|null                    $header null = no header row
     * @param iterable<list<string|int|float|null>> $rows
     * @param resource                             $h
     */
    public static function stream($h, ?array $header, iterable $rows): bool
    {
        if ($header !== null && @fwrite($h, self::line($header)) === false) {
            return false;
        }
        foreach ($rows as $row) {
            if (@fwrite($h, self::line($row)) === false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Write $header + $rows to $path. Returns null on success, else a
     * human-readable reason for the status line.
     *
     * @param list<string>                     $header
     * @param list<list<string|int|float|null>> $rows
     */
    public static function write(string $path, array $header, array $rows): ?string
    {
        return self::writeWith($path, static fn ($h): bool => self::stream($h, $header, $rows));
    }

    /**
     * Fill $path through $fill, atomically. $fill is handed the open stream and
     * returns false when a write failed. Returns null on success, else a
     * human-readable reason.
     *
     * Written to a sibling temp file and renamed into place, so a failure
     * part-way through leaves no half-written CSV for the user to open and
     * mistake for the full export — the same approach `DataDictionary::save()`
     * uses.
     *
     * @param callable(resource):bool $fill
     */
    public static function writeWith(string $path, callable $fill): ?string
    {
        $tmp = $path . '.tmp';
        $h = @fopen($tmp, 'w');
        if ($h === false) {
            return 'cannot write to ' . dirname($path) . ' (' . self::lastError() . ')';
        }
        $ok = $fill($h);
        // Flush before the rename so a full disk surfaces here and not silently.
        $ok = $ok && fflush($h);
        fclose($h);
        if (!$ok) {
            @unlink($tmp);
            return 'write failed (disk full or permissions?)';
        }
        if (@rename($tmp, $path)) {
            return null;
        }
        // Windows won't rename onto an existing file; retry via unlink. The
        // timestamped name makes this rare, but two exports inside one second
        // land on the same name.
        if (@unlink($path) && @rename($tmp, $path)) {
            return null;
        }
        @unlink($tmp);
        return 'could not replace ' . basename($path) . ' (' . self::lastError() . ')';
    }

    /** The OS reason for the last failed call, or a neutral fallback. */
    private static function lastError(): string
    {
        $e = error_get_last();
        $msg = $e['message'] ?? '';
        // PHP prefixes the function name; the tail is the part a user can act on.
        if (($pos = strrpos($msg, ': ')) !== false) {
            $msg = substr($msg, $pos + 2);
        }
        return $msg !== '' ? $msg : 'unknown error';
    }
}
