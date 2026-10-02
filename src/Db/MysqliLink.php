<?php

declare(strict_types=1);

namespace Sqli\Db;

use includes\my;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * The mysqli half of {@see Database} — the one that can run a query without
 * blocking the process, and therefore the one that makes cancelling possible.
 *
 * **Why a second driver exists at all.** pdo_mysql has no asynchronous mode:
 * `PDO::query()` blocks until the server answers, so while it runs, App's frame
 * loop is not running either — nothing repaints, nothing reads a keystroke, and
 * nothing can send a `KILL`. The measurements in `docs/beta_readiness.md` closed
 * the alternatives one at a time: a `pcntl` SIGINT handler never fires (mysqlnd
 * retries the interrupted read and the VM is not executing), and the read
 * timeout kills *the connection, not the query* — the server carries on, so it
 * is a backstop against hanging forever rather than cancellation. The only
 * mechanism left is `MYSQLI_ASYNC` + `mysqli_poll()`, and it is mysqli-only.
 *
 * **Why it is a second driver rather than a replacement.** The mysqli extension
 * is not loaded on every build — it is shipped but commented out in the Windows
 * php.ini this was developed against — and sqli has needed nothing but
 * pdo_mysql since it existed. Making mysqli mandatory would break installs to
 * add a feature, so {@see Database} prefers this link when the extension is
 * there and keeps the PDO path, untouched and still under test, when it is not.
 * The cost is that the connection is described twice; the alternative was
 * describing it once and refusing to start for some users.
 *
 * **One connection, either way.** This is a *replacement* for the PDO handle,
 * not a companion to it. Running user statements on a second connection while
 * metadata went out on the first would put `USE`, temp tables, session
 * variables and transactions on a different session from the one asking about
 * them — the same class of bug as filing persisted state under a database name
 * that is not unique (v056). Only the killer link below is ever a second
 * connection, and it exists precisely because the first one is busy.
 */
final class MysqliLink
{
    /**
     * How much of a result set to take in one call, before handing the frame
     * loop back so it can repaint and read the keyboard.
     *
     * Both limits matter. The row count bounds the work when rows are already
     * on the socket; the time bounds it when they are not, because an
     * unbuffered fetch blocks until the *next* row arrives and a slow link
     * would otherwise spend the whole transfer inside one frame — which is the
     * bug this whole mechanism exists to avoid.
     */
    private const FETCH_ROWS = 1000;
    private const FETCH_SECONDS = 0.03;

    private ?mysqli $link = null;

    /**
     * True from {@see start()} until the statement's last row has been taken.
     *
     * Deliberately *not* cleared when `reap_async_query()` returns: with an
     * unbuffered result that call hands back a cursor, not an answer, and the
     * statement is still running on the server until the rows stop. Cancel has
     * to keep working for all of that time, and it is keyed on this.
     */
    private bool $inFlight = false;

    /** The result being drained across frames, if one is. */
    private ?mysqli_result $streaming = null;

    /** @var list<string> */
    private array $streamColumns = [];

    /** @var list<ResultSource> */
    private array $streamSources = [];

    /** @var list<int> */
    private array $streamColumnSources = [];

