<?php

declare(strict_types=1);

namespace Sqli2\Db;

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
     * @param list<string>               $columns  Column names in result order.
     * @param list<array<string,mixed>>  $rows     Associative row maps.
     */
    public function __construct(
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly int $affectedRows = 0,
        public readonly float $elapsed = 0.0,
        public readonly ?string $error = null,
        public readonly bool $isSelect = false,
    ) {
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
