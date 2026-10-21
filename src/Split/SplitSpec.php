<?php

declare(strict_types=1);

namespace Sqli\Split;

/**
 * How one column's text breaks into a table (§10a): a row separator, and for
 * each field a header and the separator that follows it.
 *
 * Separators are **positional**: field 1 ends at the first `sep1`, field 2 at
 * the next `sep2`, and so on, so `@` and `@!` can sit side by side in one row.
 * The last field has no separator — it is the rest of the row. A backslash in
 * the data escapes the character after it.
 *
 * Stored per table in `sqli_formatting.json` as `{rows, fields:[{name, sep}]}`,
 * and edited as the plain text {@see toText()} writes and {@see parse()} reads.
 */
final class SplitSpec
{
    public const ESCAPE = '\\';

    /**
     * @param list<array{name:string,sep:string}> $fields the last one's sep is ''
     */
    public function __construct(
        public readonly string $rowSep,
        public readonly array $fields,
    ) {
    }

    /** @return list<string> */
    public function headers(): array
    {
        return array_map(static fn (array $f): string => $f['name'], $this->fields);
    }

    public function fieldCount(): int
    {
        return count($this->fields);
    }

    /** The separator that ends field $i ('' for the last one). */
    public function sep(int $i): string
    {
        return $this->fields[$i]['sep'] ?? '';
    }

    /** @return array{rows:string,fields:list<array{name:string,sep:string}>} */
    public function toArray(): array
    {
        return ['rows' => $this->rowSep, 'fields' => $this->fields];
    }

    /** From the stored form; null when it is not a usable definition. */
    public static function fromArray(mixed $a): ?self
    {
        if (!is_array($a) || !is_array($a['fields'] ?? null)) {
            return null;
        }
        $fields = [];
        foreach ($a['fields'] as $f) {
            if (!is_array($f)) {
                return null;
            }
            $fields[] = ['name' => (string) ($f['name'] ?? ''), 'sep' => (string) ($f['sep'] ?? '')];
        }
        $rows = is_string($a['rows'] ?? null) ? $a['rows'] : "\n";
        $problem = self::problem($rows, $fields);
        return $problem === null ? new self($rows, $fields) : null;
    }

    /**
     * Read the editable text form. Returns the spec, or a message saying what
     * is wrong and on which line.
     */
    public static function parse(string $text): self|string
    {
        $rows = "\n";
        $fields = [];
        $lineNo = 0;
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            $lineNo++;
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#')) {
                continue;
            }
            if (preg_match('/^rows\s*=\s*(.*)$/i', $t, $m)) {
                $v = self::unquote(trim($m[1]));
                if ($v === null) {
                    return 'Line ' . $lineNo . ': the row separator must be in double quotes, like rows = "\n"';
                }
                $rows = $v;
                continue;
            }
            if (preg_match('/^(.*?)\s*("(?:[^"\\\\]|\\\\.)*")$/', $t, $m)) {
                $sep = self::unquote($m[2]);
                if ($sep === null) {
                    return 'Line ' . $lineNo . ': could not read the separator ' . $m[2];
                }
                $fields[] = ['name' => trim($m[1]), 'sep' => $sep, 'line' => $lineNo];
                continue;
            }
            if (str_contains($t, '"')) {
                return 'Line ' . $lineNo . ': a separator goes at the end of the line, in double quotes';
            }
            $fields[] = ['name' => $t, 'sep' => '', 'line' => $lineNo];
        }

        $clean = [];
        foreach ($fields as $i => $f) {
            if ($f['name'] === '') {
                $f['name'] = 'Field ' . ($i + 1);
            }
            $last = $i === count($fields) - 1;
            if (!$last && $f['sep'] === '') {
                return 'Line ' . $f['line'] . ': "' . $f['name'] . '" needs a separator after it'
                    . ' — only the last field leaves it off';
            }
            if ($last && $f['sep'] !== '') {
                return 'Line ' . $f['line'] . ': the last field takes the rest of the row, so it has no separator';
            }
            $clean[] = ['name' => $f['name'], 'sep' => $f['sep']];
        }
        return self::problem($rows, $clean) ?? new self($rows, $clean);
    }

    /**
     * What makes a definition unusable, or null when it is fine.
     *
     * @param list<array{name:string,sep:string}> $fields
     */
    private static function problem(string $rows, array $fields): ?string
    {
        if ($fields === []) {
            return 'No fields: add one line per field, header first';
        }
        if ($rows === '') {
            return 'The row separator cannot be empty';
        }
        if (str_starts_with($rows, self::ESCAPE)) {
            return 'The row separator cannot start with a backslash — that is the escape character';
        }
        foreach ($fields as $i => $f) {
            $sep = $f['sep'];
            if ($i === count($fields) - 1) {
                break;
            }
            if ($sep === '') {
                return '"' . $f['name'] . '" needs a separator after it';
            }
            if (str_starts_with($sep, self::ESCAPE)) {
                return 'The separator after "' . $f['name'] . '" cannot start with a backslash — that is the escape character';
            }
            if (str_contains($sep, $rows)) {
                return 'The separator after "' . $f['name'] . '" contains the row separator';
            }
        }
        return null;
    }

    /** The editable text form, with a short explanation on top. */
    public function toText(string $title): string
    {
        $out = self::header($title);
        $out .= 'rows = ' . self::quote($this->rowSep) . "\n";
        $width = 0;
        foreach ($this->fields as $f) {
            $width = max($width, mb_strlen($f['name']));
        }
        foreach ($this->fields as $f) {
            $out .= $f['sep'] === ''
                ? $f['name'] . "\n"
                : str_pad($f['name'], $width + strlen($f['name']) - mb_strlen($f['name'])) . '  ' . self::quote($f['sep']) . "\n";
        }
        return $out;
    }

    /**
     * A starting point for a column with no definition yet: the explanation,
     * the cell's first line to work from, and an example to overwrite.
     */
    public static function template(string $title, string $sample): string
    {
        $first = (string) (preg_split('/\r\n|\n|\r/', $sample)[0] ?? '');
        if (mb_strlen($first) > 200) {
            $first = mb_substr($first, 0, 200) . '…';
        }
        return self::header($title)
            . ($first !== '' ? "# This cell's first line:\n#   " . $first . "\n#\n" : '')
            . "rows = \"\\n\"\n"
            . "Field 1  \"@\"\n"
            . "Field 2\n";
    }

    private static function header(string $title): string
    {
        return '# Table view for ' . $title . "\n"
            . "#\n"
            . "# One line per field: its header, then the separator that follows it,\n"
            . "# in double quotes. The last field has no separator. Inside the quotes:\n"
            . "# \\n newline, \\r carriage return, \\t tab, \\\" quote, \\\\ backslash.\n"
            . "# In the data, a backslash escapes the character after it.\n"
            . "#\n";
    }

    /** `"…"` with \n \r \t \" \\ decoded; null when $s is not one quoted string. */
    private static function unquote(string $s): ?string
    {
        if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/s', $s, $m)) {
            return null;
        }
        return (string) preg_replace_callback('/\\\\(.)/s', static fn (array $x): string => match ($x[1]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            default => $x[1],
        }, $m[1]);
    }

    private static function quote(string $s): string
    {
        return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t']) . '"';
    }
}
