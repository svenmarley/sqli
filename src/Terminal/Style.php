<?php

declare(strict_types=1);

namespace Sqli\Terminal;

/**
 * Immutable cell style: foreground/background color + attributes.
 *
 * A color channel is one of three things: a terminal 16-color *name* from the
 * spec (Section 13, e.g. `cyan`), a 24-bit *hex* string (`#1e88e5` — the form
 * Base16 schemes resolve to), or null/empty for "terminal default". Named colors
 * emit the classic SGR 30-37/90 codes; hex colors emit truecolor `38;2;r;g;b`
 * (and `48;2` for backgrounds). Styles are value objects: two styles with the
 * same properties are interchangeable, which lets the renderer batch runs of
 * identically-styled cells into one SGR sequence.
 */
final class Style
{
    /** Foreground color name -> SGR code. */
    private const FG = [
        'black'    => 30,
        'red'      => 31,
        'green'    => 32,
        'yellow'   => 33,
        'blue'     => 34,
        'magenta'  => 35,
        'cyan'     => 36,
        'white'    => 37,
        'gray'     => 90,   // bright black reads as a mid gray
        'darkGray' => 90,
    ];

    /**
     * Whether the terminal renders 24-bit `38;2` truecolor. When false, hex
     * colors degrade to the nearest xterm-256 index (`38;5`), which virtually
     * every modern non-truecolor terminal supports. Process-wide (a static)
     * because Style is a value object built in bulk by the theme; the renderer
     * sets it once at startup via {@see setTruecolor()}.
     */
    private static bool $truecolor = true;

    public function __construct(
        public readonly ?string $fg = null,
        public readonly ?string $bg = null,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $underline = false,
    ) {
    }

    /** Enable/disable 24-bit output (see {@see $truecolor}). */
    public static function setTruecolor(bool $on): void
    {
        self::$truecolor = $on;
    }

    /**
     * Best-effort truecolor detection from an environment map. Conservative: it
     * only claims 24-bit on positive evidence (COLORTERM, Windows Terminal,
     * ConEmu, a known truecolor TERM_PROGRAM/TERM) and otherwise degrades to
     * xterm-256, so a terminal that can't do truecolor never gets garbled.
     *
     * @param array<string,string|null> $env
     */
    public static function detectTruecolor(array $env): bool
    {
        $colorterm = strtolower((string) ($env['COLORTERM'] ?? ''));
        if ($colorterm === 'truecolor' || $colorterm === '24bit') {
            return true;
        }
        if (($env['WT_SESSION'] ?? '') !== '') {          // Windows Terminal
            return true;
        }
        if (strtoupper((string) ($env['ConEmuANSI'] ?? '')) === 'ON') {
            return true;
        }
        $program = strtolower((string) ($env['TERM_PROGRAM'] ?? ''));
        if (in_array($program, ['vscode', 'iterm.app', 'hyper', 'wezterm', 'ghostty'], true)) {
            return true;
        }
        if (str_contains(strtolower((string) ($env['TERM'] ?? '')), 'direct')) {
            return true;
        }
        return false;
    }

    public static function default(): self
    {
        return new self();
    }

    public function withFg(?string $fg): self
    {
        return new self($fg, $this->bg, $this->bold, $this->italic, $this->underline);
    }

    public function withBg(?string $bg): self
    {
        return new self($this->fg, $bg, $this->bold, $this->italic, $this->underline);
    }

    public function withBold(bool $bold = true): self
    {
        return new self($this->fg, $this->bg, $bold, $this->italic, $this->underline);
    }

    public function withItalic(bool $italic = true): self
    {
        return new self($this->fg, $this->bg, $this->bold, $italic, $this->underline);
    }

    public function withUnderline(bool $underline = true): self
    {
        return new self($this->fg, $this->bg, $this->bold, $this->italic, $underline);
    }

