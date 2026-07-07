<?php

declare(strict_types=1);

namespace Sqli2\Input;

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
        $this->clamp();
    }

    public function clear(): void
    {
        $this->chars = [];
        $this->cursor = 0;
    }

    public function insert(string $grapheme): void
    {
        array_splice($this->chars, $this->cursor, 0, [$grapheme]);
        $this->cursor++;
    }

    public function backspace(): void
    {
        if ($this->cursor > 0) {
            array_splice($this->chars, $this->cursor - 1, 1);
            $this->cursor--;
        }
    }

    public function delete(): void
    {
        if ($this->cursor < count($this->chars)) {
            array_splice($this->chars, $this->cursor, 1);
        }
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
        $start = $this->lineStartAt($this->cursor);
        $row = 0;
        for ($i = 0; $i < $start; $i++) {
            if ($this->chars[$i] === "\n") {
                $row++;
            }
        }
        return [$row, $this->cursor - $start];
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
        $end = $this->cursor;
        $this->wordLeft();
        if ($end > $this->cursor) {
            array_splice($this->chars, $this->cursor, $end - $this->cursor);
        }
    }

    /** Delete the word at/after the cursor (Ctrl+Del, §4). */
    public function deleteWordRight(): void
    {
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
