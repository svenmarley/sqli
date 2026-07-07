<?php

declare(strict_types=1);

namespace Sqli2\Sql;

/**
 * Outcome of a normalization pass (spec §5).
 *
 * Carries the SQL to actually execute, whether anything was rewritten, and a
 * short human-readable note for the status line when it was.
 */
final class NormalizeResult
{
    public function __construct(
        public readonly string $sql,
        public readonly bool $changed = false,
        public readonly string $note = '',
    ) {
    }
}
