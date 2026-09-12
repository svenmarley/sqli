<?php

declare(strict_types=1);

namespace Sqli\Sql;

use Sqli\Config\Macros;

/**
 * Context-sensitive tab completion (spec §4).
 *
 * Built as a **rules table**: {@see analyze()} parses the buffer once into a
 * context, then runs an ordered list of rule methods; the first that returns a
 * proposal wins. This keeps the contexts easy to reorder/extend and avoids one
 * giant branch. The engine is schema-agnostic — table/column/database/procedure
 * lookups arrive as callables so this class has no DB dependency.
 *
 * Two things carry most of the weight and are worth reading before changing
 * anything here:
 *
 *  1. **{@see sources()} — the statement's table references, not "the table".**
 *     A single `$table` string (the shape this class used until 2.35.8) can only
 *     ever be right for a one-table statement: it took the *first* `FROM`/`INTO`/
 *     `UPDATE` match in the buffer, so a join offered the left table's columns on
 *     both sides and an alias was invisible. Every column context now resolves
 *     against a list of `{table, alias}` pairs, and offers **qualified** names
 *     (`u.id`, `o.id`) the moment there is more than one — an unqualified `id`
 *     inserted into a join is ambiguous SQL that the server rejects.
 *  2. **{@see scope()} — the innermost statement.** A subquery gets its own
 *     sources; when it has none yet (`… WHERE id IN (SELECT ▏`) the enclosing
 *     statement's sources are used, because a correlated subquery may legally
 *     name the outer table's columns there.
 *
 * Completion only ever **edits the buffer**. It does not submit, and nothing it
 * inserts bypasses the dangerous-query guard (§5): a completed `DROP TABLE …`
 * or `ALTER TABLE …` faces `App::dangerCheck()` on Enter exactly as a typed one
 * does. `completion_test.php` pins that end-to-end.
 *
 * Offsets are grapheme-based (mb_*) to match the editor's cursor indexing.
 */
final class Completion
{
    /** SQL command keywords offered for an empty buffer / command prefix. */
    private const COMMANDS = [
        'SELECT', 'INSERT INTO', 'UPDATE', 'DELETE FROM', 'REPLACE INTO',
        'CREATE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE', 'RENAME TABLE',
        'SHOW', 'DESCRIBE', 'EXPLAIN', 'USE', 'SET', 'FROM', 'CALL',
        'ANALYZE TABLE', 'OPTIMIZE TABLE', 'CHECK TABLE', 'REPAIR TABLE',
        'LOCK TABLES', 'UNLOCK TABLES',
    ];

    /** Clause keywords offered inside a SELECT field list. */
    private const CLAUSE_KEYWORDS = ['FROM', 'WHERE', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT'];

    /** Offered once a `FROM`/`JOIN` table reference is complete (context 7b). */
    private const JOIN_KEYWORDS = [
        'WHERE', 'JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'INNER JOIN', 'ON', 'USING',
        'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT',
    ];

    /** Offered once an `INSERT INTO`/`REPLACE INTO` target is complete (context 8b). */
    private const INTO_KEYWORDS = ['(', 'VALUES', 'SET', 'SELECT'];

    /** Offered once an `UPDATE` target is complete (context 10b). */
    private const UPDATE_KEYWORDS = ['SET', 'JOIN', 'LEFT JOIN', 'WHERE', 'ORDER BY', 'LIMIT'];

    /** SHOW sub-commands (context 13). */
    private const SHOW_SUB = [
        'TABLES', 'DATABASES', 'COLUMNS FROM', 'FULL COLUMNS FROM', 'CREATE TABLE',
        'CREATE VIEW', 'INDEXES FROM', 'KEYS FROM', 'TABLE STATUS', 'TRIGGERS',
        'PROCEDURE STATUS', 'GRANTS', 'STATUS', 'VARIABLES', 'PROCESSLIST', 'ENGINES',
    ];

    /** ALTER TABLE sub-clauses (context 14). */
    private const ALTER_SUB = [
        'ADD COLUMN', 'MODIFY COLUMN', 'CHANGE COLUMN', 'DROP COLUMN', 'RENAME COLUMN',
        'ADD INDEX', 'ADD UNIQUE INDEX', 'ADD PRIMARY KEY', 'ADD FOREIGN KEY',
        'ADD CONSTRAINT', 'DROP INDEX', 'DROP PRIMARY KEY', 'DROP FOREIGN KEY',
        'RENAME TO', 'ENGINE = ', 'AUTO_INCREMENT = ', 'COMMENT = ',
        'CONVERT TO CHARACTER SET ',
    ];

    /** Offered after `EXPLAIN` alongside the table list — it takes either. */
    private const EXPLAIN_SUB = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'FOR CONNECTION'];

