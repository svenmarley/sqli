<?php

declare(strict_types=1);

namespace Sqli\Terminal;

use FFI;
use includes\my;

/**
 * POSIX terminal geometry via ioctl(TIOCGWINSZ), plus SIGWINCH notification.
 * The counterpart to {@see WindowsConsole}: isolated here so the FFI risk and
 * the platform detail stay out of {@see Terminal}.
 *
 * Why this exists: without it, POSIX had no platform size API at all, so every
 * Linux/WSL run drove resize off the DSR cursor-clamp — which costs a terminal
 * round-trip *per frame* and is documented to under-report height right after a
 * window grows. ioctl is a bare syscall against the tty: no bytes cross the
 * terminal, it is authoritative on grow and shrink alike, and it is cheap
 * enough to call every frame.
 *
 * If FFI is unavailable, isSupported() returns false and Terminal falls back to
 * the DSR path exactly as before.
 */
final class PosixTty
{
    /**
     * ioctl request number for "get window size". Linux and the BSDs disagree,
     * and the number encodes the struct — a wrong one silently reads nothing.
     */
    private const TIOCGWINSZ_LINUX  = 0x5413;
    private const TIOCGWINSZ_DARWIN = 0x40087468;

    /**
     * File descriptors to interrogate, in order. stdout first: it is the one we
     * actually paint to, and it is still the terminal when stdin is a pipe.
     */
    private const FDS = [1, 0, 2];

    private ?FFI $ffi = null;
    /** Reused across calls — this runs once a frame, so don't allocate per call. */
    private mixed $ws = null;
    private int $request;
    /** The fd that answered last time; tried first on the next call. */
    private int $fd = 1;
    /** Set by the SIGWINCH handler, drained by takeResizeSignal(). */
    private bool $resizePending = false;
    private bool $signalInstalled = false;

    public static function isSupported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows' && extension_loaded('ffi');
    }

    public function __construct()
    {
        // No library name: resolve against the already-loaded libc in this
        // process. ioctl() is variadic in C but takes one argument in every
        // practical use; declaring the third parameter fixed is the ordinary
        // way to reach it through FFI.
        $this->ffi = FFI::cdef(<<<'CDEF'
            typedef struct {
                unsigned short ws_row;
                unsigned short ws_col;
                unsigned short ws_xpixel;
                unsigned short ws_ypixel;
            } sqli_winsize;
            int ioctl(int fd, unsigned long request, void *argp);
        CDEF);

        $this->ws = $this->ffi->new('sqli_winsize');
        $this->request = PHP_OS_FAMILY === 'Darwin'
            ? self::TIOCGWINSZ_DARWIN
            : self::TIOCGWINSZ_LINUX;

        $this->installSignalHandler();
    }

    /**
     * The visible terminal size straight from the kernel, or null when no fd is
     * a terminal (piped output, a CI runner) — which is also what tells Terminal
     * to fall back to the DSR path.
     *
     * @return array{0:int,1:int}|null [width, height] in cells.
     */
    public function size(): ?array
    {
        if ($this->ffi === null) {
            return null;
        }

        foreach ([$this->fd, ...self::FDS] as $fd) {
            $rc = $this->ffi->ioctl($fd, $this->request, FFI::addr($this->ws));
            // rc 0 with a zero row count is a real answer from a pty that has
            // never been sized (the `script` case) — not a usable size, so keep
            // looking rather than reporting a 0x0 viewport.
            if ($rc === 0 && $this->ws->ws_row > 0 && $this->ws->ws_col > 0) {
                $this->fd = $fd;
                return [(int) $this->ws->ws_col, (int) $this->ws->ws_row];
            }
        }

        return null;
    }

    /**
     * True when a SIGWINCH arrived since the last call. Lets the caller repaint
     * on the resize itself instead of waiting out its size-poll throttle.
     *
     * Signals are dispatched here rather than asynchronously: the main loop
     * already calls this once a frame, and an explicit dispatch keeps every
     * handler on the loop's own thread of control instead of interrupting a
     * blocking database read.
     */
    public function takeResizeSignal(): bool
    {
        if (!$this->signalInstalled) {
            return false;
        }
        pcntl_signal_dispatch();
        $pending = $this->resizePending;
        $this->resizePending = false;
        return $pending;
    }

    /** Put SIGWINCH back to the default disposition. Idempotent. */
    public function restore(): void
    {
        if ($this->signalInstalled) {
            @pcntl_signal(SIGWINCH, SIG_DFL);
            $this->signalInstalled = false;
        }
    }

    /**
     * SIGWINCH is an accelerator, never the source of truth — size() is. When
     * pcntl is missing the resize still lands, just on the caller's poll
     * interval rather than the instant the window moves.
     */
    private function installSignalHandler(): void
    {
        if (!function_exists('pcntl_signal') || !defined('SIGWINCH')) {
            my::lineout('FILE:FILE', 'PosixTty: no pcntl, SIGWINCH unavailable');
            return;
        }
        $this->signalInstalled = @pcntl_signal(SIGWINCH, function (): void {
            $this->resizePending = true;
        });
    }
}
