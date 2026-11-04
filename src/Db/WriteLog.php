<?php

declare(strict_types=1);

namespace Sqli\Db;

/**
 * The record of what sqli sent: one line per data-modifying statement that
 * reached the server, appended to `sqli_db_writes.log` (the `log-writes`
 * option, on by default). A statement the server rejected is logged too,
 * marked FAILED; one sqli refused before sending (read-only, a declined
 * confirm) never ran and is not logged.
 *
 * A line is tab-separated, in this order:
 *
 *     datetime  status  login  user  server  database  table  query  error
 *
 * `datetime` is local `Y-m-d H:i:s` (one field). `status` is `ok` or `FAILED`;
 * `error` is the server's message on a FAILED line and empty otherwise.
 * `login` is the operating system account that ran sqli; `user` is the
 * database account it logged in as.
 *
 * One statement is always one line — a newline or tab inside the statement is
 * written as a literal `\n` / `\t` — so the file greps and cuts cleanly.
 *
 * The statement is logged as it was sent, with one exception: a password is
 * never written down. Anything that sets one (`IDENTIFIED BY '…'`,
 * `PASSWORD('…')`) keeps its shape and loses the secret — see {@see redact()}.
 * The error text gets the same treatment, and when the statement held a
 * password, the server's `near '…'` quote from it is masked whole.
 *
 * This is a convenience record, not an audit trail: anyone who can run sqli can
 * turn it off or edit the file.
 */
final class WriteLog
{
    public const FILE = 'sqli_db_writes.log';

    /** @param ?string $login the OS login to write; null = {@see loginName()} */
    public function __construct(private readonly string $path, private ?string $login = null)
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
        ?string $error = null,
    ): bool {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $this->login ??= self::loginName();
        $line = self::line($this->login, $user, $server, $database, $table, $sql, $when ?? time(), $error);
        return @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * The line {@see record()} writes, without its newline. A null $error is a
     * statement that worked; a string (even empty) is one that failed.
     */
    public static function line(
        string $login,
        string $user,
        string $server,
        string $database,
        string $table,
        string $sql,
        int $when,
        ?string $error = null,
    ): string {
        $sql = trim($sql);
        $hidden = self::redact($sql) !== $sql;
        return implode("\t", [
            date('Y-m-d H:i:s', $when),
            $error === null ? 'ok' : 'FAILED',
            self::flat($login),
            self::flat($user),
            self::flat($server),
            self::flat($database),
            self::flat($table),
            self::flat(self::redact($sql)),
            $error === null ? '' : self::flat(self::redactError($error, $hidden)),
        ]);
    }

    /**
     * A server error message, safe to write: {@see redact()} on it, and when the
     * statement held a password ($hidden), the `near '…'` fragment the server
     * quotes from the statement is replaced whole. That fragment can start in
     * the middle of the literal, where redact() cannot recognise it.
     */
    public static function redactError(string $error, bool $hidden): string
    {
        $error = self::redact($error);
        if ($hidden) {
            $error = preg_replace("/near '.*'(?= at line|\\s*\$)/s", "near '***'", $error) ?? $error;
        }
        return $error;
    }

    /**
     * The operating system account running sqli: the effective user on POSIX
     * (so `sudo` shows root), `USERNAME` on Windows, `USER`/`LOGNAME` as the
     * fallback; empty when none can be read.
     */
    public static function loginName(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && ($pw['name'] ?? '') !== '') {
                return $pw['name'];
            }
        }
        foreach (['USERNAME', 'USER', 'LOGNAME'] as $var) {
            $v = getenv($var);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return '';
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
