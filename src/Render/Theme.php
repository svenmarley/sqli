<?php

declare(strict_types=1);

namespace Sqli\Render;

use Sqli\Terminal\Style;

/**
 * Named style tokens for UI elements (spec Section 13), resolved through a
 * Base16 {@see Scheme}.
 *
 * Two tiers, which is the whole point:
 *   ROLES  — ~20 semantic roles, each a (fg slot, bg slot) pair against the 16
 *            Base16 slots. This is the authoring surface: change a role's slot
 *            (or swap the scheme) and every element wearing it follows.
 *   TOKENS — the ~57 element names the renderer actually asks for, each aliased
 *            to a role plus per-token attributes (bold/italic/underline). Keeping
 *            these names lets the renderer stay untouched while color comes
 *            entirely from the scheme.
 *
 * Slot choices follow the Base16 conventions (base05 = default fg, base02 =
 * selection, base08/0A/0B/0D = red/yellow/green/blue accents…) so any upstream
 * scheme lands looking right. Unknown tokens fall back to terminal default.
 */
final class Theme
{
    /**
     * Semantic roles → [fg slot, bg slot]. Null = leave that channel at the
     * terminal default. These are the ~20 ideas the 57 tokens below collapse to.
     *
     * @var array<string,array{0:?string,1:?string}>
     */
    private const ROLES = [
        // Surfaces & text
        'surface'     => ['05', '00'], // popup body: default fg on default bg
        'fg'          => ['05', null], // body text
        'fg_muted'    => ['04', null], // secondary text
        'fg_dim'      => ['03', null], // de-emphasized: rules, gutters, comments
        // Semantic text colors
        'accent'      => ['0D', null], // primary accent (blue)
        'accent2'     => ['0E', null], // special (magenta)
        'ok'          => ['0B', null], // strings / added / success (green)
        'number'      => ['09', null], // numbers (orange)
        'warn'        => ['0A', null], // warning / edit-in-progress (yellow)
        'error'       => ['08', null], // error / danger (red)
        // Selection, edit, cursor
        'sel_active'  => ['00', '0D'], // active cell/selection — vivid
        'sel_passive' => ['05', '02'], // whole-row highlight — subtle
        'edit'        => ['00', '0A'], // cell being edited
        'cursor'      => ['00', '05'], // painted cursor block
        // Chips (dark text on an accent) & the DD hint box
        'chip_info'   => ['00', '0D'],
        'chip_warn'   => ['00', '0A'],
        'chip_ok'     => ['00', '0B'],
        'chip_error'  => ['00', '08'],
        'chip_alt'    => ['00', '0E'],
        'note'        => ['05', '0D'], // DD comment box: light on blue
    ];

