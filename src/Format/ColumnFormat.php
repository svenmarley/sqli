<?php

declare(strict_types=1);

namespace Sqli\Format;

/**
 * A per-column display format (spec §6 "Column formatting", backlog S).
 *
 * A format decouples what is **shown** from what is **stored**: {@see display()}
 * turns the raw stored value into the string the user sees, and {@see parse()} is
 * its inverse — turning an edited display string back into the raw value written
 * to the UPDATE. The raw value always remains the source of truth; formatting is
 * a pure, reversible pair so later formats (byte sizes, bool labels, enum→label,
 * …) reuse the same mechanism.
 */
final class ColumnFormat
{
    /**
     * @param callable(string):string  $display raw stored value → shown string
     * @param callable(string):?string $parse   shown/edited string → raw value,
     *        or null when the input can't be converted (edit is rejected)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        private $display,
        private $parse,
    ) {
    }

    /** Render the raw stored value for display. */
    public function display(string $raw): string
    {
        return ($this->display)($raw);
    }

    /** Convert an edited display string back to the raw value, or null if invalid. */
    public function parse(string $input): ?string
    {
        return ($this->parse)($input);
    }
}
