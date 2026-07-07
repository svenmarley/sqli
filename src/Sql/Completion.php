<?php

declare(strict_types=1);

namespace Sqli2\Sql;

/**
 * Context-sensitive tab completion (spec §4, the 13 contexts).
 *
 * Built as a **rules table**: {@see analyze()} parses the buffer once into a
 * context, then runs an ordered list of rule methods; the first that returns a
 * proposal wins. This keeps the contexts easy to reorder/extend and avoids one
 * giant branch. The engine is schema-agnostic — table/column lookups arrive as
 * callables so this class has no DB dependency.
 *
 * Offsets are grapheme-based (mb_*) to match the editor's cursor indexing.
 */
final class Completion
{
    /** SQL command keywords offered for an empty buffer / command prefix. */
    private const COMMANDS = [
        'SELECT', 'INSERT INTO', 'UPDATE', 'DELETE FROM', 'REPLACE INTO',
        'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'SHOW', 'DESCRIBE',
        'EXPLAIN', 'USE', 'SET', 'FROM',
    ];

    /** Clause keywords offered inside a SELECT field list. */
    private const CLAUSE_KEYWORDS = ['FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT'];

    /** SHOW sub-commands (context 13). */
    private const SHOW_SUB = [
        'TABLES', 'DATABASES', 'COLUMNS FROM', 'CREATE TABLE',
        'INDEXES FROM', 'STATUS', 'VARIABLES', 'PROCESSLIST',
    ];

    /** Keywords that end a SELECT field list. */
    private const CLAUSE_BOUNDARY = '/\b(WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT)\b/iu';

    /**
     * @param callable():list<string>        $tablesFn
     * @param callable(string):list<string>  $columnsFn
     */
    public function analyze(string $text, int $cursor, callable $tablesFn, callable $columnsFn): ?CompletionResult
    {
        $ctx = $this->context($text, $cursor, $tablesFn, $columnsFn);

        return $this->ruleEmpty($ctx)
            ?? $this->ruleCommandPrefix($ctx)
            ?? $this->ruleInsertColumns($ctx)   // 9
            ?? $this->ruleWhereLike($ctx)       // 3, 4, 5, 5b
            ?? $this->ruleSelectFields($ctx)    // 6a
            ?? $this->ruleInvertedSelectFields($ctx) // 6 (SELECT FROM table fields)
            ?? $this->ruleAfterFrom($ctx)       // 7
            ?? $this->ruleAfterInto($ctx)       // 8
            ?? $this->ruleUpdateTable($ctx)     // 10
            ?? $this->ruleUpdateSet($ctx)       // 11
            ?? $this->ruleDescribe($ctx)        // 12
            ?? $this->ruleShow($ctx);           // 13
    }

