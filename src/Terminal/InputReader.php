<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * Decodes raw STDIN bytes into KeyEvent / MouseEvent objects.
 *
 * Non-blocking: poll() reads whatever bytes are available, appends them to an
 * internal buffer, and decodes as many complete events as it can. Partial
 * escape sequences are left in the buffer until the rest arrives on a later
 * poll, so a sequence split across reads is never misinterpreted.
 *
 * Handles: printable UTF-8, Ctrl+letter, Enter/Tab/Backspace/Delete/Escape,
 * arrows + Home/End/PageUp/PageDown (with Ctrl/Alt/Shift modifiers via the
 * `1;5A` form), F1/F2, and SGR (1006) mouse press/scroll.
 */
final class InputReader
{
    /** @var resource */
    private $stdin;
    private string $buf = '';
    private bool $loneEscSeen = false;
    /** @var array{0:int,1:int}|null last DSR cursor-position report as [w, h] */
    private ?array $sizeReport = null;
    /**
     * Optional probe returning whether stdin has bytes ready to read. When it
     * returns false we skip the blocking fread() entirely so the caller's loop
     * keeps ticking (Windows console stdin can't be made non-blocking, §14/resize).
     * null result = unknown → read normally.
     * @var (callable():?bool)|null
     */
    private $readableProbe = null;

    /** @param resource $stdin */
    public function __construct($stdin)
    {
        $this->stdin = $stdin;
    }

    /** @param (callable():?bool)|null $probe */
    public function setReadableProbe(?callable $probe): void
    {
        $this->readableProbe = $probe;
    }