    public function equals(Style $other): bool
    {
        return $this->fg === $other->fg
            && $this->bg === $other->bg
            && $this->bold === $other->bold
            && $this->italic === $other->italic
            && $this->underline === $other->underline;
    }

    /**
     * Build the SGR escape sequence that fully establishes this style,
     * always starting from a reset so it is self-contained.
     */
    public function sgr(): string
    {
        $codes = ['0'];

        if ($this->bold) {
            $codes[] = '1';
        }
        if ($this->italic) {
            $codes[] = '3';
        }
        if ($this->underline) {
            $codes[] = '4';
        }

        foreach (self::channelCodes($this->fg, false) as $c) {
            $codes[] = $c;
        }
        foreach (self::channelCodes($this->bg, true) as $c) {
            $codes[] = $c;
        }

        return "\e[" . implode(';', $codes) . 'm';
    }

    /**
     * SGR codes for one color channel. A `#rrggbb` hex value becomes a truecolor
     * selector (`38;2;r;g;b` / `48;2;r;g;b`); a 16-color name becomes the classic
     * 30-37/90 code (background = +10, giving the 40s/100s). Empty/unknown yields
     * no codes, i.e. terminal default for that channel.
     *
     * @return list<string>
     */
    private static function channelCodes(?string $color, bool $isBg): array
    {
        if ($color === null || $color === '') {
            return [];
        }

        $rgb = self::parseHex($color);
        if ($rgb !== null) {
            [$r, $g, $b] = $rgb;
            $lead = $isBg ? '48' : '38';
            if (self::$truecolor) {
                return [$lead, '2', (string) $r, (string) $g, (string) $b];
            }
            // Degrade to the nearest xterm-256 index for non-truecolor terminals.
            return [$lead, '5', (string) self::rgbToXterm256($r, $g, $b)];
        }

        $code = self::FG[$color] ?? null;
        if ($code === null) {
            return [];
        }
        return [(string) ($isBg ? $code + 10 : $code)];
    }

    /**
     * Map a 24-bit RGB color to the nearest xterm-256 palette index — either the
     * 6×6×6 color cube (16–231) or the 24-step grayscale ramp (232–255),
     * whichever is closer to the original.
     */
    public static function rgbToXterm256(int $r, int $g, int $b): int
    {
        // Nearest level on the cube's non-linear axis {0,95,135,175,215,255}.
        $levels = [0, 95, 135, 175, 215, 255];
        $nearest = static function (int $v) use ($levels): array {
            $bestI = 0;
            $bestD = PHP_INT_MAX;
            foreach ($levels as $i => $l) {
                $d = abs($l - $v);
                if ($d < $bestD) {
                    $bestD = $d;
                    $bestI = $i;
                }
            }
            return [$bestI, $levels[$bestI]];
        };
        [$ri, $rv] = $nearest($r);
        [$gi, $gv] = $nearest($g);
        [$bi, $bv] = $nearest($b);
        $cubeIdx = 16 + 36 * $ri + 6 * $gi + $bi;
        $cubeDist = ($rv - $r) ** 2 + ($gv - $g) ** 2 + ($bv - $b) ** 2;

        // Nearest gray on the 232..255 ramp (values 8,18,…,238).
        $gray = (int) round(($r + $g + $b) / 3);
        $grayI = max(0, min(23, (int) round(($gray - 8) / 10)));
        $grayV = 8 + $grayI * 10;
        $grayIdx = 232 + $grayI;
        $grayDist = ($grayV - $r) ** 2 + ($grayV - $g) ** 2 + ($grayV - $b) ** 2;

        return $grayDist < $cubeDist ? $grayIdx : $cubeIdx;
    }

    /**
     * Parse `#rgb` or `#rrggbb` (with or without the leading `#`) into
     * [r, g, b] 0-255, or null if it isn't a hex color. Base16 schemes store
     * six-digit hex without the `#`, so both forms are accepted.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    public static function parseHex(string $color): ?array
    {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return null;
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