    /**
     * Every command whose next identifier is a **table name** (context 12).
     *
     * One list, because the alternative — a rule per command — is how `EXPLAIN`
     * came to have no completion at all while `DESCRIBE` had one. Anything added
     * here is supported everywhere by construction.
     */
    private const TABLE_COMMANDS =
        'EXPLAIN(?:\s+ANALYZE|\s+EXTENDED)?'
        . '|DESCRIBE|DESC'
        . '|DROP\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+EXISTS)?'
        . '|DROP\s+VIEW(?:\s+IF\s+EXISTS)?'
        . '|TRUNCATE(?:\s+TABLE)?'
        . '|ALTER\s+(?:IGNORE\s+)?TABLE'
        . '|RENAME\s+TABLE'
        . '|CREATE\s+(?:OR\s+REPLACE\s+)?VIEW'
        . '|ANALYZE\s+(?:NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLE'
        . '|OPTIMIZE\s+(?:NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLE'
        . '|CHECK\s+TABLE|REPAIR\s+TABLE|CHECKSUM\s+TABLE'
        . '|LOCK\s+TABLES?'
        . '|SHOW\s+(?:CREATE\s+TABLE|CREATE\s+VIEW|TABLE\s+STATUS\s+LIKE'
            . '|(?:FULL\s+)?COLUMNS\s+FROM|(?:FULL\s+)?FIELDS\s+FROM'
            . '|INDEX(?:ES)?\s+FROM|KEYS\s+FROM|TRIGGERS\s+FROM)'
        . '|CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+`?[\p{L}\p{N}_]+`?\s+ON';

    /** Commands whose next identifier is a **database name** (context 15). */
    private const DATABASE_COMMANDS =
        'USE'
        . '|DROP\s+(?:DATABASE|SCHEMA)(?:\s+IF\s+EXISTS)?'
        . '|ALTER\s+(?:DATABASE|SCHEMA)'
        . '|SHOW\s+(?:TABLES|TABLE\s+STATUS)\s+(?:FROM|IN)';

    /** Commands whose next identifier is a **stored routine** name (context 16). */
    private const PROCEDURE_COMMANDS =
        'CALL'
        . '|DROP\s+(?:PROCEDURE|FUNCTION)(?:\s+IF\s+EXISTS)?'
        . '|SHOW\s+CREATE\s+(?:PROCEDURE|FUNCTION)';

    /** Keywords that end a SELECT field list. */
    private const CLAUSE_BOUNDARY = '/\b(WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT)\b/iu';

    /**
     * A `?` with the macro name typed so far running up to the cursor (§23).
     * The charset matches {@see \Sqli\Config\Macros::NAME} — no leading digit,
     * because `?1` is an argument slot rather than a name.
     */
    private const MACRO_OPEN = '/\?([A-Za-z_][A-Za-z0-9_.\-]*)?$/u';

    /** Title of the macro proposal — App keys the name-only insert off it. */
    public const MACROS = 'Macros';

    /** Words that may follow a table name but are never an alias. */
    private const NOT_AN_ALIAS = [
        'as', 'on', 'using', 'where', 'group', 'order', 'having', 'limit', 'set',
        'values', 'join', 'inner', 'left', 'right', 'full', 'cross', 'outer',
        'natural', 'straight_join', 'union', 'force', 'use', 'ignore', 'partition',
        'select', 'for', 'into', 'read', 'write', 'local',
    ];

