<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * What the built-in macro `?db_status` expands to: sqli's answer to mysql's
 * `\s`. The values are read when the macro is expanded — with `SHOW`
 * statements, which every MySQL and MariaDB version answers, so no
 * `performance_schema` or `@@variable` that one server lacks can fail the
 * lot — and handed back as one `SELECT … UNION ALL …` of literals. That runs
 * through the ordinary path, so the answer lands in a data view like any other
 * result: sortable, filterable, exportable, and read-only (it has no table).
 *
 * A `host_label` hides the address everywhere else on screen, so it does here
 * too: the client address and the server's own hostname are left out.
 */
final class DbStatus
{
    /** Session variables to show, in display order: name => [section, label]. */
    private const VARIABLES = [
        'version'                  => ['Server', 'Version'],
        'version_comment'          => ['Server', 'Distribution'],
        'hostname'                 => ['Server', 'Hostname'],
        'port'                     => ['Server', 'Port'],
        'socket'                   => ['Server', 'Socket'],
        'protocol_version'         => ['Server', 'Protocol version'],
        'read_only'                => ['Server', 'Server read_only'],
        'max_connections'          => ['Server', 'Max connections'],
        'sql_mode'                 => ['Session', 'sql_mode'],
        'time_zone'                => ['Session', 'Time zone'],
        'system_time_zone'         => ['Session', 'System time zone'],
        'autocommit'               => ['Session', 'Autocommit'],
        'transaction_isolation'    => ['Session', 'Transaction isolation'],
        'tx_isolation'             => ['Session', 'Transaction isolation'],
        'transaction_read_only'    => ['Session', 'Transaction read-only'],
        'tx_read_only'             => ['Session', 'Transaction read-only'],
        'wait_timeout'             => ['Session', 'wait_timeout (s)'],
        'interactive_timeout'      => ['Session', 'interactive_timeout (s)'],
        'max_execution_time'       => ['Session', 'max_execution_time (ms)'],
        'max_statement_time'       => ['Session', 'max_statement_time (s)'],
        'character_set_server'     => ['Character sets', 'Server'],
        'character_set_database'   => ['Character sets', 'Database'],
        'character_set_client'     => ['Character sets', 'Client'],
        'character_set_connection' => ['Character sets', 'Connection'],
        'character_set_results'    => ['Character sets', 'Results'],
        'collation_server'         => ['Character sets', 'Server collation'],
        'collation_database'       => ['Character sets', 'Database collation'],
        'collation_connection'     => ['Character sets', 'Connection collation'],
    ];

    /** Server counters (global status), in display order. */
    private const STATUS = [
        'Uptime'               => 'Uptime',
        'Threads_connected'    => 'Threads connected',
        'Threads_running'      => 'Threads running',
        'Max_used_connections' => 'Max used connections',
        'Connections'          => 'Connections since start',
        'Questions'            => 'Statements since start',
        'Slow_queries'         => 'Slow queries',
    ];

    /** Variables whose value names where the server is. */
    private const ADDRESS_VARIABLES = ['hostname', 'socket', 'port'];

    /**
     * @param array{version:string, env:?string, readOnly:bool} $client what only
     *        the App knows: sqli's version, the selected env, the client-side
     *        read-only flag
     */
    public static function sql(Database $db, array $client): string
    {
        return self::toSql(self::rows($db, $client));
    }

