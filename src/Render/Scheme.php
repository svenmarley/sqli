<?php

declare(strict_types=1);

namespace Sqli\Render;

/**
 * A Base16 color scheme: the 16 slots base00–base0F, each a hex color.
 *
 * This is the *palette* layer — the portable half of a theme. Schemes are
 * consumed verbatim from the Base16 ecosystem (hundreds exist: Nord, Gruvbox,
 * Solarized…); every one is just these 16 values. What each slot *means* in our
 * UI is decided separately, by the token→slot template in {@see Theme} — that
 * split is exactly the Base16 "scheme vs template" model.
 *
 * Slot conventions (honored so any upstream scheme lands looking right):
 *   base00 darkest bg → base07 lightest, base05 = default foreground;
 *   base08–base0F are the accents (red, orange, yellow, green, cyan, blue,
 *   magenta, brown).
 */
final class Scheme
{
    public const SLOTS = [
        'base00', 'base01', 'base02', 'base03', 'base04', 'base05', 'base06', 'base07',
        'base08', 'base09', 'base0A', 'base0B', 'base0C', 'base0D', 'base0E', 'base0F',
    ];

    /**
     * Reserved name of the built-in palette (see {@see default()}), which lives
     * in code and has no file behind it. The angle brackets are deliberate: they
     * can't occur in a scheme *file* name (the save-as validator rejects them),
     * so the built-in can never be shadowed by, confused with, or overwritten by
     * a file — and it sorts to the top of the picker.
     *
     * Distinct from the bare name `default`, which still means "unspecified" and
     * resolves through the normal search path, so dropping a file named `default` in a
     * themes dir reskins the fallback.
     */
    public const BUILT_IN = '<default>';

    /**
     * @param array<string,string> $colors slot name → hex (e.g. 'base00' => '#181818')
     */
    public function __construct(
        public readonly string $name,
        private readonly array $colors,
    ) {
    }

    /**
     * All 16 slots as slot name => hex, in canonical base00..base0F order. The
     * theme editor seeds itself from this and writes it back out as a scheme
     * file; everything else reads single slots via {@see slot()}.
     *
     * @return array<string,string>
     */
    public function colors(): array
    {
        $out = [];
        foreach (self::SLOTS as $slot) {
            $out[$slot] = $this->colors[$slot] ?? '';
        }
        return $out;
    }

    /** Hex for a slot ('05' or 'base05'), or '' if the slot is unset. */
    public function slot(?string $ref): string
    {
        if ($ref === null || $ref === '') {
            return '';
        }
        $key = str_starts_with($ref, 'base') ? $ref : 'base' . $ref;
        return $this->colors[$key] ?? '';
    }

    /**
     * The built-in default palette: Base16 "Default Dark" (Chris Kempson's
     * reference scheme). Kept as an authentic scheme rather than bespoke colors
     * so the default renders through the exact same path as any downloaded one.
     * Read-only by construction — it can be loaded into the theme editor and
     * saved under another name, but never written back to.
     */
    public static function default(): self
    {
        return new self(self::BUILT_IN, [
            'base00' => '#181818', // background
            'base01' => '#282828', // subtle raised bg
            'base02' => '#383838', // selection bg
            'base03' => '#585858', // comments / dim
            'base04' => '#b8b8b8', // dark foreground
            'base05' => '#d8d8d8', // default foreground
            'base06' => '#e8e8e8', // light foreground
            'base07' => '#f8f8f8', // light background
            'base08' => '#ab4642', // red    — error
            'base09' => '#dc9656', // orange — numbers
            'base0A' => '#f7ca88', // yellow — warn / edit
            'base0B' => '#a1b56c', // green  — ok / strings
            'base0C' => '#86c1b9', // cyan   — (reserve)
            'base0D' => '#7cafc2', // blue   — primary accent
            'base0E' => '#ba8baf', // magenta— special
            'base0F' => '#a16946', // brown  — (reserve)
        ]);
    }
}
