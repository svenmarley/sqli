<?php

declare(strict_types=1);

namespace Sqli\Export;

/**
 * Writes the arranged data view out as a CSV file (spec Section 19).
 *
 * Deliberately pure: it is handed a header row and already-rendered string
 * cells, so every decision about *what* to export — column order, hidden
 * columns, the active sort, display formats — stays in App/State where the
 * arrangement lives. That keeps this class headlessly testable and means the
 * exporter can never disagree with what is on screen.
 */
final class CsvExport
{
    /** Characters allowed in a generated file name; everything else folds to `_`. */
    private const SAFE = '/[^A-Za-z0-9._-]+/';

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
     * Write $header + $rows to $path. Returns null on success, else a
     * human-readable reason for the status line.
     *
     * Written to a sibling temp file and renamed into place, so a failure
     * part-way through leaves no half-written CSV for the user to open and
     * mistake for the full export — the same approach `DataDictionary::save()`
     * uses.
     *
     * @param list<string>       $header
     * @param list<list<string>> $rows
     */
    public static function write(string $path, array $header, array $rows): ?string
    {
        $tmp = $path . '.tmp';
        $h = @fopen($tmp, 'w');
        if ($h === false) {
            return 'cannot write to ' . dirname($path) . ' (' . self::lastError() . ')';
        }
        // escape: '' opts out of PHP's non-standard backslash escaping, which
        // would otherwise corrupt a value ending in a backslash. Required from
        // 8.1 on anyway — the default is deprecated.
        $ok = fputcsv($h, $header, escape: '') !== false;
        foreach ($rows as $row) {
            if (!$ok) {
                break;
            }
            $ok = fputcsv($h, $row, escape: '') !== false;
        }
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
