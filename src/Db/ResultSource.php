<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * One table a result set draws its columns from, in the order the server first
 * mentions it.
 *
 * A join's columns can collide — `templates.id` and `template_items.id` are both
 * called `id` — and the grid has to be able to say which is which without
 * spending half a screen of width on `template_items.id`. The answer is to
 * number the sources and prefix only the names that actually clash: `1.id`,
 * `2.id`, with the numbers spelled out once on the header tile. {@see
 * ResultColumns} does the naming; this is what it names them after.
 *
 * What each driver can tell us differs, and the difference is visible:
 *
 * - **mysqli** reports the alias (`table`), the real table behind it
 *   (`orgtable`) and the database, so the tile can read
 *   `prod.templates t(1), template_items ti(2)`.
 * - **PDO** reports only the alias. `$table` is then empty and the alias stands
 *   in for the name, so the same query tiles as `t(1), ti(2)`. Honest, and
 *   narrower, but it is why mysqli is the preferred driver.
 */
final class ResultSource
{
    public function __construct(
        /** Real table name; `''` when the driver only gave us an alias. */
        public readonly string $table = '',
        /** Alias as written in the SQL; equal to the table when none was given. */
        public readonly string $alias = '',
        /** Database the table lives in; `''` when the driver didn't say. */
        public readonly string $database = '',
    ) {
    }

    /** The real name when we know it, else whatever the driver called it. */
    public function name(): string
    {
        return $this->table !== '' ? $this->table : $this->alias;
    }

    /**
     * True when the SQL gave this table an alias distinct from its own name.
     *
     * False when the real name is unknown — under PDO the alias *is* the name
     * {@see name()} returns, and appending it would label the source `t t`.
     */
    public function hasAlias(): bool
    {
        return $this->table !== '' && $this->alias !== '' && $this->alias !== $this->table;
    }

    /**
     * How the header tile spells this source.
     *
     * The alias is appended only when $withAlias — which the tile turns on for
     * the one case that needs it, a self-join, where two entries would otherwise
     * both read `widgets` and the numbers would be the only thing telling them
     * apart. Everywhere else the alias is noise: nobody needs to be told that
     * `templates` was called `t`.
     */
    public function label(bool $withAlias = false, bool $withDatabase = false): string
    {
        $label = $this->name();
        if ($withAlias && $this->hasAlias()) {
            $label .= ' ' . $this->alias;
        }
        if ($withDatabase && $this->database !== '') {
            $label = $this->database . '.' . $label;
        }
        return $label;
    }
}
