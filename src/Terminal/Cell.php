<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * A single terminal cell: one visible character plus its style.
 *
 * Cells are mutable for performance — the screen buffer reuses them across
 * frames rather than allocating a fresh object per cell per frame.
 */
final class Cell
{
    public function __construct(
        public string $char = ' ',
        public Style $style = new Style(),
    ) {
    }

    public function set(string $char, Style $style): void
    {
        $this->char = $char;
        $this->style = $style;
    }

    public function reset(): void
    {
        $this->char = ' ';
        $this->style = Style::default();
    }

    public function equals(Cell $other): bool
    {
        return $this->char === $other->char && $this->style->equals($other->style);
    }
}
