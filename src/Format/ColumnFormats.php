<?php

declare(strict_types=1);

namespace Sqli\Format;

/**
 * Registry of the available column formats (spec §6 "Column formatting").
 *
 * Pure and stateless. A column is marked with a format **id**; the grid, detail
 * view, cell inspector, and clipboard render through {@see get()}'s display
 * transform, and the cell editor round-trips edits through its parse transform.
 *
 * Currently ships one format — `epoch` (Unix timestamp → local datetime) — but
 * the shape is deliberately open so more can be added without touching callers.
 */
final class ColumnFormats
{
    public const EPOCH = 'epoch';

    /** Display/edit pattern for epoch values (local timezone). */
    private const EPOCH_PATTERN = 'Y-m-d H:i:s';

    /** @var array<string,ColumnFormat>|null */
    private static ?array $registry = null;

    /** The format for $id, or null when $id is unknown / empty. */
    public static function get(?string $id): ?ColumnFormat
    {
        if ($id === null || $id === '') {
            return null;
        }
        return self::registry()[$id] ?? null;
    }

    public static function has(?string $id): bool
    {
        return self::get($id) !== null;
    }

    /** @return list<string> the known format ids */
    public static function ids(): array
    {
        return array_keys(self::registry());
    }

    /** @return array<string,ColumnFormat> */
    private static function registry(): array
    {
        return self::$registry ??= [
            self::EPOCH => new ColumnFormat(
                self::EPOCH,
                'Epoch → local time',
                // Display: an integer Unix timestamp becomes a local datetime.
                // Anything that isn't a plain integer is shown unchanged so a
                // stray/non-numeric value is never mangled.
                static function (string $raw): string {
                    $t = trim($raw);
                    if ($t === '' || preg_match('/^-?\d+$/', $t) !== 1) {
                        return $raw;
                    }
                    return date(self::EPOCH_PATTERN, (int) $t);
                },
                // Parse: an empty string passes through (clears/keeps as-is); a
                // bare integer is already an epoch; otherwise parse the datetime
                // to a timestamp. Unparseable input → null (edit rejected).
                static function (string $input): ?string {
                    $t = trim($input);
                    if ($t === '') {
                        return '';
                    }
                    if (preg_match('/^-?\d+$/', $t) === 1) {
                        return $t;
                    }
                    $ts = strtotime($t);
                    return $ts === false ? null : (string) $ts;
                },
            ),
        ];
    }
}
