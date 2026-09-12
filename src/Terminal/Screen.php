<?php

declare(strict_types=1);

namespace Sqli\Terminal;

/**
 * Double-buffered character grid with differential flushing.
 *
 * The renderer draws a full frame into the BACK buffer every tick (clear +
 * draw). flush() then compares the back buffer to the FRONT buffer (what is
 * physically on screen) and emits ANSI only for the cells that actually
 * changed — moving the cursor as little as possible and re-issuing an SGR
 * sequence only when the style differs from the previously written cell.
 *
 * This is the core performance mechanism: idle frames cost nothing, and a
 * blinking cursor or single keystroke repaints a handful of cells, not the
 * whole screen.
 *
 * Coordinates are 0-based (x = column, y = row) at this layer; ANSI's 1-based
 * coordinates are produced only inside flush().
 */
final class Screen
{
    /** @var Cell[][] indexed [y][x] — physically on screen */
    private array $front = [];
    /** @var Cell[][] indexed [y][x] — being drawn for the next frame */
    private array $back = [];

    /**
     * Style painted into every blank cell by {@see clear()}. Defaults to the
     * terminal default; the renderer sets it to the theme's background (Base16
     * base00) so unwritten areas carry the scheme's ground instead of the
     * terminal's — essential for light themes on a dark terminal, and vice-versa.
     */
    private Style $background;

    public function __construct(
        private int $width,
        private int $height,
    ) {
        $this->background = Style::default();
        $this->allocate();
    }

    /** Set the fill style for blank cells (see {@see $background}). */
    public function setBackground(Style $style): void
    {
        $this->background = $style;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function resize(int $width, int $height): void
    {
        if ($width === $this->width && $height === $this->height) {
            return;
        }
        $this->width = max(1, $width);
        $this->height = max(1, $height);
        $this->allocate();
    }

    /** Reset the back buffer to blank cells, ready for a fresh frame. */
    public function clear(): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $this->back[$y][$x]->set(' ', $this->background);
            }
        }
    }

    /**
     * Write a string into the back buffer starting at (x, y), one grapheme per
     * cell. Clips at the right edge and ignores off-screen writes. Multibyte
     * safe; assumes single-width glyphs (CJK double-width handled in a later pass).
     */
    public function put(int $x, int $y, string $text, ?Style $style = null): void
    {
        if ($y < 0 || $y >= $this->height || $text === '') {
            return;
        }
        // Defend the diff/flush against raw control bytes in cell data: an ESC
        // or other C0/DEL byte emitted verbatim would be interpreted by the
        // terminal as a control sequence and corrupt the screen (stray/leftover
        // characters). Replace them all with a space. (UTF-8 lead/continuation
        // bytes are >= 0x80, so this never touches multibyte glyphs.)
        $text = preg_replace('/[\x00-\x1f\x7f]/', ' ', $text) ?? $text;
        $style ??= Style::default();
        $chars = mb_str_split($text);
        $col = $x;
        foreach ($chars as $ch) {
            if ($col >= $this->width) {
                break;
            }
            if ($col >= 0) {
                $this->back[$y][$col]->set($ch, $style);
            }
            $col++;
        }
    }

    /** Fill a horizontal span with a single character (e.g. a separator rule). */
    public function fill(int $x, int $y, int $length, string $char, ?Style $style = null): void
    {
        if ($length <= 0) {
            return;
        }
        $this->put($x, $y, str_repeat($char, $length), $style);
    }

    /**
     * Diff the back buffer against the front buffer and return the ANSI byte
     * stream needed to bring the physical screen up to date. The front buffer
     * is then updated to match. Returns an empty string when nothing changed.
     */
    public function flush(): string
    {
        $out = '';
        $lastStyle = null;       // style of the previously emitted cell
        $cursorRow = -1;         // 1-based row where the cursor currently sits
        $cursorCol = -1;         // 1-based col

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $b = $this->back[$y][$x];
                $f = $this->front[$y][$x];
                if ($b->equals($f)) {
                    continue;
                }

                // Reposition the cursor unless we're already there.
                $row = $y + 1;
                $col = $x + 1;
                if ($row !== $cursorRow || $col !== $cursorCol) {
                    $out .= Ansi::moveTo($row, $col);
                }

                // Re-establish style only when it changes.
                if ($lastStyle === null || !$b->style->equals($lastStyle)) {
                    $out .= $b->style->sgr();
                    $lastStyle = $b->style;
                }

                $out .= $b->char;

                // Update the front buffer to reflect what we just wrote.
                $f->set($b->char, $b->style);

                // The cursor advanced one column after printing.
                $cursorRow = $row;
                $cursorCol = $col + 1;
            }
        }

        if ($out !== '') {
            $out .= Ansi::RESET;
        }

        return $out;
    }

    /**
     * Force the next flush to repaint everything (e.g. after a resize or when
     * the terminal may have been corrupted by an external write).
     */
    public function invalidate(): void
    {
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                // Make front differ from any plausible back content.
                $this->front[$y][$x]->set("\0", new Style(fg: 'black'));
            }
        }
    }

    private function allocate(): void
    {
        $this->front = [];
        $this->back = [];
        for ($y = 0; $y < $this->height; $y++) {
            $frontRow = [];
            $backRow = [];
            for ($x = 0; $x < $this->width; $x++) {
                $frontRow[$x] = new Cell();
                $backRow[$x] = new Cell();
            }
            $this->front[$y] = $frontRow;
            $this->back[$y] = $backRow;
        }
        // Ensure the first flush paints every cell.
        $this->invalidate();
    }
}