    /**
     * @param callable():list<string>        $tablesFn
     * @param callable(string):list<string>  $columnsFn
     * @param callable():list<string>|null   $databasesFn   null → no database contexts
     * @param callable():list<string>|null   $proceduresFn  null → no routine contexts
     * @param callable():array<string,string>|null $macrosFn name => value, or
     *        null → no macro context
     */
    public function analyze(
        string $text,
        int $cursor,
        callable $tablesFn,
        callable $columnsFn,
        ?callable $databasesFn = null,
        ?callable $proceduresFn = null,
        ?callable $macrosFn = null,
    ): ?CompletionResult {
        $ctx = $this->context($text, $cursor, $tablesFn, $columnsFn, $databasesFn, $proceduresFn);
        $ctx['macrosFn'] = $macrosFn;

        return $this->ruleMacro($ctx)         // 0   ?name — before every SQL rule
            ?? $this->ruleEmpty($ctx)
            ?? $this->ruleCommandPrefix($ctx)
            ?? $this->ruleQualified($ctx)        // 3b  alias./table. anywhere
            ?? $this->ruleDatabaseCommand($ctx)  // 15
            ?? $this->ruleProcedureCommand($ctx) // 16
            ?? $this->ruleAlterColumn($ctx)      // 14b MODIFY/CHANGE/DROP/AFTER
            ?? $this->ruleAlterSub($ctx)         // 14  ALTER TABLE t ▏
            ?? $this->ruleParenColumns($ctx)     // 9   (…) column lists
            ?? $this->ruleTableCommand($ctx)     // 12  every table-taking command
            ?? $this->ruleDescribeColumn($ctx)   // 12b DESCRIBE table ▏
            ?? $this->ruleOnUsing($ctx)          // 7c  JOIN … ON / USING
            ?? $this->ruleWhereLike($ctx)        // 3, 4, 5, 5b
            ?? $this->ruleSelectFields($ctx)     // 6a
            ?? $this->ruleInvertedSelectFields($ctx) // 6 (SELECT FROM table fields)
            ?? $this->ruleFromRegion($ctx)       // 7, 7b
            ?? $this->ruleIntoRegion($ctx)       // 8, 8b
            ?? $this->ruleUpdateRegion($ctx)     // 10, 10b
            ?? $this->ruleUpdateSet($ctx)        // 11
            ?? $this->ruleShow($ctx);            // 13
    }

    /**
     * @param callable():list<string>       $tablesFn
     * @param callable(string):list<string> $columnsFn
     * @return array<string,mixed>
     */
    private function context(
        string $text,
        int $cursor,
        callable $tablesFn,
        callable $columnsFn,
        ?callable $databasesFn,
        ?callable $proceduresFn,
    ): array {
        // The statement the cursor is actually inside — a subquery is its own.
        [$scopeStart, $scopeEnd] = $this->scope($text, $cursor);
        $stmt   = mb_substr($text, $scopeStart, $scopeEnd - $scopeStart);
        $before = mb_substr($text, $scopeStart, $cursor - $scopeStart);

        $trailingSpace = preg_match('/\s$/u', $before) === 1;

        $partial = '';
        if (!$trailingSpace && preg_match('/([\p{L}\p{N}_]+)$/u', $before, $m) === 1) {
            $partial = $m[1];
        }
        $start = $cursor - mb_strlen($partial);

        // The `alias.` / `table.` qualifier immediately left of the partial, if any.
        $qualifier = '';
        $stem = mb_substr($before, 0, mb_strlen($before) - mb_strlen($partial));
        if (preg_match('/`?([\p{L}\p{N}_]+)`?\s*\.\s*$/u', $stem, $qm) === 1) {
            $qualifier = $qm[1];
        }

        $clause = null;
        if (preg_match_all(
            '/\b(where|order\s+by|group\s+by|having|set|values|select|from|into|on|using|join|limit)\b/iu',
            $before,
            $mm,
        ) >= 1) {
            $last = (string) end($mm[1]);
            $clause = strtolower((string) preg_replace('/\s+/', ' ', $last));
        }

        // Table references for this statement; an empty subquery borrows the
        // enclosing statement's, because a correlated subquery may name them.
        $sources = $this->sources($stmt);
        if ($sources === [] && ($scopeStart > 0 || $scopeEnd < mb_strlen($text))) {
            $sources = $this->sources($text);
        }

        return [
            'text' => $stmt, 'fullText' => $text, 'cursor' => $cursor, 'before' => $before,
            'trailingSpace' => $trailingSpace, 'partial' => $partial, 'start' => $start,
            'clause' => $clause, 'qualifier' => $qualifier, 'sources' => $sources,
            'tablesFn' => $tablesFn, 'columnsFn' => $columnsFn,
            'databasesFn' => $databasesFn, 'proceduresFn' => $proceduresFn,
        ];
    }

    // --- statement scope and table references -------------------------------

