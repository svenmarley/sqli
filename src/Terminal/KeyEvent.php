<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * A decoded keyboard event.
 *
 * For printable input, $key === Key::Char and $char holds the grapheme.
 * For special keys, $key identifies it and $char is empty. Modifier booleans
 * report Ctrl/Alt/Shift where the terminal exposes them (Ctrl is reliable for
 * letter combos; Shift/Alt only for keys whose sequences encode them).
 */
final class KeyEvent
{
    public function __construct(
        public readonly Key $key,
        public readonly string $char = '',
        public readonly bool $ctrl = false,
        public readonly bool $alt = false,
        public readonly bool $shift = false,
    ) {
    }

    public static function char(string $char): self
    {
        return new self(Key::Char, $char);
    }

    public static function special(Key $key, bool $ctrl = false, bool $alt = false, bool $shift = false): self
    {
        return new self($key, '', $ctrl, $alt, $shift);
    }

    /** Convenience: a printable character with no modifiers we track. */
    public function isChar(): bool
    {
        return $this->key === Key::Char;
    }
}
