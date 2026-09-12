<?php

declare(strict_types=1);

namespace Sqli\Db;

use includes\my;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin MySQL wrapper. Owns the connection, executes queries into QueryResult
 * value objects, and transparently reconnects once on "server has gone away"
 * (2006) / "lost connection" (2013) per spec 5.
 *
 * **Two drivers, one connection.** The handle underneath is a {@see MysqliLink}
 * when the mysqli extension is present and a `PDO` when it is not, chosen once
 * at construction and never mixed. The whole reason for the second driver is
 * that pdo_mysql cannot run a statement without blocking the process, and a
 * query nobody can interrupt is a UI that freezes — see MysqliLink's class
 * comment for why every cheaper route was measured and closed. When mysqli is
 * missing, everything here behaves exactly as it did before it existed, minus
 * {@see supportsCancel()}.
 */
final class Database
{
    private const RECONNECT_CODES = [2006, 2013];

    /**
     * Seconds between post-loss probes. One dead connection produces a burst of
     * failures — the statement, then whatever the UI asks next — and one probe
     * answers for all of them.
     */
    private const PROBE_INTERVAL = 30.0;

    /** Connect timeout for the probe, in seconds. It runs while a person waits. */
    private const PROBE_TIMEOUT = 5;

    /** When the last post-loss probe ran, for {@see PROBE_INTERVAL}. */
    private float $lastProbe = 0.0;

    /**
     * The connection is known to be dead and has not been reopened yet.
     *
     * Set the moment a 2006/2013 is seen, cleared by a successful connect. It
     * exists because nothing else can be asked: a mysqli handle whose server has
     * gone stays an object, `isConnected()` keeps saying yes, and the only way
     * to find out is to send a statement and have it fail — which is precisely
     * the experience this flag removes.
     */
    private bool $lost = false;

    /**
     * How the last connection died: phase, seconds elapsed, rows already taken.
     *
     * The flag above says the connection is gone; this says whether the
     * statement that met it ever reached the server — which is the only thing
     * that makes an automatic re-send safe. Recorded at the loss because all
     * three are gone by the time anyone asks.
     *
     * @var array{phase:string,elapsed:float,rows:int}|null
     */
    private ?array $lastLoss = null;

    private ?PDO $pdo = null;

    /** The mysqli handle, when that is the driver in use. */
    private ?MysqliLink $link = null;

    /**
     * A second connection, opened lazily, used only to `KILL QUERY` the first.
     *
     * It has to be a second connection: the session being cancelled is busy, and
     * that is the whole problem. It is opened on the first cancel rather than at
     * connect time — most sessions never cancel anything, and an idle connection
     * per session is a real cost on a server with a connection limit — and then
     * kept, so a second cancel does not pay for a handshake again.
     */
    private ?MysqliLink $killer = null;

    /** When the in-flight async statement started, for its elapsed time. */
    private float $asyncStart = 0.0;

    /**
     * The in-flight statement's text, kept for the log alone.
     *
     * The reap happens an unbounded number of frames after the send, and the
     * caller that had the SQL returned long ago — so a failure collected there
     * would otherwise be recorded without saying what failed.
     */
    private string $asyncSql = '';

    private TlsOptions $tls;

    private ConnectionOptions $options;

    /** Cipher of the live session, `''` when the connection is plaintext. */
    private string $tlsCipher = '';

    /** Whether the live session is compressed; null when compression was never requested. */
    private ?bool $compressed = null;

    /** Is the connection a {@see MysqliLink} (and therefore cancellable)? */
    private bool $useMysqli = false;

    /**
     * The flight recorder every connection event is written to.
     *
     * Owned here because this is the one object that sees all four ways a
     * statement can reach the server — the sync path, the async send, the async
     * reap and the reconnect — and a recorder that only saw some of them would
     * report idle gaps that never happened.
     */
    private ConnectionLog $log;

    public function __construct(
        private readonly string $host = 'localhost',
        private readonly string $user = '',
        private readonly string $password = '',
        private string $database = '',
        private readonly int $port = 3306,
        private readonly string $socket = '',
        private readonly bool $debug = false,
        ?TlsOptions $tls = null,
        ?ConnectionOptions $options = null,
        ?bool $preferMysqli = null,
    ) {
        $this->tls = $tls ?? TlsOptions::disabled();
        $this->options = $options ?? ConnectionOptions::defaults();
        $this->log = new ConnectionLog();
        // The `driver` option decides this; the constructor argument overrides
        // it, which is how the tests pin a driver deliberately rather than
        // getting whichever one the machine happens to have. null from both
        // means auto: mysqli if this build has it.
        $preferMysqli ??= $this->options->preferMysqli();
        $this->useMysqli = $preferMysqli ?? MysqliLink::available();
        if ($this->useMysqli && !MysqliLink::available()) {
            $this->useMysqli = false;
        }
    }

