<?php

declare(strict_types=1);

namespace Sqli2\Sql;

/**
 * Rewrites sqli's input conveniences into standard SQL before execution (§5).
 *
 * Currently implements the **inverted syntax** pass: a query that leads with
 * `FROM … SELECT …` is rewritten to `SELECT … FROM …`. Rewriting is always on
 * (not a mode). Normal queries (those that already start with SELECT, or with
 * any non-FROM keyword) pass through untouched.
 *
 * Also implements the **mixed-star** pass (§5): a bare `*` mixed with named
 * fields (`SELECT id, *, name`) is expanded in place to the columns not already
 * listed. A lone `SELECT *` and `COUNT(*)` are left alone. Column resolution is
 * the caller's job — `expandMixedStar()` takes a resolver callback so this class
 * stays free of any DB or Data Dictionary dependency.
 */
final class Normalizer
{
    // Clause keywords that terminate the SELECT field list in inverted syntax.
    private const CLAUSE_BOUNDARY = '/\b(WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT)\b/i';

    public function normalize(string $sql): NormalizeResult
    {
        $trimmed = ltrim($sql);

        // Form 1: FROM <from> SELECT <fields> [clauses]
        if (preg_match('/^FROM\s+(.*?)\s+SELECT\s+(.*)$/is', $trimmed, $m) === 1) {
            $fromPart = trim($m[1]);
            [$fields, $rest] = $this->splitFieldsAndClauses(trim($m[2]));
            if ($fromPart === '' || $fields === '') {
                return new NormalizeResult($sql);
            }
            return new NormalizeResult($this->assemble($fields, $fromPart, $rest), true, 'Rewrote inverted FROM…SELECT to standard SQL');
        }

        // Form 2: SELECT FROM <table> <fields> [clauses]  (FROM directly after
        // SELECT — the same intent as form 1, table named up front).
        if (preg_match('/^SELECT\s+FROM\s+`?([\p{L}\p{N}_]+)`?\s+(.*)$/isu', $trimmed, $m) === 1) {
            $table = $m[1];
            [$fields, $rest] = $this->splitFieldsAndClauses(trim($m[2]));
            if ($fields === '') {
                return new NormalizeResult($sql);
            }
            return new NormalizeResult($this->assemble($fields, $table, $rest), true, 'Rewrote SELECT FROM…fields to standard SQL');
        }

        return new NormalizeResult($sql);
    }

    /** Split "fields [clause …]" into [fields, trailingClauses]. */
    private function splitFieldsAndClauses(string $afterSelect): array
    {
        if (preg_match(self::CLAUSE_BOUNDARY, $afterSelect, $cm, PREG_OFFSET_CAPTURE) === 1) {
            $pos = $cm[0][1];
            return [rtrim(substr($afterSelect, 0, $pos)), trim(substr($afterSelect, $pos))];
        }
        return [$afterSelect, ''];
    }

    private function assemble(string $fields, string $fromPart, string $rest): string
    {
        $sql = 'SELECT ' . $fields . ' FROM ' . $fromPart;
        return $rest === '' ? $sql : $sql . ' ' . $rest;
    }

    /**
     * Expand a bare `*` mixed with named fields to the columns not already
     * listed, substituted in place (§5). Leaves a lone `SELECT *` and
     * `COUNT(*)`-style forms untouched.
     *
     * @param callable(string):list<string> $columnsFor resolves a table name to
     *        its column list (empty = could not resolve; leave the query as-is)
     */
    public function expandMixedStar(string $sql, callable $columnsFor): NormalizeResult
    {
        // Simple top-level "SELECT <fields> FROM <rest>" only.
        if (preg_match('/^\s*SELECT\s+(.*?)\s+FROM\s+(.*)$/is', $sql, $m) !== 1) {
            return new NormalizeResult($sql);
        }
        $fields = $this->splitTopLevel($m[1]);
        $afterFrom = $m[2];

        $starIdx = [];
        foreach ($fields as $i => $f) {
            if (trim($f) === '*') {
                $starIdx[] = $i;
            }
        }
        // No bare star, or a lone `SELECT *` → nothing to do.
        if ($starIdx === [] || count($fields) === 1) {
            return new NormalizeResult($sql);
        }

        if (preg_match('/^\s*`?([A-Za-z0-9_]+)`?/', $afterFrom, $tm) !== 1) {
            return new NormalizeResult($sql);
        }
        $allCols = $columnsFor($tm[1]);
        if ($allCols === []) {
            return new NormalizeResult($sql); // unresolved — leave the * in place
        }

        // Columns already named explicitly (so the * skips them).
        $listed = [];
        foreach ($fields as $i => $f) {
            if (in_array($i, $starIdx, true)) {
                continue;
            }
            $name = $this->simpleColumnName($f);
            if ($name !== null) {
                $listed[strtolower($name)] = true;
            }
        }
        $expansion = [];
        foreach ($allCols as $c) {
            if (!isset($listed[strtolower($c)])) {
                $expansion[] = $c;
            }
        }

        // Rebuild: the first bare star becomes the expansion; any further bare
        // stars are dropped to avoid duplicating columns.
        $rebuilt = [];
        $used = false;
        foreach ($fields as $i => $f) {
            if (in_array($i, $starIdx, true)) {
                if (!$used) {
                    array_push($rebuilt, ...$expansion);
                    $used = true;
                }
                continue;
            }
            $rebuilt[] = trim($f);
        }

        $newSql = 'SELECT ' . implode(', ', $rebuilt) . ' FROM ' . $afterFrom;
        return new NormalizeResult($newSql, true, 'Expanded * to remaining columns');
    }

    /**
     * Split a comma list at top level only (ignoring commas inside parentheses
     * or quotes). Byte-wise is safe: the delimiters are all ASCII.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $list): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = '';
        $len = strlen($list);
        for ($i = 0; $i < $len; $i++) {
            $ch = $list[$i];
            if ($quote !== '') {
                $buf .= $ch;
                if ($ch === $quote && ($i === 0 || $list[$i - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '' || $parts !== []) {
            $parts[] = $buf;
        }
        return $parts;
    }

    /**
     * The plain column name a field refers to (`u.name AS x` → `name`), or null
     * for expressions/functions that aren't a single column.
     */
    private function simpleColumnName(string $field): ?string
    {
        $f = trim($field);
        if ($f === '' || str_contains($f, '(')) {
            return null;
        }
        $token = preg_split('/\s+/', $f)[0] ?? $f; // drop any alias
        $token = str_replace('`', '', $token);
        if (str_contains($token, '.')) {
            $parts = explode('.', $token);
            $token = (string) end($parts);
        }
        return preg_match('/^[A-Za-z0-9_]+$/', $token) === 1 ? $token : null;
    }
}
