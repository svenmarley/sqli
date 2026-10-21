<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * The record of what sqli changed: one line per data-modifying statement that
 * ran, appended to `sqli_db_writes.log` (the `log-writes` option, on by default).
 *
 * A line is tab-separated, in this order:
 *
 *     date  time  user  server  database  table  query
 *
 * One statement is always one line — a newline or tab inside the statement is
 * written as a literal `\n` / `\t` — so the file greps and cuts cleanly.
 *
 * The statement is logged as it was sent, with one exception: a password is
 * never written down. Anything that sets one (`IDENTIFIED BY '…'`,
 * `PASSWORD('…')`) keeps its shape and loses the secret — see {@see redact()}.
 *
 * This is a convenience record, not an audit trail: anyone who can run sqli can
 * turn it off or edit the file.
 */
final class WriteLog
{
    public const FILE = 'sqli_db_writes.log';

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Append one statement. False when the line could not be written (the
     * directory cannot be created, the file is not writable); never throws,
     * because the statement has already run and nothing here can take it back.
     */
    public function record(
        string $user,
        string $server,
        string $database,
        string $table,
        string $sql,
        ?int $when = null,
    ): bool {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $line = self::line($user, $server, $database, $table, $sql, $when ?? time());
        return @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    /** The line {@see record()} writes, without its newline. */
    public static function line(
        string $user,
        string $server,
        string $database,
        string $table,
        string $sql,
        int $when,
    ): string {
        return implode("\t", [
            date('Y-m-d', $when),
            date('H:i:s', $when),
            self::flat($user),
            self::flat($server),
            self::flat($database),
            self::flat($table),
            self::flat(self::redact(trim($sql))),
        ]);
    }

    /**
     * The statement with any password literal replaced by `'***'`:
     * `IDENTIFIED [WITH plugin] BY|AS '…'` (CREATE/ALTER USER, GRANT) and
     * `PASSWORD('…')` / `PASSWORD = '…'` / `PASSWORD FOR x = '…'`.
     */
    public static function redact(string $sql): string
    {
        $literal = '(?:\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*")';
        $patterns = [
            '/\b(IDENTIFIED\s+(?:WITH\s+\S+\s+)?(?:BY|AS)\s+(?:PASSWORD\s+)?)' . $literal . '/i',
            '/\b(PASSWORD\s*\(\s*)' . $literal . '/i',
            '/\b(PASSWORD\b[^=;\'"]*=\s*)' . $literal . '/i',
            '/\b(REPLACE\s+)' . $literal . '/i',
        ];
        return preg_replace($patterns, "\$1'***'", $sql) ?? $sql;
    }

    /** One field on one line: tabs and line breaks become their escapes. */
    private static function flat(string $value): string
    {
        return str_replace(["\r\n", "\n", "\r", "\t"], ['\n', '\n', '\n', '\t'], $value);
    }
}