    /**
     * Element token → [role, attrs]. attrs is any subset of 'b'/'i'/'u'.
     *
     * @var array<string,array{0:string,1?:string}>
     */
    private const TOKENS = [
        // The screen ground — painted into every blank cell so the whole UI sits
        // on the scheme's base00, not the terminal's background.
        'background'          => ['surface'],

        // Header
        'header'              => ['accent', 'b'],
        'header_tag_cli'      => ['chip_info'],
        'header_tag_data'     => ['chip_warn'],
        // The user's own note beside the tag (§7). Bracketed text in the
        // scheme's "special" hue rather than a chip: it must read as neither CLI
        // nor DATA, and never be mistaken for the `!db` alarm.
        'header_comment'      => ['accent2', 'b'],
        'header_conn'         => ['fg_muted'],
        'header_db'           => ['chip_info'],
        // The table the selected column came from, on a joined result's chip
        // (§3). A warm chip against `header_db`'s cool one: the two sit side by
        // side in one list and the eye has to find the live one without reading
        // it. Same pair as the CLI/DATA tag, for the same reason.
        'header_db_active'    => ['chip_warn', 'b'],
        'header_flash'        => ['chip_ok'],
        // The connection is not healthy (§2) — the header's `!db` marker. A red
        // chip rather than a coloured word: five characters have to read as an
        // alarm at a glance from across a desk, next to a grid that still shows
        // the rows the dead connection returned.
        //
        // One token, not two. `header_relink` was the amber half of a pair back
        // when the row spelled out which unhealthy state it was in; every state
        // now draws the same marker, and what distinguishes them is said on the
        // status line, in words.
        'header_tls'          => ['ok', 'b'],
        'header_plain'        => ['fg_muted'],
        'header_lost'         => ['chip_error', 'b'],

        // Borders
        'border_focused'      => ['warn'],
        'border_unfocused'    => ['fg_dim'],

        // Data view tabs (§20), painted onto the result box's top border
        'tab_active'          => ['chip_info', 'b'],
        'tab_used'            => ['fg_muted'],
        'tab_empty'           => ['fg_dim'],

        // Result grid
        'grid_header'         => ['fg', 'bu'],
        'grid_rule'           => ['fg_dim'],
        'row_num'             => ['fg_dim'],
        'row_even'            => ['fg'],
        'row_odd'             => ['fg_muted'],
        'cell_null'           => ['fg_dim', 'i'],
        'selected_row'        => ['sel_passive'],
        'selected_cell'       => ['sel_active'],
        'header_selected'     => ['sel_active'],
        'cell_pending'        => ['warn', 'bi'],
        // Rows marked for deletion, and the mark-mode checkbox gutter (§22)
        'row_marked'          => ['error', 'b'],
        'mark_box'            => ['chip_error'],
        'mark_box_off'        => ['fg_dim'],
        'cell_edit'           => ['edit'],
        'col_moving'          => ['chip_alt', 'b'],
        'col_formatted'       => ['accent2', 'bu'],

        // Input
        'input_text'          => ['fg'],
        'input_text_inactive' => ['fg_muted'],
        'input_selection'     => ['sel_active'],
        'input_border_on'     => ['accent', 'b'],
        'input_border_off'    => ['fg_dim'],
        'cursor'              => ['cursor'],

        // Status
        'status'              => ['fg_dim'],
        'status_ro'           => ['chip_error', 'b'],
        'status_error'        => ['error', 'b'],
        'layout_indicator'    => ['chip_alt', 'b'],
        'scrollbar'           => ['fg_dim'],
        'scrollbar_thumb'     => ['accent', 'b'],

        // Detail view
        'detail_title'        => ['accent', 'b'],
        'detail_field'        => ['fg_muted'],
        'detail_field_sel'    => ['sel_active', 'b'],
        'detail_value'        => ['fg'],
        'detail_value_sel'    => ['fg', 'b'],
        'detail_json'         => ['ok'],

        // Vertical (\G) output
        'vertical_sep'        => ['fg_dim'],

        // Popups / menus
        'popup'               => ['surface'],
        'popup_border'        => ['accent'],
        'popup_title'         => ['accent', 'b'],
        'popup_selected'      => ['sel_active', 'b'],
        'dd_comment'          => ['note'],
        'dd_badge_diff'       => ['warn', 'b'],
        'dd_badge_orphan'     => ['error', 'b'],
        'dd_badge_new'        => ['ok', 'b'],

        // Macros viewer (§23)
        'macro_name'       => ['accent2', 'b'],
        'macro_value'      => ['fg'],
        'macro_source'     => ['fg_dim'],

        // JSON viewer / editor
        'json_marker'         => ['fg_dim'],
        'json_key'            => ['accent'],
        'json_brace'          => ['fg_dim', 'b'],
        'json_string'         => ['ok'],
        'json_number'         => ['number'],
        'json_bool'           => ['accent2', 'b'],
        'json_null'           => ['fg_dim', 'i'],
        'json_selected'       => ['sel_active'],
        'json_notice'         => ['warn', 'b'],
    ];

    /**
     * What each Base16 slot actually drives in *this* UI — the plain-English
     * version of the ROLES table above, for the theme editor's slot list. Purely
     * descriptive: editing a slot changes every role pointing at it.
     *
     * @var array<string,string>
     */
    public const SLOT_ROLES = [
        'base00' => 'ground · popup bg · chip text',
        'base01' => 'raised background',
        'base02' => 'row highlight bg',
        'base03' => 'dim text · rules · NULL',
        'base04' => 'secondary text',
        'base05' => 'body text · cursor',
        'base06' => 'light foreground',
        'base07' => 'lightest foreground',
        'base08' => 'red — errors · ✗ badge',
        'base09' => 'orange — numbers',
        'base0A' => 'yellow — warn · edit · focus border',
        'base0B' => 'green — ok · strings · ⊕ badge',
        'base0C' => 'cyan — (reserve)',
        'base0D' => 'blue — accent · selection · titles',
        'base0E' => 'magenta — special · layout tag',
        'base0F' => 'brown — (reserve)',
    ];

    private readonly Scheme $scheme;

    /** @var array<string,Style> */
    private array $tokens;

    public function __construct(
        public readonly string $name = 'default',
        ?Scheme $scheme = null,
    ) {
        // Phase 3 will resolve $name to a loaded scheme; for now the built-in
        // Default Dark palette backs every theme name.
        $this->scheme = $scheme ?? Scheme::default();
        $this->tokens = $this->build();
    }

    public function style(string $token): Style
    {
        return $this->tokens[$token] ?? Style::default();
    }

    public function scheme(): Scheme
    {
        return $this->scheme;
    }

    /** @return array<string,Style> */
    private function build(): array
    {
        $out = [];
        foreach (self::TOKENS as $token => $spec) {
            $role = $spec[0];
            $attr = $spec[1] ?? '';
            [$fgSlot, $bgSlot] = self::ROLES[$role];
            // A role with no explicit bg still sits on base00, so text carries the
            // scheme's ground rather than the terminal's — otherwise a light
            // theme's dark text lands on a dark terminal with no light backdrop.
            $bg = $bgSlot !== null ? $this->scheme->slot($bgSlot) : $this->scheme->slot('00');
            $out[$token] = new Style(
                fg: $this->scheme->slot($fgSlot) ?: null,
                bg: $bg ?: null,
                bold: str_contains($attr, 'b'),
                italic: str_contains($attr, 'i'),
                underline: str_contains($attr, 'u'),
            );
        }
        return $out;
    }
}
