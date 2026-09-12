<?php

declare(strict_types=1);

namespace Sqli\Terminal;

/**
 * Read a password from the terminal without echoing it (spec §2).
 *
 * Runs on the **plain** terminal, before {@see Terminal::open()} takes over —
 * the alternate screen and raw mode are not up yet, so this owns echo
 * suppression itself and must put it back however it exits.
 *
 * Platform split, same rule as everywhere else in `Terminal/`: native Windows
 * goes through the console API ({@see WindowsConsole::disableEcho()}), everyone
 * else through `stty`. When neither is available — a pipe, a mintty window where
 * `GetConsoleMode` fails, a CI job — the read still happens but the caller is
 * told masking failed, because silently echoing a password onto a shared screen
 * is worse than refusing to hide it quietly.
 */
final class PasswordPrompt
{
    /**
     * Prompt on stderr and read one line from stdin with echo off.
     *
     * stderr, not stdout, so `sqli ... | something` still works and the prompt
     * cannot end up inside piped output.
     *
     * @param bool|null   $masked set to whether the input was actually hidden
     * @param string|null $raw    set to the bytes as they arrived, before any
     *                            cleanup — the only useful evidence when a
     *                            pasted password "doesn't work"
     */
    public static function read(
        string $label = 'Password',
        ?bool &$masked = null,
        ?string &$raw = null,
    ): string {
        $interactive = self::isInteractive();
        $restore = $interactive ? self::disableEcho() : null;
        $masked = $restore !== null;

        if ($interactive) {
            fwrite(STDERR, $label . ': ');
            if (!$masked) {
                fwrite(STDERR, '(warning: cannot hide input on this terminal) ');
            }
        }

        $line = fgets(STDIN);

        if ($restore !== null) {
            $restore();
        }
        if ($interactive) {
            fwrite(STDERR, PHP_EOL); // the user's Enter was never echoed
        }

        $raw = $line === false ? '' : $line;

        return self::clean($raw);
    }

    /**
     * Strip what the terminal added, and nothing else.
     *
     * Only the line ending and a **bracketed-paste** wrapper come off. When a
     * terminal is in bracketed-paste mode (DECSET 2004) a paste arrives as
     * `ESC[200~<text>ESC[201~`, so the password the user pasted reaches us with
     * six invisible bytes glued to the front and six to the back — it looks
     * identical on screen (where nothing is echoed anyway) and fails
     * authentication every time. sqli's own TUI turns that mode on, so a
     * previous run that died without restoring the terminal is enough to arm
     * this; the shell's line editor arms it routinely too.
     *
     * Surrounding **whitespace is deliberately kept**: a space is a legal
     * password character, and silently trimming one would turn a working
     * password into a mystery failure — the exact bug class this method exists
     * to remove.
     */
    private static function clean(string $raw): string
    {
        $value = rtrim($raw, "\r\n");
        if (str_starts_with($value, "\e[200~")) {
            $value = substr($value, 6);
        }
        if (str_ends_with($value, "\e[201~")) {
            $value = substr($value, 0, -6);
        }
        return $value;
    }

    /**
     * Render bytes for a debug line: printable characters as themselves,
     * everything else as `\xNN`, so a stray CR, a NBSP from a web page, or a
     * paste wrapper is visible rather than merely absent.
     */
    public static function describeBytes(string $s): string
    {
        $out = '';
        foreach (str_split($s) as $ch) {
            $b = ord($ch);
            $out .= ($b >= 0x20 && $b < 0x7F) ? $ch : sprintf('\x%02X', $b);
        }
        return $out;
    }

    /**
     * True when there is a human at the keyboard. A piped stdin
     * (`echo pw | sqli -p`) is a legitimate way to feed a password from a secret
     * store, so it is read — just not prompted for, and not masked, since there
     * is nothing to mask.
     */
    public static function isInteractive(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    /**
     * Turn terminal echo off, returning the restorer. Null when echo could not
     * be suppressed — never throws, since failing to hide a password must not
     * also prevent connecting.
     *
     * @return callable():void|null
     */
    private static function disableEcho(): ?callable
    {
        if (WindowsConsole::isSupported()) {
            try {
                $console = new WindowsConsole();
                $restore = $console->disableEcho();
                if ($restore !== null) {
                    return $restore;
                }
            } catch (\Throwable) {
                // fall through to stty — PHP for Windows running under a
                // non-console host (mintty) lands here
            }
        }

        $saved = @shell_exec('stty -g 2>/dev/null');
        if (!is_string($saved) || trim($saved) === '') {
            return null;
        }
        $saved = trim($saved);
        if (@shell_exec('stty -echo 2>/dev/null') === null && !self::echoIsOff()) {
            return null;
        }
        return static function () use ($saved): void {
            @shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
        };
    }

    /** Confirm `stty -echo` took, for shells where shell_exec returns null on success. */
    private static function echoIsOff(): bool
    {
        $now = @shell_exec('stty -a 2>/dev/null');
        return is_string($now) && str_contains($now, '-echo');
    }
}
