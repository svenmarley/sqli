<?php

declare(strict_types=1);

namespace Sqli2\Input;

/**
 * Multi-line SQL input model (spec §4 "Multi-line input").
 *
 * Holds the ordered set of input lines (top to bottom) and which one is active.
 * The active line is edited through a {@see LineEditor} — the single-line
 * primitive — while the other lines are committed strings. Enter on a line with
 * no terminator pushes a fresh line below; Up/Down move between lines; Backspace
 * at column 0 merges the active line into the one above. The renderer reads
 * {@see lines()} and {@see activeIndex()} to lay out the growing input region.
 */
final class InputBuffer
{
    /** @var list<string> committed text of every line, top to bottom */
    private array $lines = [''];
    private int $active = 0;
    private LineEditor $editor;

    public function __construct()
    {
        $this->editor = new LineEditor();
    }

    /** The editor for the active line (char insert/delete, cursor moves). */
    public function editor(): LineEditor
    {
        return $this->editor;
    }

    public function activeIndex(): int
    {
        return $this->active;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    /** @return list<string> every line, with the active line reflecting live edits */
    public function lines(): array
    {
        $this->commit();
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lineCount() === 1 && $this->editor->isEmpty();
    }

    /** Live text of the active line. */
    public function activeText(): string
    {
        return $this->editor->text();
    }

    /** All lines joined by a single space, trimmed (the stored/executed form). */
    public function combinedText(): string
    {
        $this->commit();
        return trim(implode(' ', array_map('trim', $this->lines)));
    }

    /** Push the active line up and open a fresh empty line below it. */
    public function pushLine(): void
    {
        $this->commit();
        array_splice($this->lines, $this->active + 1, 0, ['']);
        $this->active++;
        $this->editor->setText('');
    }

    /** Move to the previous line. Returns false if already on the top line. */
    public function moveUp(): bool
    {
        if ($this->active === 0) {
            return false;
        }
        $this->commit();
        $this->active--;
        $this->editor->setText($this->lines[$this->active]);
        return true;
    }

    /** Move to the next line. Returns false if already on the bottom line. */
    public function moveDown(): bool
    {
        if ($this->active >= count($this->lines) - 1) {
            return false;
        }
        $this->commit();
        $this->active++;
        $this->editor->setText($this->lines[$this->active]);
        return true;
    }

    /**
     * Merge the active line into the one above when the cursor is at column 0.
     * Returns false when not applicable (caller should do a normal backspace).
     */
    public function backspaceMerge(): bool
    {
        if ($this->active === 0 || $this->editor->cursor() !== 0) {
            return false;
        }
        $this->commit();
        $tail = $this->lines[$this->active];
        $prev = $this->lines[$this->active - 1];
        array_splice($this->lines, $this->active, 1);
        $this->active--;
        $this->lines[$this->active] = $prev . $tail;
        $this->editor->setText($this->lines[$this->active], mb_strlen($prev));
        return true;
    }

    /** Collapse to a single line holding $text (history load). */
    public function setSingle(string $text): void
    {
        $this->lines = [$text];
        $this->active = 0;
        $this->editor->setText($text);
    }

    /**
     * Replace the buffer with the given lines, active line at the bottom.
     *
     * @param list<string> $lines
     */
    public function setLines(array $lines): void
    {
        $this->lines = $lines === [] ? [''] : array_values($lines);
        $this->active = count($this->lines) - 1;
        $this->editor->setText($this->lines[$this->active]);
    }

    /** Reset to one empty line. */
    public function clearAll(): void
    {
        $this->lines = [''];
        $this->active = 0;
        $this->editor->clear();
    }

    private function commit(): void
    {
        $this->lines[$this->active] = $this->editor->text();
    }
}
