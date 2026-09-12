<?php

declare(strict_types=1);

namespace Sqli\Input;

/**
 * A single editable line of text with a cursor (spec Section 4, basic editing).
 *
 * Operates on graphemes via mbstring so multibyte input behaves. Multi-line
 * SQL build-up (Enter-pushes-a-continuation-line) layers on top of this in a
 * later pass; the editor itself stays a clean single-line primitive.
 */
final class LineEditor
{
    /** @var list<string> one grapheme per element */
    private array $chars = [];
    private int $cursor = 0;

    /**
     * Selection anchor as a grapheme offset, or null when nothing is selected.
     * The other end of the selection is the live {@see cursor()}. Because the
     * buffer is one flat grapheme list (newlines included), a selection is just a
     * pair of offsets — it spans lines for free. Every edit drops the anchor so it
     * can never dangle past a text change (§4).
     */
    private ?int $anchor = null;

    public function text(): string
    {
        return implode('', $this->chars);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function isEmpty(): bool
    {
        return $this->chars === [];
    }

    public function setText(string $text, ?int $cursor = null): void
    {
        $this->chars = $text === '' ? [] : mb_str_split($text);
        $this->cursor = $cursor ?? count($this->chars);
        $this->anchor = null;
        $this->clamp();
    }

    public function clear(): void
    {
        $this->chars = [];
        $this->cursor = 0;
        $this->anchor = null;
    }

    public function insert(string $grapheme): void
    {
        $this->deleteSelection(); // typing over a selection replaces it (§4)
        array_splice($this->chars, $this->cursor, 0, [$grapheme]);
        $this->cursor++;
    }

    /**
     * Insert a run of text at the cursor — used for clipboard paste (§19). Any
     * active selection is replaced first. By default embedded newlines become hard
     * line breaks (for the multi-line cell and comment editors); pass
     * $allowNewlines = false for single-line fields (a DD path, a JSON key/value)
     * so a stray newline can't corrupt the value.
     */
    public function insertText(string $text, bool $allowNewlines = true): void
    {
        if ($text === '') {
            return;
        }
        $text = $allowNewlines
            ? str_replace(["\r\n", "\r"], "\n", $text)   // normalize line breaks
            : str_replace(["\r\n", "\r", "\n"], '', $text); // single line: drop them
        if ($text === '') {
            return;
        }
        $this->deleteSelection();
        $graphemes = mb_str_split($text);
        array_splice($this->chars, $this->cursor, 0, $graphemes);
        $this->cursor += count($graphemes);
    }

    public function backspace(): void
    {
        if ($this->deleteSelection()) {
            return;
        }
        if ($this->cursor > 0) {
            array_splice($this->chars, $this->cursor - 1, 1);
            $this->cursor--;
        }
    }

    public function delete(): void
    {
        if ($this->deleteSelection()) {
            return;
        }
        if ($this->cursor < count($this->chars)) {
            array_splice($this->chars, $this->cursor, 1);
        }
    }

    // --- selection (§4) -----------------------------------------------------
    //
    // A selection spans from the anchor offset to the live cursor. Callers drive
    // it: startSelection() before a shifted move, clearSelection() on an unshifted
    // move, and act on it with selectedText()/deleteSelection()/selectAll().

    /** Anchor the selection at the current cursor, unless one already exists. */
    public function startSelection(): void
    {
        if ($this->anchor === null) {
            $this->anchor = $this->cursor;
        }
    }

    public function clearSelection(): void
    {
        $this->anchor = null;
    }

    /** True when an anchor exists and the cursor has moved away from it. */
    public function hasSelection(): bool
    {
        return $this->anchor !== null && $this->anchor !== $this->cursor;
    }

    /**
     * The selection normalized to [start, end] grapheme offsets (start ≤ end), or
     * null when nothing is selected.
     *
     * @return array{0:int,1:int}|null
     */
    public function selectionRange(): ?array
    {
        if (!$this->hasSelection()) {
            return null;
        }
        $a = (int) $this->anchor;
        return $a < $this->cursor ? [$a, $this->cursor] : [$this->cursor, $a];
    }

    /** Select the whole buffer (Ctrl+A): anchor at the start, cursor at the end. */
    public function selectAll(): void
    {
        $this->anchor = 0;
        $this->cursor = count($this->chars);
    }

    /** The selected text ('' when nothing is selected). */
    public function selectedText(): string
    {
        $r = $this->selectionRange();
        if ($r === null) {
            return '';
        }
        return implode('', array_slice($this->chars, $r[0], $r[1] - $r[0]));
    }

    /**
     * Delete the selection, parking the cursor at its start. Returns false (and
     * just drops the anchor) when nothing is selected, so callers can fall back to
     * a normal backspace/delete.
     */
    public function deleteSelection(): bool
    {
        $r = $this->selectionRange();
        $this->anchor = null;
        if ($r === null) {
            return false;
        }
        array_splice($this->chars, $r[0], $r[1] - $r[0]);
        $this->cursor = $r[0];
        return true;
    }

    // --- mouse / offset mapping (§4) ----------------------------------------

    /** Move the cursor to a grapheme offset (clamped), leaving the anchor alone. */
    public function setCursorOffset(int $offset): void
    {
        $this->cursor = max(0, min($offset, count($this->chars)));
    }

    /**
     * The flat grapheme offset of display [row, col] (row = line index, col =
     * column within that line). Used to turn a mouse click into a cursor position.
     * Clamped to the addressed line's length and the buffer as a whole.
     */
    public function offsetAt(int $row, int $col): int
    {
        $n = count($this->chars);
        $line = 0;
        $i = 0;
        while ($line < $row && $i < $n) {
            if ($this->chars[$i] === "\n") {
                $line++;
            }
            $i++;
        }
        // $i is now at the first grapheme of the target line (or end of buffer).
        $lineEnd = $this->lineEndAt($i);
        return min($i + max(0, $col), $lineEnd);
    }

    public function left(): void
    {
        if ($this->cursor > 0) {
            $this->cursor--;
        }
    }

    public function right(): void
    {
        if ($this->cursor < count($this->chars)) {
            $this->cursor++;
        }
    }

    public function home(): void
    {
        $this->cursor = 0;
    }

    public function end(): void
    {
        $this->cursor = count($this->chars);
    }

    // --- multi-line helpers (used by the DD comment editor, §11) ------------
    //
    // The buffer stays a flat grapheme list; a newline is just a "\n" grapheme.
    // These methods give line-aware navigation/rendering on top of that, so the
    // single-line callers are entirely unaffected.

    /** Insert a hard line break at the cursor (Shift+Enter). */
    public function insertNewline(): void
    {
        $this->insert("\n");
    }

    /** Index of the first grapheme on the line containing $pos. */
    private function lineStartAt(int $pos): int
    {
        while ($pos > 0 && $this->chars[$pos - 1] !== "\n") {
            $pos--;
        }
        return $pos;
    }

    /** Index just past the last grapheme on the line containing $pos (at the \n or end). */
    private function lineEndAt(int $pos): int
    {
        $n = count($this->chars);
        while ($pos < $n && $this->chars[$pos] !== "\n") {
            $pos++;
        }
        return $pos;
    }

    /** Move to the first column of the current line. */
    public function lineHome(): void
    {
        $this->cursor = $this->lineStartAt($this->cursor);
    }

    /** Move to the last column of the current line (before its newline). */
    public function lineEnd(): void
    {
        $this->cursor = $this->lineEndAt($this->cursor);
    }

    /** Move up one line, preserving the column where the line is long enough. */
    public function upLine(): void
    {
        $start = $this->lineStartAt($this->cursor);
        if ($start === 0) {
            return; // already on the first line
        }
        $col = $this->cursor - $start;
        $prevStart = $this->lineStartAt($start - 1); // $start-1 is the previous \n
        $prevLen = ($start - 1) - $prevStart;
        $this->cursor = $prevStart + min($col, $prevLen);
    }

    /** Move down one line, preserving the column where the line is long enough. */
    public function downLine(): void
    {
        $start = $this->lineStartAt($this->cursor);
        $end = $this->lineEndAt($this->cursor);
        if ($end >= count($this->chars)) {
            return; // already on the last line
        }
        $col = $this->cursor - $start;
        $nextStart = $end + 1;               // skip the \n
        $nextLen = $this->lineEndAt($nextStart) - $nextStart;
        $this->cursor = $nextStart + min($col, $nextLen);
    }

    /** @return list<string> the text split into display lines. */
    public function lines(): array
    {
        return explode("\n", $this->text());
    }

    /**
     * The cursor as a [lineIndex, column] pair, for rendering a multi-line box.
     *
     * @return array{0:int,1:int}
     */
    public function cursorRowCol(): array
    {
        return $this->rowColAt($this->cursor);
    }

    /**
     * The [lineIndex, column] of an arbitrary grapheme offset — used to place the
     * cursor and to map selection endpoints for highlighting (§4).
     *
     * @return array{0:int,1:int}
     */
    public function rowColAt(int $offset): array
    {
        $offset = max(0, min($offset, count($this->chars)));
        $start = $this->lineStartAt($offset);
        $row = 0;
        for ($i = 0; $i < $start; $i++) {
            if ($this->chars[$i] === "\n") {
                $row++;
            }
        }
        return [$row, $offset - $start];
    }

    public function wordLeft(): void
    {
        while ($this->cursor > 0 && $this->chars[$this->cursor - 1] === ' ') {
            $this->cursor--;
        }
        while ($this->cursor > 0 && $this->chars[$this->cursor - 1] !== ' ') {
            $this->cursor--;
        }
    }

    public function wordRight(): void
    {
        $n = count($this->chars);
        while ($this->cursor < $n && $this->chars[$this->cursor] === ' ') {
            $this->cursor++;
        }
        while ($this->cursor < $n && $this->chars[$this->cursor] !== ' ') {
            $this->cursor++;
        }
    }

    /** Delete the word before the cursor (Ctrl+Backspace, §4). */
    public function deleteWordLeft(): void
    {
        if ($this->deleteSelection()) {
            return;
        }
        $end = $this->cursor;
        $this->wordLeft();
        if ($end > $this->cursor) {
            array_splice($this->chars, $this->cursor, $end - $this->cursor);
        }
    }

    /** Delete the word at/after the cursor (Ctrl+Del, §4). */
    public function deleteWordRight(): void
    {
        if ($this->deleteSelection()) {
            return;
        }
        $start = $this->cursor;
        $this->wordRight();
        $end = $this->cursor;
        $this->cursor = $start;
        if ($end > $start) {
            array_splice($this->chars, $start, $end - $start);
        }
    }

    private function clamp(): void
    {
        $n = count($this->chars);
        $this->cursor = max(0, min($this->cursor, $n));
    }
}
