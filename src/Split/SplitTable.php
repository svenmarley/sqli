<?php

declare(strict_types=1);

namespace Sqli\Split;

/**
 * One cell's text broken into rows and fields by a {@see SplitSpec}, editable,
 * and joined back into text (§10a).
 *
 * **What was not touched comes back byte for byte.** Each row keeps its raw
 * text, and each field its raw (still-escaped) piece. A row nobody edited is
 * written back as it was read. In an edited row, only the fields whose value
 * changed are re-escaped; the rest keep their original piece. So opening a
 * cell and closing it again never rewrites it, and an edit to one field never
 * reformats its neighbours.
 *
 * A row may stop early — the trailing fields are optional. They show as blank,
 * and stay absent on the way back unless something is typed into them.
 */
final class SplitTable
{
    /**
     * @var list<array{raw:string,pieces:list<string>,orig:list<string>,cells:list<string>}>
     *   raw: the row as read ('' for a new row); pieces: its raw field texts;
     *   orig: the fields as read, unescaped; cells: the fields now, unescaped
     *   and padded to the field count
     */
    private array $rows = [];

    /** The value ended with a row separator. */
    private bool $trailing = false;

    public function __construct(private SplitSpec $spec, string $value)
    {
        $raws = self::splitRows($value, $spec->rowSep);
        if ($raws !== [] && end($raws) === '' && $value !== '') {
            array_pop($raws);
            $this->trailing = true;
        }
        foreach ($raws as $raw) {
            $pieces = $this->splitFields($raw);
            $orig = array_map([self::class, 'unescape'], $pieces);
            $this->rows[] = [
                'raw' => $raw,
                'pieces' => $pieces,
                'orig' => $orig,
                'cells' => $this->padded($orig),
            ];
        }
    }

