<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * Immutable outcome of a single query execution.
 *
 * Carries either a row set (for SELECT-like queries) or an affected-row count
 * (for writes), plus elapsed time and any error. Rows are associative arrays
 * keyed by column name; columns preserves the SELECT order.
 */
final class QueryResult
{
    /**
     * @param list<string>               $columns  Column names in result order,
     *                                             made unique by {@see ResultColumns}.
     * @param list<array<string,mixed>>  $rows     Associative row maps.
     * @param list<ResultSource>         $sources  Tables the columns came from,
     *                                             in first-appearance order; the
     *                                             index+1 is the number a
     *                                             qualified column name carries.
     * @param list<int>                  $columnSources Parallel to $columns: the
     *                                             1-based source number each one
     *                                             came from, 0 for none.
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly int $affectedRows = 0,
        public readonly float $elapsed = 0.0,
        public readonly ?string $error = null,
        public readonly bool $isSelect = false,
        public readonly array $sources = [],
        public readonly array $columnSources = [],
    ) {
    }

    /**
     * Which table a column came from, as a 1-based source number — 0 when it
     * came from none (an expression) or is not a column of this result.
     *
     * Answered for every column, not only the ones whose name carries a number,
     * because the header chip highlights the selected column's table whether or
     * not that column's name needed qualifying (§3).
     */
    public function sourceOf(string $column): int
    {
        $i = array_search($column, $this->columns, true);
        return $i === false ? 0 : ($this->columnSources[$i] ?? 0);
    }

    /**
     * True when the rows were assembled from more than one table.
     *
     * The grid cannot safely write to such a result: a cell edit or a row delete
     * has one table name to work with — the first one the SQL mentions — and no
     * way to know that the column it is about to change belongs to a different
     * one. The callers refuse rather than guess (§9, §22).
     */
    public function isMultiSource(): bool
    {
        return count($this->sources) > 1;
    }

    public static function fromError(string $error, float $elapsed = 0.0): self
    {
        return new self(error: $error, elapsed: $elapsed);
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
