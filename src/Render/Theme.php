<?php

declare(strict_types=1);

namespace Sqli2\Render;

use Sqli2\Terminal\Style;

/**
 * Named style tokens for UI elements (spec Section 13).
 *
 * This slice ships the built-in `default` theme only; the full theme system
 * (file loading, per-token editing, ~80 tokens across groups) layers on top of
 * this same token lookup later. Unknown tokens fall back to terminal default,
 * so views can reference a token before it has a styled definition.
 */
final class Theme
{
    /** @var array<string,Style> */
    private array $tokens;

    public function __construct(
        public readonly string $name = 'default',
    ) {
        $this->tokens = self::defaults();
    }

    public function style(string $token): Style
    {
        return $this->tokens[$token] ?? Style::default();
    }

    /** @return array<string,Style> */
    private static function defaults(): array
    {
        return [
            // Header. NB: `bold` emits SGR 1, which Windows Terminal renders by
            // *brightening* the foreground — so `black + bold` came out mid-gray and
            // unreadable on these colored chips. Dropping bold keeps black true black.
            'header'           => new Style(fg: 'cyan', bold: true),
            'header_tag_cli'   => new Style(fg: 'black', bg: 'cyan'),
            'header_tag_data'  => new Style(fg: 'black', bg: 'yellow'),
            'header_conn'      => new Style(fg: 'gray'),
            'header_db'        => new Style(fg: 'black', bg: 'cyan'),
            'header_flash'     => new Style(fg: 'black', bg: 'green'),

            // Borders
            'border_focused'   => new Style(fg: 'yellow'),
            'border_unfocused' => new Style(fg: 'darkGray'),

            // Result grid
            'grid_header'      => new Style(fg: 'white', bold: true, underline: true),
            'grid_rule'        => new Style(fg: 'darkGray'),
            'row_num'          => new Style(fg: 'darkGray'),
            'row_even'         => new Style(),
            'row_odd'          => new Style(fg: 'gray'),
            'cell_null'        => new Style(fg: 'darkGray', italic: true),
            // fg black so the selected row's text stays readable on blue instead of
            // inheriting the row's gray; bold dropped on the chips (see header note).
            'selected_row'     => new Style(fg: 'black', bg: 'blue'),
            'selected_cell'    => new Style(fg: 'black', bg: 'cyan'),
            'header_selected'  => new Style(fg: 'black', bg: 'yellow'),
            'cell_pending'     => new Style(fg: 'yellow', bold: true, italic: true),
            'cell_edit'        => new Style(fg: 'black', bg: 'yellow'),
            // Column being relocated in "move column" mode (§6).
            'col_moving'       => new Style(fg: 'black', bg: 'magenta', bold: true),

            // Input
            'input_text'       => new Style(),
            'input_text_inactive' => new Style(fg: 'gray'),
            'input_border_on'  => new Style(fg: 'cyan', bold: true),
            'input_border_off' => new Style(fg: 'darkGray'),

            // Input cursor (real cursor is hidden; we paint a reverse cell)
            'cursor'           => new Style(fg: 'black', bg: 'white'),

            // Status
            'status'           => new Style(fg: 'gray'),
            'status_ro'        => new Style(fg: 'black', bg: 'red', bold: true),
            'status_error'     => new Style(fg: 'red', bold: true),
            'layout_indicator' => new Style(fg: 'black', bg: 'magenta', bold: true),
            // Scrollbars on the result box edges (§6).
            'scrollbar'        => new Style(fg: 'darkGray'),
            'scrollbar_thumb'  => new Style(fg: 'cyan', bold: true),

            // Detail view (§8)
            'detail_title'     => new Style(fg: 'cyan', bold: true),
            'detail_field'     => new Style(fg: 'gray'),
            'detail_field_sel' => new Style(fg: 'black', bg: 'cyan', bold: true),
            'detail_value'     => new Style(),
            'detail_value_sel' => new Style(bold: true),
            'detail_json'      => new Style(fg: 'green'),

            // Vertical (\G) output (§5)
            'vertical_sep'     => new Style(fg: 'darkGray'),

            // Popups / menus (§6)
            'popup'            => new Style(fg: 'white', bg: 'black'),
            'popup_border'     => new Style(fg: 'cyan'),
            'popup_title'      => new Style(fg: 'cyan', bold: true),
            'popup_selected'   => new Style(fg: 'black', bg: 'cyan', bold: true),
            // DD comment body (the `word?` hint) — a distinct box so it stands out.
            'dd_comment'       => new Style(fg: 'white', bg: 'blue'),
            // Schema-diff badges in the DD viewer (§11): a table/field that differs
            // from the live schema vs. one that has no live counterpart at all.
            'dd_badge_diff'    => new Style(fg: 'yellow', bold: true),
            'dd_badge_orphan'  => new Style(fg: 'red', bold: true),

            // JSON viewer / editor (§10): syntax-tinted tree nodes.
            'json_marker'      => new Style(fg: 'darkGray'),
            'json_key'         => new Style(fg: 'cyan'),
            'json_brace'       => new Style(fg: 'darkGray', bold: true),
            'json_string'      => new Style(fg: 'green'),
            'json_number'      => new Style(fg: 'yellow'),
            'json_bool'        => new Style(fg: 'magenta', bold: true),
            'json_null'        => new Style(fg: 'darkGray', italic: true),
            'json_selected'    => new Style(fg: 'black', bg: 'cyan'),
        ];
    }
}
