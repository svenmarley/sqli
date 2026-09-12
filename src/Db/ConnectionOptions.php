<?php

declare(strict_types=1);

namespace Sqli\Db;

use PDO;
use Sqli\Sql\Splitter;

/**
 * Non-TLS connection settings (spec §2, "Connection options").
 *
 * The companion to {@see TlsOptions}: same shape, same contract — a pure value
 * object that validates what the config layers produced, and hands
 * {@see Database} an attribute array plus the one setting that is not an
 * attribute at all. It performs no I/O beyond what validation needs, so every
 * rule here can be pinned without a server.
 *
 * **Everything here is fixed at connect time**, and that is not a limitation of
 * this class — it is what the driver does. The seven PDO attributes are
 * connect-only by definition, and `mysqlnd.net_read_timeout` was measured to be
 * the same: the value is copied into the connection when it opens, and a later
 * `ini_set()` reports success, changes what `ini_get()` returns, and has no
 * effect whatsoever on the live session (probe in `tests/connection_test.php`).
 * So there is deliberately no setter on this object and no way to change any of
 * it from the UI: the only honest way to apply a new value is a new connection.
 *
 * **The option set is closed, and stays closed.** An open passthrough — a
 * comma-separated bag of `MYSQL_ATTR_*` — was considered and rejected: it would
 * reach `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` and `MYSQL_ATTR_LOCAL_INFILE`, the
 * two settings whose absence §2 documents as deliberate, and it would reach
 * `ATTR_ERRMODE`, `ATTR_CASE` and the fetch mode, which are not preferences but
 * the contract the rest of the app is written against. Anything added here has
 * to be named, typed and validated, so `--lint` can report it and a typo cannot
 * be silently ignored.
 */
final class ConnectionOptions
{
    /**
     * `PDO::ATTR_TIMEOUT`, in seconds. For pdo_mysql this bounds the **connect**
     * phase only — never a query. 10 leaves room for a TLS handshake to a
     * managed database over a slow link; below 3 that handshake starts failing
     * on a link that would have worked, hence the warning.
     */
    public const CONNECT_TIMEOUT_DEFAULT    = 10;
    public const CONNECT_TIMEOUT_MAX        = 300;
    public const CONNECT_TIMEOUT_WARN_BELOW = 3;

    /**
     * `mysqlnd.net_read_timeout`, in seconds. The shipped PHP default is
     * **86400** on both builds, so before this option a query that never
     * answered froze the TUI for 24 hours.
     *
     * 1800 is chosen against what this timeout actually measures: the gap
     * between packets, not the length of the query. A result set of a million
     * rows streams continuously and never comes near it; what trips it is a
     * server that says nothing at all — a long aggregate, a lock wait, a dead
     * link. Half an hour of complete silence is past the point where a person
     * watching would rather be disconnected than frozen.
     *
     * Worth knowing when reading a timeout error: this kills **the connection,
     * not the query**. The server carries on executing, and {@see Database}'s
     * 2006/2013 reconnect will happily reconnect to a server still working.
     */
    public const READ_TIMEOUT_DEFAULT    = 1800;
    public const READ_TIMEOUT_MAX        = 86400;
    public const READ_TIMEOUT_WARN_BELOW = 30;
    public const READ_TIMEOUT_INI        = 'mysqlnd.net_read_timeout';

    /**
     * Which driver opens the connection.
     *
     * `auto` takes mysqli where the extension exists, because that is the only
     * one that can run a statement without blocking the process and therefore
     * the only one a query can be cancelled on (§21). `pdo` is the way back if
     * mysqli ever misbehaves against a particular server — the PDO path is
     * everything sqli did before §21 and is still under test — and `mysqli`
     * asks for it explicitly, which turns "this build cannot cancel" from a
     * silence into a startup error.
     */
    public const DRIVER_AUTO   = 'auto';
    public const DRIVER_MYSQLI = 'mysqli';
    public const DRIVER_PDO    = 'pdo';

