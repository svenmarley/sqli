<?php

declare(strict_types=1);

namespace Sqli\Sql;

/**
 * A resolved tab-completion proposal (spec §4).
 *
 * `items` are the candidates to show; selecting one replaces the buffer span
 * from `start` (a grapheme offset) up to the cursor. `title` labels the popup.
 *
 * `details` is an optional second column, one entry per item, shown beside the
 * name and never inserted — what a macro expands to (§23), where the name alone
 * does not say which one you meant. Empty for every other proposal, since a
 * table or a column *is* its own description.
 */
final class CompletionResult
{
    /**
     * @param list<string> $items
     * @param list<string> $details parallel to $items, or empty for none
     */
    public function __construct(
        public readonly array $items,
        public readonly int $start,
        public readonly string $title,
        public readonly array $details = [],
    ) {
    }
}
