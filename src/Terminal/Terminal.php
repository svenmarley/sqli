<?php

declare(strict_types=1);

namespace Sqli\Terminal;

use includes\my;

/**
 * Owns the physical terminal: raw mode, alternate screen, mouse capture,
 * cursor visibility, sizing, and the output byte stream. Platform differences
 * (Windows FFI vs POSIX stty) live here and nowhere else.
 *
 * Lifecycle: open() to take over the screen, close() to restore it. close()
 * is idempotent and safe to call from a shutdown handler.
 */
final class Terminal
{
    /**
     * How long to keep retrying a refused write before giving up on the rest of
     * the buffer. A *time* budget rather than an attempt count on purpose:
     * usleep() only guarantees a lower bound, and on Windows it routinely
     * overshoots by 10ms+, so "100 attempts 1ms apart" measured 1.5 seconds — a
     * visible freeze per dropped frame. A deadline costs the same 50ms whatever
     * the scheduler does.
     *
     * 50ms is chosen to outlast ordinary congestion while keeping a wedged
     * terminal at roughly 20 frames a second instead of stopped. Dropping a frame
     * is cheap because the next one repaints in full (see App::draw()).
     */
    private const WRITE_STALL_BUDGET_S = 0.05;
    private const WRITE_STALL_SLEEP_US = 200;

    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stdin;

    private InputReader $input;
    private ?WindowsConsole $win = null;
    private ?PosixTty $posix = null;
    private ?string $sttyOriginal = null;
    private bool $open = false;
    private bool $lastMeasureFromReport = false;
    /** True once open() has confirmed stdin really went non-blocking. */
    private bool $stdinNonBlocking = false;
    /** A DSR report has been asked for and not yet collected. */
    private bool $dsrOutstanding = false;
    /** Lifetime count of DSR size requests written. Diagnostics only. */
    private int $dsrRequests = 0;
    /**
     * Last size a platform API returned. Kept so one failed call cannot change
     * which sizing strategy the app is using.
     * @var array{0:int,1:int}|null
     */
    private ?array $lastPlatformSize = null;
    /** How many times a platform call returned nothing after one had worked. */
    private int $platformMisses = 0;
    /** Writes that gave up with bytes unsent. Never silently zero — see write(). */
    private int $writeDrops = 0;