    /** @var list<array<string,mixed>> */
    private array $streamRows = [];

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $password,
        private readonly string $database,
        private readonly int $port,
        private readonly string $socket,
        private readonly bool $debug,
        private readonly TlsOptions $tls,
        private readonly ConnectionOptions $options,
    ) {
    }

    /**
     * Can this PHP build run a query asynchronously?
     *
     * Both halves are checked because they can differ: `mysqli_poll()` is
     * mysqlnd-only, so a build linked against libmysqlclient has the extension
     * and not the function. Asking for the function that is actually used is the
     * honest test — `extension_loaded('mysqli')` alone would promise cancel and
     * then fail at the first query.
     */
    public static function available(): bool
    {
        return extension_loaded('mysqli')
            && function_exists('mysqli_poll')
            && class_exists(mysqli::class);
    }

    /**
     * Open the connection. `$withTls = false` is the plaintext retry that
     * {@see TlsOptions::shouldRetryPlaintext()} allows under `preferred`.
     *
     * @throws mysqli_sql_exception
     */
    public function open(bool $withTls = true): void
    {
        // PHP 8.1+ reports mysqli errors as exceptions by default, but the mode
        // is global and a host application could have changed it. Setting it
        // here means every failure below arrives as an exception, which is the
        // shape Database's error handling is written against.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        $link = mysqli_init();
        if ($link === false) {
            throw new mysqli_sql_exception('could not initialise a mysqli handle');
        }

        if ($this->options->connectTimeout > 0) {
            $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, $this->options->connectTimeout);
        }
        // Off unless `local-infile` asked for it (§2). Set explicitly in both
        // directions rather than inherited: mysqli has its own ini default, so
        // leaving it out would mean a php.ini could turn on the one setting that
        // lets a server ask this client for a local file — and could equally
        // leave it off after the user asked for it on the command line.
        $link->options(MYSQLI_OPT_LOCAL_INFILE, $this->options->localInfile);
        // The mysqli equivalent of PDO::ATTR_STRINGIFY_FETCHES => false. Without
        // it every column arrives as a string and the Format layer — which is
        // written against real PHP types — starts formatting integers as text.
        $link->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
        // The read timeout as a *connection* option rather than as the ini
        // `mysqlnd.net_read_timeout` the PDO path has to use. Same setting, but
        // set on the handle it applies to, so it cannot be defeated by a build
        // that ships that ini as PHP_INI_SYSTEM and refuses it at runtime — the
        // failure ConnectionOptions::applyReadTimeout() can only report.
        if ($this->options->readTimeout > 0) {
            $link->options(MYSQLI_OPT_READ_TIMEOUT, $this->options->readTimeout);
        }
        if ($this->options->initCommand !== '') {
            $link->options(MYSQLI_INIT_COMMAND, $this->options->initCommand);
        }
        if ($this->tls->serverPublicKey !== '') {
            $link->options(MYSQLI_SERVER_PUBLIC_KEY, $this->tls->serverPublicKey);
        }

        $flags = $this->options->mysqliFlags();
        if ($withTls && $this->tls->wantsTls()) {
            [$key, $cert, $ca, $caPath, $cipher] = $this->tls->mysqliSslSet();
            $link->ssl_set($key, $cert, $ca, $caPath, $cipher);
            $flags |= $this->tls->mysqliFlags();
        }

        try {
            // Silenced deliberately. `mysqli_report()` above already turns every
            // failure here into an exception, so the warning is a duplicate — but
            // it is a duplicate written straight to the terminal, and this runs
            // under a full-screen TUI that owns every cell on the screen. TLS
            // over a Unix socket is the one that reaches it in practice: "This
            // stream does not support SSL/crypto", printed over the grid.
            @$link->real_connect(
                $this->socket !== '' ? 'localhost' : $this->host,
                $this->user,
                $this->password,
                $this->database !== '' ? $this->database : null,
                $this->socket !== '' ? 0 : $this->port,
                $this->socket !== '' ? $this->socket : null,
                $flags,
            );
        } catch (mysqli_sql_exception $e) {
            @$link->close();
            throw $e;
        }

        // The DSN pins utf8mb4 on the PDO path; mysqli has no DSN, so the same
        // guarantee has to be made by hand. Everything downstream assumes it.
        $link->set_charset('utf8mb4');
        $this->link     = $link;
        $this->inFlight = false;
    }

    public function close(): void
    {
        if ($this->streaming !== null) {
            @$this->streaming->free();
        }
        if ($this->link !== null) {
            @$this->link->close();
        }
        $this->link          = null;
        $this->streaming     = null;
        $this->streamColumns = [];
        $this->streamSources = [];
        $this->streamColumnSources = [];
        $this->streamRows    = [];
        $this->inFlight      = false;
    }

    public function isOpen(): bool
    {
        return $this->link !== null;
    }

    public function inFlight(): bool
    {
        return $this->inFlight;
    }

    /**
     * The server's id for this session, which is what `KILL QUERY` names.
     *
     * Read from the handle rather than with `SELECT CONNECTION_ID()` — the
     * connection whose id is wanted is by definition the busy one, so a query
     * is exactly what cannot be used to ask.
     */
    public function threadId(): int
    {
        return $this->link !== null ? (int) $this->link->thread_id : 0;
    }

    /** The server's own version string, for the connection log. */
    public function serverVersion(): string
    {
        return $this->link !== null ? (string) $this->link->server_info : '';
    }

    /**
     * Where in a statement's life this link is, in {@see ConnectionLog}'s terms.
     *
     * Only meaningful at the moment something throws, and that is the only place
     * it is asked. The distinction it draws — sent-and-silent versus
     * rows-were-arriving-and-stopped — is invisible from the error code, which
     * is 2006 either way, and it is the difference between an idle timeout and a
     * transfer the server abandoned.
     */
    public function phase(): string
    {
        if (!$this->inFlight) {
            return ConnectionLog::PHASE_SEND;
        }
        return $this->streaming !== null ? ConnectionLog::PHASE_STREAM : ConnectionLog::PHASE_WAIT;
    }

    /** How many rows of the in-flight result set have been taken so far. */
    public function streamedRows(): int
    {
        return count($this->streamRows);
    }

    /**
     * One `SHOW SESSION STATUS` value, or `''` when it cannot be read. Never
     * throws: the callers treat an unreadable answer as the negative one, which
     * for the TLS cipher is the safe reading.
     */
    public function sessionStatus(string $name): string
    {
        if ($this->link === null || preg_match('/^[A-Za-z_]+$/', $name) !== 1) {
            return '';
        }
        try {
            $res = $this->link->query("SHOW SESSION STATUS LIKE '" . $name . "'");
            if (!$res instanceof mysqli_result) {
                return '';
            }
            $row = $res->fetch_assoc();
            $res->free();
            return is_array($row) ? trim((string) ($row['Value'] ?? '')) : '';
        } catch (mysqli_sql_exception $e) {
            if ($this->debug) {
                my::lineout('FILE:FILE', 'could not read session status', $name, $e->getMessage());
            }
            return '';
        }
    }

    /**
     * mysqli's field metadata in the shape {@see ResultColumns} reads.
     *
     * mysqli is the driver that knows the whole story: `table` is the alias the
     * SQL used, `orgtable` the real table behind it, and `db` the database. The
     * PDO path can only fill in the first of the three, which is why the header
     * tile reads `prod.templates t(1)` here and `t(1)` there.
     *
     * @return list<array{name:string,alias:string,table:string,db:string}>
     */
    private static function describeFields(mysqli_result $res): array
    {
        $fields = [];
        foreach ($res->fetch_fields() as $field) {
            $fields[] = [
                'name'  => (string) $field->name,
                'alias' => (string) $field->table,
                'table' => (string) ($field->orgtable !== '' ? $field->orgtable : $field->table),
                'db'    => (string) $field->db,
            ];
        }
        return $fields;
    }

    /**
     * Run a statement and wait for it — the synchronous path, used for the
     * metadata queries that were never cancellable anyway.
     *
     * @throws mysqli_sql_exception
     */
    public function run(string $sql, float $start): QueryResult
    {
        if ($this->link === null) {
            return QueryResult::fromError('Not connected to a database');
        }
        $res = $this->link->query($sql);
        $elapsed = microtime(true) - $start;

        if ($res instanceof mysqli_result) {
            ['columns' => $columns, 'sources' => $sources, 'columnSources' => $colSources]
                = ResultColumns::resolve(self::describeFields($res));
            // Buffered, unlike the async path: this one is only used for the
            // bounded metadata queries, which were never what froze the UI and
            // have no frame loop to hand back to.
            //
            // Fetched positionally and keyed afterwards. `MYSQLI_ASSOC` keys the
            // row itself, and a join with two columns called `id` loses one of
            // them to the other before we ever see it (see {@see ResultColumns}).
            $rows = [];
            foreach ($res->fetch_all(MYSQLI_NUM) as $row) {
                $rows[] = array_combine($columns, $row);
            }
            $res->free();
            $this->drainRowsets();
            return new QueryResult(
                columns: $columns,
                rows: $rows,
                elapsed: $elapsed,
                isSelect: true,
                sources: $sources,
                columnSources: $colSources,
            );
        }

        $affected = (int) $this->link->affected_rows;
        $this->drainRowsets();
        return new QueryResult(
            affectedRows: $affected,
            elapsed: $elapsed,
            isSelect: false,
        );
    }

    /**
     * Send a statement and return immediately, leaving the answer to
     * {@see ready()} / {@see reap()}.
     *
     * **`MYSQLI_USE_RESULT` is what makes this cancellable, and it was not
     * obvious.** With the default buffered mode, `reap_async_query()` pulls the
     * entire result set before it returns — so a query that starts answering at
     * once and then sends for a second spends that whole second inside one
     * blocking call, with the frame loop stopped. On screen that is a frozen
     * clock, a still spinner and a dead Esc: indistinguishable from having no
     * async path at all, which is exactly how it was reported. Unbuffered, the
     * reap hands back a cursor immediately and {@see reap()} takes the rows a
     * bounded chunk at a time.
     *
     * @throws mysqli_sql_exception
     */
    public function start(string $sql): void
    {
        if ($this->link === null) {
            throw new mysqli_sql_exception('Not connected to a database');
        }
        $this->streaming     = null;
        $this->streamColumns = [];
        $this->streamSources = [];
        $this->streamColumnSources = [];
        $this->streamRows    = [];
        $this->link->query($sql, MYSQLI_ASYNC | MYSQLI_USE_RESULT);
        $this->inFlight = true;
    }

    /**
     * Has the in-flight statement finished? Returns without waiting, so this is
     * safe to call from the frame loop.
     *
     * The zero timeout is what keeps the UI alive: `mysqli_poll()` is asked
     * whether the socket has an answer *now*, and the loop goes back to
     * repainting when it does not. A false from poll is reported as "ready" so
     * the error surfaces through {@see reap()}, which knows how to describe it —
     * treating it as "not ready" would spin forever on a broken socket.
     */
    public function ready(): bool
    {
        if ($this->link === null || !$this->inFlight) {
            return false;
        }
        // Mid-drain there is always work to do, and it is not the socket that
        // says so — the cursor does.
        if ($this->streaming !== null) {
            return true;
        }
        $read   = [$this->link];
        $error  = [$this->link];
        $reject = [$this->link];
        $count  = @mysqli_poll($read, $error, $reject, 0, 0);
        if ($count === false) {
            return true;
        }
        return $count > 0 && ($read !== [] || $error !== [] || $reject !== []);
    }

    /**
     * Take as much of the answer as fits in one frame.
     *
     * Returns the finished {@see QueryResult}, or **null meaning "not done —
     * call me again next frame"**. The caller repaints in between, which is the
     * entire point: a result set is read across many frames rather than in one
     * blocking call, so the clock keeps counting, the spinner keeps turning and
     * Esc keeps working for as long as the rows are still arriving.
     *
     * Only call once {@see ready()} has said yes.
     *
     * @throws mysqli_sql_exception
     */
    public function reap(float $start): ?QueryResult
    {
        if ($this->link === null) {
            return QueryResult::fromError('Not connected to a database');
        }

        // First visit: collect the cursor (or the answer, for a statement with
        // no rows). Unbuffered, so this returns as soon as the column
        // definitions have arrived rather than after the last row.
        if ($this->streaming === null) {
            $res = $this->link->reap_async_query();
            if (!$res instanceof mysqli_result) {
                $affected = (int) $this->link->affected_rows;
                $this->finish();
                return new QueryResult(
                    affectedRows: $affected,
                    elapsed: microtime(true) - $start,
                    isSelect: false,
                );
            }
            $this->streaming = $res;
            ['columns' => $this->streamColumns, 'sources' => $this->streamSources,
             'columnSources' => $this->streamColumnSources]
                = ResultColumns::resolve(self::describeFields($res));
        }

        $deadline = microtime(true) + self::FETCH_SECONDS;
        $taken = 0;
        while (true) {
            // Positional, then keyed — `fetch_assoc()` would drop one of a
            // join's two same-named columns on the way in ({@see ResultColumns}).
            $row = $this->streaming->fetch_row();
            if ($row === null) {
                // End of the result set — and, for an unbuffered read, the
                // moment the statement is actually over.
                $columns = $this->streamColumns;
                $rows    = $this->streamRows;
                $sources = $this->streamSources;
                $colSources = $this->streamColumnSources;
                $this->streaming->free();
                $this->finish();
                return new QueryResult(
                    columns: $columns,
                    rows: $rows,
                    elapsed: microtime(true) - $start,
                    isSelect: true,
                    sources: $sources,
                    columnSources: $colSources,
                );
            }
            $this->streamRows[] = array_combine($this->streamColumns, $row);
            if (++$taken >= self::FETCH_ROWS || microtime(true) >= $deadline) {
                return null; // hand the frame back; the rest comes next time
            }
        }
    }

    /** The statement is over: drop the cursor and let the connection go idle. */
    private function finish(): void
    {
        $this->streaming     = null;
        $this->streamColumns = [];
        $this->streamSources = [];
        $this->streamColumnSources = [];
        $this->streamRows    = [];
        $this->inFlight      = false;
        $this->drainRowsets();
    }

    /**
     * Consume any result sets after the first — the mysqli twin of the PDO
     * path's rowset drain, and it exists for the same reason: a `CALL` returns
     * one rowset per SELECT plus a status packet, and leaving them unread
     * poisons the *next* statement on this connection with an error that points
     * nowhere near the procedure that caused it.
     */
    private function drainRowsets(): void
    {
        if ($this->link === null) {
            return;
        }
        try {
            while ($this->link->more_results() && $this->link->next_result()) {
                $extra = $this->link->store_result();
                if ($extra instanceof mysqli_result) {
                    $extra->free();
                }
            }
        } catch (mysqli_sql_exception) {
            // cleanup only — the result already in hand is the one being returned
        }
    }

    /**
     * Ask the server to stop whatever this *other* session is running.
     *
     * Runs on its own connection by necessity: the session being killed is busy,
     * which is the entire problem. Returns null on success, or a human-readable
     * reason it could not be done.
     *
     * `KILL QUERY` stops the statement and leaves the session alive — the
     * distinction from the read timeout, which drops the connection and leaves
     * the *server* still working. No privilege is needed to kill your own
     * thread. Managed MySQL is the exception: RDS withholds the grant that plain
     * `KILL` needs, and offers `mysql.rds_kill_query()` in its place, so a
     * refusal is retried that way before giving up.
     */
    public function kill(int $threadId): ?string
    {
        if ($this->link === null || $threadId <= 0) {
            return 'no connection to send the cancel on';
        }
        try {
            $this->link->query('KILL QUERY ' . $threadId);
            return null;
        } catch (mysqli_sql_exception $e) {
            if ($this->debug) {
                my::lineout('FILE:FILE', 'KILL QUERY refused', $e->getCode(), $e->getMessage());
            }
        }
        try {
            $this->link->query('CALL mysql.rds_kill_query(' . $threadId . ')');
            return null;
        } catch (mysqli_sql_exception $e) {
            return $e->getMessage();
        }
    }
}