    /** @var list<string> */
    public const DRIVERS = [self::DRIVER_AUTO, self::DRIVER_MYSQLI, self::DRIVER_PDO];

    /**
     * `CLIENT_MULTI_STATEMENTS` from the MySQL client capability flags, spelled
     * out because **mysqli does not export a constant for it** — verified
     * against the full `MYSQLI_CLIENT_*` set on PHP 8.2, which has SSL,
     * COMPRESS, INTERACTIVE, IGNORE_SPACE, NO_SCHEMA, FOUND_ROWS, the two SSL
     * verify flags and CAN_HANDLE_EXPIRED_PASSWORDS, and nothing for this. The
     * extension expects `multi_query()` to be used instead; sqli does not,
     * because {@see Splitter} has already separated the statements and each one
     * has to face the read-only and dangerous-query guards on its own.
     *
     * `1 << 16` is fixed by the client/server protocol rather than by any
     * library, which is what makes writing it here safe.
     */
    private const CLIENT_MULTI_STATEMENTS = 65536;

    /** Longest accepted `init-command`. A session preamble is not a program. */
    public const INIT_COMMAND_MAX = 1024;

    /**
     * The only command `init-command` may run.
     *
     * Not paranoia about typos — about reach. The config cascade walks **up**
     * from the cwd, so a `.sqli/` in any ancestor directory can inject an
     * init-command into every sqli run beneath it, and that command runs on
     * every connect *and* on every automatic reconnect. Bounded to `SET`, that
     * is a session preference. Unbounded, a shared checkout could hand out
     * arbitrary SQL. `USE` is refused by the same rule for a duller reason: it
     * would desynchronise {@see Database::currentDatabase()} from the session.
     */
    public const INIT_COMMAND_VERB = 'SET';

    private function __construct(
        public readonly int $connectTimeout,
        public readonly int $readTimeout,
        public readonly string $initCommand,
        public readonly bool $compress,
        public readonly bool $foundRows,
        public readonly bool $multiStatements,
        public readonly bool $ignoreSpace,
        public readonly string $driver = self::DRIVER_AUTO,
        public readonly bool $localInfile = false,
    ) {
    }

    /**
     * Should {@see Database} open a {@see MysqliLink}? Null means "decide from
     * what this build has", which is what `auto` means.
     *
     * `mysqli` returns true even on a build without the extension, deliberately:
     * Database refuses to start rather than quietly connecting through PDO, so
     * a configuration that asked for a cancellable connection never silently
     * gets one that is not.
     */
    public function preferMysqli(): ?bool
    {
        return match ($this->driver) {
            self::DRIVER_MYSQLI => true,
            self::DRIVER_PDO    => false,
            default             => null,
        };
    }

    /** The shipped defaults, for callers with no config to consult. */
    public static function defaults(): self
    {
        return new self(
            self::CONNECT_TIMEOUT_DEFAULT,
            self::READ_TIMEOUT_DEFAULT,
            '',
            false,
            false,
            false,
            false,
            self::DRIVER_AUTO,
            false,
        );
    }

