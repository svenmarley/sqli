<?php

declare(strict_types=1);

namespace Sqli\Core;

/**
 * Screen geometry of the most recently drawn result grid (spec §6 mouse support).
 *
 * The renderer publishes this each frame so the event loop can map a mouse click
 * back to a row/column without knowing anything about layout. Coordinates are
 * absolute, 0-based screen cells. Lives in Core (not Render) so {@see State} can
 * hold it without inverting the Core ← Render dependency direction.
 */
final class GridLayout
{
    /**
     * @param list<array{idx:int,x:int,w:int}> $columns visible columns:
     *        visible-column index, absolute screen x, rendered width
     * @param int $markX absolute screen x of the mark-mode checkbox gutter, or
     *        -1 when mark mode is off (§22)
     * @param int $markW width of that gutter, 0 when absent
     */
    public function __construct(
        public readonly int $headerY,
        public readonly int $dataTop,
        public readonly int $dataRows,
        public readonly int $rowOffset,
        public readonly array $columns,
        public readonly int $markX = -1,
        public readonly int $markW = 0,
    ) {
    }

    /** True when screen-x falls in the mark-mode checkbox gutter (§22). */
    public function isMarkColumn(int $x): bool
    {
        return $this->markW > 0 && $x >= $this->markX && $x < $this->markX + $this->markW;
    }

    /** Visible-column index under screen-x, or null if none. */
    public function columnAt(int $x): ?int
    {
        foreach ($this->columns as $c) {
            if ($x >= $c['x'] && $x < $c['x'] + $c['w']) {
                return $c['idx'];
            }
        }
        return null;
    }
}