    /** True when a running query can be cancelled — i.e. the driver is mysqli. */
    public function supportsCancel(): bool
    {
        return $this->useMysqli;
    }

    /**
     * Which driver opened this connection: `mysqli` or `pdo`.
     *
     * Deliberately the same two words the `driver` option takes, so what the
     * header shows is exactly what you would type to pin it. Separate from
     * {@see supportsCancel()} even though the two agree today: one names the
     * driver, the other names a capability, and the header wants the first.
     */
    public function driverName(): string
    {
        return $this->useMysqli ? ConnectionOptions::DRIVER_MYSQLI : ConnectionOptions::DRIVER_PDO;
    }

    /**
     * Open the connection, honouring the TLS policy.
     *
     * Under `preferred` a server that speaks no TLS is retried in plaintext —
     * see {@see TlsOptions::shouldRetryPlaintext()} for why that retry is
     * deliberately narrow. Under `required` and the `verify-*` modes the
     * session's own cipher is read back and an unencrypted connection is
     * rejected here: asking the driver for TLS and *checking* you got it are
     * different things, and only the second one is a guarantee.
     *
     * @throws PDOException
     */
    public function connect(): void
    {
        // Before `new PDO`, and again on every reconnect: mysqlnd reads this
        // when it opens a connection and never again, so a value applied late
        // applies to nothing. Cheap enough to repeat, and repeating it is what
        // keeps an auto-reconnect from silently inheriting the 24-hour default.
        $readTimeoutProblem = $this->options->applyReadTimeout();
        if ($readTimeoutProblem !== null && $this->debug) {
            my::lineout('FILE:FILE', $readTimeoutProblem);
        }

        // A Unix socket cannot carry TLS: PHP's `unix://` stream has no crypto
        // layer, so arming TLS over one fails the connection outright — 2002,
        // "Cannot connect to MySQL using SSL", plus a `This stream does not
        // support SSL/crypto` warning on the terminal — before the server is
        // ever asked. `mysql` draws the same line: under `--ssl-mode=PREFERRED`
        // a socket connection is simply not encrypted. So don't try. The
        // fallback below would recover the connection either way, but only
        // after a failed handshake, and `host=localhost` on Linux is the
        // ordinary case, not an edge one.
        $onSocket = TlsOptions::isSocketTransport($this->host, $this->socket);
        $withTls  = !($onSocket && $this->tls->mode === TlsOptions::PREFERRED);
        if ($onSocket && !$withTls && $this->debug) {
            my::lineout('FILE:FILE', 'socket transport: connecting plaintext under preferred');
        }

        try {
            $this->openConnection($withTls);
        } catch (\Throwable $e) {
            $code = self::errorCodeOf($e);
            // Same failure under `required` and the `verify-*` modes, where no
            // downgrade is allowed. Nothing about "Cannot connect to MySQL
            // using SSL" says which side refused, so say it here: the answer is
            // this side, and the fix is a TCP connection.
            if ($onSocket && $this->tls->requiresTls()
                && TlsOptions::isTlsUnavailable($code, $e->getMessage())) {
                throw new PDOException(
                    'ssl-mode is ' . $this->tls->mode . ' but this connection goes over a Unix socket, '
                    . 'which PHP cannot encrypt. Connect over TCP instead (host 127.0.0.1, not localhost), '
                    . 'or set ssl-mode to preferred — a socket is already local to the server.',
                    $code
                );
            }
            if (!$withTls || !$this->tls->shouldRetryPlaintext($code, $e->getMessage())) {
                throw $e;
            }
            if ($this->debug) {
                my::lineout('FILE:FILE', 'tls unavailable, retrying plaintext', $code, $e->getMessage());
            }
            $this->openConnection(false);
        }

        $this->tlsCipher = $this->readSessionCipher();
        if ($this->tls->requiresTls() && $this->tlsCipher === '') {
            $this->disconnect();
            throw new PDOException(
                'ssl-mode is ' . $this->tls->mode . ' but the connection is not encrypted. '
                . 'The server accepted a plaintext session; check that it offers TLS, or set ssl-mode to preferred.'
            );
        }
        $this->compressed = $this->options->compress ? $this->readSessionStatus('Compression') === 'ON' : null;
        if ($this->compressed === false && $this->debug) {
            my::lineout('FILE:FILE', 'compress was requested but the session is not compressed');
        }
        if ($this->debug) {
            my::lineout('FILE:FILE', 'connected', $this->getConnectionLabel(), 'tls', $this->tlsDescription());
        }

        $this->lost = false;

        // The session's own limits, read here because here is the only place
        // they can be read: they describe the connection that a later 2006 will
        // have destroyed, and asking a *new* connection what the dead one's
        // wait_timeout was is asking a different question. One statement.
        $snapshot = $this->readSessionSnapshot();
        $this->log->connected(
            driver: $this->driverName(),
            label: $this->getConnectionLabel(),
            threadId: $snapshot['thread_id'] ?? 0,
            serverVersion: $this->serverVersion(),
            tls: $this->tlsDescription(),
            limits: $snapshot['limits'] ?? [],
        );
    }

