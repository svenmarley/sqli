<?php

declare(strict_types=1);

namespace Sqli\Db;

use includes\my;

/**
 * The connection's flight recorder: what the session was doing when it died,
 * and enough measured context to say *why* without guessing.
 *
 * **Why this exists.** "MySQL server has gone away" (2006) is the error MySQL
 * gives for at least six unrelated events — an idle session the server closed,
 * a session a proxy or NAT closed, a server that restarted or failed over, a
 * statement larger than `max_allowed_packet`, the client's own read timeout
 * dropping the socket, and the server giving up on sending rows nobody is
 * reading. The message is identical in every case and names none of them, so
 * the error on its own can never be acted on. Everything logged here is chosen
 * to separate those six, and nothing else is logged at all.
 *
 * **What separates them** is four measurements taken at the moment of the loss:
 *
 * - **the idle gap** — how long since the server last said anything on this
 *   session, against the session's own `wait_timeout`. A gap past `wait_timeout`
 *   is MySQL closing an idle session; a gap well under it means something
 *   *between* sqli and MySQL closed the socket, which is a different fix.
 * - **the phase and how fast it failed** — {@see PHASE_SEND} (the write itself
 *   was refused), {@see PHASE_WAIT} (sent, nothing came back),
 *   {@see PHASE_STREAM} (rows were arriving and stopped), {@see PHASE_SYNC} (the
 *   blocking metadata path). The phase alone is not enough, because a statement
 *   sent down an already-dead socket succeeds at the write and fails at the
 *   first read — see {@see INSTANT_SECONDS}. Together with the elapsed time it
 *   separates "the connection was already gone" from "the server was asked and
 *   never answered", which the error code never will.
 * - **the statement's size in bytes**, against `max_allowed_packet`. An
 *   oversized statement is refused by closing the connection, with no other
 *   diagnostic anywhere.
 * - **the server's uptime**, read on a fresh connection immediately afterwards.
 *   Uptime shorter than this session's age means the server restarted
 *   underneath it and every connection died — which no client setting prevents.
 *
 * {@see verdict()} is the pure function that turns those into one sentence, and
 * it is pure precisely so it can be pinned in a test with no server present.
 *
 * Logging goes through `my::lineout('FILE:FILE', …)` to `debug.log`, like
 * everything else here, and is unconditional rather than behind `--debug`: this
 * is for a failure that happens once an afternoon, and a diagnostic you have to
 * have predicted is a diagnostic you do not have. The cost is a few lines per
 * connection, against a heartbeat that already writes one a second.
 */
final class ConnectionLog
{
    /** The statement never left: the socket was already dead when it was sent. */
    public const PHASE_SEND = 'send';

    /** Sent, and the first packet of the answer never arrived. */
    public const PHASE_WAIT = 'wait';

    /** Rows were arriving across frames and stopped part-way. */
    public const PHASE_STREAM = 'stream';

    /** The blocking path — metadata queries, the DD, the exporter. */
    public const PHASE_SYNC = 'sync';

    /**
     * Below this, a failure means the connection was already dead rather than
     * that the server was asked and did not answer.
     *
     * A second is orders of magnitude above what a dead socket takes to report
     * (0.042s, measured) and far below anything a server that is genuinely
     * working takes to give up on.
     */
    private const INSTANT_SECONDS = 1.0;

    /**
     * Did the statement provably never reach the server?
     *
     * The one question a caller has to answer before it may re-send anything by
     * itself. Three facts settle it and they are all recorded at the loss:
     *
     * * **rows** — a single row already taken is proof the server had the
     *   statement, ran it, and began answering. Nothing after that is replayable.
     * * **phase** — `stream` means the answer was already coming back.
     * * **elapsed** — under {@see INSTANT_SECONDS} the socket reported back too
     *   fast for a server to have been involved at all (0.042s, measured in
     *   v076); the write landed in the local send buffer of a connection that
     *   was already gone. Above it, the server may have been working, and "may"
     *   is the whole difference: an UPDATE that ran and could not say so must
     *   never be sent twice.
     *
     * Pure and static, like {@see verdict()}: what this returns decides whether
     * a write can be repeated, so it is pinned in a test rather than trusted.
     */
    public static function neverRan(string $phase, float $elapsed, int $rows): bool
    {
        return $rows === 0
            && $phase !== self::PHASE_STREAM
            && $elapsed < self::INSTANT_SECONDS;
    }

    /**
     * Session values read once at connect, because they are what every verdict
     * is measured against and they cannot be read afterwards: the connection
     * they describe is by then gone.
     *
     * @var array<string,int>
     */
    private array $limits = [];

    /** When the live session was opened, for the uptime comparison. */
    private float $connectedAt = 0.0;

    /**
     * When the server last said anything at all on this session — a result, an
     * error, or one chunk of a streaming result set.
     *
     * That a *chunk* counts is the whole point. The unbuffered read (§21) takes
     * a result set across many frames, and each chunk resets the server's
     * `net_write_timeout` exactly as it resets this; a value that only moved on
     * completed statements would report a two-minute idle gap in the middle of a
     * two-minute transfer that never idled once.
     */
    private float $lastActivity = 0.0;