    /**
     * Build from resolved config values.
     *
     * An out-of-range number is **not** clamped silently — it lands in $errors
     * and the default is used, because a `connect-timeout: 3000` quietly
     * becoming 300 is a config that lies about itself. The returned object is
     * always usable, so the caller decides whether to refuse to start.
     *
     * @param list<string> $errors   filled with problems that should stop startup
     * @param list<string> $warnings filled with values that work but are probably a mistake
     */
    public static function fromValues(
        int $connectTimeout = self::CONNECT_TIMEOUT_DEFAULT,
        int $readTimeout = self::READ_TIMEOUT_DEFAULT,
        string $initCommand = '',
        bool $compress = false,
        bool $foundRows = false,
        bool $multiStatements = false,
        bool $ignoreSpace = false,
        array &$errors = [],
        array &$warnings = [],
        string $driver = self::DRIVER_AUTO,
        bool $localInfile = false,
    ): self {
        if ($connectTimeout < 0 || $connectTimeout > self::CONNECT_TIMEOUT_MAX) {
            $errors[] = 'connect-timeout: ' . $connectTimeout . ' is out of range (0-'
                . self::CONNECT_TIMEOUT_MAX . ' seconds; 0 leaves it to the operating system)';
            $connectTimeout = self::CONNECT_TIMEOUT_DEFAULT;
        } elseif ($connectTimeout > 0 && $connectTimeout < self::CONNECT_TIMEOUT_WARN_BELOW) {
            $warnings[] = 'connect-timeout: ' . $connectTimeout
                . 's is short enough to fail on a link that would have worked — a TLS handshake '
                . 'to a remote server can take longer than that';
        }

        if ($readTimeout < 0 || $readTimeout > self::READ_TIMEOUT_MAX) {
            $errors[] = 'read-timeout: ' . $readTimeout . ' is out of range (0-'
                . self::READ_TIMEOUT_MAX . ' seconds; 0 leaves the PHP setting alone)';
            $readTimeout = self::READ_TIMEOUT_DEFAULT;
        } elseif ($readTimeout > 0 && $readTimeout < self::READ_TIMEOUT_WARN_BELOW) {
            $warnings[] = 'read-timeout: ' . $readTimeout
                . 's will disconnect any query that takes longer than that to send its first row';
        }

        $initCommand = self::validateInitCommand($initCommand, $errors, $warnings);

        $driver = strtolower(trim($driver));
        if ($driver === '') {
            $driver = self::DRIVER_AUTO;
        }
        if (!in_array($driver, self::DRIVERS, true)) {
            $errors[] = "driver: unknown value '$driver' (expected " . implode(', ', self::DRIVERS) . ')';
            $driver = self::DRIVER_AUTO;
        }
        // Asked for by name on a build that does not have it. This is an error
        // rather than a silent fallback for the same reason an unknown ssl-mode
        // is: someone wrote `driver: mysqli` to get a cancellable connection,
        // and quietly handing them one that cannot cancel is the failure they
        // would never notice — which is exactly how this was first found.
        if ($driver === self::DRIVER_MYSQLI && !MysqliLink::available()) {
            $errors[] = 'driver: mysqli was asked for, but this PHP build has no usable mysqli '
                . '(the extension and mysqli_poll are both needed). Enable it in php.ini, or use '
                . 'driver: auto to fall back to pdo — with no way to cancel a running query.';
        }

        // Not an error, and deliberately not a silent one either: this is the
        // single option here that changes what a *server* can ask of this
        // machine, so turning it on says so out loud even when nothing is wrong
        // with the configuration.
        if ($localInfile) {
            $warnings[] = 'local-infile: on — LOAD DATA LOCAL INFILE is enabled, which means the '
                . 'server on the other end can ask this client for any file the process can read. '
                . 'Turn it off (the default) unless you are loading a file right now.';
        }

        return new self(
            $connectTimeout,
            $readTimeout,
            $initCommand,
            $compress,
            $foundRows,
            $multiStatements,
            $ignoreSpace,
            $driver,
            $localInfile,
        );
    }

    /**
     * The one cross-option rule this class cannot enforce on its own, because it
     * needs a setting that is not a connection option at all.
     *
     * `read-only` is a promise that this session cannot modify data;
     * `local-infile` hands the server a way to read the user's filesystem. They
     * are not merely an odd pair — the combination is a configuration whose two
     * halves contradict each other about how much this session is trusted, and
     * the likeliest way to arrive at it is a `local-infile` inherited from an
     * ancestor `.sqli/` landing on a connection someone marked read-only for a
     * reason. Refused rather than resolved: sqli does not get to decide which
     * half the user meant.
     *
     * Returns the error, or null when the combination is fine.
     */
    public static function localInfileConflict(bool $localInfile, bool $readOnly): ?string
    {
        if (!$localInfile || !$readOnly) {
            return null;
        }
        return 'local-infile: refused together with read-only. read-only says this session may not '
            . 'change anything; local-infile lets the server read files from this machine. Drop one '
            . 'of them — they cannot both be what you meant.';
    }

