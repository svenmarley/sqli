<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

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
    /** @var resource */
    private $stdout;
    /** @var resource */
    private $stdin;

    private InputReader $input;
    private ?WindowsConsole $win = null;
    private ?string $sttyOriginal = null;
    private bool $open = false;
    private bool $lastMeasureFromReport = false;

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
        }
    }

    public function input(): InputReader
    {
        return $this->input;
    }

    /**
     * True when a window-resize event arrived since the last call (Windows console
     * only). Lets the loop repaint the instant the window changes rather than
     * waiting for the periodic size poll. Always false without an FFI console.
     */
    public function takeResizeSignal(): bool
    {
        return $this->win?->takeResizePending() ?? false;
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
        stream_set_blocking($this->stdin, false);
        // Keep stdout blocking so write() can rely on fwrite making progress
        // (no busy-spin) when it loops to defeat short writes.
        stream_set_blocking($this->stdout, true);
        $this->write(Ansi::ALT_SCREEN_ON . Ansi::AUTOWRAP_OFF . Ansi::CURSOR_HIDE . Ansi::MOUSE_ON . Ansi::CLEAR_SCREEN);
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
        $this->write(Ansi::MOUSE_OFF . Ansi::CURSOR_SHOW . Ansi::AUTOWRAP_ON . Ansi::ALT_SCREEN_OFF . Ansi::RESET);
        $this->disableRawMode();
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
        if ($this->win !== null) {
            return;
        }
        $deadline = microtime(true) + 0.12;
        while (microtime(true) < $deadline) {
            $this->input->poll(); // ingest + decode (events discarded on exit)
            if ($this->input->takeSizeReport() !== null) {
                return; // the outstanding DSR reply is now consumed
            }
            usleep(4000);
        }
    }

    public function write(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        // fwrite can write fewer bytes than requested (a "short write") on a
        // large buffer — common writing to a PTY, and guaranteed if stdout is
        // ever non-blocking. The unwritten tail would be silently dropped, and
        // because Screen::flush() emits top-to-bottom that tail is the *bottom*
        // rows (input line + status). A big full repaint (e.g. a large result
        // after a query) therefore left the CLI/status stale until the next full
        // repaint. Loop until everything is on the wire.
        $len = strlen($bytes);
        $written = 0;
        while ($written < $len) {
            $n = fwrite($this->stdout, substr($bytes, $written));
            if ($n === false || $n === 0) {
                // Pipe full / would block: drain it and retry rather than drop.
                fflush($this->stdout);
                continue;
            }
            $written += $n;
        }
        fflush($this->stdout);
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
            $r = $this->input->takeSizeReport();
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
     * True when a platform API (Windows FFI srWindow) can report the visible
     * window size directly. When this holds, the FFI size is authoritative for
     * both grow and shrink and should be preferred over the DSR cursor-clamp,
     * which under-reports height right after the window grows (the alt-screen
     * buffer lags the window, stranding the CLI at the old bottom row).
     */
    public function hasPlatformSize(): bool
    {
        return $this->win !== null && $this->win->size() !== null;
    }

    /**
     * @return array{0:int,1:int} [width, height] in cells.
     */
    public function size(): array
    {
        if ($this->win !== null) {
            $size = $this->win->size();
            if ($size !== null) {
                return $size;
            }
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
