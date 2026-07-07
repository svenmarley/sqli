<?php

declare(strict_types=1);

namespace Sqli2\Sql;

/**
 * A resolved tab-completion proposal (spec §4).
 *
 * `items` are the candidates to show; selecting one replaces the buffer span
 * from `start` (a grapheme offset) up to the cursor. `title` labels the popup.
 */
final class CompletionResult
{
    /** @param list<string> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $start,
        public readonly string $title,
    ) {
    }
}
