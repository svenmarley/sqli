<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * Raw ANSI / VT escape sequences. Pure constants and tiny helpers — no state.
 */
final class Ansi
{
    public const ESC = "\e";

    // Screen / cursor lifecycle
    public const ALT_SCREEN_ON  = "\e[?1049h";
    public const ALT_SCREEN_OFF = "\e[?1049l";
    public const CURSOR_HIDE    = "\e[?25l";
    public const CURSOR_SHOW    = "\e[?25h";
    public const CLEAR_SCREEN   = "\e[2J";
    public const CURSOR_HOME    = "\e[H";
    public const RESET          = "\e[0m";

    // Auto-wrap (DECAWM). A full-screen TUI positions every cell absolutely and
    // must NOT auto-wrap: writing the bottom-right cell with wrap on scrolls the
    // whole screen up one line, dropping the input/status rows (the "CLI
    // disappears on resize / at startup" bug). Off while we own the screen.
    public const AUTOWRAP_OFF   = "\e[?7l";
    public const AUTOWRAP_ON    = "\e[?7h";

    // Mouse tracking: button events + SGR extended coordinates (1006).
    public const MOUSE_ON  = "\e[?1000h\e[?1006h";
    public const MOUSE_OFF = "\e[?1006l\e[?1000l";

    // Cursor position report request (device status report).
    public const REPORT_CURSOR = "\e[6n";

    /** Move the cursor to a 1-based (row, col). */
    public static function moveTo(int $row, int $col): string
    {
        return "\e[{$row};{$col}H";
    }

    /** Move cursor to bottom-right extreme; used with REPORT_CURSOR to size the screen. */
    public static function moveToExtreme(): string
    {
        return "\e[9999;9999H";
    }

    /**
     * OSC 52 sequence to set the system clipboard ("c" selection) to $data.
     * Supported by Windows Terminal and most modern emulators (works over SSH);
     * emits no visible output, so it needs no screen invalidation.
     */
    public static function clipboardSet(string $data): string
    {
        return "\e]52;c;" . base64_encode($data) . "\x07";
    }
}