    /**
     * @param callable():list<string>       $tablesFn
     * @param callable(string):list<string> $columnsFn
     * @return array{text:string,cursor:int,before:string,trailingSpace:bool,partial:string,start:int,clause:?string,table:string,tablesFn:callable,columnsFn:callable}
     */
    private function context(string $text, int $cursor, callable $tablesFn, callable $columnsFn): array
    {
        $before = mb_substr($text, 0, $cursor);
        $trailingSpace = preg_match('/\s$/u', $before) === 1;

        $partial = '';
        if (!$trailingSpace && preg_match('/([\p{L}\p{N}_]+)$/u', $before, $m) === 1) {
            $partial = $m[1];
        }
        $start = $cursor - mb_strlen($partial);

        $clause = null;
        if (preg_match_all('/\b(where|order\s+by|group\s+by|having|set|values|select|from|into)\b/iu', $before, $mm) >= 1) {
            $last = (string) end($mm[1]);
            $clause = strtolower((string) preg_replace('/\s+/', ' ', $last));
        }

        $table = '';
        if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([\p{L}\p{N}_]+)`?/iu', $text, $tm) === 1) {
            $table = $tm[1];
        }

        return compact('text', 'cursor', 'before', 'trailingSpace', 'partial', 'start', 'clause', 'table')
            + ['tablesFn' => $tablesFn, 'columnsFn' => $columnsFn];
    }

    // --- rules (in spec evaluation order) ----------------------------------

    /** 1: empty buffer → all command keywords. */
    private function ruleEmpty(array $ctx): ?CompletionResult
    {
        if (trim($ctx['text']) !== '') {
            return null;
        }
        return new CompletionResult(self::COMMANDS, $ctx['cursor'], 'Commands');
    }

    /** 2: a single partial token that prefixes a command → matching commands. */
    private function ruleCommandPrefix(array $ctx): ?CompletionResult
    {
        if ($ctx['partial'] === '' || preg_match('/^\s*[\p{L}\p{N}_]+$/u', $ctx['before']) !== 1) {
            return null;
        }
        return $this->result(self::COMMANDS, $ctx, 'Commands');
    }

    /** 9: inside INSERT INTO table ( … → that table's columns. */
    private function ruleInsertColumns(array $ctx): ?CompletionResult
    {
        if (preg_match('/\bINTO\s+`?([\p{L}\p{N}_]+)`?\s*\([^)]*$/iu', $ctx['before'], $m) !== 1) {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($m[1]), $ctx, 'Columns');
    }

    /** 3, 4, 5, 5b: after WHERE / ORDER BY / GROUP BY / HAVING → all columns. */
    private function ruleWhereLike(array $ctx): ?CompletionResult
    {
        if (!in_array($ctx['clause'], ['where', 'order by', 'group by', 'having'], true) || $ctx['table'] === '') {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($ctx['table']), $ctx, 'Columns');
    }

    /** 6, 6a: inside a SELECT field list → columns not yet listed + clause keywords. */
    private function ruleSelectFields(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'select') {
            return null;
        }
        $items = [];
        if ($ctx['table'] !== '') {
            $listed = $this->listedColumns($ctx['before']);
            foreach (($ctx['columnsFn'])($ctx['table']) as $col) {
                if (!isset($listed[strtolower($col)])) {
                    $items[] = $col;
                }
            }
        }
        foreach (self::CLAUSE_KEYWORDS as $kw) {
            $items[] = $kw;
        }
        return $this->result($items, $ctx, 'Columns');
    }

    /**
     * 6 (informal): `SELECT FROM table <fields>` — FROM directly after SELECT,
     * with the field list after the table. Offers columns not yet listed +
     * clause keywords, same as the standard field list.
     */
    private function ruleInvertedSelectFields(array $ctx): ?CompletionResult
    {
        // FROM must directly follow SELECT, and the cursor must be past the
        // table name (a space after it) — i.e. in the field-list position.
        if (preg_match('/^\s*SELECT\s+FROM\s+`?([\p{L}\p{N}_]+)`?\s+(.*)$/isu', $ctx['before'], $m) !== 1) {
            return null;
        }
        // Once a real clause (WHERE/ORDER BY/…) starts, this isn't field list.
        if (preg_match(self::CLAUSE_BOUNDARY, $m[2]) === 1) {
            return null;
        }
        $listed = $this->namedColumns($m[2]);
        $items = [];
        foreach (($ctx['columnsFn'])($m[1]) as $col) {
            if (!isset($listed[strtolower($col)])) {
                $items[] = $col;
            }
        }
        foreach (self::CLAUSE_KEYWORDS as $kw) {
            $items[] = $kw;
        }
        return $this->result($items, $ctx, 'Columns');
    }

    /** 7: immediately after FROM → table names. */
    private function ruleAfterFrom(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'from') {
            return null;
        }
        return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
    }

    /** 8: immediately after INTO → table names. */
    private function ruleAfterInto(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'into') {
            return null;
        }
        return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
    }

    /** 10: UPDATE with only a (partial) table name so far → table names. */
    private function ruleUpdateTable(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*UPDATE\s+[\p{L}\p{N}_]*$/iu', $ctx['before']) !== 1) {
            return null;
        }
        return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
    }

    /** 11: after UPDATE table SET → that table's columns. */
    private function ruleUpdateSet(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'set' || $ctx['table'] === '') {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($ctx['table']), $ctx, 'Columns');
    }

    /** 12: DESCRIBE / DESC with only a (partial) table name → table names. */
    private function ruleDescribe(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*(?:DESCRIBE|DESC)\s+[\p{L}\p{N}_]*$/iu', $ctx['before']) !== 1) {
            return null;
        }
        return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
    }

    /** 13: SHOW with a partial / missing sub-command → SHOW sub-commands. */
    private function ruleShow(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*SHOW\s+[\p{L}\p{N}_]*$/iu', $ctx['before']) !== 1) {
            return null;
        }
        return $this->result(self::SHOW_SUB, $ctx, 'SHOW');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Build a result by filtering items against the current partial token.
     * Returns null only when the context offers **no candidates at all** — if
     * there are candidates but the partial filters them down to nothing, an
     * empty result is still returned so the popup stays open and the user can
     * backspace to refill it (§4).
     *
     * @param list<string> $items
     */
    private function result(array $items, array $ctx, string $title): ?CompletionResult
    {
        $pool = array_values($items);
        if ($pool === []) {
            return null;
        }
        $filtered = $this->filterMatch($pool, $ctx['partial']);
        return new CompletionResult($filtered, $ctx['start'], $title);
    }

    /**
     * Filter candidates by the partial token: case-insensitive **substring**
     * match (the typed text may appear anywhere in the name), not just a prefix.
     *
     * @param list<string> $items
     * @return list<string>
     */
    private function filterMatch(array $items, string $partial): array
    {
        if ($partial === '') {
            return array_values($items);
        }
        $out = [];
        foreach ($items as $item) {
            if (stripos($item, $partial) !== false) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Simple column names already named in the SELECT field list (so the popup
     * can skip them, context 6 field tracking).
     *
     * @return array<string,bool>
     */
    private function listedColumns(string $before): array
    {
        // Field list = text after the last SELECT in the buffer-before-cursor.
        if (preg_match('/.*\bSELECT\b(.*)$/isu', $before, $m) !== 1) {
            return [];
        }
        return $this->namedColumns($m[1]);
    }

    /**
     * Simple column names appearing in a comma-separated field text.
     *
     * @return array<string,bool>
     */
    private function namedColumns(string $fieldText): array
    {
        $listed = [];
        foreach (explode(',', $fieldText) as $piece) {
            $p = trim($piece);
            if ($p === '' || $p === '*' || str_contains($p, '(')) {
                continue;
            }
            $tok = preg_split('/\s+/u', $p)[0] ?? $p;
            $tok = str_replace('`', '', $tok);
            if (str_contains($tok, '.')) {
                $parts = explode('.', $tok);
                $tok = (string) end($parts);
            }
            if (preg_match('/^[A-Za-z0-9_]+$/', $tok) === 1) {
                $listed[strtolower($tok)] = true;
            }
        }
        return $listed;
    }
}
