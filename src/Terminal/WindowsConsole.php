<?php

declare(strict_types=1);

namespace Sqli\Terminal;

use FFI;

/**
 * Windows console control via FFI (kernel32). Isolated here so the rest of the
 * codebase stays platform-agnostic and the FFI risk is contained.
 *
 * Why this matters: the previous implementation was only smooth under Windows
 * Terminal. By clearing ENABLE_LINE_INPUT/ENABLE_ECHO_INPUT and setting
 * ENABLE_VIRTUAL_TERMINAL_INPUT on the console here, we get unbuffered,
 * VT-encoded keystrokes in cmd.exe and PowerShell as well.
 *
 * If FFI is unavailable, isSupported() returns false and the Terminal falls
 * back to best-effort behavior (see Terminal::enableRawMode()).
 */
final class WindowsConsole
{
    private const STD_INPUT_HANDLE  = 4294967286; // (DWORD)-10
    private const STD_OUTPUT_HANDLE = 4294967285; // (DWORD)-11

    // Input mode flags
    private const ENABLE_PROCESSED_INPUT        = 0x0001;
    private const ENABLE_LINE_INPUT             = 0x0002;
    private const ENABLE_ECHO_INPUT             = 0x0004;
    private const ENABLE_VIRTUAL_TERMINAL_INPUT = 0x0200;

    // Output mode flags
    private const ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004;

    // Console input record EventType values.
    private const KEY_EVENT                = 0x0001;
    private const MOUSE_EVENT              = 0x0002;
    private const WINDOW_BUFFER_SIZE_EVENT = 0x0004;

    private ?FFI $ffi = null;
    private mixed $hIn = null;
    private mixed $hOut = null;
    private ?int $origInMode = null;
    private ?int $origOutMode = null;
    /** Set when a window-resize record is drained, so the loop can repaint at once. */
    private bool $resizePending = false;

    public static function isSupported(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && extension_loaded('ffi');
    }

    public function __construct()
    {
        $this->ffi = FFI::cdef(<<<'CDEF'
            typedef void* HANDLE;
            typedef struct { short X; short Y; } COORD;
            typedef struct { short Left; short Top; short Right; short Bottom; } SMALL_RECT;
            typedef struct {
                COORD dwSize;
                COORD dwCursorPosition;
                unsigned short wAttributes;
                SMALL_RECT srWindow;
                COORD dwMaximumWindowSize;
            } CONSOLE_SCREEN_BUFFER_INFO;
            // INPUT_RECORD with the KEY_EVENT_RECORD layout overlaid on the event
            // union — we only ever read EventType (to classify) and bKeyDown.
            typedef struct {
                unsigned short EventType;
                unsigned short _pad;
                int            bKeyDown;
                unsigned short wRepeatCount;
                unsigned short wVirtualKeyCode;
                unsigned short wVirtualScanCode;
                unsigned short uChar;
                unsigned int   dwControlKeyState;
            } INPUT_RECORD;
            HANDLE GetStdHandle(unsigned long nStdHandle);
            int GetConsoleMode(HANDLE hConsoleHandle, unsigned long* lpMode);
            int SetConsoleMode(HANDLE hConsoleHandle, unsigned long dwMode);
            int GetConsoleScreenBufferInfo(HANDLE hConsoleOutput, CONSOLE_SCREEN_BUFFER_INFO* lpInfo);
            int GetNumberOfConsoleInputEvents(HANDLE hConsoleInput, unsigned long* lpNumberOfEvents);
            int PeekConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, unsigned long nLength, unsigned long* lpNumberOfEventsRead);
            int ReadConsoleInputW(HANDLE hConsoleInput, INPUT_RECORD* lpBuffer, unsigned long nLength, unsigned long* lpNumberOfEventsRead);
        CDEF, 'kernel32.dll');