    /** Statements sent on the live session, for the loss line. */
    private int $statements = 0;

    private int $threadId = 0;

    /**
     * Record a fresh connection, and the numbers its verdicts will be measured
     * against.
     *
     * @param array<string,int> $limits wait_timeout, net_write_timeout, max_allowed_packet, …
     */
    public function connected(
        string $driver,
        string $label,
        int $threadId,
        string $serverVersion,
        string $tls,
        array $limits,
    ): void {
        $this->connectedAt  = microtime(true);
        $this->lastActivity = $this->connectedAt;
        $this->statements   = 0;
        $this->threadId     = $threadId;
        $this->limits       = $limits;

        my::lineout(
            'FILE:FILE',
            'conn open', $label,
            'driver', $driver,
            'thread', $threadId,
            'server', $serverVersion,
            'tls', $tls,
            'limits', self::describeLimits($limits),
        );
    }

    /** One chunk of a streaming result set landed: the session is not idle. */
    public function answered(): void
    {
        $this->lastActivity = microtime(true);
    }

    /** Seconds since the server last said anything on this session. */
    public function idleGap(): float
    {
        return $this->lastActivity > 0.0 ? microtime(true) - $this->lastActivity : 0.0;
    }

    /** How long the live session has been open, in seconds. */
    public function sessionAge(): float
    {
        return $this->connectedAt > 0.0 ? microtime(true) - $this->connectedAt : 0.0;
    }

    /**
     * A statement finished. Logged in one compact line — the verb and the size,
     * not the text.
     *
     * The text is deliberately withheld from the healthy path: this log runs
     * against production for days at a time, and what a timeline needs is a
     * statement's *shape*. The head of the SQL is logged when something goes
     * wrong, where it earns the exposure.
     */
    public function statement(string $sql, float $elapsed, int $rows, ?string $error = null): void
    {
        $gap = $this->idleGap();
        $this->lastActivity = microtime(true);
        $this->statements++;

        // Healthy, fast and prompt: the overwhelmingly common case, and logging
        // it would bury the lines that matter. A slow statement, or a long gap
        // before one, is kept — both are context for a loss that comes later.
        if ($error === null && $elapsed < 1.0 && $gap < 60.0) {
            return;
        }

        my::lineout(
            'FILE:FILE',
            'conn stmt', self::verbOf($sql),
            'bytes', strlen($sql),
            'idle before', sprintf('%.1fs', $gap),
            'took', sprintf('%.3fs', $elapsed),
            'rows', $rows,
            'error', $error ?? 'none',
        );
    }

    /**
     * The connection died. This is the line the whole class exists to write.
     *
     * @param array<string,mixed> $probe what a fresh connection could see afterwards
     */
    public function lost(
        int $code,
        string $message,
        string $phase,
        string $sql,
        float $elapsed,
        int $rows,
        int $readTimeout,
        array $probe,
    ): void {
        $gap = $this->idleGap();
        my::lineout(
            'FILE:FILE',
            'CONN LOST code', $code,
            'phase', $phase,
            'idle before loss', sprintf('%.1fs', $gap),
            'session age', sprintf('%.1fs', $this->sessionAge()),
            'stmt took', sprintf('%.3fs', $elapsed),
            'rows so far', $rows,
            'stmts on session', $this->statements,
            'thread', $this->threadId,
            'sql bytes', strlen($sql),
            'sql head', self::head($sql),
            'client read timeout', $readTimeout,
            'limits', self::describeLimits($this->limits),
            'probe', self::describeProbe($probe),
            'message', $message,
            'VERDICT', self::verdict(
                phase: $phase,
                idleGap: $gap,
                sessionAge: $this->sessionAge(),
                elapsed: $elapsed,
                bytes: strlen($sql),
                readTimeout: $readTimeout,
                limits: $this->limits,
                probe: $probe,
            ),
        );
    }