    /**
     * @param array{version:string, env:?string, readOnly:bool} $client
     * @return list<array{0:string, 1:string, 2:string}> [section, item, value]
     */
    public static function rows(Database $db, array $client): array
    {
        $hide = $db->hidesAddress();
        $rows = [];
        $add = static function (string $section, string $item, string $value) use (&$rows): void {
            $rows[] = [$section, $item, $value];
        };

        $add('sqli', 'Version', $client['version']);
        $add('sqli', 'Driver', $db->driverName());
        if ($client['env'] !== null && $client['env'] !== '') {
            $add('sqli', 'Environment', $client['env']);
        }
        $add('sqli', 'Read-only', ($client['readOnly'] ? 'on' : 'off')
            . ($db->sessionReadOnly() ? ' (the server refuses writes too)' : ''));
        $add('sqli', 'Connection options', $db->options()->describe());

        $who = self::first($db, 'SELECT CONNECTION_ID() AS id, CURRENT_USER() AS cu, USER() AS u, DATABASE() AS db');
        $add('Connection', 'Connection ID', (string) ($who['id'] ?? '?'));
        if ($hide) {
            $add('Connection', 'Shown as', $db->getConnectionLabel() . ' (host_label — the address is not shown)');
        } else {
            $add('Connection', 'Address', $db->server());
            // The label hides the account too: `admin@` alone still names one.
            $add('Connection', 'Logged in as', (string) ($who['u'] ?? $db->user()));
            $add('Connection', 'Account', (string) ($who['cu'] ?? ''));
        }
        $add('Connection', 'Database', (string) ($who['db'] ?? ''));
        $add('Connection', 'Encryption', $db->tlsDescription());
        $add('Connection', 'TLS mode', $db->tlsMode());
        $ssl = self::pairs($db, "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version')");
        if (($ssl['Ssl_version'] ?? '') !== '') {
            $add('Connection', 'TLS version', $ssl['Ssl_version']);
        }
        $compressed = $db->compressionActive();
        if ($compressed !== null) {
            $add('Connection', 'Compression', $compressed ? 'on' : 'off');
        }

        $names = "'" . implode("','", array_keys(self::VARIABLES)) . "'";
        $vars = self::pairs($db, "SHOW VARIABLES WHERE Variable_name IN ($names)");
        $statusNames = "'" . implode("','", array_keys(self::STATUS)) . "'";
        $status = self::pairs($db, "SHOW GLOBAL STATUS WHERE Variable_name IN ($statusNames)");

        $seen = [];
        foreach (self::VARIABLES as $name => [$section, $label]) {
            if (!array_key_exists($name, $vars) || isset($seen[$section . "\0" . $label])) {
                continue; // a server has only one of each pair of spellings
            }
            if ($hide && in_array($name, self::ADDRESS_VARIABLES, true)) {
                continue;
            }
            if ($name === 'socket' && $vars[$name] === '') {
                continue;
            }
            $seen[$section . "\0" . $label] = true;
            $add($section, $label, $vars[$name]);
            if ($name === 'max_connections') {
                // Server health sits beside the server's own facts.
                foreach (self::STATUS as $s => $sLabel) {
                    if (array_key_exists($s, $status)) {
                        $add('Server', $sLabel, $s === 'Uptime' ? self::uptime((int) $status[$s]) : $status[$s]);
                    }
                }
                $up = (int) ($status['Uptime'] ?? 0);
                if ($up > 0 && isset($status['Questions'])) {
                    $add('Server', 'Statements per second (average)', sprintf('%.2f', ((int) $status['Questions']) / $up));
                }
            }
        }
        return $rows;
    }

    /**
     * One `SELECT … UNION ALL …` of literals with three columns. A value that
     * is not plain text goes in as hex, so no quote, backslash or `?` in it can
     * change the statement — nor be read as a macro or a prompt — whatever the
     * server's `sql_mode` says about backslashes.
     *
     * @param list<array{0:string, 1:string, 2:string}> $rows
     */
    public static function toSql(array $rows): string
    {
        $parts = [];
        foreach ($rows as $i => [$section, $item, $value]) {
            $parts[] = $i === 0
                ? 'SELECT ' . self::literal($section) . ' AS `Section`, ' . self::literal($item) . ' AS `Item`, '
                    . self::literal($value) . ' AS `Value`'
                : 'SELECT ' . self::literal($section) . ', ' . self::literal($item) . ', ' . self::literal($value);
        }
        return implode(' UNION ALL ', $parts);
    }

    public static function literal(string $v): string
    {
        if (preg_match('/^[A-Za-z0-9 _.,:;\/@()+=%#*-]*$/', $v) === 1) {
            return "'" . $v . "'";
        }
        return $v === '' ? "''" : "CONVERT(X'" . bin2hex($v) . "' USING utf8mb4)";
    }

    /** `3d 4h 12m (275520 s)` */
    public static function uptime(int $seconds): string
    {
        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);
        $m = intdiv($seconds % 3600, 60);
        return ($d > 0 ? $d . 'd ' : '') . $h . 'h ' . $m . 'm (' . $seconds . ' s)';
    }

    /** @return array<string,mixed> the first row, or [] */
    private static function first(Database $db, string $sql): array
    {
        $r = $db->query($sql);
        return $r->hasError() ? [] : ($r->rows[0] ?? []);
    }

    /**
     * A two-column SHOW (`Variable_name`, `Value`) as name => value. Column
     * names are read by position, since their case differs by server.
     *
     * @return array<string,string>
     */
    private static function pairs(Database $db, string $sql): array
    {
        $r = $db->query($sql);
        if ($r->hasError() || count($r->columns) < 2) {
            return [];
        }
        [$k, $v] = $r->columns;
        $out = [];
        foreach ($r->rows as $row) {
            $out[(string) ($row[$k] ?? '')] = (string) ($row[$v] ?? '');
        }
        return $out;
    }
}