    /**
     * @return array<int, KeyEvent|MouseEvent>
     */
    public function poll(): array
    {
        // Only read when the probe says bytes are waiting (or can't tell). This
        // avoids a fread() that would block the whole loop on an idle Windows
        // console, stranding the periodic resize check until the next keystroke.
        $canRead = true;
        if ($this->readableProbe !== null) {
            $canRead = ($this->readableProbe)() ?? true;
        }
        $chunk = $canRead ? @fread($this->stdin, 8192) : false;
        $hadNewBytes = ($chunk !== false && $chunk !== '');
        if ($hadNewBytes) {
            $this->buf .= $chunk;
            $this->loneEscSeen = false;
        }

        $events = [];
        while ($this->buf !== '') {
            $result = $this->decodeOne();
            if ($result === null) {
                // Incomplete sequence. One exception: a lone ESC that has now
                // survived a poll with no following bytes is a real Escape press.
                if ($this->buf === "\e") {
                    if ($this->loneEscSeen && !$hadNewBytes) {
                        $this->buf = '';
                        $events[] = KeyEvent::special(Key::Escape);
                    } else {
                        $this->loneEscSeen = true;
                    }
                }
                break;
            }
            [$event, $consumed] = $result;
            $this->buf = substr($this->buf, $consumed);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Consume the most recent DSR cursor-position report, if any. Returned as
     * [width, height]; used by the terminal sizer (a report taken right after
     * moving the cursor to the bottom-right extreme measures the viewport).
     *
     * @return array{0:int,1:int}|null
     */
    public function takeSizeReport(): ?array
    {
        $r = $this->sizeReport;
        $this->sizeReport = null;
        return $r;
    }

    /**
     * Try to decode one event from the front of the buffer.
     *
     * @return array{0: KeyEvent|MouseEvent|null, 1: int}|null
     *         [event|null, bytesConsumed], or null if the buffer holds only an
     *         incomplete sequence and we must wait for more bytes.
     */
    private function decodeOne(): ?array
    {
        $b0 = ord($this->buf[0]);

        // --- ESC-led sequences ---------------------------------------------
        if ($b0 === 0x1b) {
            $len = strlen($this->buf);
            if ($len === 1) {
                return null; // could be a lone Escape or the start of a sequence
            }

            $b1 = $this->buf[1];

            // ESC ESC: a standalone Escape press immediately followed by another
            // escape sequence. This happens on the POSIX/WSL path where the
            // resize poller solicits DSR replies (ESC [ … R) that can land in the
            // same read as the user's Esc — without this the Esc would be misread
            // as Alt+ESC (the ESC-<char> branch below) and swallowed. Emit the
            // Escape now and let the trailing sequence decode on the next pass.
            if ($b1 === "\e") {
                return [KeyEvent::special(Key::Escape), 1];
            }

            // CSI: ESC [
            if ($b1 === '[') {
                return $this->decodeCsi();
            }

            // SS3: ESC O  (F1/F2 and application-mode arrows)
            if ($b1 === 'O') {
                if ($len < 3) {
                    return null;
                }
                return match ($this->buf[2]) {
                    'P'     => [KeyEvent::special(Key::F1), 3],
                    'Q'     => [KeyEvent::special(Key::F2), 3],
                    'A'     => [KeyEvent::special(Key::Up), 3],
                    'B'     => [KeyEvent::special(Key::Down), 3],
                    'C'     => [KeyEvent::special(Key::Right), 3],
                    'D'     => [KeyEvent::special(Key::Left), 3],
                    'H'     => [KeyEvent::special(Key::Home), 3],
                    'F'     => [KeyEvent::special(Key::End), 3],
                    default => [null, 3],
                };
            }

            // ESC <ctrl-byte>  =>  Ctrl+Alt+letter (e.g. ESC 0x03 = Ctrl+Alt+C).
            $b1ord = ord($b1);
            if ($b1ord >= 0x01 && $b1ord <= 0x1a) {
                return [new KeyEvent(Key::Char, chr($b1ord + 0x60), ctrl: true, alt: true), 2];
            }

            // ESC <char>  =>  Alt+char
            return [new KeyEvent(Key::Char, $b1, alt: true), 2];
        }

        // --- single control bytes ------------------------------------------
        if ($b0 === 0x0d || $b0 === 0x0a) {
            return [KeyEvent::special(Key::Enter), 1];
        }
        if ($b0 === 0x09) {
            return [KeyEvent::special(Key::Tab), 1];
        }
        // Windows Terminal sends 0x7f for Backspace and 0x08 (^H) for
        // Ctrl+Backspace. We surface the latter as Backspace+ctrl so editors
        // can delete a whole word (§4). NOTE: verify on the target terminal —
        // some emulators swap these two bytes.
        if ($b0 === 0x7f) {
            return [KeyEvent::special(Key::Backspace), 1];
        }
        if ($b0 === 0x08) {
            return [KeyEvent::special(Key::Backspace, ctrl: true), 1];
        }

        // Ctrl+letter: 0x01..0x1a maps to Ctrl+A..Ctrl+Z (Tab/Enter handled above)
        if ($b0 >= 0x01 && $b0 <= 0x1a) {
            $letter = chr($b0 + 0x60); // 0x01 -> 'a'
            return [new KeyEvent(Key::Char, $letter, ctrl: true), 1];
        }

        // Ctrl+/ arrives as 0x1f (US) on xterm / Windows Terminal. Surface it as
        // Ctrl+'/' so it can open the system menu (§12) while a plain '/' stays a
        // literal CLI character. (0x1f is also Ctrl+_ on some layouts.)
        if ($b0 === 0x1f) {
            return [new KeyEvent(Key::Char, '/', ctrl: true), 1];
        }

        // Other C0 control bytes we don't model: drop them.
        if ($b0 < 0x20) {
            return [null, 1];
        }

        // --- printable UTF-8 -----------------------------------------------
        return $this->decodeUtf8();
    }

    /**
     * Decode a CSI sequence (buffer starts with ESC [). Returns null if the
     * terminator hasn't arrived yet.
     *
     * @return array{0: KeyEvent|MouseEvent|null, 1: int}|null
     */
    private function decodeCsi(): ?array
    {
        $len = strlen($this->buf);

        // SGR mouse: ESC [ < params (M|m)
        if ($len >= 3 && $this->buf[2] === '<') {
            for ($i = 3; $i < $len; $i++) {
                $c = $this->buf[$i];
                if ($c === 'M' || $c === 'm') {
                    $params = substr($this->buf, 3, $i - 3);
                    return [$this->decodeMouse($params, $c === 'M'), $i + 1];
                }
            }
            return null; // terminator not yet received
        }

        // Find the final byte (0x40..0x7e) that ends the CSI sequence.
        $final = '';
        $finalPos = -1;
        for ($i = 2; $i < $len; $i++) {
            $code = ord($this->buf[$i]);
            if ($code >= 0x40 && $code <= 0x7e) {
                $final = $this->buf[$i];
                $finalPos = $i;
                break;
            }
        }
        if ($finalPos === -1) {
            return null; // incomplete
        }

        $paramStr = substr($this->buf, 2, $finalPos - 2);
        $consumed = $finalPos + 1;
        $params = $paramStr === '' ? [] : array_map('intval', explode(';', $paramStr));

        // DSR cursor-position report: ESC [ row ; col R. Not a key — record it
        // for the sizer (after moveToExtreme this is the viewport's bottom-right,
        // i.e. the real height/width) and consume it silently.
        if ($final === 'R' && count($params) >= 2) {
            if ($params[0] > 0 && $params[1] > 0) {
                $this->sizeReport = [$params[1], $params[0]]; // [width, height]
            }
            return [null, $consumed];
        }

        // Modifier code is the second parameter (e.g. "1;5A" -> ctrl).
        $modCode = $params[1] ?? 1;
        $mod = $modCode - 1;
        $shift = (bool) ($mod & 1);
        $alt = (bool) ($mod & 2);
        $ctrl = (bool) ($mod & 4);

        $key = match ($final) {
            'A'     => Key::Up,
            'B'     => Key::Down,
            'C'     => Key::Right,
            'D'     => Key::Left,
            'H'     => Key::Home,
            'F'     => Key::End,
            '~'     => $this->tildeKey($params[0] ?? 0),
            default => null,
        };

        if ($key === null) {
            return [null, $consumed];
        }

        return [KeyEvent::special($key, ctrl: $ctrl, alt: $alt, shift: $shift), $consumed];
    }

    private function tildeKey(int $code): ?Key
    {
        return match ($code) {
            1, 7    => Key::Home,
            4, 8    => Key::End,
            3       => Key::Delete,
            5       => Key::PageUp,
            6       => Key::PageDown,
            11      => Key::F1,
            12      => Key::F2,
            default => null,
        };
    }

    private function decodeMouse(string $params, bool $press): ?MouseEvent
    {
        $parts = explode(';', $params);
        if (count($parts) < 3) {
            return null;
        }
        $btn = (int) $parts[0];
        $x = (int) $parts[1] - 1; // SGR mouse is 1-based
        $y = (int) $parts[2] - 1;

        // Scroll events have bit 6 (64) set: 64 = up, 65 = down.
        if ($btn & 64) {
            $kind = ($btn & 1) ? MouseEvent::SCROLL_DOWN : MouseEvent::SCROLL_UP;
            return new MouseEvent($kind, $x, $y);
        }

        // Left-button drag: motion bit 32 set with the left button (bits 0) held.
        // Checked before the plain-press case since both have button bits 0.
        if ($press && ($btn & 32) && ($btn & 3) === 0) {
            return new MouseEvent(MouseEvent::LEFT_DRAG, $x, $y);
        }

        // Plain left-button press (button bits 0, no motion, press not release).
        if ($press && ($btn & 3) === 0) {
            return new MouseEvent(MouseEvent::LEFT_DOWN, $x, $y);
        }

        // Bare movement, other buttons, releases: ignored.
        return null;
    }

    /**
     * @return array{0: KeyEvent|null, 1: int}|null
     */
    private function decodeUtf8(): ?array
    {
        $b0 = ord($this->buf[0]);
        $width = match (true) {
            $b0 < 0x80        => 1,
            ($b0 & 0xe0) === 0xc0 => 2,
            ($b0 & 0xf0) === 0xe0 => 3,
            ($b0 & 0xf8) === 0xf0 => 4,
            default           => 1, // invalid lead byte; consume it
        };

        if (strlen($this->buf) < $width) {
            return null; // wait for the rest of the codepoint
        }

        $char = substr($this->buf, 0, $width);
        return [KeyEvent::char($char), $width];
    }
}