    public function spec(): SplitSpec
    {
        return $this->spec;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** @return list<string> row $r's fields, one per header */
    public function cells(int $r): array
    {
        return $this->rows[$r]['cells'] ?? [];
    }

    /** @return list<list<string>> */
    public function allCells(): array
    {
        return array_map(static fn (array $row): array => $row['cells'], $this->rows);
    }

    /** True when row $r is new or a field in it changed. */
    public function isChanged(int $r): bool
    {
        $row = $this->rows[$r] ?? null;
        // A row read from the value always has at least one piece, so no
        // pieces means the row was added here.
        return $row !== null && ($row['pieces'] === [] || $row['cells'] !== $this->padded($row['orig']));
    }

    public function set(int $r, int $c, string $value): void
    {
        if (isset($this->rows[$r]['cells'][$c])) {
            $this->rows[$r]['cells'][$c] = $value;
        }
    }

    /** Insert a blank row so that it becomes row $at. */
    public function insertRow(int $at): void
    {
        $at = max(0, min($at, count($this->rows)));
        array_splice($this->rows, $at, 0, [[
            'raw' => '',
            'pieces' => [],
            'orig' => [],
            'cells' => $this->padded([]),
        ]]);
    }

    public function deleteRow(int $r): void
    {
        if (isset($this->rows[$r])) {
            array_splice($this->rows, $r, 1);
        }
    }

    /** The text to write back. */
    public function join(): string
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[] = $this->joinRow($row);
        }
        $text = implode($this->spec->rowSep, $out);
        return $this->trailing && $out !== [] ? $text . $this->spec->rowSep : $text;
    }

    // ------------------------------------------------------------ parsing ---

    /**
     * Cut $value at every unescaped $sep, keeping each piece raw.
     *
     * @return list<string>
     */
    public static function splitRows(string $value, string $sep): array
    {
        if ($value === '') {
            return [];
        }
        $out = [];
        $start = 0;
        $len = strlen($value);
        $sl = strlen($sep);
        for ($p = 0; $p < $len;) {
            if ($value[$p] === SplitSpec::ESCAPE) {
                $p += 2;
                continue;
            }
            if (substr_compare($value, $sep, $p, $sl) === 0) {
                $out[] = substr($value, $start, $p - $start);
                $p += $sl;
                $start = $p;
                continue;
            }
            $p++;
        }
        $out[] = (string) substr($value, $start);
        return $out;
    }

    /**
     * One row's raw field pieces, positionally: field $i ends at the first
     * unescaped separator $i; the last field is the rest of the row. A row
     * that runs out early has fewer pieces than fields.
     *
     * @return list<string>
     */
    private function splitFields(string $raw): array
    {
        $n = $this->spec->fieldCount();
        $pieces = [];
        $buf = '';
        $i = 0;
        $len = strlen($raw);
        for ($p = 0; $p < $len;) {
            if ($raw[$p] === SplitSpec::ESCAPE && $p + 1 < $len) {
                $buf .= $raw[$p] . $raw[$p + 1];
                $p += 2;
                continue;
            }
            if ($i < $n - 1) {
                $sep = $this->spec->sep($i);
                if (substr_compare($raw, $sep, $p, strlen($sep)) === 0) {
                    $pieces[] = $buf;
                    $buf = '';
                    $p += strlen($sep);
                    $i++;
                    continue;
                }
            }
            $buf .= $raw[$p];
            $p++;
        }
        $pieces[] = $buf;
        return $pieces;
    }

    /** Drop each escaping backslash, keeping the character after it. */
    public static function unescape(string $piece): string
    {
        if (!str_contains($piece, SplitSpec::ESCAPE)) {
            return $piece;
        }
        $out = '';
        $len = strlen($piece);
        for ($p = 0; $p < $len; $p++) {
            if ($piece[$p] === SplitSpec::ESCAPE && $p + 1 < $len) {
                $p++;
            }
            $out .= $piece[$p];
        }
        return $out;
    }

    // ------------------------------------------------------------ joining ---

    /** @param array{raw:string,pieces:list<string>,orig:list<string>,cells:list<string>} $row */
    private function joinRow(array $row): string
    {
        $origCount = count($row['pieces']);
        if ($row['cells'] === $this->padded($row['orig']) && $origCount > 0) {
            return $row['raw'];
        }
        // Every field that was there stays; beyond that, up to the last one
        // with something in it. A blanked optional field that was present is
        // still written (empty), which is what "delete is blank" means.
        $last = -1;
        foreach ($row['cells'] as $k => $v) {
            if ($v !== '') {
                $last = $k;
            }
        }
        $emit = max($origCount, $last + 1);
        $text = $this->assemble($row, $emit, false);
        if ($this->readsBack($text, $row['cells'], $emit)) {
            return $text;
        }
        // Escaping only where a separator starts was not enough for some
        // overlapping pair; escape every separator character instead.
        return $this->assemble($row, $emit, true);
    }

    /** @param array{raw:string,pieces:list<string>,orig:list<string>,cells:list<string>} $row */
    private function assemble(array $row, int $emit, bool $heavy): string
    {
        $n = $this->spec->fieldCount();
        $text = '';
        for ($k = 0; $k < $emit; $k++) {
            $value = $row['cells'][$k] ?? '';
            $piece = ($k < count($row['pieces']) && $value === ($row['orig'][$k] ?? null))
                ? $row['pieces'][$k]
                : $this->escape($value, $k < $n - 1 ? $this->spec->sep($k) : '', $heavy);
            $text .= $piece;
            if ($k < $emit - 1) {
                $text .= $this->spec->sep($k);
            }
        }
        return $text;
    }

    /** @param list<string> $cells */
    private function readsBack(string $text, array $cells, int $emit): bool
    {
        $got = $this->padded(array_map([self::class, 'unescape'], $this->splitFields($text)));
        $want = $cells;
        for ($k = $emit; $k < count($want); $k++) {
            $want[$k] = '';
        }
        return $got === $want;
    }

    /**
     * Escape a value for field position with separator $sep ('' = last field):
     * every backslash, and the first character of any separator or row
     * separator inside it. $heavy escapes every character any separator uses.
     */
    private function escape(string $value, string $sep, bool $heavy): string
    {
        $rowSep = $this->spec->rowSep;
        $heavyChars = [];
        if ($heavy) {
            foreach ($this->spec->fields as $f) {
                foreach (str_split($f['sep']) as $ch) {
                    if ($ch !== '') {
                        $heavyChars[$ch] = true;
                    }
                }
            }
            foreach (str_split($rowSep) as $ch) {
                $heavyChars[$ch] = true;
            }
        }
        $out = '';
        $len = strlen($value);
        for ($p = 0; $p < $len; $p++) {
            $ch = $value[$p];
            if ($ch === SplitSpec::ESCAPE
                || isset($heavyChars[$ch])
                || ($sep !== '' && substr_compare($value, $sep, $p, strlen($sep)) === 0)
                || substr_compare($value, $rowSep, $p, strlen($rowSep)) === 0) {
                $out .= SplitSpec::ESCAPE;
            }
            $out .= $ch;
        }
        return $out;
    }

    /**
     * @param  list<string> $cells
     * @return list<string> exactly one per field
     */
    private function padded(array $cells): array
    {
        $n = $this->spec->fieldCount();
        return array_slice(array_pad($cells, $n, ''), 0, $n);
    }
}
