<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * Immutable cell style: foreground/background color + attributes.
 *
 * Colors are the terminal 16-color names from the spec (Section 13). An empty
 * string or null means "terminal default" for that channel. Styles are value
 * objects: two styles with the same properties are interchangeable, which lets
 * the renderer batch runs of identically-styled cells into one SGR sequence.
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

    public function __construct(
        public readonly ?string $fg = null,
        public readonly ?string $bg = null,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $underline = false,
    ) {
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

        $fg = $this->fg !== null && $this->fg !== '' ? (self::FG[$this->fg] ?? null) : null;
        if ($fg !== null) {
            $codes[] = (string) $fg;
        }

        $bg = $this->bg !== null && $this->bg !== '' ? (self::FG[$this->bg] ?? null) : null;
        if ($bg !== null) {
            // Background = foreground code + 10 (40s for normal, 100s for bright).
            $codes[] = (string) ($bg + 10);
        }

        return "\e[" . implode(';', $codes) . 'm';
    }
}