    public function __construct()
    {
        $this->stdout = STDOUT;
        $this->stdin = STDIN;
        $this->input = new InputReader($this->stdin);

        if (WindowsConsole::isSupported()) {
            try {
                $this->win = new WindowsConsole();
            } catch (\Throwable $e) {
                my::lineout('FILE:FILE', 'WindowsConsole init failed', $e->getMessage());
                $this->win = null;
            }
        }

        if (PosixTty::isSupported()) {
            try {
                $this->posix = new PosixTty();
            } catch (\Throwable $e) {
                my::lineout('FILE:FILE', 'PosixTty init failed', $e->getMessage());
                $this->posix = null;
            }
        }

        // On Windows the console stdin can't be made non-blocking, so let the
        // reader peek the console input queue and skip the blocking fread() when
        // nothing is pending — keeps the main loop (and resize polling) alive
        // while idle. The probe returns null when the count can't be read, which
        // the reader treats as "read normally".
        // Only use the console-input probe when the FULL console API works (size
        // included). In that regime input arrives as console records, so peeking
        // the queue is meaningful. When size() fails (e.g. launched through Git
        // Bash/MSYS, where stdin is a pipe), input flows through the pipe instead;
        // the console queue would read empty and make poll() skip the pipe — never
        // reading the DSR size replies, so resize would stall until a keystroke.
        // There, a plain blocking read is correct: the periodic DSR replies tick
        // the loop and drive resize on their own.
        $win = $this->win;
        if ($win !== null && $win->size() !== null) {
            // Drains non-character records (resize/focus/key-up) and reports
            // whether real key/mouse bytes are waiting, so the read never wedges.
            $this->input->setReadableProbe(static fn (): ?bool => $win->readableForBytes());
        } elseif (PHP_OS_FAMILY !== 'Windows') {
            // POSIX gets a probe too, and for a stronger reason than efficiency:
            // it makes it *impossible* to enter a read with nothing to read. The
            // loop must never depend on input arriving to turn — a frame has a
            // query to collect, a resize to apply and a clock to advance whether
            // or not anyone is typing. `stream_set_blocking(false)` is supposed to
            // guarantee that on its own, and measured on a pty it does; this costs
            // ~0.04ms a frame to stop the guarantee being load-bearing.
            $stdin = $this->stdin;
            $this->input->setReadableProbe(static function () use ($stdin): ?bool {
                $read = [$stdin];
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 0, 0);
                // false = select itself failed: say "don't know" and let the
                // reader do what it did before.
                return $ready === false ? null : $ready > 0;
            });
        }
    }

    public function input(): InputReader
    {
        return $this->input;
    }

    /**
     * True when a window-resize event arrived since the last call — a console
     * record on Windows, a SIGWINCH on POSIX. Lets the loop repaint the instant
     * the window changes rather than waiting for the periodic size poll. Always
     * false where neither is available.
     */
    public function takeResizeSignal(): bool
    {
        $win = $this->win?->takeResizePending() ?? false;
        $posix = $this->posix?->takeResizeSignal() ?? false;
        return $win || $posix;
    }

    /**
     * True when stdin is genuinely non-blocking, so a read that finds no bytes
     * returns instead of parking. This is what decides whether the DSR resize
     * path may be throttled: where the read can block, the DSR reply is the only
     * thing that wakes an idle loop and the request must not be suppressed.
     * False until open() has actually tried it.
     */
    public function stdinIsNonBlocking(): bool
    {
        return $this->stdinNonBlocking;
    }

    /**
     * One line describing how this process is sizing itself, for the debug log:
     * which API answers, what it currently says, whether stdin really went
     * non-blocking, and how many DSR requests have gone out since start. Read
     * together with the frame rate it is logged beside, this separates "the loop
     * stopped" from "the loop is fine but the size is wrong".
     */
    public function diagnostics(): string
    {
        $platform = $this->platformSize();
        // The tty name is what lets two windows be told apart in one shared log.
        $tty = function_exists('posix_ttyname') ? @posix_ttyname($this->stdout) : false;
        return sprintf(
            'tty=%s api=%s platform=%s nonblock=%s dsrSent=%d misses=%d drops=%d',
            is_string($tty) ? $tty : '?',
            $this->win !== null ? 'wincon' : ($this->posix !== null ? 'ioctl' : 'none'),
            $platform === null ? 'none' : $platform[0] . 'x' . $platform[1],
            $this->stdinNonBlocking ? 'yes' : 'no',
            $this->dsrRequests,
            $this->platformMisses,
            $this->writeDrops,
        );
    }

    /** Diagnostic: pending console input records, or null when unavailable. */
    public function pendingInputCount(): ?int
    {
        return $this->win?->pendingInputCount();
    }

    /** Take over the terminal: raw mode, alt screen, hidden cursor, mouse on. */
    public function open(): void
    {
        if ($this->open) {
            return;
        }
        $this->enableRawMode();
        // Windows consoles and pipes refuse non-blocking mode; POSIX ttys accept
        // it. The answer decides the resize strategy, so keep it.
        $this->stdinNonBlocking = @stream_set_blocking($this->stdin, false);
        // Keep stdout blocking so write() can rely on fwrite making progress
        // (no busy-spin) when it loops to defeat short writes.
        stream_set_blocking($this->stdout, true);
        $this->write(Ansi::ALT_SCREEN_ON . Ansi::AUTOWRAP_OFF . Ansi::CURSOR_HIDE . Ansi::MOUSE_ON . Ansi::BRACKETED_PASTE_ON . Ansi::CLEAR_SCREEN);
        $this->open = true;
    }

    /** Restore the terminal to its original state. Idempotent. */
    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        // Swallow any in-flight DSR cursor report before restoring cooked mode.
        // The POSIX/VT resize loop always keeps one `\e[6n` request outstanding;
        // its `\e[row;colR` reply, if it lands after the terminal is restored,
        // echoes onto the bare command line as stray "...R" characters.
        $this->drainPendingInput();
        $this->write(Ansi::BRACKETED_PASTE_OFF . Ansi::MOUSE_OFF . Ansi::CURSOR_SHOW . Ansi::AUTOWRAP_ON . Ansi::ALT_SCREEN_OFF . Ansi::RESET);
        $this->disableRawMode();
        $this->posix?->restore();
        $this->open = false;
    }

    /**
     * Read and discard pending stdin while still in raw mode — chiefly the
     * in-flight DSR size reply (see close()). Waits briefly for it to arrive,
     * then returns the moment it's consumed (or after a short cap, so a terminal
     * that never answers can't hang the exit). No-op on the FFI path, which
     * never solicits DSR.
     */
    private function drainPendingInput(): void
    {
        // Nothing was solicited, so nothing can be in flight — and on the ioctl
        // path that is the normal case. Waiting here would just add 0.12s to
        // every exit.
        if ($this->win !== null || !$this->dsrOutstanding) {
            return;
        }
        $deadline = microtime(true) + 0.12;
        while (microtime(true) < $deadline) {
            $this->input->poll(); // ingest + decode (events discarded on exit)
            if ($this->takeSizeReport() !== null) {
                return; // the outstanding DSR reply is now consumed
            }
            usleep(4000);
        }
    }

    public function write(string $bytes): bool
    {
        if ($bytes === '') {
            return true;
        }
        // fwrite can write fewer bytes than requested (a "short write") on a
        // large buffer — common writing to a PTY, and guaranteed if stdout is
        // ever non-blocking. The unwritten tail would be silently dropped, and
        // because Screen::flush() emits top-to-bottom that tail is the *bottom*
        // rows (input line + status). A big full repaint (e.g. a large result
        // after a query) therefore left the CLI/status stale until the next full
        // repaint. So the loop below keeps pushing until everything is on the wire.
        //
        // **But the loop must be able to give up.** It used to `continue` forever
        // on a false/0 return, on the assumption that such a return always meant
        // "would block". It does not: a PTY write can fail hard, and under WSL a
        // congested one returns **EIO**. The old loop then spun on a write that
        // could never succeed — and because `log_errors` is on, every failed
        // attempt wrote a PHP notice to `php_errors.log`, which on a Windows drive
        // seen from WSL costs milliseconds *per notice*. `php_errors.log` recorded
        // 111 of them inside one second. That spin is the frozen app, the slow
        // query and the half-painted screen, all three.
        $len = strlen($bytes);
        $written = 0;
        $deadline = null;
        while ($written < $len) {
            // Suppressed: a retryable stall is normal and must not be able to
            // storm the error log. A give-up is reported once, below, instead.
            $n = @fwrite($this->stdout, substr($bytes, $written));
            if ($n === false || $n === 0) {
                // The clock starts at the first refusal, so a write that never
                // stalls never reads it.
                $deadline ??= microtime(true) + self::WRITE_STALL_BUDGET_S;
                if (microtime(true) > $deadline) {
                    $this->writeDrops++;
                    my::lineout('FILE:FILE', 'terminal write dropped bytes',
                        (string) ($len - $written), 'of', (string) $len);
                    return false;
                }
                // Give the terminal time to drain instead of hammering it. A hot
                // retry loop is itself back-pressure on the thing we are waiting
                // for.
                usleep(self::WRITE_STALL_SLEEP_US);
                continue;
            }
            $deadline = null;
            $written += $n;
        }
        @fflush($this->stdout);
        return true;
    }

    /**
     * Ask the terminal to report the cursor's bottom-right extreme position
     * (DSR). The async reply is decoded by the InputReader on a later poll and
     * read back via takeSizeReport(). The cursor only moves — nothing is
     * printed — so this never scrolls, and the hidden cursor stays invisible.
     */
    public function requestSizeReport(): void
    {
        $this->write(Ansi::moveToExtreme() . Ansi::REPORT_CURSOR);
        $this->dsrOutstanding = true;
        $this->dsrRequests++;
    }

    /**
     * Collect a DSR reply, if one has been decoded. Goes through the Terminal
     * rather than the reader directly so the in-flight bookkeeping stays honest
     * — close() uses it to decide whether a reply is still owed.
     *
     * @return array{0:int,1:int}|null
     */
    public function takeSizeReport(): ?array
    {
        $report = $this->input->takeSizeReport();
        if ($report !== null) {
            $this->dsrOutstanding = false;
        }
        return $report;
    }

    /**
     * Authoritatively measure the viewport via the DSR cursor-position report,
     * blocking briefly for the reply. This is more reliable than the platform
     * APIs, which can over-report height on the alternate screen buffer (the
     * symptom: bottom rows and the input line painted below the visible area).
     * Falls back to size() if no reply arrives in time.
     *
     * @return array{0:int,1:int} [width, height] in cells.
     */
    public function measureSize(): array
    {
        $this->lastMeasureFromReport = false;
        $this->requestSizeReport();
        $deadline = microtime(true) + 0.15;
        while (microtime(true) < $deadline) {
            $this->input->poll(); // ingest bytes so the reader can decode the reply
            $r = $this->takeSizeReport();
            if ($r !== null) {
                $this->lastMeasureFromReport = true;
                return $r;
            }
            usleep(5000);
        }
        return $this->size();
    }

    /** True when the most recent measureSize() came from a real DSR reply. */
    public function lastMeasureFromReport(): bool
    {
        return $this->lastMeasureFromReport;
    }

    /**
     * True when a platform API can report the visible window size directly:
     * the Windows FFI srWindow, or ioctl(TIOCGWINSZ) on POSIX. When this holds
     * the platform size is authoritative for both grow and shrink and should be
     * preferred over the DSR cursor-clamp, which under-reports height right
     * after the window grows (the alt-screen buffer lags the window, stranding
     * the CLI at the old bottom row).
     *
     * It also costs nothing on the wire, which is the other half of the point:
     * the DSR alternative spends a terminal round-trip every frame for as long
     * as sqli is open.
     */
    public function hasPlatformSize(): bool
    {
        return $this->platformSize() !== null;
    }

    /**
     * The platform's own answer for the viewport, or null when neither API is
     * available (a pipe, or a POSIX build without FFI).
     *
     * @return array{0:int,1:int}|null [width, height] in cells.
     */
    private function platformSize(): ?array
    {
        $size = $this->win?->size() ?? $this->posix?->size();
        if ($size !== null) {
            $this->lastPlatformSize = $size;
            return $size;
        }

        // A platform API that has answered before and now does not (an ioctl
        // interrupted by a signal, say) must NOT be read as "this build has no
        // platform size". That would drop the caller into the DSR path for a
        // frame, and the two disagree about height on the alternate screen — so
        // the app would alternate between two sizes, paying a full invalidate and
        // a RESIZE_SETTLE window on every flip. Keeping the last good answer makes
        // a missed call a no-op instead of a strategy change.
        if ($this->lastPlatformSize !== null) {
            $this->platformMisses++;
        }
        return $this->lastPlatformSize;
    }

    /**
     * @return array{0:int,1:int} [width, height] in cells.
     */
    public function size(): array
    {
        // The platform APIs come first: both read the live window, so neither
        // can go stale the way an inherited $COLUMNS does.
        $size = $this->platformSize();
        if ($size !== null) {
            return $size;
        }

        $cols = (int) getenv('COLUMNS');
        $rows = (int) getenv('LINES');
        if ($cols > 0 && $rows > 0) {
            return [$cols, $rows];
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $out = @shell_exec('stty size 2>/dev/null');
            if (is_string($out) && preg_match('/(\d+)\s+(\d+)/', $out, $m)) {
                return [(int) $m[2], (int) $m[1]];
            }
        }

        return [120, 30];
    }

    private function enableRawMode(): void
    {
        if ($this->win !== null) {
            $this->win->enableRawMode();
            return;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $saved = @shell_exec('stty -g 2>/dev/null');
            if (is_string($saved) && trim($saved) !== '') {
                $this->sttyOriginal = trim($saved);
                @shell_exec('stty raw -echo 2>/dev/null');
            }
        }
        // Windows without FFI: best effort — rely on the host terminal. See README.
    }

    private function disableRawMode(): void
    {
        if ($this->win !== null) {
            $this->win->restore();
            return;
        }
        if ($this->sttyOriginal !== null) {
            @shell_exec('stty ' . $this->sttyOriginal . ' 2>/dev/null');
            $this->sttyOriginal = null;
        }
    }
}