    /**
     * Check `init-command` and return it normalised (terminator stripped), or
     * '' when it is unusable.
     *
     * The statement is read with {@see Splitter}, not a regular expression, and
     * that is the point: `commandKeyword()` steps past comments and resolves a
     * `WITH`, so a DROP wrapped in an executable comment is named as DROP and
     * refused rather than mistaken for a comment. A first-token check — which
     * is what this would otherwise be — is exactly the bypass the splitter was
     * built to close, and re-introducing it here would be re-opening it.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private static function validateInitCommand(string $sql, array &$errors, array &$warnings): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }
        if (strlen($sql) > self::INIT_COMMAND_MAX) {
            $errors[] = 'init-command: too long (' . strlen($sql) . ' bytes, limit '
                . self::INIT_COMMAND_MAX . ')';
            return '';
        }

        $statements = Splitter::split($sql);
        if ($statements === []) {
            $errors[] = 'init-command: no statement here — only comments or empty text';
            return '';
        }
        if (count($statements) > 1) {
            $errors[] = 'init-command: ' . count($statements)
                . ' statements given, and the server runs only one — combine them into a single '
                . 'SET, e.g. `SET SESSION a = 1, SESSION b = 2`';
            return '';
        }

        $statement = $statements[0];
        $verb = Splitter::commandKeyword($statement);
        if ($verb !== self::INIT_COMMAND_VERB) {
            $errors[] = 'init-command: only ' . self::INIT_COMMAND_VERB . ' is allowed here, and this is '
                . ($verb === '' ? 'not a statement at all' : $verb)
                . ' — it runs on every connect and on every automatic reconnect'
                . ($verb === 'USE' ? ', and USE would leave sqli disagreeing with the session about the current database' : '');
            return '';
        }

        // The DSN pins utf8mb4 and the whole app assumes it; a SET NAMES here
        // silently wins, and every subsequent mis-decoded string looks like a
        // bug somewhere else entirely.
        if (preg_match('/\bSET\s+NAMES\b/i', $statement) === 1) {
            $warnings[] = 'init-command: SET NAMES overrides the utf8mb4 character set sqli connects with '
                . '— text may come back mis-decoded';
        }

        return $statement;
    }

    /**
     * Attributes for {@see \PDO::__construct}, merged with the TLS set.
     *
     * Only what has an effect is emitted, so a default configuration produces
     * the same connection sqli built before these options existed — with one
     * deliberate exception. `MYSQL_ATTR_MULTI_STATEMENTS` is always sent,
     * including when false, because PDO's own default is **true**: leaving it
     * out would mean the capability stays advertised, which is the thing this
     * option exists to be able to turn off.
     *
     * @return array<int,mixed>
     */
    public function pdoAttributes(): array
    {
        // Both of these are sent even when false, and for the same reason: their
        // default is not ours to assume. PDO's own MULTI_STATEMENTS default is
        // true, and LOCAL_INFILE — off in mysqlnd since PHP 8.1, verified on the
        // wire (docs/beta_readiness.md, item 13) — is a default this class does
        // not control and some builds expose an ini for. Saying it explicitly
        // costs one attribute and removes the question.
        $attrs = [
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => $this->multiStatements,
            PDO::MYSQL_ATTR_LOCAL_INFILE     => $this->localInfile,
        ];
        if ($this->connectTimeout > 0) {
            $attrs[PDO::ATTR_TIMEOUT] = $this->connectTimeout;
        }
        if ($this->initCommand !== '') {
            $attrs[PDO::MYSQL_ATTR_INIT_COMMAND] = $this->initCommand;
        }
        if ($this->compress) {
            $attrs[PDO::MYSQL_ATTR_COMPRESS] = true;
        }
        if ($this->foundRows) {
            $attrs[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }
        if ($this->ignoreSpace) {
            $attrs[PDO::MYSQL_ATTR_IGNORE_SPACE] = true;
        }
        return $attrs;
    }

    /**
     * The same capability set as {@see pdoAttributes()}, as mysqli connect
     * flags, for {@see MysqliLink}.
     *
     * Note what is *not* here and is not an oversight: connect timeout, init
     * command and the public key are `mysqli_options()` calls rather than flags,
     * and are made by the link itself. Multi-statements is a flag on this side
     * and mysqli's default is off, so — unlike PDO, whose default is on and
     * which therefore has to be told `false` explicitly — the option is emitted
     * only when it is wanted. Same resulting connection, opposite defaults.
     *
     * Returns 0 when the extension is absent, so this stays callable (and
     * testable) on a build that has no mysqli to name the constants.
     */
    public function mysqliFlags(): int
    {
        if (!extension_loaded('mysqli')) {
            return 0;
        }
        $flags = 0;
        if ($this->compress) {
            $flags |= MYSQLI_CLIENT_COMPRESS;
        }
        if ($this->foundRows) {
            $flags |= MYSQLI_CLIENT_FOUND_ROWS;
        }
        if ($this->multiStatements) {
            $flags |= self::CLIENT_MULTI_STATEMENTS;
        }
        if ($this->ignoreSpace) {
            $flags |= MYSQLI_CLIENT_IGNORE_SPACE;
        }
        return $flags;
    }

    /**
     * Put the read timeout in force for connections opened after this call, and
     * return a human-readable problem when it could not be done.
     *
     * Must be called **before** `new PDO`: mysqlnd copies the value into the
     * connection as it opens it, so calling this afterwards would appear to
     * work and change nothing (see the class comment). The `ini_set` return is
     * checked rather than assumed because a build that ships this setting as
     * `PHP_INI_SYSTEM` refuses it, and silently keeping 24-hour behaviour while
     * the config says 30 minutes is the failure nobody would ever spot.
     */
    public function applyReadTimeout(): ?string
    {
        if ($this->readTimeout <= 0) {
            return null; // 0 = leave PHP's own setting exactly as it is
        }
        if (@ini_set(self::READ_TIMEOUT_INI, (string) $this->readTimeout) === false) {
            return 'read-timeout: this PHP build will not let ' . self::READ_TIMEOUT_INI
                . ' be set at runtime (currently ' . var_export(ini_get(self::READ_TIMEOUT_INI), true)
                . ') — set it in php.ini instead';
        }
        return null;
    }

    /** Short description for the log and `--lint`, listing only what is not default. */
    public function describe(): string
    {
        $parts = ['driver ' . $this->driver
            . ($this->driver === self::DRIVER_AUTO
                ? ' (' . (MysqliLink::available() ? 'mysqli' : 'pdo — a running query cannot be cancelled') . ')'
                : '')];
        $parts[] = 'connect-timeout ' . ($this->connectTimeout > 0 ? $this->connectTimeout . 's' : 'os default');
        $parts[] = 'read-timeout ' . ($this->readTimeout > 0 ? $this->readTimeout . 's' : 'php default');
        $parts[] = 'multi-statements ' . ($this->multiStatements ? 'on' : 'off');
        // Named whenever it is on, and never quietly: --lint is the only place
        // this setting is visible at all (it is deliberately kept off the header).
        if ($this->localInfile) {
            $parts[] = 'local-infile ON — the server can ask this client for local files';
        }
        foreach (['compress' => $this->compress, 'found-rows' => $this->foundRows, 'ignore-space' => $this->ignoreSpace] as $name => $on) {
            if ($on) {
                $parts[] = $name . ' on';
            }
        }
        if ($this->initCommand !== '') {
            $parts[] = 'init-command `' . $this->initCommand . '`';
        }
        return implode(', ', $parts);
    }
}