    /**
     * One sentence naming the most likely cause, from the measurements alone.
     *
     * Pure and static on purpose: every rule below is a claim about MySQL's
     * behaviour, and a claim that cannot be pinned without a live server that
     * misbehaves on demand is a claim nobody will ever check.
     * `.claude/tests/connection_test.php` drives this directly.
     *
     * The order is by how decisive the evidence is, not by how common the cause
     * is. A restarted server explains every other symptom on the list, so it is
     * asked first; and "no rule matched" is a real answer, said plainly rather
     * than dressed up as the most common guess.
     *
     * @param array<string,int>   $limits
     * @param array<string,mixed> $probe
     */
    public static function verdict(
        string $phase,
        float $idleGap,
        float $sessionAge,
        float $elapsed,
        int $bytes,
        int $readTimeout,
        array $limits = [],
        array $probe = [],
    ): string {
        $wait      = (int) ($limits['wait_timeout'] ?? 0);
        $netWrite  = (int) ($limits['net_write_timeout'] ?? 0);
        $maxPacket = (int) ($limits['max_allowed_packet'] ?? 0);
        $uptime    = (int) ($probe['uptime'] ?? 0);
        $reachable = $probe['reachable'] ?? null;
        // A statement sent down a socket the server has already closed does not
        // fail at the write — the write lands in the local send buffer and
        // succeeds. It fails at the *first read*, which arrives as `wait` a few
        // milliseconds later. Measured on a real 2006: `phase=[wait] stmt
        // took=[0.042s]` on a session that had been idle for twenty minutes.
        //
        // So the phase alone cannot tell "the connection was already dead" from
        // "the server was asked and never answered", and the first version of
        // this function got that wrong and returned "no rule matched" for a
        // textbook idle timeout. What separates them is the clock: a dead socket
        // reports back in milliseconds, and a server that is really thinking
        // takes as long as it takes. Rows already taken settle it outright — the
        // server was demonstrably alive after the statement was sent.
        $instant = $phase !== self::PHASE_STREAM && $elapsed < self::INSTANT_SECONDS;

        if ($reachable === false) {
            return 'the server could not be reached at all straight afterwards — an outage or a network '
                . 'break, not a session-level timeout';
        }
        // Uptime shorter than this session's age is proof rather than inference:
        // the server this session was talking to is not the process answering now.
        if ($uptime > 0 && $sessionAge > 0.0 && $uptime < $sessionAge) {
            return sprintf(
                'the server restarted or failed over — its uptime is %ds but this session was %.0fs old, '
                . 'so every connection to it was dropped',
                $uptime,
                $sessionAge,
            );
        }
        if ($instant && $maxPacket > 0 && $bytes >= $maxPacket) {
            return sprintf(
                'the statement is %d bytes, at or over max_allowed_packet (%d) — the server closes the '
                . 'connection instead of reading it; raise max_allowed_packet or send less at once',
                $bytes,
                $maxPacket,
            );
        }
        // sqli's own read timeout drops the socket while the server is still
        // working, so it surfaces as 2006 and reads exactly like a server fault.
        // The 0.95 is slack between the timer firing and the throw arriving.
        //
        // Deliberately not restricted by phase, because the blocking path cannot
        // report one: a metadata query that waits out the read timeout arrives
        // here as `sync`, indistinguishable by phase from a statement that never
        // left. The elapsed time is what separates them — a statement sent down
        // an already-dead socket fails in milliseconds, not in half an hour.
        if ($readTimeout > 0 && $elapsed >= $readTimeout * 0.95) {
            return sprintf(
                'sqli\'s own read timeout fired — %.0fs of silence against a read-timeout of %ds. The '
                . 'connection was dropped from this end and the server is probably still running the '
                . 'query; raise read-timeout, or cancel with Esc instead',
                $elapsed,
                $readTimeout,
            );
        }
        if ($phase === self::PHASE_STREAM && $netWrite > 0 && $idleGap >= $netWrite) {
            return sprintf(
                'sqli stopped taking rows for %.0fs, past the server\'s net_write_timeout (%ds) — the '
                . 'server gave up sending the rest of the result set',
                $idleGap,
                $netWrite,
            );
        }
        if ($instant && $wait > 0 && $idleGap >= $wait) {
            return sprintf(
                'the session sat idle %.0fs, past wait_timeout (%ds) — MySQL closed it, and this statement '
                . 'went down a socket that was already gone',
                $idleGap,
                $wait,
            );
        }
        if ($instant && $idleGap >= 60.0) {
            return sprintf(
                'the session sat idle %.0fs and was closed before wait_timeout (%s) — so it was closed by '
                . 'something between sqli and MySQL: a proxy, load balancer, NAT table or firewall with a '
                . 'shorter idle limit of its own',
                $idleGap,
                $wait > 0 ? $wait . 's' : 'unknown',
            );
        }
        return 'no rule matched — the connection died while it was in use and not idle; read the fields '
            . 'on this line rather than trusting a guess';
    }

    /** `wait_timeout=28800 net_write_timeout=60 …`, or `unread` when the read failed.
     *
     * @param array<string,int> $limits
     */
    private static function describeLimits(array $limits): string
    {
        if ($limits === []) {
            return 'unread';
        }
        $parts = [];
        foreach ($limits as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode(' ', $parts);
    }

    /** @param array<string,mixed> $probe */
    private static function describeProbe(array $probe): string
    {
        if ($probe === []) {
            return 'not run';
        }
        if (($probe['reachable'] ?? null) === false) {
            return 'unreachable: ' . (string) ($probe['error'] ?? '');
        }
        $parts = ['reachable'];
        if (isset($probe['uptime'])) {
            $parts[] = 'uptime=' . (int) $probe['uptime'] . 's';
        }
        return implode(' ', $parts);
    }

    /** The first word of a statement, uppercased — its shape without its data. */
    private static function verbOf(string $sql): string
    {
        $trimmed = ltrim($sql);
        $verb = substr($trimmed, 0, min(strcspn($trimmed, " \t\r\n("), 20));
        return $verb === '' ? '?' : strtoupper($verb);
    }

    /** Enough of the statement to recognise it, on the failure path only. */
    private static function head(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
        return strlen($flat) > 120 ? substr($flat, 0, 117) . '…' : $flat;
    }
}