        $this->hIn = $this->ffi->GetStdHandle(self::STD_INPUT_HANDLE);
        $this->hOut = $this->ffi->GetStdHandle(self::STD_OUTPUT_HANDLE);
    }

    /** Put the console into raw, VT-input mode. Records original modes for restore(). */
    public function enableRawMode(): void
    {
        $mode = $this->ffi->new('unsigned long');

        if ($this->ffi->GetConsoleMode($this->hIn, FFI::addr($mode))) {
            $this->origInMode = $mode->cdata;
            $newIn = ($this->origInMode
                & ~self::ENABLE_LINE_INPUT
                & ~self::ENABLE_ECHO_INPUT
                & ~self::ENABLE_PROCESSED_INPUT)
                | self::ENABLE_VIRTUAL_TERMINAL_INPUT;
            $this->ffi->SetConsoleMode($this->hIn, $newIn);
        }

        if ($this->ffi->GetConsoleMode($this->hOut, FFI::addr($mode))) {
            $this->origOutMode = $mode->cdata;
            $newOut = $this->origOutMode | self::ENABLE_VIRTUAL_TERMINAL_PROCESSING;
            $this->ffi->SetConsoleMode($this->hOut, $newOut);
        }
    }

    /**
     * Turn **only** input echo off, leaving line editing and Ctrl+C processing
     * alone, and return the restorer. Used by {@see PasswordPrompt} before the
     * TUI starts — unlike {@see enableRawMode()} this wants the console's normal
     * line reader, just without the characters appearing on screen.
     *
     * Null when the mode could not be read: PHP for Windows under a
     * non-console host (mintty/Git Bash) has no real console handle.
     *
     * @return callable():void|null
     */
    public function disableEcho(): ?callable
    {
        $mode = $this->ffi->new('unsigned long');
        if (!$this->ffi->GetConsoleMode($this->hIn, FFI::addr($mode))) {
            return null;
        }
        $orig = $mode->cdata;
        $this->ffi->SetConsoleMode($this->hIn, $orig & ~self::ENABLE_ECHO_INPUT);
        return function () use ($orig): void {
            $this->ffi->SetConsoleMode($this->hIn, $orig);
        };
    }

    /** Restore the console modes captured by enableRawMode(). */
    public function restore(): void
    {
        if ($this->origInMode !== null) {
            $this->ffi->SetConsoleMode($this->hIn, $this->origInMode);
        }
        if ($this->origOutMode !== null) {
            $this->ffi->SetConsoleMode($this->hOut, $this->origOutMode);
        }
    }

    /**
     * @return array{0:int,1:int}|null  [width, height] in cells, or null on failure.
     */
    public function size(): ?array
    {
        $info = $this->ffi->new('CONSOLE_SCREEN_BUFFER_INFO');
        if (!$this->ffi->GetConsoleScreenBufferInfo($this->hOut, FFI::addr($info))) {
            return null;
        }
        $w = $info->srWindow->Right - $info->srWindow->Left + 1;
        $h = $info->srWindow->Bottom - $info->srWindow->Top + 1;
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        return [$w, $h];
    }

    /** Diagnostic: unread console input records, or null if the query fails. */
    public function pendingInputCount(): ?int
    {
        $n = $this->ffi->new('unsigned long');
        if (!$this->ffi->GetNumberOfConsoleInputEvents($this->hIn, FFI::addr($n))) {
            return null;
        }
        return (int) $n->cdata;
    }

    /**
     * Whether the console has byte-yielding input (a key-down or mouse event)
     * waiting to be read. Records that produce no ReadFile bytes — window-resize,
     * focus, menu, and key-up events — are drained here so a following fread()
     * can't block on them (the cause of the idle-resize freeze, §14/resize).
     *
     * @return bool|null true = key/mouse bytes pending; false = nothing to read;
     *                   null = the console API is unavailable (read normally).
     */
    public function readableForBytes(): ?bool
    {
        // Bound the drain so a flood of events can't spin forever in one call.
        for ($guard = 0; $guard < 256; $guard++) {
            $n = $this->ffi->new('unsigned long');
            if (!$this->ffi->GetNumberOfConsoleInputEvents($this->hIn, FFI::addr($n))) {
                return null;
            }
            if ((int) $n->cdata <= 0) {
                return false;
            }

            $rec = $this->ffi->new('INPUT_RECORD');
            $read = $this->ffi->new('unsigned long');
            if (!$this->ffi->PeekConsoleInputW($this->hIn, FFI::addr($rec), 1, FFI::addr($read))
                || (int) $read->cdata < 1) {
                return null;
            }

            $type = (int) $rec->EventType;
            // A key-down or any mouse event will yield VT bytes → let fread read.
            if (($type === self::KEY_EVENT && (int) $rec->bKeyDown !== 0)
                || $type === self::MOUSE_EVENT) {
                return true;
            }

            // Otherwise (resize / focus / menu / key-up): consume and discard so
            // it can't wedge the blocking read, then re-check what's behind it.
            // A resize record is the live signal that the window changed — flag it
            // so the loop repaints immediately instead of waiting for the poll.
            if ($type === self::WINDOW_BUFFER_SIZE_EVENT) {
                $this->resizePending = true;
            }
            if (!$this->ffi->ReadConsoleInputW($this->hIn, FFI::addr($rec), 1, FFI::addr($read))
                || (int) $read->cdata < 1) {
                return false;
            }
        }
        return false;
    }

    /** Returns and clears the "a resize record arrived" flag (event-driven repaint). */
    public function takeResizePending(): bool
    {
        $r = $this->resizePending;
        $this->resizePending = false;
        return $r;
    }
}