    /**
     * Bounds of the innermost statement containing the cursor: the whole buffer,
     * unless the cursor sits inside an unclosed `(` whose contents start a
     * SELECT — a subquery, which has its own FROM and its own columns.
     *
     * Quoted text is skipped, so a `(` inside a string literal cannot open a
     * scope that never closes.
     *
     * @return array{0:int,1:int} start and end grapheme offsets
     */
    private function scope(string $text, int $cursor): array
    {
        $chars = mb_str_split($text);
        $n = count($chars);
        $cursor = max(0, min($cursor, $n));

        $open = [];
        $quote = '';
        for ($i = 0; $i < $cursor; $i++) {
            $c = $chars[$i];
            if ($quote !== '') {
                if ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                continue;
            }
            if ($c === '(') {
                $open[] = $i;
            } elseif ($c === ')') {
                array_pop($open);
            }
        }
        if ($open === []) {
            return [0, $n];
        }
        $start = (int) end($open) + 1;
        // Only a sub-SELECT is a statement of its own; `INSERT INTO t (` is a
        // column list belonging to the statement around it.
        if (preg_match('/\bSELECT\b/iu', mb_substr($text, $start, $cursor - $start)) !== 1) {
            return [0, $n];
        }

        $depth = 0;
        $end = $n;
        for ($i = $cursor; $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
                $depth--;
            }
        }
        return [$start, $end];
    }

    /**
     * Every table reference in a statement, in the order written, each with the
     * alias it was given (empty when it has none).
     *
     * @return list<array{table:string,alias:string}>
     */
    private function sources(string $stmt): array
    {
        $out = [];

        // FROM / JOIN regions: everything up to the next clause or join keyword.
        $boundary = '(?=\b(?:WHERE|GROUP\s+BY|ORDER\s+BY|HAVING|LIMIT|UNION|SET|VALUES'
            . '|ON|USING|INNER|CROSS|LEFT|RIGHT|FULL|OUTER|NATURAL|STRAIGHT_JOIN|JOIN)\b|\)|$)';
        if (preg_match_all('/\b(?:FROM|JOIN)\s+(.*?)' . $boundary . '/isu', $stmt, $mm) >= 1) {
            foreach ($mm[1] as $region) {
                foreach ($this->parseRefs($region) as $ref) {
                    $out[] = $ref;
                }
            }
        }
        // UPDATE targets (may itself be a comma/JOIN list).
        if (preg_match('/\bUPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*(.*?)(?=\bSET\b|$)/isu', $stmt, $um) === 1) {
            foreach ($this->parseRefs($um[1]) as $ref) {
                $out[] = $ref;
            }
        }
        // INSERT / REPLACE targets.
        if (preg_match(
            '/\b(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+INTO\s+(.*?)'
                . '(?=[(,]|\bVALUES\b|\bSET\b|\bSELECT\b|$)/isu',
            $stmt,
            $im,
        ) === 1) {
            foreach ($this->parseRefs($im[1]) as $ref) {
                $out[] = $ref;
            }
        }
        // Table-shaped DDL/utility commands (ALTER TABLE t, CREATE INDEX … ON t).
        if (preg_match(
            '/\b(?:ALTER\s+(?:IGNORE\s+)?TABLE|CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX'
                . '\s+`?[\p{L}\p{N}_]+`?\s+ON)\s+`?([\p{L}\p{N}_]+)`?/iu',
            $stmt,
            $am,
        ) === 1) {
            $out[] = ['table' => $am[1], 'alias' => ''];
        }

        // De-duplicate on table+alias, keeping first appearance.
        $seen = [];
        $uniq = [];
        foreach ($out as $ref) {
            $k = strtolower($ref['table'] . '|' . $ref['alias']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $uniq[] = $ref;
        }
        return $uniq;
    }

    /**
     * Split one FROM/UPDATE/INTO region into its comma-separated references and
     * read `table`, `db.table`, `table alias` and `table AS alias` out of each.
     *
     * @return list<array{table:string,alias:string}>
     */
    private function parseRefs(string $region): array
    {
        $out = [];
        foreach (explode(',', $region) as $piece) {
            $piece = trim((string) preg_replace('/\(.*/su', '', $piece));
            if ($piece === '') {
                continue;
            }
            $tokens = preg_split('/\s+/u', $piece) ?: [];
            $tokens = array_values(array_filter(array_map(
                static fn (string $t): string => str_replace('`', '', trim($t)),
                $tokens,
            ), static fn (string $t): bool => $t !== ''));
            if ($tokens === []) {
                continue;
            }

            $name = $tokens[0];
            if (str_contains($name, '.')) {          // db.table → table
                $parts = explode('.', $name);
                $name = (string) end($parts);
            }
            if (preg_match('/^[\p{L}\p{N}_]+$/u', $name) !== 1) {
                continue;
            }
            if (in_array(strtolower($name), self::NOT_AN_ALIAS, true)) {
                continue;   // a keyword landed here; not a table reference
            }

            $alias = '';
            $next = $tokens[1] ?? '';
            if (strcasecmp($next, 'AS') === 0) {
                $next = $tokens[2] ?? '';
            }
            if ($next !== ''
                && preg_match('/^[\p{L}\p{N}_]+$/u', $next) === 1
                && !in_array(strtolower($next), self::NOT_AN_ALIAS, true)
            ) {
                $alias = $next;
            }
            $out[] = ['table' => $name, 'alias' => $alias];
        }
        return $out;
    }

    /**
     * The column pool for the statement's sources. One source → bare column
     * names; two or more → **qualified** names (`u.id`), because a bare `id`
     * inserted into a join is ambiguous and the server refuses it.
     *
     * @return list<string>
     */
    private function sourceColumns(array $ctx): array
    {
        // A qualifier the statement does not know (`WHERE zz.▏`) means the user
        // named a table or alias that isn't there. Offering some *other* table's
        // columns under it would build a statement the server rejects, so the
        // honest answer is nothing at all — {@see ruleQualified} has already had
        // its chance to resolve it.
        if ($ctx['qualifier'] !== '') {
            return [];
        }
        $sources = $ctx['sources'];
        if ($sources === []) {
            return [];
        }
        if (count($sources) === 1) {
            return ($ctx['columnsFn'])($sources[0]['table']);
        }
        $out = [];
        foreach ($sources as $ref) {
            $prefix = ($ref['alias'] !== '' ? $ref['alias'] : $ref['table']) . '.';
            foreach (($ctx['columnsFn'])($ref['table']) as $col) {
                $out[] = $prefix . $col;
            }
        }
        return $out;
    }

    /** Resolve an `alias.` / `table.` qualifier to a real table name, or ''. */
    private function resolveQualifier(array $ctx, string $qualifier): string
    {
        foreach ($ctx['sources'] as $ref) {
            if ($ref['alias'] !== '' && strcasecmp($ref['alias'], $qualifier) === 0) {
                return $ref['table'];
            }
        }
        foreach ($ctx['sources'] as $ref) {
            if (strcasecmp($ref['table'], $qualifier) === 0) {
                return $ref['table'];
            }
        }
        foreach (($ctx['tablesFn'])() as $t) {
            if (strcasecmp($t, $qualifier) === 0) {
                return $t;
            }
        }
        return '';
    }

    // --- rules (in evaluation order) ----------------------------------------

    /**
     * 0: `?▏` → the macro names, with a slice of each value beside it (§23).
     *
     * First in the chain, and the only rule that ignores the SQL *shape*
     * entirely, because a macro reference is textual: it is as legal halfway
     * through a table name or a column list as it is where a value belongs, and
     * every rule below reads the statement's structure to decide what may go at
     * the cursor.
     *
     * What it does not ignore is quoting. A `?name` inside a string literal is
     * left alone by expansion (§23), so offering names there would propose an
     * insert that does nothing — `'… ?▏'` falls through to the SQL rules like
     * any other position instead.
     *
     * It also carries its own partial-token scan rather than using the shared
     * one: macro names may contain `-` and `.`, which the SQL identifier
     * pattern stops at, so `?fl-ag▏` would otherwise filter on `ag`.
     *
     * The value goes in {@see CompletionResult::$details}, not into the item:
     * a name is what gets inserted, and the value is there to answer "which one
     * was that?" without opening the viewer — a list of twenty names that all
     * begin `agy-` is otherwise a list of twenty guesses.
     */
    private function ruleMacro(array $ctx): ?CompletionResult
    {
        $fn = $ctx['macrosFn'] ?? null;
        if ($fn === null) {
            return null;
        }
        $before = mb_substr($ctx['fullText'], 0, $ctx['cursor']);
        if (preg_match(self::MACRO_OPEN, $before, $m) !== 1) {
            return null;
        }
        $partial = $m[1] ?? '';
        // The `?` itself, one byte before the partial name — byte offsets,
        // because that is what Macros works in and the two must not drift.
        if (self::inMacroInertSpan($before, strlen($before) - strlen($partial) - 1)) {
            return null;
        }
        $macros = $fn();
        if ($macros === []) {
            return null;
        }
        $names = $this->filterMatch(array_keys($macros), $partial);
        return new CompletionResult(
            $names,
            $ctx['cursor'] - mb_strlen($partial),
            self::MACROS,
            array_map(static fn (string $n): string => (string) ($macros[$n] ?? ''), $names),
        );
    }

    /**
     * Whether the byte at $at sits somewhere a macro reference would not be
     * expanded — a string literal, a backtick identifier, a comment.
     *
     * It asks {@see \Sqli\Config\Macros::inertSpans()} rather than walking the
     * text itself. There used to be a second walker here, with a comment saying
     * the two had to agree; two implementations of one rule agree until one of
     * them learns something, which is exactly what happened when the macro
     * lexer learned about comments and backticks. A popup that offers a name
     * expansion would ignore is worse than no popup, so there is now one reader
     * of what counts as text and this is a caller of it.
     *
     * An unterminated quote counts as open to the end, which is the whole point
     * here — the cursor is normally *in* the string being typed.
     */
    private static function inMacroInertSpan(string $text, int $at): bool
    {
        if ($at < 0) {
            return false;
        }
        foreach (Macros::inertSpans($text) as [$from, $to]) {
            if ($at < $from) {
                return false; // ascending order: nothing later can contain it
            }
            if ($at < $to) {
                return true;
            }
        }
        return false;
    }

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

    /**
     * 3b: an `alias.` or `table.` qualifier → that table's columns, bare (the
     * qualifier is already on the line). Unresolvable qualifiers fall through
     * rather than offering some other table's columns.
     */
    private function ruleQualified(array $ctx): ?CompletionResult
    {
        if ($ctx['qualifier'] === '') {
            return null;
        }
        $table = $this->resolveQualifier($ctx, $ctx['qualifier']);
        if ($table === '') {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($table), $ctx, 'Columns');
    }

    /** 15: USE / DROP DATABASE / SHOW TABLES FROM … → database names. */
    private function ruleDatabaseCommand(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*(?:' . self::DATABASE_COMMANDS . ')\s+`?[\p{L}\p{N}_]*$/iu', $ctx['before']) !== 1) {
            return null;
        }
        $fn = $ctx['databasesFn'];
        return $fn === null ? null : $this->result($fn(), $ctx, 'Databases');
    }

    /** 16: CALL / DROP PROCEDURE … → stored routine names. */
    private function ruleProcedureCommand(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*(?:' . self::PROCEDURE_COMMANDS . ')\s+`?[\p{L}\p{N}_]*$/iu', $ctx['before']) !== 1) {
            return null;
        }
        $fn = $ctx['proceduresFn'];
        return $fn === null ? null : $this->result($fn(), $ctx, 'Procedures');
    }

    /**
     * 14b: `ALTER TABLE t … MODIFY|CHANGE|DROP|RENAME|AFTER ▏` → that table's
     * columns. `ADD COLUMN` is deliberately absent — that name is new, and
     * offering the existing ones there proposes a duplicate.
     */
    private function ruleAlterColumn(array $ctx): ?CompletionResult
    {
        if (preg_match(
            '/^\s*ALTER\s+(?:IGNORE\s+)?TABLE\s+`?([\p{L}\p{N}_]+)`?\s+.*'
                . '\b(?:MODIFY|CHANGE|DROP|RENAME|AFTER)\s+(?:COLUMN\s+)?`?[\p{L}\p{N}_]*$/isu',
            $ctx['before'],
            $m,
        ) !== 1) {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($m[1]), $ctx, 'Columns');
    }

    /** 14: `ALTER TABLE t ▏` → the sub-clauses that may follow. */
    private function ruleAlterSub(array $ctx): ?CompletionResult
    {
        if (preg_match(
            '/^\s*ALTER\s+(?:IGNORE\s+)?TABLE\s+`?[\p{L}\p{N}_]+`?\s+[\p{L}\p{N}_]*$/iu',
            $ctx['before'],
        ) !== 1) {
            return null;
        }
        return $this->result(self::ALTER_SUB, $ctx, 'ALTER');
    }

    /**
     * 9: inside a parenthesised column list → the statement's table columns.
     * Covers `INSERT INTO t (`, `ALTER TABLE t ADD INDEX i (` and
     * `CREATE INDEX i ON t (`.
     *
     * A `VALUES (` list is data, not identifiers, and is excluded — as is
     * `CREATE TABLE t (`, where every name is being invented.
     */
    private function ruleParenColumns(array $ctx): ?CompletionResult
    {
        if (preg_match('/\(([^()]*)$/su', $ctx['before'], $m) !== 1) {
            return null;
        }
        $head = mb_substr($ctx['before'], 0, mb_strlen($ctx['before']) - mb_strlen($m[0]));
        if (preg_match('/\bVALUES\b[\s,()]*$/isu', $head) === 1) {
            return null;   // row data
        }
        if (preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/iu', $ctx['before']) === 1) {
            return null;   // new column definitions
        }
        if (preg_match(
            '/\b(?:INTO|ALTER\s+(?:IGNORE\s+)?TABLE|ON)\s+`?([\p{L}\p{N}_]+)`?/iu',
            $head,
            $tm,
        ) !== 1) {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($tm[1]), $ctx, 'Columns');
    }

    /**
     * 12: any command whose next identifier is a table name — the single rule
     * behind EXPLAIN, DESCRIBE, DROP/TRUNCATE/ALTER/RENAME TABLE, the
     * ANALYZE/OPTIMIZE/CHECK/REPAIR/CHECKSUM family, LOCK TABLES, SHOW CREATE
     * TABLE, SHOW COLUMNS FROM and CREATE INDEX … ON. A comma-separated list
     * already typed (`DROP TABLE a, b, ▏`) still completes.
     */
    private function ruleTableCommand(array $ctx): ?CompletionResult
    {
        if (preg_match(
            '/^\s*(' . self::TABLE_COMMANDS . ')\s+((?:`?[\p{L}\p{N}_]+`?\s*,\s*)*)`?[\p{L}\p{N}_]*$/iu',
            $ctx['before'],
            $m,
        ) !== 1) {
            return null;
        }
        $command = (string) preg_replace('/\s+/', ' ', strtoupper(trim($m[1])));
        // EXPLAIN ANALYZE only explains a *statement*; plain EXPLAIN takes
        // either, so it offers both and lets the filter sort it out —
        // `EXPLAIN SEL▏` narrows to SELECT on its own.
        if ($command === 'EXPLAIN ANALYZE') {
            return $this->result(self::EXPLAIN_SUB, $ctx, 'Keywords');
        }
        $items = ($ctx['tablesFn'])();
        if ($command === 'EXPLAIN') {
            foreach (self::EXPLAIN_SUB as $kw) {
                $items[] = $kw;
            }
        }
        return $this->result($items, $ctx, 'Tables');
    }

    /** 12b: `DESCRIBE table ▏` — MySQL takes a column name here (EXPLAIN too). */
    private function ruleDescribeColumn(array $ctx): ?CompletionResult
    {
        if (preg_match(
            '/^\s*(?:DESCRIBE|DESC|EXPLAIN)\s+`?([\p{L}\p{N}_]+)`?\s+`?[\p{L}\p{N}_]*$/iu',
            $ctx['before'],
            $m,
        ) !== 1) {
            return null;
        }
        return $this->result(($ctx['columnsFn'])($m[1]), $ctx, 'Columns');
    }

    /** 7c: after `ON` / `USING` in a join → columns of every joined table. */
    private function ruleOnUsing(array $ctx): ?CompletionResult
    {
        if (!in_array($ctx['clause'], ['on', 'using'], true)) {
            return null;
        }
        return $this->result($this->sourceColumns($ctx), $ctx, 'Columns');
    }

    /** 3, 4, 5, 5b: after WHERE / ORDER BY / GROUP BY / HAVING → columns. */
    private function ruleWhereLike(array $ctx): ?CompletionResult
    {
        if (!in_array($ctx['clause'], ['where', 'order by', 'group by', 'having'], true)) {
            return null;
        }
        return $this->result($this->sourceColumns($ctx), $ctx, 'Columns');
    }

    /** 6, 6a: inside a SELECT field list → columns not yet listed + clause keywords. */
    private function ruleSelectFields(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'select') {
            return null;
        }
        $items = [];
        $listed = $this->listedColumns($ctx['before']);
        // The token under the cursor is what we're *completing*, not a field
        // already chosen — don't let it exclude its own exact match (a bare
        // `email` must still offer `email`). A completed field + trailing
        // space has partial='' and stays excluded.
        if ($ctx['partial'] !== '') {
            unset($listed[strtolower($ctx['partial'])]);
        }
        foreach ($this->sourceColumns($ctx) as $col) {
            if (!isset($listed[strtolower($this->bareName($col))])) {
                $items[] = $col;
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
        // See ruleSelectFields: don't exclude the token being typed from its own
        // completion (a bare `id` must still offer `id`, not just `agency_id`).
        if ($ctx['partial'] !== '') {
            unset($listed[strtolower($ctx['partial'])]);
        }
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

    /**
     * 7 / 7b: inside a `FROM` or `JOIN` reference list.
     *
     * Offers **tables** while a reference is still being written, and the
     * keywords that may follow once one is complete — `SELECT * FROM users ▏`
     * wants `WHERE`/`JOIN`, not a second table name it has no comma for.
     */
    private function ruleFromRegion(array $ctx): ?CompletionResult
    {
        if (!in_array($ctx['clause'], ['from', 'join'], true)) {
            return null;
        }
        // The *last* FROM/JOIN — in `FROM users JOIN ▏` the region being written
        // belongs to the JOIN, and matching the first one offers join keywords
        // when what is wanted is the table being joined to.
        if (preg_match('/.*\b(?:FROM|JOIN)\b(.*)$/isu', $ctx['before'], $m) !== 1) {
            return null;
        }
        // Region text with the token being typed removed.
        $region = mb_substr($m[1], 0, mb_strlen($m[1]) - mb_strlen($ctx['partial']));
        $region = rtrim($region);

        if ($region === '' || str_ends_with($region, ',') || str_ends_with($region, '.')) {
            return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
        }
        // A bare `FROM users` with the cursor still on `users` is a filter, not
        // a finished reference.
        if ($ctx['partial'] !== '' && trim($region) === '') {
            return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
        }
        return $this->result(self::JOIN_KEYWORDS, $ctx, 'Keywords');
    }

    /** 8 / 8b: after `INTO` → target tables, then what may follow one. */
    private function ruleIntoRegion(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'into') {
            return null;
        }
        if (preg_match('/\bINTO\b(.*)$/isu', $ctx['before'], $m) !== 1) {
            return null;
        }
        $region = rtrim(mb_substr($m[1], 0, mb_strlen($m[1]) - mb_strlen($ctx['partial'])));
        if ($region === '' || str_ends_with($region, '.') || strcasecmp($region, 'TABLE') === 0) {
            return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
        }
        return $this->result(self::INTO_KEYWORDS, $ctx, 'Keywords');
    }

    /** 10 / 10b: `UPDATE ▏` → tables; `UPDATE t ▏` → what may follow one. */
    private function ruleUpdateRegion(array $ctx): ?CompletionResult
    {
        if (preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*(.*)$/isu', $ctx['before'], $m) !== 1) {
            return null;
        }
        $region = rtrim(mb_substr($m[1], 0, mb_strlen($m[1]) - mb_strlen($ctx['partial'])));
        // Once a clause has started, the target list is behind us — `SET` and
        // `WHERE` have their own rules and must not be read as a table alias.
        if (preg_match('/\b(?:SET|WHERE|JOIN|ON|USING|ORDER|GROUP|HAVING|LIMIT)\b/iu', $region) === 1) {
            return null;
        }
        if ($region === '' || str_ends_with($region, ',') || str_ends_with($region, '.')) {
            return $this->result(($ctx['tablesFn'])(), $ctx, 'Tables');
        }
        // One complete reference and nothing else typed yet.
        if (preg_match('/^`?[\p{L}\p{N}_]+`?(?:\s+(?:AS\s+)?[\p{L}\p{N}_]+)?$/iu', trim($region)) === 1) {
            return $this->result(self::UPDATE_KEYWORDS, $ctx, 'Keywords');
        }
        return null;
    }

    /** 11: after UPDATE table SET → that table's columns. */
    private function ruleUpdateSet(array $ctx): ?CompletionResult
    {
        if ($ctx['clause'] !== 'set') {
            return null;
        }
        return $this->result($this->sourceColumns($ctx), $ctx, 'Columns');
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

    /** `u.id` → `id`; used when comparing a qualified candidate to typed text. */
    private function bareName(string $name): string
    {
        if (!str_contains($name, '.')) {
            return $name;
        }
        $parts = explode('.', $name);
        return (string) end($parts);
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