    /**
     * Did compression actually engage? `null` when it was never asked for.
     *
     * Asking for something and getting it are different things — the lesson the
     * TLS cipher read-back was written for, and compression is the one other
     * option here whose effect the session will state plainly. It is only a
     * performance setting, so a `false` is logged rather than fatal.
     */
    public function compressionActive(): ?bool
    {
        return $this->compressed;
    }

    /**
     * The TLS state of the live connection: the negotiated cipher, or `''` when
     * the session is plaintext (including when it was never connected).
     */
    public function tlsCipher(): string
    {
        return $this->tlsCipher;
    }

    /** Configured TLS policy, for the status line and `--lint`. */
    public function tlsMode(): string
    {
        return $this->tls->mode;
    }

    /** `TLS TLS_AES_256_GCM_SHA384` / `not encrypted`, for humans. */
    public function tlsDescription(): string
    {
        return $this->tlsCipher !== '' ? 'TLS ' . $this->tlsCipher : 'not encrypted';
    }

    /**
     * Is the **live** session encrypted? Read from the cipher the server
     * actually negotiated, never from what was asked for — the distinction that
     * once shipped a plaintext release wearing a TLS label (§2). The header asks
     * this once a frame rather than being told, because the plaintext retry and
     * the 2006 reconnect can both change the answer under a running session.
     */
    public function isEncrypted(): bool
    {
        return $this->tlsCipher !== '';
    }

    /**
     * Open whichever driver is in use, with or without TLS armed.
     *
     * The plaintext retry is expressed as a boolean rather than as "call me with
     * an empty attribute array", because the two drivers arm TLS by different
     * means — PDO through the attribute set, mysqli through a connect flag — and
     * a retry that only knows how to empty one of them would silently keep TLS
     * on for the other.
     */
    private function openConnection(bool $withTls): void
    {
        // Close whatever is already here first. connect() is re-entered by the
        // 2006/2013 reconnect and by the plaintext retry, and a mysqli handle
        // left behind by either is a socket nothing will ever close again.
        $this->disconnect();

        if ($this->useMysqli) {
            $this->link = new MysqliLink(
                $this->host, $this->user, $this->password, $this->database,
                $this->port, $this->socket, $this->debug, $this->tls, $this->options,
            );
            $this->link->open($withTls);
            return;
        }
        $this->open($withTls ? $this->tls->pdoAttributes() : []);
    }

    /** Drop the live handle, whichever driver holds it. */
    private function disconnect(): void
    {
        $this->pdo = null;
        $this->link?->close();
        $this->link = null;
    }

    /**
     * The MySQL error number behind a failure, from either driver. PDO carries
     * it in `errorInfo[1]` and mysqli in the exception code, and connect-time
     * PDO failures sometimes have neither — hence the fall back to `getCode()`.
     */
    private static function errorCodeOf(\Throwable $e): int
    {
        if ($e instanceof PDOException) {
            return (int) ($e->errorInfo[1] ?? $e->getCode());
        }
        return (int) $e->getCode();
    }

