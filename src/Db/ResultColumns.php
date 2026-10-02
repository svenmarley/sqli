<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * Turn a driver's field metadata into a set of **unique** column names, plus the
 * numbered list of tables those names refer back to.
 *
 * This exists because of a bug with teeth. Rows arrive as associative arrays
 * keyed by column name, and a join can name two columns the same thing:
 *
 *     SELECT t.id, … , ti.* FROM templates t, template_items ti WHERE …
 *
 * The field metadata correctly reports two columns called `id`, so the grid drew
 * two `id` headers — but a PHP array cannot hold the key `id` twice, so the
 * second value overwrote the first and both headers read the *same* value. The
 * first column was quietly lying, and the same collapse reached `Ctrl+Alt+O`
 * exports, sorting, and worst of all row deletion, which reads the primary key
 * out of the row by name and would have written `DELETE FROM templates WHERE id
 * IN (…)` using `template_items.id` values.
 *
 * The fix is to make the names unique before the rows are keyed by them, and to
 * make the uniqueness *readable*: a clashing name is prefixed with its table's
 * number, `1.id` / `2.id`, which costs one column of width where
 * `template_items.id` would have cost fourteen. Names that don't clash are left
 * exactly as the server gave them, so a single-table `SELECT *` is untouched.
 */
final class ResultColumns
{
    /**
     * @param  list<array{name:string,alias:string,table:string,db:string}> $fields
     *         One entry per column, in result order. `alias` is what the SQL
     *         called the table (the drivers' `table`), `table` is the real name
     *         behind it (mysqli's `orgtable`, empty under PDO).
     * @return array{columns:list<string>,sources:list<ResultSource>,columnSources:list<int>}
     *         `columnSources` is parallel to `columns`: the 1-based source
     *         number each column came from, or 0 for one that came from no
     *         table. It is kept for **every** column, not only the qualified
     *         ones, because the header chip highlights the table the selected
     *         column belongs to whether or not its name needed a number.
     */
    public static function resolve(array $fields): array
    {
        // Distinct sources, in order of first appearance, keyed by *alias* — a
        // self-join mentions one real table under two aliases and is genuinely
        // two sources, which is exactly the case where the numbers earn their
        // keep. A computed expression belongs to no table and gets number 0.
        $numberOf = [];
        $sources  = [];
        $number   = [];
        foreach ($fields as $i => $f) {
            $key = $f['alias'] !== '' ? $f['alias'] : $f['table'];
            if ($key === '') {
                $number[$i] = 0;
                continue;
            }
            if (!isset($numberOf[$key])) {
                $numberOf[$key] = count($sources) + 1;
                $sources[] = new ResultSource($f['table'], $f['alias'], $f['db']);
            }
            $number[$i] = $numberOf[$key];
        }

        $names = [];
        foreach ($fields as $i => $f) {
            $names[$i] = $f['name'] !== '' ? $f['name'] : ('col' . $i);
        }
        $clashes = array_count_values($names);

        // The number is only worth showing when there is more than one table to
        // point at. With a single source `1.` in front of every clashing name
        // would be pure noise — the trailing-suffix pass below still guarantees
        // uniqueness for the odd `SELECT w.id, w.id`.
        $numbered = count($sources) > 1;
        $columns = [];
        foreach ($names as $i => $name) {
            if ($numbered && ($clashes[$name] ?? 0) > 1 && $number[$i] > 0) {
                $name = $number[$i] . '.' . $name;
            }
            $columns[] = $name;
        }

        return [
            'columns' => self::deduplicate($columns),
            'sources' => $sources,
            'columnSources' => array_values($number),
        ];
    }

    /**
     * The same columns spelled out in full — `templates.id`, not `1.id`.
     *
     * The number is the *grid's* spelling, and it only works there: the header
     * chip sits three rows above and says what `1` and `2` mean, and a grid
     * header cell is where width is scarce. Everywhere the name outlives that
     * screen, the table gets its real name:
     *
     * - **Exports** (§19, §24). A CSV opened next week in something else has no
     *   chip to decode a number against, and a file has no width problem.
     * - **Saved column state** (§6) — widths, hidden columns, order, formats.
     *   There the number is worse than unhelpful, it is *unstable*: swap the two
     *   tables in the FROM clause and `1.id` silently means the other column,
     *   so a stored width would land on the wrong one.
     *
     * Only the numeric prefixes this class put there are expanded, and only
     * when the number names a source we actually have. Every other name is
     * passed through untouched, so a column genuinely called `1.x` survives.
     *
     * @param  list<string>       $columns  Column names, possibly qualified.
     * @param  list<ResultSource> $sources  The result's sources, in order.
     * @return list<string>
     */
    public static function qualified(array $columns, array $sources): array
    {
        $out = [];
        foreach ($columns as $name) {
            if (preg_match('/^([1-9][0-9]*)\.(.+)$/', $name, $m) !== 1) {
                $out[] = $name;
                continue;
            }
            $source = $sources[(int) $m[1] - 1] ?? null;
            // The real table when the driver gave one, else the alias — which
            // under PDO is all there has ever been (see {@see ResultSource}).
            $out[] = $source === null ? $name : $source->name() . '.' . $m[2];
        }
        return $out;
    }

    /**
     * Last line of defence: whatever is still doubled gets `_2`, `_3`, … .
     *
     * The numbering above resolves the cases a reader can act on. It cannot
     * resolve two columns from the *same* table under the same name
     * (`SELECT w.id, w.id`) or two same-named expressions (`SELECT 1 AS id, 2 AS
     * id`), and those must not be allowed through either — uniqueness here is
     * what stops a value being overwritten, not a nicety.
     *
     * @param  list<string> $columns
     * @return list<string>
     */
    private static function deduplicate(array $columns): array
    {
        $taken = [];
        $out = [];
        foreach ($columns as $name) {
            if (!isset($taken[$name])) {
                $taken[$name] = true;
                $out[] = $name;
                continue;
            }
            $n = 2;
            while (isset($taken[$name . '_' . $n])) {
                $n++;
            }
            $taken[$name . '_' . $n] = true;
            $out[] = $name . '_' . $n;
        }
        return $out;
    }
}