    /** @param array<int,mixed> $tlsAttrs */
    private function open(array $tlsAttrs): void
    {
        $dsn = $this->buildDsn();
        if ($this->debug) {
            my::lineout('FILE:FILE', 'connecting dsn', $dsn, 'tls', $this->tls->describe(),
                'options', $this->options->describe());
        }
        // The three at the bottom are not options and never will be: every error
        // path in query() is built on the exception, the grid is keyed on column
        // names, and the formatters are written against real PHP types. `+` keeps
        // them last-resort defaults, but nothing above may supply them.
        $this->pdo = new PDO($dsn, $this->user, $this->password, $tlsAttrs + $this->options->pdoAttributes() + [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }

    /**
     * `SHOW SESSION STATUS LIKE 'Ssl_cipher'` — empty value means plaintext.
     * Never throws: a server that won't answer this is reported as unencrypted,
     * which is the safe reading (and under `required` becomes a hard failure).
     */
    private function readSessionCipher(): string
    {
        return $this->readSessionStatus('Ssl_cipher');
    }

    /**
     * One `SHOW SESSION STATUS` value, or `''` when it cannot be read. Never
     * throws: a server that won't answer is reported as the empty/negative
     * answer, which is the safe reading for every caller here.
     */
    private function readSessionStatus(string $name): string
    {
        if ($this->link !== null) {
            return $this->link->sessionStatus($name);
        }
        if ($this->pdo === null) {
            return '';
        }
        // Interpolated, not bound, so the TLS read-back stays byte-for-byte the
        // statement that was proven on the wire. Safe because every caller is
        // in this file and passes a literal; the guard keeps it that way.
        if (preg_match('/^[A-Za-z_]+$/', $name) !== 1) {
            return '';
        }
        try {
            $row = $this->pdo->query("SHOW SESSION STATUS LIKE '" . $name . "'")->fetch();
            return is_array($row) ? trim((string) ($row['Value'] ?? '')) : '';
        } catch (PDOException $e) {
            if ($this->debug) {
                my::lineout('FILE:FILE', 'could not read session status', $name, $e->getMessage());
            }
            return '';
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null || $this->link?->isOpen() === true;
    }

    /**
     * Is the connection known to be dead? True from the 2006/2013 until it has
     * been reopened — which is what the header's red notice is drawn from.
     */
    public function isLost(): bool
    {
        return $this->lost;
    }

    /**
     * Did the statement that lost the connection provably never reach the
     * server? False whenever there is no answer — an unrecorded loss is not
     * evidence of anything, and this gates repeating writes.
     *
     * @see ConnectionLog::neverRan() for what the three facts mean.
     */
    public function lostBeforeItRan(): bool
    {
        return $this->lastLoss !== null && ConnectionLog::neverRan(
            $this->lastLoss['phase'],
            $this->lastLoss['elapsed'],
            $this->lastLoss['rows'],
        );
    }

    /**
     * Reopen a connection the server dropped. Returns null on success, or the
     * reason it could not be reopened.
     *
     * **Reconnect only. It re-runs nothing**, which is what keeps it compatible
     * with §21's refusal to retry an async statement: the statement that met the
     * dead socket has already been reported as an error, and this puts a live
     * session behind the *next* thing the user asks for. Nothing can be repeated
     * because nothing is replayed.
     */
    public function reconnect(): ?string
    {
        try {
            $this->connect();
            return null;
        } catch (\Throwable $e) {
            return $this->formatError($e);
        }
    }

    /**
     * The session's thread id and the four limits a lost connection is read
     * against, in one statement.
     *
     * `CONNECTION_ID()` is included rather than taken from the mysqli handle so
     * that both drivers report the same field from the same place — and because
     * the PDO path has no handle to ask.
     *
     * Never throws. A server that will not answer this (an old MariaDB, a proxy
     * that rewrites system-variable reads) costs the log its `limits=` field and
     * nothing else; a connection is not worth refusing over a diagnostic.
     *
     * @return array{thread_id?:int, limits?:array<string,int>}
     */
    private function readSessionSnapshot(): array
    {
        $sql = 'SELECT CONNECTION_ID() AS thread_id,'
            . ' @@session.wait_timeout AS wait_timeout,'
            . ' @@session.interactive_timeout AS interactive_timeout,'
            . ' @@session.net_read_timeout AS net_read_timeout,'
            . ' @@session.net_write_timeout AS net_write_timeout,'
            . ' @@session.max_allowed_packet AS max_allowed_packet';
        try {
            $row = $this->run($sql, microtime(true))->rows[0] ?? [];
        } catch (\Throwable $e) {
            if ($this->debug) {
                my::lineout('FILE:FILE', 'could not read the session snapshot', $e->getMessage());
            }
            return [];
        }
        $limits = [];
        foreach (['wait_timeout', 'interactive_timeout', 'net_read_timeout',
                  'net_write_timeout', 'max_allowed_packet'] as $name) {
            if (isset($row[$name])) {
                $limits[$name] = (int) $row[$name];
            }
        }
        return ['thread_id' => (int) ($row['thread_id'] ?? 0), 'limits' => $limits];
    }

    /** The server's version string, from whichever driver holds the connection. */
    private function serverVersion(): string
    {
        if ($this->link !== null) {
            return $this->link->serverVersion();
        }
        try {
            return (string) $this->pdo?->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * After a connection is lost: what can a *fresh* connection see?
     *
     * This answers the one question the dead session cannot. `Uptime` shorter
     * than the dead session's age means the server restarted or failed over —
     * proof, not inference, and the difference between "tune your timeouts" and
     * "your database bounced". A connection that will not open at all is the
     * other decisive answer, and is recorded as such.
     *
     * TLS is armed only if the dead session actually had it, so a plaintext
     * session under `preferred` is not probed with a handshake that was already
     * known to fail — that would report a reachable server as unreachable.
     *
     * @return array<string,mixed>
     */
    private function probeServer(): array
    {
        // A dead connection usually produces several failures in a row — the
        // statement, then whatever the UI tries next. One probe answers for all
        // of them, and each probe costs a connect timeout.
        $now = microtime(true);
        if ($now - $this->lastProbe < self::PROBE_INTERVAL) {
            return [];
        }
        $this->lastProbe = $now;
        $withTls = $this->tlsCipher !== '';

        try {
            if ($this->useMysqli) {
                // Its own short timeouts, not the session's. The configured
                // connect-timeout is up to 300s and the read-timeout up to a
                // day; a diagnostic that freezes the UI for either is worse than
                // no diagnostic. Everything else about the connection is
                // irrelevant to reading one status variable.
                $bounded = ConnectionOptions::fromValues(
                    connectTimeout: self::PROBE_TIMEOUT,
                    readTimeout: self::PROBE_TIMEOUT,
                );
                $probe = new MysqliLink(
                    $this->host, $this->user, $this->password, $this->database,
                    $this->port, $this->socket, $this->debug, $this->tls, $bounded,
                );
                $probe->open($withTls);
                $uptime = (int) $probe->sessionStatus('Uptime');
                $probe->close();
                return ['reachable' => true, 'uptime' => $uptime];
            }
            $probe = new PDO(
                $this->buildDsn(),
                $this->user,
                $this->password,
                ($withTls ? $this->tls->pdoAttributes() : []) + [
                    PDO::ATTR_TIMEOUT => self::PROBE_TIMEOUT,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            );
            // Reachability is decided by the connect, never by the read. A
            // server that hands out a session and then refuses this statement —
            // an old MariaDB, a proxy that filters `SHOW` — is emphatically
            // reachable, and reporting it as an outage would put the most
            // decisive verdict there is on the least evidence.
            $uptime = 0;
            try {
                $row = $probe->query("SHOW SESSION STATUS LIKE 'Uptime'")->fetch(PDO::FETCH_ASSOC);
                $uptime = (int) (is_array($row) ? ($row['Value'] ?? 0) : 0);
            } catch (\Throwable $refused) {
                unset($refused); // see the note on the outer catch
            }
            $probe = null;
            return ['reachable' => true, 'uptime' => $uptime];
        } catch (\Throwable $e) {
            // The message is copied out and the exception dropped on purpose.
            // A caught PDOException keeps the handle that threw it alive inside
            // its own stack trace, so simply returning here would leave the
            // probe's connection open across the reconnect that follows — an
            // extra session on a server that may be at its connection limit,
            // held for exactly the moment things are already going wrong. It
            // cost a deadlock against a single-threaded test server, which is
            // the polite version of the bug a busy production one would show.
            $message = $e->getMessage();
            unset($e, $probe);
            return ['reachable' => false, 'error' => $message];
        }
    }

    /**
     * Is this the death of the connection rather than a complaint about the
     * statement? If so, record everything that separates the six causes.
     *
     * Called from all three execution paths, which is the point: 2006 at `send`,
     * at `wait` and mid-`stream` are three different events wearing one error
     * code, and only the caller knows which one it just saw.
     */
    private function noteLoss(\Throwable $e, string $phase, string $sql, float $elapsed, int $rows): void
    {
        $code = self::errorCodeOf($e);
        if (!in_array($code, self::RECONNECT_CODES, true)) {
            return;
        }
        $this->lost = true;
        $this->lastLoss = ['phase' => $phase, 'elapsed' => $elapsed, 'rows' => $rows];
        $this->log->lost(
            code: $code,
            message: $e->getMessage(),
            phase: $phase,
            sql: $sql,
            elapsed: $elapsed,
            rows: $rows,
            readTimeout: $this->options->readTimeout,
            probe: $this->probeServer(),
        );
    }

    /**
     * Is this failure "wrong credentials" — the one connection error a retry can
     * actually fix?
     *
     * Deliberately narrow. MySQL **1045** is `Access denied for user 'x'@'h'
     * (using password: YES)`: the server did not accept who you are, so asking
     * again is worth something. **1044** reads almost identically — `Access
     * denied for user 'x'@'h' to database 'd'` — but it means the credentials
     * were fine and the *grant* is missing, so re-prompting would make the user
     * retype a correct password three times and learn nothing. The `using
     * password` clause is what separates them when no error code survives.
     *
     * Public + static so the classification can be pinned without a server.
     */
    public static function isAuthFailure(\Throwable $e): bool
    {
        if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1045) {
            return true;
        }
        $m = strtolower($e->getMessage());
        if (str_contains($m, '[1044]') || str_contains($m, 'to database')) {
            return false;
        }
        return str_contains($m, '[1045]')
            || (str_contains($m, 'access denied') && str_contains($m, 'using password'));
    }

    public function getConnectionLabel(): string
    {
        $where = $this->socket !== '' ? $this->socket : $this->host . ':' . $this->port;
        $db = $this->database !== '' ? '/' . $this->database : '';
        $who = $this->user !== '' ? $this->user . '@' : '';
        return $who . $where . $db;
    }

    /**
     * Human-readable connection target for the pre-connect "Connecting to …"
     * message, e.g. `mydb on 10.0.0.5:3306` or `on /tmp/mysql.sock` when no
     * database is named. No user/password — this is shown on the plain terminal.
     */
    public function target(): string
    {
        $where = $this->socket !== '' ? $this->socket : $this->host . ':' . $this->port;
        $db = $this->database !== '' ? $this->database . ' ' : '';
        return $db . 'on ' . $where;
    }

    public function currentDatabase(): string
    {
        return $this->database;
    }

    /**
     * The key persisted UI state is filed under: `host:port/db`, or `socket/db`
     * on a socket connection. `''` when no database is selected — which every
     * caller already reads as "nothing to save or restore".
     *
     * The database *name* is not unique. `prod` on a staging box and `prod` in
     * production are different databases that happen to share a name, and
     * keying state on the name alone let them silently overwrite each other's
     * column widths, order, formats, hidden columns and dictionary association.
     *
     * The user is deliberately left out: reconnecting as someone else is still
     * the same database, and should not lose the layout.
     */
    public function stateKey(): string
    {
        if ($this->database === '') {
            return '';
        }
        $where = $this->socket !== '' ? $this->socket : $this->host . ':' . $this->port;
        return $where . '/' . $this->database;
    }

    public function setCurrentDatabase(string $db): void
    {
        $this->database = $db;
    }

    /**
     * Execute a statement and return a QueryResult. Never throws for query
     * errors — they are captured in the result's error field.
     */
    public function query(string $sql): QueryResult
    {
        // A known-dead connection is reopened before it is used, rather than
        // discovered again by failing. The App does this visibly for statements
        // the user typed; this is the same thing for everything else — the
        // schema cache, the Data Dictionary, the exporter — none of which has a
        // place to show a notice, and all of which would otherwise hand back an
        // error for a server that is perfectly well.
        if ($this->lost) {
            $problem = $this->reconnect();
            if ($problem !== null) {
                return QueryResult::fromError($problem);
            }
        }
        if (!$this->isConnected()) {
            return QueryResult::fromError('Not connected to a database');
        }

        $start = microtime(true);
        try {
            $result = $this->run($sql, $start);
            $this->log->statement($sql, microtime(true) - $start, $result->rowCount());
            return $result;
        } catch (\Throwable $e) {
            $code = self::errorCodeOf($e);
            $this->noteLoss($e, ConnectionLog::PHASE_SYNC, $sql, microtime(true) - $start, 0);
            if (in_array($code, self::RECONNECT_CODES, true)) {
                if ($this->debug) {
                    my::lineout('FILE:FILE', 'reconnecting on code', $code);
                }
                try {
                    $this->connect();
                    $result = $this->run($sql, $start);
                    $this->log->statement($sql, microtime(true) - $start, $result->rowCount());
                    return $result;
                } catch (\Throwable $retry) {
                    $error = $this->formatError($retry);
                    $this->log->statement($sql, microtime(true) - $start, 0, 'after reconnect: ' . $error);
                    return QueryResult::fromError($error, microtime(true) - $start);
                }
            }
            $error = $this->formatError($e);
            $this->log->statement($sql, microtime(true) - $start, 0, $error);
            return QueryResult::fromError($error, microtime(true) - $start);
        }
    }

    private function run(string $sql, float $start): QueryResult
    {
        if ($this->link !== null) {
            return $this->link->run($sql, $start);
        }
        return $this->runPdo($sql, $start);
    }

    private function runPdo(string $sql, float $start): QueryResult
    {
        $stmt = $this->pdo->query($sql);
        $elapsed = microtime(true) - $start;

        // A result-bearing statement reports one or more columns.
        if ($stmt->columnCount() > 0) {
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'] ?? ('col' . $i);
            }
            $rows = $stmt->fetchAll();
            $this->drainRowsets($stmt);
            return new QueryResult(
                columns: $columns,
                rows: $rows,
                elapsed: $elapsed,
                isSelect: true,
            );
        }

        $affected = $stmt->rowCount();
        $this->drainRowsets($stmt);
        return new QueryResult(
            affectedRows: $affected,
            elapsed: $elapsed,
            isSelect: false,
        );
    }

    /**
     * Consume any result sets after the first, which only the first is ever
     * shown from.
     *
     * `CALL` returns one rowset per SELECT in the procedure plus a final status
     * packet, and leaving them unread poisons the *next* query on the connection
     * with "Cannot execute queries while other unbuffered queries are active" —
     * a failure that lands on an innocent statement and points nowhere near the
     * procedure that caused it. Errors while draining are swallowed for the same
     * reason: this is cleanup, and the result being returned is already good.
     */
    private function drainRowsets(PDOStatement $stmt): void
    {
        try {
            while ($stmt->nextRowset()) {
                // discarded on purpose — see above
            }
        } catch (PDOException) {
            // some drivers throw rather than return false at the end
        }
        $stmt->closeCursor();
    }

    private function buildDsn(): string
    {
        $parts = ['charset=utf8mb4'];
        if ($this->socket !== '') {
            $parts[] = 'unix_socket=' . $this->socket;
        } else {
            $parts[] = 'host=' . $this->host;
            $parts[] = 'port=' . $this->port;
        }
        if ($this->database !== '') {
            $parts[] = 'dbname=' . $this->database;
        }
        return 'mysql:' . implode(';', $parts);
    }

    private function formatError(\Throwable $e): string
    {
        $code = $e instanceof PDOException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode();
        $msg  = $e instanceof PDOException ? ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage();
        if ($this->debug) {
            my::lineout('FILE:FILE', 'query error code', $code, 'msg', (string) $msg);
        }
        return trim('[' . $code . '] ' . $msg);
    }

    // ---------------------------------------------------------------- async --
    //
    // The cancellable path (§21). Only user-submitted statements use it; the
    // metadata queries behind the schema cache, the Data Dictionary and the
    // exporter stay synchronous, because they are bounded work that was never
    // what froze the UI, and every one of them would otherwise need its own
    // resumption point.

    /**
     * Send a statement and return at once. The caller then polls
     * {@see asyncReady()} from its frame loop and collects with
     * {@see asyncReap()}.
     *
     * Returns a QueryResult only when the statement could not be *sent* — a
     * failure that has no in-flight query to wait for, and so has to be reported
     * immediately in the same shape as any other error.
     */
    public function startAsync(string $sql): ?QueryResult
    {
        $this->asyncStart = microtime(true);
        $this->asyncSql   = $sql;
        // The App reopens a lost connection before it gets here, with the header
        // saying so. This is the backstop for any path that does not, and for
        // the window between the two.
        if ($this->lost) {
            $problem = $this->reconnect();
            if ($problem !== null) {
                return QueryResult::fromError($problem, microtime(true) - $this->asyncStart);
            }
        }
        if ($this->link === null || !$this->isConnected()) {
            return QueryResult::fromError('Not connected to a database');
        }
        try {
            $this->link->start($sql);
            return null;
        } catch (\Throwable $e) {
            // A failure here is a failure to *send*: whatever killed the
            // connection did so while it sat idle, before this statement, which
            // is what makes the idle gap on the log line the decisive number.
            $elapsed = microtime(true) - $this->asyncStart;
            $this->noteLoss($e, ConnectionLog::PHASE_SEND, $sql, $elapsed, 0);
            $error = $this->formatError($e);
            $this->log->statement($sql, $elapsed, 0, $error);
            return QueryResult::fromError($error, $elapsed);
        }
    }

    /** Is an async statement waiting to be collected? */
    public function asyncInFlight(): bool
    {
        return $this->link?->inFlight() === true;
    }

    /** Has the in-flight statement finished? Never waits. */
    public function asyncReady(): bool
    {
        return $this->link?->ready() === true;
    }

    /** How long the in-flight statement has been running, in seconds. */
    public function asyncElapsed(): float
    {
        return $this->asyncStart > 0.0 ? microtime(true) - $this->asyncStart : 0.0;
    }

    /**
     * Take as much of the answer as fits in one frame. Returns the finished
     * result, or **null meaning "still arriving — call again next frame"**.
     *
     * The null is not a nicety. `mysqli_poll()` reports a statement ready when
     * its *first packet* lands, not when it has finished, so a query that
     * answers immediately and then streams for a second would otherwise spend
     * that whole second inside this call with the loop stopped — a frozen
     * clock, a still spinner and a dead Esc. The rows are taken a bounded chunk
     * at a time instead, and the caller repaints in between.
     *
     * A cancelled query surfaces here as MySQL **1317**, `Query execution was
     * interrupted` — an ordinary query error, on a connection that is still
     * open. That last part is the whole point of cancelling rather than timing
     * out: the read timeout gets the UI back by dropping the connection while
     * the server keeps working, and this gets it back by stopping the work.
     *
     * There is deliberately no reconnect-and-retry here, unlike {@see query()}.
     * A 2006/2013 during an async statement means the connection died mid-query;
     * silently re-running it could repeat a write that had already landed, and
     * this path is the one the user reaches with arbitrary SQL.
     */
    public function asyncReap(): ?QueryResult
    {
        if ($this->link === null) {
            return QueryResult::fromError('Not connected to a database');
        }
        try {
            $result = $this->link->reap($this->asyncStart);
            if ($result === null) {
                // A chunk of the result set landed. It counts as the server
                // having spoken — both for the idle gap here and for the
                // server's own net_write_timeout, which it resets in exactly the
                // same way.
                $this->log->answered();
                return null;
            }
            $this->log->statement($this->asyncSql, $result->elapsed, $result->rowCount(), $result->error);
            return $result;
        } catch (\Throwable $e) {
            // Asked before anything is reset: the link still knows whether it
            // was waiting for the first packet or part-way through a result set,
            // and that is the one thing the error code will not say.
            $phase = $this->link->phase();
            $rows  = $this->link->streamedRows();
            $this->noteLoss($e, $phase, $this->asyncSql, $this->asyncElapsed(), $rows);
            $error = $this->formatError($e);
            $this->log->statement($this->asyncSql, $this->asyncElapsed(), $rows, $error);
            return QueryResult::fromError($error, $this->asyncElapsed());
        }
    }

    /**
     * Ask the server to stop the in-flight statement. Returns null when the
     * cancel was sent, or a human-readable reason it could not be.
     *
     * Sending it is not the same as it having happened: the query stops when the
     * server notices, and the result of that is whatever {@see asyncReap()}
     * returns next — usually 1317, but a statement that finished in the gap
     * returns its rows and is *not* an error. The caller must therefore treat a
     * successful cancel as a request, not as an outcome.
     */
    public function cancelAsync(): ?string
    {
        // inFlight stays true while a result set is still being taken off the
        // wire, not just while the server is thinking — so a cancel works for
        // the whole life of the statement, including a long transfer.
        if ($this->link === null || !$this->link->inFlight()) {
            return 'nothing is running';
        }
        $threadId = $this->link->threadId();
        if ($threadId <= 0) {
            return 'the connection did not report a thread id';
        }

        // Lazily opened, then kept: see the property comment. A killer that has
        // been idle long enough for the server to have dropped it is reopened
        // once rather than reported as a failure, because the cancel it was
        // opened for is happening now.
        $problem = 'the server refused the cancel';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            if ($this->killer === null || !$this->killer->isOpen()) {
                try {
                    $this->killer = new MysqliLink(
                        $this->host, $this->user, $this->password, $this->database,
                        $this->port, $this->socket, $this->debug, $this->tls, $this->options,
                    );
                    $this->killer->open();
                } catch (\Throwable $e) {
                    $this->killer = null;
                    return 'could not open a second connection to cancel on: ' . $e->getMessage();
                }
            }
            $problem = $this->killer->kill($threadId);
            if ($problem === null) {
                if ($this->debug) {
                    my::lineout('FILE:FILE', 'cancel sent for thread', $threadId);
                }
                return null;
            }
            $this->killer->close();
            $this->killer = null;
        }
        return $problem;
    }
}
