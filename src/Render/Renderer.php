<?php

declare(strict_types=1);

namespace Sqli\Render;

use Sqli\Core\GridLayout;
use Sqli\Core\State;
use Sqli\Db\QueryResult;
use Sqli\Format\ColumnFormats;
use Sqli\Input\LineEditor;
use Sqli\Terminal\Screen;
use Sqli\Terminal\Style;

/**
 * Paints the whole UI into the screen back-buffer each frame (spec Section 3).
 *
 * Regions, top to bottom: header, bordered result area, input line, status
 * line. The result box switches between single- and double-line borders to
 * signal CLI vs DATA focus (Section 7). The renderer is stateless beyond the
 * Theme; it reads State + LineEditor and writes cells — the Screen handles the
 * differential flush.
 */
final class Renderer
{
    private const MIN_COL_WIDTH = 3;
    private const MAX_COL_WIDTH = 40;
    private const CELL_TRUNCATE = 200;
    /** Narrowest header gap still worth putting a db.table tile in. */
    private const HEADER_DB_MIN = 6;
    /** Longest header comment drawn, ellipsis included (§7). */
    private const HEADER_COMMENT_MAX = 28;
    /** Narrowest header gap the comment will take a bite out of (§7). */
    private const HEADER_COMMENT_MIN = 12;
    /** Longest table name a data view tab shows before eliding (§20). */
    private const TAB_LABEL_MAX = 12;
    private const WIDTH_SAMPLE  = 200;
    /**
     * Width of the mark-mode checkbox gutter (§22): `[x]` plus the space that
     * separates it from the first data column.
     */
    private const MARK_W        = 4;

    /**
     * Frames of the running-query spinner (§21), and how long each is held.
     *
     * Deliberately ASCII. The obvious prettier fourth frame is an em dash, and
     * U+2014 is East Asian Ambiguous width: a terminal configured for CJK draws
     * it two cells wide, so one frame in four would be wider than the others —
     * and because the notice is right-justified, that does not merely look
     * wrong, it slides the whole string a cell sideways and back on every
     * rotation. `-` is one cell everywhere.
     */
    private const SPINNER        = ['|', '/', '-', '\\'];
    private const SPINNER_PERIOD = 0.1;

    // Single- vs double-line box-drawing sets.
    private const BORDER = [
        'cli'  => ['tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘', 'h' => '─', 'v' => '│'],
        'data' => ['tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝', 'h' => '═', 'v' => '║'],
    ];

    public function __construct(
        private Theme $theme,
    ) {
    }

    /**
     * Swap the active theme. Every element colour is read through {@see
     * Theme::style()} on each frame, so the next repaint recolours the entire UI
     * — which is what makes the theme editor's live preview the real thing
     * rather than a mock-up (§13).
     */
    public function setTheme(Theme $theme): void
    {
        $this->theme = $theme;
    }

    public function render(Screen $screen, State $state, LineEditor $input): void
    {
        // Blank cells carry the scheme's ground (base00), so the whole UI — not
        // just the styled regions — sits on the theme background.
        $screen->setBackground($this->theme->style('background'));
        $screen->clear();
        $w = $screen->width();
        $h = $screen->height();

        // Geometry published for click mapping is rebuilt every frame.
        $state->gridLayout = null;
        $state->layoutIndicatorX = -1;
        $state->layoutIndicatorY = -1;
        $state->layoutIndicatorW = 0;
        $state->layoutIndicatorH = 0;
        $state->vScrollLen = 0;
        $state->hScrollLen = 0;
        $state->modalRect = null;
        $state->errorRect = null;
        $state->cellPopupRect = null;
        $state->editFieldRegion = null;
        $state->editorFrameRect = null;
        $state->inputCursorX = -1;
        $state->inputCursorY = -1;

        if ($h < 6 || $w < 10) {
            $screen->put(0, 0, 'Terminal too small', $this->theme->style('status_error'));
            return;
        }

        // The input region grows by one row per pushed line (§4), capped so the
        // result box always keeps a few rows. Status sits on the bottom row.
        $statusY = $h - 1;
        $maxInput = max(1, $h - 5);
        $inputCount = min(count($input->lines()), $maxInput);
        $inputBottom = $h - 2;
        $inputTop = $inputBottom - ($inputCount - 1);
        $inputRuleY = $inputTop - 1;
        $boxTop = 1;
        $boxBottom = $inputRuleY - 1;

        $this->drawHeader($screen, $state, $w);
        [$cx, $cy, $cw, $ch] = $this->drawResultBox($screen, $state, $w, $boxTop, $boxBottom);
        // Data view tabs ride ON the box's top border rather than taking a row of
        // their own (§20) — five tabs are worth a strip, not a whole line of grid.
        $this->drawTabs($screen, $state, $w, $boxTop);

        // While any layout customization is active, a vertical "Layout Config"
        // label runs down the LEFT border of the data view (§6). It paints over
        // the border glyphs rather than reserving a strip, so the grid keeps its
        // full width. It starts below the header separator rule so it clears the
        // top corner and the field-name row.
        $showLayout = $state->layoutActive() && !$state->vertical && $state->hasResult();
        $this->drawResultContent($screen, $state, $cx, $cy, $cw, $ch);
        if ($showLayout) {
            $this->drawLayoutLabel($screen, $state, 0, $cy + 2, $ch - 2);
        }
        // Scrollbars overlay the box edges, sized by row/column counts (§6).
        $this->drawScrollbars($screen, $state, $w, $boxTop, $boxBottom);
        // Inline cell editor overlays the selected grid cell; big values and
        // inspector edits are drawn later as popups instead (§9).
        if ($state->cellEdit && !$state->cellEditPopup && !$state->cellEditFromView) {
            $this->drawCellEdit($screen, $state);
        }
        $this->drawInput($screen, $state, $input, $w, $inputRuleY, $inputTop, $inputBottom, $maxInput);
        $this->drawStatus($screen, $state, $w, $statusY);

        // The Data Dictionary viewer is a full-screen mode: paint it over the
        // base UI and skip the small overlays (§11).
        if ($state->ddViewer) {
            $this->drawDdViewer($screen, $state, $w, $h);
            $this->drawBusy($screen, $state, $w, $h);
            return;
        }

        // The JSON viewer is likewise a full-screen mode painted over everything
        // (it can sit over the detail view); it owns input until closed (§10).
        if ($state->jsonView) {
            $this->drawJsonViewer($screen, $state, $w, $h);
            $this->drawBusy($screen, $state, $w, $h);
            return;
        }

        // Modal overlays paint last, centered over everything (§6).
        if ($state->layoutPopup) {
            $this->drawLayoutPopup($screen, $state, $w, $h);
        } elseif ($state->systemMenu) {
            $this->drawSystemMenu($screen, $state, $w, $h);
        }
        // Assign-DD picker: directory entry then file list (§11).
        if ($state->ddPicker) {
            $this->drawDdPicker($screen, $state, $w, $h);
        }
        // Read-only macros viewer (§23).
        if ($state->macrosView) {
            $this->drawMacrosView($screen, $state, $w, $h);
        }
        // The completion popup floats over the input, anchored at the cursor (§4).
        if ($state->completion) {
            $this->drawCompletion($screen, $state, $w, $h);
        }
        // Context-sensitive help overlay (§15).
        if ($state->help) {
            $this->drawHelp($screen, $state, $w, $h);
        }
        // DD field-hint popup (`word?`, §11): field name centered over the comment.
        if ($state->ddHint) {
            $state->modalRect = $this->drawDdHint($screen, $state, $w, $h);
        }
        // The movable, non-modal cell inspector. While editing from it, the edit
        // box takes the inspector's spot; otherwise the read-only value shows. The
        // quick (Enter) editor for big values is a separate centred popup. (§9)
        if ($state->cellView) {
            if ($state->cellEdit && $state->cellEditFromView) {
                $this->drawCellInspectorEdit($screen, $state, $w, $h);
            } else {
                $this->drawCellInspector($screen, $state, $w, $h);
            }
        } elseif ($state->cellEdit && $state->cellEditPopup) {
            $this->drawCellEditPopup($screen, $state, $w, $h);
        }

        // Theme editor: a panel over the live UI, which is itself already drawn
        // in the palette being edited (§13).
        if ($state->themeEditor) {
            $this->drawThemeEditor($screen, $state, $w, $h);
        } elseif ($state->themeSelect) {
            // Standalone theme switch — the UI behind is already repainted in
            // whichever scheme the selection is sitting on.
            $this->drawThemePicker($screen, $state, $w, $h, ' Select theme ', '↑↓ preview · Enter: keep · Esc: cancel');
        }

        // Generic chooser popup: create-config dir picker / state-dir prompt (§16).
        if ($state->chooser !== null) {
            $this->drawChooser($screen, $state, $w, $h);
        }

        // The header-comment prompt (system menu, §7). Drawn over everything the
        // menu itself was drawn over, since it replaces it.
        if ($state->commentEdit && $state->commentEditor !== null) {
            $this->drawTextPrompt(
                $screen,
                $state,
                $w,
                $h,
                ' Header comment ',
                $state->commentEditor,
                'Enter: set · empty: clear · Esc: cancel',
            );
        }

        // A `?{name}` question (§23), drawn like the header-comment prompt it
        // shares its shape with. Above the menus, because the submission behind
        // it is already under way and nothing else can be done until it is
        // answered or dropped.
        if ($state->promptAsk && $state->promptEditor !== null) {
            $this->drawTextPrompt(
                $screen,
                $state,
                $w,
                $h,
                $state->promptTitle,
                $state->promptEditor,
                'Enter: use this value · Esc: cancel the statement',
            );
        }

        // Top-most: PK-missing popup and the confirm dialog (§5/§9/§18).
        if ($state->pkPopup) {
            $state->modalRect = $this->drawDialog($screen, $w, $h, [$state->pkPopupMessage]);
        }
        if ($state->confirm) {
            $opts = implode('   ', array_map(
                static fn (array $o): string => '[' . $o[0] . '] ' . $o[1],
                $state->confirmOptions,
            ));
            $state->modalRect = $this->drawDialog($screen, $w, $h, [$state->confirmMessage, $opts]);
        }

        // A "please wait" popup, topmost of all, while a slow op blocks the loop.
        $this->drawBusy($screen, $state, $w, $h);
    }

    /**
     * "Please wait" notice shown while the App runs a known-slow synchronous
     * operation. Drawn last so it sits over every other overlay; a no-op when no
     * busy op is active.
     *
     * Routine ops (queries, DD load/sync, schema warm) draw an unobtrusive
     * one-liner just above the status/help line — no border, no mid-screen modal.
     * Only the initial DB connect sets $busyModal for the centered dialog, since
     * it can block for the full TCP timeout and warrants the emphasis.
     */
    private function drawBusy(Screen $screen, State $state, int $w, int $h): void
    {
        // queryRunning is only ever set on the cancellable path, so canCancel is
        // belt and braces — but it is the one flag that says "none of the moving
        // parts apply here", and drawBusy is where that has to be true.
        $live = $state->queryRunning && $state->canCancel;
        $message = $live ? $this->runningQueryNotice($state, $w) : $state->busyMessage;
        if ($message === '') {
            return;
        }
        if ($state->busyModal && !$live) {
            $this->drawDialog($screen, $w, $h, [$message]);
            return;
        }
        // Inline notice right-justified on the row just above the status/help
        // line (h-1), one cell in from the right edge.
        $row = max(1, $h - 2);
        $msg = $this->truncate(' ' . $message . ' ', $w);
        $col = max(0, $w - mb_strlen($msg) - 1);
        $screen->put($col, $row, $msg, $this->theme->style('popup'));
    }

    /**
     * The notice for a query running on the cancellable path (§21) — the one
     * that moves.
     *
     * It occupies the same row, right-justified, as the notice a blocking query
     * has always drawn; what is new is that the loop is still running, so this
     * can count and spin and offer a way out. The spinner is the honest part:
     * it is driven by the real frame loop, so if sqli itself wedges it stops,
     * and "the server is slow" stops looking like "the client is stuck".
     *
     * Widths shed from the right as the terminal narrows — hint first, then the
     * clock, leaving the spinner — the same ladder the tab strip uses (§20), and
     * for the same reason: what survives to the end should be the part that is
     * load-bearing, and here that is the proof something is still alive.
     */
    private function runningQueryNotice(State $state, int $w): string
    {
        $elapsed = max(0.0, microtime(true) - $state->queryStarted);
        // Tied to elapsed time rather than to a frame counter: at 8ms a frame,
        // counting frames would spin at 125fps and report how busy the renderer
        // is instead of how long the query has taken.
        $spinner = self::SPINNER[(int) ($elapsed / self::SPINNER_PERIOD) % count(self::SPINNER)];

        // Fixed width, so ticking past 10s does not shift a right-justified
        // string one cell sideways mid-spin.
        $clock = str_pad(number_format($elapsed, 1) . 's', 6, ' ', STR_PAD_LEFT);
        $label = $state->queryCancelling ? 'Cancelling …' : 'Running query …';
        $hint  = $state->queryCancelling ? '' : ' · Esc to cancel';

        foreach ([
            $label . ' ' . $clock . $hint . ' ' . $spinner,
            $label . ' ' . $clock . ' ' . $spinner,
            $label . ' ' . $spinner,
            $spinner,
        ] as $candidate) {
            if (mb_strlen($candidate) + 2 <= $w) {
                return $candidate;
            }
        }
        return $spinner;
    }

    /**
     * What the header tile says the grid is showing, in two widths.
     *
     * Single table — the ordinary case — is unchanged: `db.table`, with the
     * database droppable because the connection label on the right already ends
     * in `/db`.
     *
     * A join is the case this was rewritten for. Its columns come from several
     * tables, the clashing ones are renamed `1.id` / `2.id` ({@see
     * \Sqli\Db\ResultColumns}), and those numbers have to be legible somewhere:
     * here, as `prod.templates(1), template_items(2)`. The database is shown on
     * the first entry only, and again on any later one that came from a
     * different database, because a cross-database join is the one time the
     * repetition carries information.
     *
     * The alias is appended only where two sources share a real table name — a
     * self-join, `prod.widgets w(1), widgets w2(2)`, where the numbers would
     * otherwise be the only thing distinguishing two identical labels. On an
     * ordinary join nobody needs to be told that `templates` was called `t`.
     *
     * **The selected column's table is highlighted** as the user walks the grid
     * sideways, so `1.id` does not have to be decoded against the list by
     * counting — the entry it refers to lights up. Every column gets this, not
     * only the qualified ones: a column whose name needed no number still came
     * from one of these tables and the chip still says which. When the selected
     * column came from no table at all (an expression), nothing is highlighted
     * rather than something being highlighted wrongly.
     *
     * @return array{full:string,short:string,fullSpan:?array{0:int,1:int},shortSpan:?array{0:int,1:int}}
     *         The full label and the narrower one to fall back to (`''` when
     *         there is none), plus the offset and length, within each, of the
     *         entry to highlight (null for none).
     */
    private static function headerWhere(State $state): array
    {
        $sources = $state->result?->sources ?? [];
        if (count($sources) < 2) {
            $parts = array_values(array_filter(
                [$state->currentDatabase, $state->currentTable],
                static fn (string $s): bool => $s !== '',
            ));
            return [
                'full' => implode('.', $parts),
                'short' => count($parts) === 2 ? $parts[1] : '',
                'fullSpan' => null,
                'shortSpan' => null,
            ];
        }

        $names = [];
        foreach ($sources as $source) {
            $names[] = $source->name();
        }
        $repeated = array_count_values($names);

        // Which entry the grid is standing on, as a 1-based source number.
        // Only while the grid has focus: at the CLI there is no "current column"
        // the user is looking at, so a lit table would point at nothing.
        $selected = $state->focus === State::FOCUS_DATA
            ? ($state->visibleColumnNames()[$state->selectedCol] ?? null)
            : null;
        $active = $selected === null ? 0 : ($state->result?->sourceOf($selected) ?? 0);

        $full = [];
        $short = [];
        $fullSpan = null;
        $shortSpan = null;
        $firstDb = $sources[0]->database;
        foreach ($sources as $i => $source) {
            $alias = ($repeated[$source->name()] ?? 0) > 1;
            $tail = '(' . ($i + 1) . ')';
            $showDb = $source->database !== '' && ($i === 0 || $source->database !== $firstDb);
            $one = $source->label($alias, $showDb) . $tail;
            $two = $source->label($alias, $i > 0 && $source->database !== $firstDb) . $tail;
            if ($i + 1 === $active) {
                // Measured before this entry is appended, so the offset is the
                // width of everything already in the list plus its separators.
                // The span skips any `db.` prefix: the highlight names the
                // table, and the database is shared context, not the answer.
                $bare = mb_strlen($source->label($alias) . $tail);
                $fullSpan  = [mb_strlen(implode(', ', $full)) + ($full === [] ? 0 : 2) + mb_strlen($one) - $bare, $bare];
                $shortSpan = [mb_strlen(implode(', ', $short)) + ($short === [] ? 0 : 2) + mb_strlen($two) - $bare, $bare];
            }
            $full[]  = $one;
            $short[] = $two;
        }
        return [
            'full' => implode(', ', $full),
            'short' => implode(', ', $short),
            'fullSpan' => $fullSpan,
            'shortSpan' => $shortSpan,
        ];
    }

    private function drawHeader(Screen $screen, State $state, int $w): void
    {
        $left = ' sqli ' . ($state->version !== '' ? 'v' . $state->version : '');
        $screen->put(0, 0, $left, $this->theme->style('header'));

        $tag = $state->focus === State::FOCUS_DATA ? ' DATA ' : ' CLI ';
        $tagStyle = $state->focus === State::FOCUS_DATA
            ? $this->theme->style('header_tag_data')
            : $this->theme->style('header_tag_cli');
        $tagX = mb_strlen($left) + 1;
        $screen->put($tagX, 0, $tag, $tagStyle);

        // A managed-database hostname is routinely 60-80 characters
        // (`admin@database-devel.cluster-xxxx.us-east-1.rds.amazonaws.com:3306/devel`),
        // which on anything short of a very wide terminal claimed the entire
        // header row — pushing out the db.table tile, and then failing its own
        // fit check and vanishing too, leaving a header with nothing on it.
        // Elide the middle instead: the head (`user@database-devel`) is what
        // says which server this is, and the tail (`:port/db`) is what says
        // which database, so both ends earn their place and only the provider
        // boilerplate in between is spent.
        $conn = $state->connectionLabel !== '' ? $state->connectionLabel : 'not connected';
        $conn = self::elideMiddle($conn, max(24, intdiv($w * 45, 100)));

        // Prefixes, in the order they are read: which driver opened the
        // connection, whether it is read-only, whether it is encrypted, then who
        // and where. All three sit *outside* the elision above, so none is ever
        // half a word — the budget belongs to the hostname, which is the part
        // with slack in it.
        //
        // The connection is described **here and nowhere else**. It used to be
        // spelled out under the CLI as well (`Connected · admin@host/db · TLS
        // ECDHE-RSA-AES256-GCM-SHA384`), which spent the status line's first
        // screenful repeating a row already on screen — and the status line is
        // where row counts, rewrite notes and edit prompts have to go.
        //
        // The cipher is not shown. `TLS` answers the question anyone reads this
        // row to ask — *is this encrypted* — and the suite that was negotiated
        // answers a different one, which `debug.log` and the connection log
        // already answer in full. `no TLS` is drawn rather than left blank
        // because the marker is droppable at narrow widths, and a missing marker
        // must never be readable as a plaintext session.
        $leftEnd = $tagX + mb_strlen($tag);
        $ro = $state->readOnly ? [['[RO] ', 'header_conn']] : [];
        // An env nobody typed (a config file's default, or $SQLI_ENV) — named,
        // because its settings sit under everything the command line said and
        // `[RO]` alone cannot say which of the two turned it on. It outlasts the
        // driver and the security marker; only a terminal too narrow for it and
        // the host together sheds it, and the `[RO]` message still names it.
        $env = $state->envUntyped !== '' ? [['env ' . $state->envUntyped . ' ', 'header_comment']] : [];
        $driver = $state->driverName !== '' ? [['(' . $state->driverName . ') ', 'header_conn']] : [];
        $sec = $state->connectionLabel === ''
            ? []   // nothing is open; "no TLS" would be a claim about no session
            : [$state->secure ? ['TLS ', 'header_tls'] : ['no TLS ', 'header_plain']];
        $hostSeg = [[$conn, 'header_conn']];

        // Dropped in order of what a narrow terminal can least afford to lose.
        // The driver goes first: it never changes during a session. The security
        // marker goes next, then an untyped env. `[RO]` and the host never go — one changes what the
        // next statement will do, the other says which server it will do it to.
        $candidates = [
            array_merge($driver, $env, $ro, $sec, $hostSeg),
            array_merge($env, $ro, $sec, $hostSeg),
            array_merge($env, $ro, $hostSeg),
            array_merge($ro, $hostSeg),
        ];
        foreach ($candidates as $segments) {
            $width = 0;
            foreach ($segments as [$text]) {
                $width += mb_strlen($text);
            }
            $connX = $w - $width - 1;
            if ($connX > $leftEnd + 1) {
                $cx = $connX;
                foreach ($segments as [$text, $token]) {
                    $screen->put($cx, 0, $text, $this->theme->style($token));
                    $cx += mb_strlen($text);
                }
                break;
            }
        }

        // Active database + table, centered on the header so it's easy to spot
        // what the grid is showing: `db.table` when both are known, else whichever
        // is available. Only drawn when it fits clear of the left tag and the right
        // connection label (and it's hidden under a flash, below).
        // A flash is only "up" while it hasn't timed out — the string itself is
        // never cleared, so gate on the deadline (not on flashMessage !== '')
        // or the db.table label would stay hidden forever after the first flash.
        //
        // The connection marker. This was three sentences — SERVER DISCONNECTED
        // — the next statement reconnects / RECONNECTING — RESENDING YOUR
        // STATEMENT … / RECONNECTING … — each in two spellings, and it is now
        // five characters, for one reason: a loss sqli can handle by itself is
        // no longer narrated anywhere. There is nothing left for the row to
        // explain, so what is left is the fact, and the fact is small enough to
        // sit beside the db.table tile instead of evicting it.
        //
        // One marker for every unhealthy state, red for all of them. The states
        // differ in what sqli is doing, not in what the user should do, and the
        // two that *do* want something from the user — a write that may have
        // landed, a server that has stopped answering — say so in full on the
        // status line, where a sentence can be read (§2).
        //
        // Drawn past the moment the link comes back (`linkLingerUntil`), because
        // the whole of a handled loss can pass inside half a second.
        $linkUp = $state->linkState === State::LINK_OK
            && microtime(true) >= $state->linkLingerUntil;
        $link = $linkUp ? null : ' !db ';

        $flashActive = $state->flashMessage !== '' && microtime(true) < $state->flashUntil;
        $where = self::headerWhere($state);
        $whereFull = $where['full'];
        $whereShort = $where['short'];
        // The gap left between the app tag and the connection label. Everything
        // below fits the tile into *this*, rather than centering on the whole
        // row and giving up when the result overlaps something.
        $gapStart = $leftEnd + 1;
        $gapEnd = $connX - 1;
        $avail = $gapEnd - $gapStart;
        // The marker is parked against the connection label — a fact about the
        // connection, sitting where the connection is described — and its room
        // is taken out of the gap *before* the tile and the flash measure what
        // is left. That is the whole reason it stopped being a sentence: at five
        // characters it can be reserved instead of taking the row, so a
        // half-second blip no longer costs the user the db.table tile for as
        // long as the marker lingers. Position now, drawn last.
        $linkX = -1;
        if ($link !== null && mb_strlen($link) <= $avail) {
            $linkX = $gapEnd - mb_strlen($link);
            $gapEnd = $linkX - 1;
            $avail = $gapEnd - $gapStart;
        } elseif ($link !== null) {
            // Too narrow to reserve: it takes what it needs at the right-hand
            // end of the row. A tile is recoverable by widening the terminal; a
            // disconnect nobody was shown is not.
            $linkX = max(0, $w - mb_strlen($link));
        }
        // The user's pinned note (§7), drawn against the tag it qualifies —
        // ` sqli v2.35.13  CLI [PROD] ` — and in its own colour, because the
        // question it answers ("which window am I typing into?") is not the one
        // the tag or the connection label answers.
        //
        // It takes its room out of the gap exactly as the link marker just did,
        // from the left rather than the right, so the tile and the flash below
        // measure what is actually left instead of being painted over.
        //
        // Capped at half the gap as well as at HEADER_COMMENT_MAX: the text is
        // the user's own words and can be any length, and a long one must not be
        // able to evict the tile that says what the grid is showing. Within that
        // budget it is truncated rather than dropped, so a note that is up stays
        // up as the terminal narrows — `[PROD]` is exactly the kind of thing that
        // has to still be there when it matters. Below HEADER_COMMENT_MIN the
        // gap cannot hold even a couple of characters of it, and an ellipsis on
        // its own says nothing, so it goes.
        if ($state->comment !== '' && $avail >= self::HEADER_COMMENT_MIN) {
            // 3 = the two brackets plus one column of air before the tile.
            $room = min(self::HEADER_COMMENT_MAX, intdiv($avail, 2)) - 3;
            $note = '[' . $this->truncate($state->comment, $room) . ']';
            $screen->put($gapStart, 0, $note, $this->theme->style('header_comment'));
            $gapStart += mb_strlen($note) + 1;
            $avail = $gapEnd - $gapStart;
        }

        if ($whereFull !== '' && !$flashActive && $avail >= self::HEADER_DB_MIN) {
            // Was: centered, and simply not drawn when the centered position
            // collided with the connection label. That made the tile vanish on
            // running a query — `devel` fit, `devel.sa_learning` did not — which
            // read as a bug precisely because the tile is how you know what the
            // grid is showing. It now degrades instead of disappearing.
            $label = $whereFull;
            $span = $where['fullSpan'];
            if (mb_strlen($label) + 2 > $avail && $whereShort !== '') {
                // Drop the database before the table: the connection label on the
                // right already ends in `/db`, so it is the redundant half, while
                // the table is the part that just changed.
                $label = $whereShort;
                $span = $where['shortSpan'];
            }
            $label = ' ' . $this->truncate($label, $avail - 2) . ' ';
            // Centered when the gap allows, otherwise slid along it — never
            // outside it.
            $dbX = intdiv($w - mb_strlen($label), 2);
            $dbX = max($gapStart, min($dbX, $gapEnd - mb_strlen($label)));
            $screen->put($dbX, 0, $label, $this->theme->style('header_db'));
            // The selected column's table, repainted over the chip in its own
            // colour (§3). Offsets are into the label before the leading space,
            // hence the +1; a truncated label can cut the entry short or lose it
            // entirely, and a highlight that would start past the end is simply
            // not drawn — half a highlighted word says less than none.
            if ($span !== null) {
                $room = mb_strlen($label) - 1 - ($span[0] + 1);
                $len = min($span[1], $room);
                if ($len > 0) {
                    $screen->put(
                        $dbX + $span[0] + 1,
                        0,
                        mb_substr($label, $span[0] + 1, $len),
                        $this->theme->style('header_db_active'),
                    );
                }
            }
        }

        // Transient clipboard/notice flash, centered on the top line so it's
        // unmissable; auto-expires (the ever-running loop repaints it away). §19.
        //
        // Clamped into the gap on the tile's terms, and for the tile's reason. It
        // used to centre on the whole row, which was safe only because a link
        // notice suppressed it outright — the marker no longer does that (a copy
        // during the three-second linger has to be visible), so the flash has to
        // keep to its own columns instead. Centred on the row where it cannot
        // fit the gap at all, which is where it was before.
        if ($flashActive) {
            $msg = ' ' . $state->flashMessage . ' ';
            $len = mb_strlen($msg);
            $fx = intdiv($w - $len, 2);
            $fx = $len <= $avail
                ? max($gapStart, min($fx, $gapEnd - $len))
                : max(0, $fx);
            $screen->put($fx, 0, $msg, $this->theme->style('header_flash'));
        }

        // Last, so it is painted over anything that reached its column — the
        // flash centres on the whole row and can still land here on a narrow
        // terminal, and of the two this is the one that must survive.
        if ($link !== null) {
            $screen->put($linkX, 0, $link, $this->theme->style('header_lost'));
        }
    }

    /**
     * Draw the result box frame and the [L] layout indicator; return the inner
     * content rect [x, y, w, h] for whatever view fills it.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function drawResultBox(Screen $screen, State $state, int $w, int $top, int $bottom): array
    {
        $b = self::BORDER[$state->focus === State::FOCUS_DATA ? 'data' : 'cli'];
        $borderStyle = $state->focus === State::FOCUS_DATA
            ? $this->theme->style('border_focused')
            : $this->theme->style('border_unfocused');

        // Top & bottom edges.
        $screen->put(0, $top, $b['tl'] . str_repeat($b['h'], max(0, $w - 2)) . $b['tr'], $borderStyle);
        $screen->put(0, $bottom, $b['bl'] . str_repeat($b['h'], max(0, $w - 2)) . $b['br'], $borderStyle);
        // Side edges.
        for ($y = $top + 1; $y < $bottom; $y++) {
            $screen->put(0, $y, $b['v'], $borderStyle);
            $screen->put($w - 1, $y, $b['v'], $borderStyle);
        }

        // The Layout Config indicator is drawn separately as a vertical label
        // painted over the left border (see drawLayoutLabel), so the box frame
        // itself no longer carries it.
        return [1, $top + 1, $w - 2, $bottom - 1 - ($top + 1) + 1];
    }

    /**
     * Paint the data view tab strip onto the result box's top border (§20).
     *
     * A tab shows `N` while its view has never held a result and `N:table` once
     * it has, so the strip doubles as a map of what is parked where. Labels are
     * dropped for bare digits when the informative form would not fit, and the
     * strip is skipped entirely when even that would not — a narrow terminal
     * keeps its border rather than getting a truncated mess.
     */
    private function drawTabs(Screen $screen, State $state, int $w, int $y): void
    {
        $avail = $w - 4;   // clear of both corners and one border glyph each side
        $labels = $state->tabLabels;

        $cells = [];
        for ($i = 0; $i < State::TAB_COUNT; $i++) {
            $name = $labels[$i] ?? '';
            $cells[] = $name === ''
                ? ' ' . ($i + 1) . ' '
                : ' ' . ($i + 1) . ':' . $this->truncate($name, self::TAB_LABEL_MAX) . ' ';
        }
        if ($this->stripWidth($cells) > $avail) {
            $cells = [];
            for ($i = 0; $i < State::TAB_COUNT; $i++) {
                $cells[] = ' ' . ($i + 1) . ' ';
            }
        }
        if ($this->stripWidth($cells) > $avail) {
            return;
        }

        $x = 2;
        foreach ($cells as $i => $cell) {
            if ($i === $state->activeTab) {
                $style = $this->theme->style('tab_active');
            } elseif (($labels[$i] ?? '') === '') {
                $style = $this->theme->style('tab_empty');
            } else {
                $style = $this->theme->style('tab_used');
            }
            $screen->put($x, $y, $cell, $style);
            $x += mb_strlen($cell);
        }
    }

    /** @param list<string> $cells */
    private function stripWidth(array $cells): int
    {
        $n = 0;
        foreach ($cells as $c) {
            $n += mb_strlen($c);
        }
        return $n;
    }

    private function drawResultContent(Screen $screen, State $state, int $x, int $y, int $w, int $h): void
    {
        $result = $state->result;
        if ($result === null) {
            $screen->put($x + 1, $y, 'Type a SQL query below and press Enter.', $this->theme->style('status'));
            return;
        }
        if ($result->hasError()) {
            $this->drawError($screen, $state, $result, $x + 1, $y, $w - 2);
            return;
        }
        if ($result->isEmpty()) {
            $msg = $result->isSelect
                ? 'Query returned no rows.'
                : ($result->affectedRows . ' row(s) affected.');
            $screen->put($x + 1, $y, $msg, $this->theme->style('status'));
            return;
        }
        if ($state->vertical) {
            $this->drawVertical($screen, $state, $result, $x, $y, $w, $h);
            return;
        }
        if ($state->detail) {
            $this->drawDetail($screen, $state, $result, $x, $y, $w, $h);
            return;
        }
        $this->drawGrid($screen, $state, $result, $x, $y, $w, $h);
    }

    private function drawError(Screen $screen, State $state, QueryResult $result, int $x, int $y, int $w): void
    {
        $screen->put($x, $y, $this->truncate('input: ' . $state->lastSql, $w), $this->theme->style('status'));
        $screen->put($x, $y + 1, $this->truncate('ERROR: ' . (string) $result->error, $w), $this->theme->style('status_error'));

        // Both lines above are truncated to the box, so a long driver message is
        // not merely awkward to copy — the rest of it is not on screen at all.
        // Publish the rect so a click can put the *whole* thing on the clipboard,
        // and say so, because an invisible gesture helps nobody.
        $hint = '[click to copy]';
        $screen->put($x, $y + 2, $this->truncate($hint, $w), $this->theme->style('status'));
        $state->errorRect = [$x, $y, $w, 3];
    }

    private function drawGrid(Screen $screen, State $state, QueryResult $result, int $x, int $y, int $w, int $hRows): void
    {
        if ($hRows < 3) {
            return;
        }
        $colNames = $state->visibleColumnNames();
        if ($colNames === []) {
            $screen->put($x + 1, $y, 'All columns hidden — open [L] to restore.', $this->theme->style('status'));
            return;
        }
        $rows = $result->rows;
        $order = $state->displayOrder();
        $widths = $this->gridWidths($state, $colNames, $rows);

        $rowNumW = max(3, strlen((string) count($order)));
        // Mark mode (§22) puts a checkbox between the row-number gutter and the
        // data. It is drawn as extra gutter rather than a real column so column
        // indices, click mapping, layout persistence and CSV export all stay
        // exactly as they are — nothing downstream can see it.
        $markW = $state->markMode ? self::MARK_W : 0;
        $markX = $markW > 0 ? $x + $rowNumW + 1 : -1;
        $gutter = $rowNumW + 1 + $markW;
        $gridX = $x + $gutter;
        $gridW = $w - $gutter;

        // Keep the selected column visible by adjusting the horizontal offset.
        $this->ensureColVisible($state, $widths, $gridW);

        $visible = $this->visibleColumns($widths, $gridW, $state->colOffset);

        // Header row, with a sort arrow on the sorted column.
        $headerY = $y;
        foreach ($visible as [$colIdx, $colW, $colX]) {
            $name = $colNames[$colIdx];
            $label = $name;
            if ($state->sortCol === $name) {
                $label .= $state->sortDir === 'desc' ? ' ↓' : ' ↑';
            }
            $sel = $state->selectedRow === -1 && $colIdx === $state->selectedCol;
            if ($state->moveMode && $colIdx === $state->selectedCol) {
                $style = $this->theme->style('col_moving');
            } elseif ($sel) {
                $style = $this->theme->style('header_selected');
            } elseif ($state->formatOf($name) !== null) {
                // A formatted column's header is tinted so it's obvious the shown
                // value differs from what's stored (§6, backlog S).
                $style = $this->theme->style('col_formatted');
            } else {
                $style = $this->theme->style('grid_header');
            }
            $screen->put($gridX + $colX, $headerY, $this->pad($label, $colW), $style);
        }

        if ($markW > 0) {
            // A header for the checkbox gutter, so the column reads as one.
            $screen->put($markX, $headerY, $this->pad(' ✓ ', $markW), $this->theme->style('grid_header'));
        }

        // Separator rule under the header.
        $ruleY = $y + 1;
        $screen->fill($x, $ruleY, $w, '─', $this->theme->style('grid_rule'));

        // Data rows (iterated in display order so client-side sort is honored).
        $dataTop = $y + 2;
        $visibleDataRows = $hRows - 2;
        $this->ensureRowVisible($state, $visibleDataRows);

        $drawnRows = 0;
        for ($i = 0; $i < $visibleDataRows; $i++) {
            $dispIdx = $state->rowOffset + $i;
            if ($dispIdx >= count($order)) {
                break;
            }
            $rawIdx = $order[$dispIdx];
            $screenY = $dataTop + $i;
            $isSelRow = $dispIdx === $state->selectedRow;

            $isMarked = isset($state->deleteMarks[$rawIdx]);

            // Row number gutter (display position).
            $screen->put($x, $screenY, str_pad((string) ($dispIdx + 1), $rowNumW, ' ', STR_PAD_LEFT), $this->theme->style('row_num'));

            if ($markW > 0) {
                $screen->put(
                    $markX,
                    $screenY,
                    $this->pad($isMarked ? '[x]' : '[ ]', $markW),
                    $this->theme->style($isMarked ? 'mark_box' : 'mark_box_off'),
                );
            }

            // A marked row outranks the row-selection tint: the selected row moves
            // with every arrow key, while a mark is a decision about a record that
            // is about to be deleted, and it has to stay legible from across the
            // grid. The selected *cell* still wins over both (below), so the cursor
            // is never lost inside a marked row.
            if ($isMarked) {
                $rowBase = $this->theme->style('row_marked');
            } else {
                $rowBase = $isSelRow ? $this->theme->style('selected_row') : ($dispIdx % 2 ? $this->theme->style('row_odd') : $this->theme->style('row_even'));
            }

            foreach ($visible as [$colIdx, $colW, $colX]) {
                $name = $colNames[$colIdx];
                // array_key_exists: a staged NULL (Ctrl+N) is a pending null.
                $isPending = array_key_exists($name, $state->pending[$rawIdx] ?? []);
                $raw = $isPending ? $state->pending[$rawIdx][$name] : ($rows[$rawIdx][$name] ?? null);
                $isNull = $raw === null;
                if ($isNull) {
                    $disp = 'NULL';
                } else {
                    $disp = (string) $raw;
                    $fmt = ColumnFormats::get($state->formatOf($name));
                    if ($fmt !== null && $disp !== '') {
                        $disp = $fmt->display($disp); // raw → shown value (§6)
                    }
                }
                $text = $this->pad($disp, $colW);

                $isSelCell = $isSelRow && $colIdx === $state->selectedCol && $state->focus === State::FOCUS_DATA;
                if ($state->moveMode && $colIdx === $state->selectedCol) {
                    // Whole moving column stands out so the relocation is obvious.
                    $style = $this->theme->style('col_moving');
                } elseif ($isSelCell) {
                    $style = $this->theme->style('selected_cell');
                } elseif ($isMarked) {
                    // Whole row reads as one doomed record — a pending edit or a
                    // NULL inside it is not the point any more.
                    $style = $rowBase;
                } elseif ($isPending) {
                    $style = $this->theme->style('cell_pending');
                } elseif ($isNull) {
                    $style = $isSelRow ? $rowBase : $this->theme->style('cell_null');
                } else {
                    $style = $rowBase;
                }
                $screen->put($gridX + $colX, $screenY, $text, $style);
            }
            $drawnRows++;
        }

        // Publish geometry for click mapping (§6).
        $layoutCols = [];
        foreach ($visible as [$colIdx, $colW, $colX]) {
            $layoutCols[] = ['idx' => $colIdx, 'x' => $gridX + $colX, 'w' => $colW];
        }
        $state->gridLayout = new GridLayout(
            headerY: $headerY,
            dataTop: $dataTop,
            dataRows: $drawnRows,
            rowOffset: $state->rowOffset,
            columns: $layoutCols,
            markX: $markX,
            markW: $markW,
        );
    }

    private function drawDetail(Screen $screen, State $state, QueryResult $result, int $x, int $y, int $w, int $h): void
    {
        $order = $state->displayOrder();
        $rawIdx = $order[$state->selectedRow] ?? ($order[0] ?? 0);
        $row = $result->rows[$rawIdx] ?? [];
        $cols = $state->detailColumns();
        $total = count($cols);

        $nameW = 1;
        foreach ($cols as $c) {
            $nameW = max($nameW, mb_strlen($c));
        }
        $nameW = min($nameW, max(8, intdiv($w, 3)));

        // The field order is named up top, with the key that flips it.
        $title = sprintf(
            'Row %d of %d  ·  [S] %s  ·  Esc/Enter to close',
            $state->selectedRow + 1,
            count($order),
            $state->detailSorted ? 'Fields A–Z' : 'Result order',
        );
        $screen->put($x + 1, $y, $this->truncate($title, $w - 2), $this->theme->style('detail_title'));

        $listTop = $y + 1;
        $visibleFields = max(1, $h - 1);
        $state->detailVisibleFields = $visibleFields;
        $offset = 0;
        if ($state->detailFieldIdx >= $visibleFields) {
            $offset = $state->detailFieldIdx - $visibleFields + 1;
        }

        for ($i = 0; $i < $visibleFields; $i++) {
            $fi = $offset + $i;
            if ($fi >= $total) {
                break;
            }
            $name = $cols[$fi];
            $raw = $row[$name] ?? null;
            $fmt = ColumnFormats::get($state->formatOf($name));
            $val = $raw === null ? 'NULL' : (string) $raw;
            if ($fmt !== null && $raw !== null && $val !== '') {
                $val = $fmt->display($val); // raw → shown value (§6)
            }
            $isSel = $fi === $state->detailFieldIdx;
            $isJson = $raw !== null && $fmt === null && $this->looksJson($val);

            if ($isSel) {
                $nameStyle = $this->theme->style('detail_field_sel');
            } elseif ($fmt !== null) {
                $nameStyle = $this->theme->style('col_formatted'); // signal the format
            } else {
                $nameStyle = $this->theme->style('detail_field');
            }
            if ($raw === null) {
                $valStyle = $this->theme->style('cell_null');
            } elseif ($isJson) {
                $valStyle = $this->theme->style('detail_json');
            } else {
                $valStyle = $isSel ? $this->theme->style('detail_value_sel') : $this->theme->style('detail_value');
            }

            $screen->put($x + 1, $listTop + $i, $this->pad($name, $nameW), $nameStyle);
            $valX = $x + 1 + $nameW + 1;
            $valW = $w - 2 - $nameW - 1;
            $screen->put($valX, $listTop + $i, $this->truncate($val, $valW), $valStyle);
        }
    }

    /**
     * Vertical (\G) output: one field per line, name right-aligned, with a
     * `*** N. row ***` separator between rows — mirrors the mysql CLI (§5).
     * Line-scrolled via State->verticalScroll, which is clamped here.
     */
    private function drawVertical(Screen $screen, State $state, QueryResult $result, int $x, int $y, int $w, int $h): void
    {
        $cols = $result->columns;
        $order = $state->displayOrder();

        $nameW = 1;
        foreach ($cols as $c) {
            $nameW = max($nameW, mb_strlen($c));
        }
        $nameW = min($nameW, max(8, intdiv($w, 3)));

        // Flatten rows into renderable lines.
        $lines = [];
        foreach ($order as $pos => $rawIdx) {
            $star = str_repeat('*', max(3, intdiv($w - 12, 2)));
            $lines[] = ['sep' => sprintf('%s %d. row %s', $star, $pos + 1, $star)];
            $row = $result->rows[$rawIdx] ?? [];
            foreach ($cols as $name) {
                $raw = $row[$name] ?? null;
                $lines[] = [
                    'name'  => str_pad($name, $nameW, ' ', STR_PAD_LEFT),
                    'value' => $raw === null ? 'NULL' : (string) $raw,
                    'null'  => $raw === null,
                ];
            }
        }

        $maxScroll = max(0, count($lines) - $h);
        $state->verticalScroll = max(0, min($state->verticalScroll, $maxScroll));

        for ($i = 0; $i < $h; $i++) {
            $li = $state->verticalScroll + $i;
            if ($li >= count($lines)) {
                break;
            }
            $line = $lines[$li];
            $rowY = $y + $i;
            if (isset($line['sep'])) {
                $screen->put($x, $rowY, $this->truncate($line['sep'], $w), $this->theme->style('vertical_sep'));
                continue;
            }
            $label = $line['name'] . ': ';
            $screen->put($x, $rowY, $this->truncate($label, $w), $this->theme->style('detail_field'));
            $valX = $x + mb_strlen($label);
            $valW = $w - mb_strlen($label);
            $valStyle = $line['null'] ? $this->theme->style('cell_null') : $this->theme->style('detail_value');
            if ($valW > 0) {
                $screen->put($valX, $rowY, $this->truncate($line['value'], $valW), $valStyle);
            }
        }
    }

    private function drawInput(Screen $screen, State $state, LineEditor $input, int $w, int $ruleY, int $top, int $bottom, int $maxInput): void
    {
        $cliFocus = $state->focus === State::FOCUS_CLI;
        $ruleStyle = $cliFocus ? $this->theme->style('input_border_on') : $this->theme->style('input_border_off');
        $screen->fill(0, $ruleY, $w, '─', $ruleStyle);

        $lines = $input->lines();
        $total = count($lines);
        // The single flat editor exposes the active line + column as a [row, col]
        // pair; the CLI draw is line-oriented, so work in those terms (§4).
        [$activeIdx, $cursor] = $input->cursorRowCol();
        $promptStyle = $this->theme->style('input_border_on');
        // selectionRange() is flat grapheme offsets; project each end to [row, col]
        // so the per-line highlight below can address it like before.
        $range = $input->selectionRange();
        $sel = $range === null ? null : [$input->rowColAt($range[0]), $input->rowColAt($range[1])];
        $selStyle = $this->theme->style('input_selection');

        // When more lines exist than fit, scroll the window to keep the active
        // line visible (default to showing the tail).
        $start = 0;
        if ($total > $maxInput) {
            $start = $total - $maxInput;
            if ($activeIdx < $start) {
                $start = $activeIdx;
            }
        }

        // Publish the input-region geometry so mouse clicks/drags can be mapped
        // back to a buffer [line, column] (§4). textX is the prompt width (5).
        $state->cliTop = $top;
        $state->cliBottom = min($bottom, $top + max(0, $total - $start) - 1);
        $state->cliTextX = 5;
        $state->cliLineStart = $start;
        $state->cliActiveScroll = 0;

        for ($row = $top; $row <= $bottom; $row++) {
            $li = $start + ($row - $top);
            if ($li >= $total) {
                break;
            }
            $isActiveLine = $li === $activeIdx;
            $prompt = $li === 0 ? 'sql> ' : '...> ';
            $screen->put(0, $row, $prompt, $promptStyle);

            $textX = mb_strlen($prompt);
            $avail = $w - $textX;
            $text = $lines[$li];

            // Horizontal scroll only tracks the cursor on the active line.
            $scroll = 0;
            if ($isActiveLine && $cursor > $avail - 1) {
                $scroll = $cursor - ($avail - 1);
            }
            if ($isActiveLine) {
                $state->cliActiveScroll = $scroll;
            }
            $visibleText = mb_substr($text, $scroll, $avail);
            $style = $isActiveLine ? $this->theme->style('input_text') : $this->theme->style('input_text_inactive');
            $screen->put($textX, $row, $visibleText, $style);

            // Repaint the selected span (if any of it falls on this line) with
            // the highlight style, over the base text and under the cursor (§4).
            if ($sel !== null && $li >= $sel[0][0] && $li <= $sel[1][0]) {
                $lineLen = mb_strlen($text);
                $selFrom = $li === $sel[0][0] ? $sel[0][1] : 0;
                $selTo = $li === $sel[1][0] ? $sel[1][1] : $lineLen;
                $visFrom = max($selFrom, $scroll);
                $visTo = min($selTo, $scroll + mb_strlen($visibleText));
                if ($visTo > $visFrom) {
                    $seg = mb_substr($text, $visFrom, $visTo - $visFrom);
                    $screen->put($textX + ($visFrom - $scroll), $row, $seg, $selStyle);
                }
            }

            // Paint the cursor cell (the real cursor is hidden) on the active
            // line when CLI-focused, and publish its position for the
            // completion popup to anchor against (§4).
            if ($cliFocus && $isActiveLine) {
                $curCol = $textX + ($cursor - $scroll);
                $state->inputCursorX = $curCol;
                $state->inputCursorY = $row;
                $under = mb_substr($text, $cursor, 1);
                $screen->put($curCol, $row, $under === '' ? ' ' : $under, $this->theme->style('cursor'));
            }
        }
    }

    /**
     * Repaint the selected span of one display line ($li) of a LineEditor-backed
     * modal field with the selection style, over the base text and under the
     * cursor — the same highlight the CLI draws (§4). A no-op when nothing is
     * selected or the selection doesn't touch this line. $textX is the on-screen
     * column where the line's text begins, $hScroll its horizontal scroll, $availW
     * the visible width.
     */
    private function drawFieldSelection(
        Screen $screen,
        LineEditor $editor,
        int $li,
        int $textX,
        int $y,
        int $hScroll,
        int $availW,
        Style $selStyle,
    ): void {
        $range = $editor->selectionRange();
        if ($range === null) {
            return;
        }
        [$sRow, $sCol] = $editor->rowColAt($range[0]);
        [$eRow, $eCol] = $editor->rowColAt($range[1]);
        if ($li < $sRow || $li > $eRow) {
            return;
        }
        $text = $editor->lines()[$li] ?? '';
        $selFrom = $li === $sRow ? $sCol : 0;
        $selTo = $li === $eRow ? $eCol : mb_strlen($text);
        $visFrom = max($selFrom, $hScroll);
        $visTo = min($selTo, $hScroll + $availW);
        if ($visTo > $visFrom) {
            $seg = mb_substr($text, $visFrom, $visTo - $visFrom);
            $screen->put($textX + ($visFrom - $hScroll), $y, $seg, $selStyle);
        }
    }

    /**
     * DATA-focus help topics for the status hint line, **most useful first** —
     * the tail is what gets dropped on a narrow terminal (§3). These are plain
     * words, not bindings: the keys themselves live in F1 help, which is what
     * the hint line now points at.
     *
     * @var list<string>
     */
    private const DATA_TOPICS = [
        'detail', 'expand cell', 'json', 'copy cell/row', 'csv', 'configure', 'menu',
    ];

    /**
     * CLI-focus counterpart of {@see DATA_TOPICS}.
     *
     * @var list<string>
     */
    private const CLI_TOPICS = [
        'history', 'complete', 'select/copy', 'dd hint', 'clear', 'menu', 'exit',
    ];

    /**
     * Fit a hint line into `$avail` columns: the always-live keys verbatim, then
     * as many topics as remain, in order, with a trailing `…` when any were
     * dropped so the list never looks complete when it isn't.
     *
     * Pure and static so the fitting can be tested without a terminal — the
     * behaviour that matters is at the boundaries (exact fit, one topic left,
     * no room for even the keys), which is exactly what is invisible in a
     * screenshot.
     *
     * @param string       $keys     Bindings that must always be shown, pre-formatted.
     * @param list<string> $topics   Topic words, most useful first.
     * @param string       $fallback Shown when `$keys` itself won't fit — keep it to
     *                               the one binding that still leads somewhere (F1),
     *                               so a narrow terminal never strands the user with
     *                               no way in to the shortcut list.
     */
    public static function fitHints(string $keys, array $topics, int $avail, string $fallback = ''): string
    {
        for ($n = count($topics); $n > 0; $n--) {
            $more = $n < count($topics) ? '…' : '';
            $line = $keys . ' (' . implode(', ', array_slice($topics, 0, $n)) . $more . ')';
            if (mb_strlen($line) <= $avail) {
                return $line;
            }
        }
        // Not even one topic fits, so shed the bindings too, widest first. Below
        // the fallback the status message gets the whole row.
        foreach ([$keys, $fallback] as $line) {
            if ($line !== '' && mb_strlen($line) <= $avail) {
                return $line;
            }
        }
        return '';
    }

    /**
     * What a finished result reports about itself: how many rows came back, how
     * wide they are, how long it took — and the row cap, when it bit (§5).
     *
     * Public and static because App needs the identical string: a rewrite note
     * owns the status line, so App prefixes the note to *this* rather than
     * displacing it. Two spellings of the same line is how the row count went
     * missing in the first place.
     */
    public static function resultStats(QueryResult $r, int $autoLimitedAt = 0): string
    {
        $stats = sprintf('%d row(s), %d col(s), %.3fs', $r->rowCount(), $r->columnCount(), $r->elapsed);

        // Only ever a qualifier on the count, never a replacement for it: this is
        // the one case where the count is a floor rather than a total.
        return $autoLimitedAt > 0
            ? $stats . ' · auto-limited to ' . $autoLimitedAt
            : $stats;
    }

    private function drawStatus(Screen $screen, State $state, int $w, int $y): void
    {
        $left = $state->statusLine;
        // An open cell editor owns the status line to advertise its keys (§9).
        if ($state->cellEdit) {
            $left = 'Editing cell · [Ctrl+S] save · [Enter] new line · [Esc] cancel';
        } elseif ($state->moveMode) {
            $left = 'Move column · [←/→] move · [Home/End] first/last · [Enter] done · [Esc] cancel';
        } elseif ($state->widthAdjust) {
            $left = 'Change width · [→/←] ±1 · [↑/↓] ±5 · [Enter] done';
        }
        // While a cell edit is in flight its UPDATE sits on the CLI; remind the
        // user how to resolve it (§9) — takes precedence over the row/col stats.
        if ($left === '' && $state->hasPending()) {
            $left = 'UPDATE on the CLI · [Enter] run · [Esc] cancel';
        } elseif ($left === '' && $state->result !== null && !$state->result->hasError()) {
            // Nothing else claimed the line, so the result describes itself. When
            // a rewrite note *did* claim it, App has already appended these same
            // stats to the note (§5) — which is why this branch does not have to
            // know about notes at all.
            $left = self::resultStats($state->result, $state->autoLimitedAt);
        }

        $style = $state->statusIsError ? $this->theme->style('status_error') : $this->theme->style('status');

        // A column mode's key hint owns the whole status row — drop the generic
        // right-side hints so the mode hint isn't truncated (§6).
        $hints = '';
        if (!$state->moveMode && !$state->widthAdjust) {
            // The status message has first claim on the row; the hints take what
            // is left and shed topics to fit (§3). Spelling out every binding is
            // what overflowed the line in the first place.
            $avail = $w - mb_strlen($left) - 2;
            $hints = $state->focus === State::FOCUS_DATA
                ? self::fitHints('[Ctrl+D] CLI  [F1] Help', self::DATA_TOPICS, $avail, '[F1] Help')
                : self::fitHints('[Ctrl+D] Data  [Enter] Submit  [F1] Help', self::CLI_TOPICS, $avail, '[F1] Help');
        }

        $screen->put(0, $y, $this->truncate($left, $w - mb_strlen($hints) - 2), $style);
        if ($hints !== '') {
            $screen->put($w - mb_strlen($hints) - 1, $y, $hints, $this->theme->style('status'));
        }
    }

    /**
     * Draw vertical (rows) and horizontal (columns) scroll indicators on the
     * result box edges (§6). Proportions are measured in **row count** and
     * **column count** — not pixel widths — so a wide column doesn't distort the
     * "how much is left" sense. Only shown in the grid view when content
     * overflows the visible window. Overlays the box border glyphs.
     */
    private function drawScrollbars(Screen $screen, State $state, int $w, int $boxTop, int $boxBottom): void
    {
        if (!$state->hasResult() || $state->detail || $state->vertical) {
            return;
        }
        $gl = $state->gridLayout;
        if ($gl === null) {
            return;
        }
        $track = $this->theme->style('scrollbar');
        $thumbStyle = $this->theme->style('scrollbar_thumb');

        // Vertical: rows, on the right border between the top/bottom corners.
        $totalRows = count($state->displayOrder());
        $visRows = $gl->dataRows;
        $vTrackTop = $boxTop + 1;
        $vTrackLen = ($boxBottom - 1) - $vTrackTop + 1;
        if ($totalRows > $visRows && $visRows > 0 && $vTrackLen >= 2) {
            [$thumb, $pos] = $this->scrollThumb($vTrackLen, $visRows, $totalRows, $state->rowOffset);
            for ($i = 0; $i < $vTrackLen; $i++) {
                $on = $i >= $pos && $i < $pos + $thumb;
                $screen->put($w - 1, $vTrackTop + $i, $on ? '█' : '░', $on ? $thumbStyle : $track);
            }
            $state->vScrollX = $w - 1;
            $state->vScrollTop = $vTrackTop;
            $state->vScrollLen = $vTrackLen;
            $state->vScrollTotal = $totalRows;
            $state->vScrollVisible = $visRows;
        }

        // Horizontal: columns, on the bottom border between the corners.
        $totalCols = $state->totalCols();
        $visCols = count($gl->columns);
        $hTrackLeft = 1;
        $hTrackLen = $w - 2;
        if ($totalCols > $visCols && $visCols > 0 && $hTrackLen >= 2) {
            [$thumb, $pos] = $this->scrollThumb($hTrackLen, $visCols, $totalCols, $state->colOffset);
            for ($i = 0; $i < $hTrackLen; $i++) {
                $on = $i >= $pos && $i < $pos + $thumb;
                $screen->put($hTrackLeft + $i, $boxBottom, $on ? '█' : '░', $on ? $thumbStyle : $track);
            }
            $state->hScrollY = $boxBottom;
            $state->hScrollLeft = $hTrackLeft;
            $state->hScrollLen = $hTrackLen;
            $state->hScrollTotal = $totalCols;
            $state->hScrollVisible = $visCols;
        }
    }

    /**
     * Compute a scrollbar thumb [size, position] for a track of $trackLen cells
     * showing $visible of $total items, scrolled to $offset.
     *
     * @return array{0:int,1:int} [thumbSize, thumbStart]
     */
    private function scrollThumb(int $trackLen, int $visible, int $total, int $offset): array
    {
        $thumb = max(1, min($trackLen, (int) floor($trackLen * $visible / $total)));
        $maxOff = $total - $visible;
        $pos = $maxOff > 0 ? (int) round(($trackLen - $thumb) * $offset / $maxOff) : 0;
        $pos = max(0, min($pos, $trackLen - $thumb));
        return [$thumb, $pos];
    }

    /**
     * Vertical "Layout Config" label painted over the left border of the data
     * view (§6). It deliberately shows no per-customization detail — Data View
     * Configuration (click here, Ctrl+L, or the system menu) is where that lives.
     * Publishes its cell span for click mapping.
     */
    private function drawLayoutLabel(Screen $screen, State $state, int $x, int $y, int $h): void
    {
        $chars = mb_str_split('Layout Config');
        $n = min(count($chars), max(0, $h));
        if ($n <= 0) {
            return;
        }
        $style = $this->theme->style('layout_indicator');
        for ($i = 0; $i < $n; $i++) {
            $screen->put($x, $y + $i, $chars[$i], $style);
        }
        $state->layoutIndicatorX = $x;
        $state->layoutIndicatorY = $y;
        $state->layoutIndicatorW = 1;
        $state->layoutIndicatorH = $n;
    }

    // --- column geometry helpers -------------------------------------------

    /**
     * Width per visible column: an explicit override when set, otherwise the
     * natural width sampled from content (capped). The sorted column reserves
     * two extra cells for its arrow.
     *
     * @param list<string>              $colNames
     * @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    private function gridWidths(State $state, array $colNames, array $rows): array
    {
        $widths = [];
        $sample = array_slice($rows, 0, self::WIDTH_SAMPLE);
        foreach ($colNames as $i => $name) {
            if (isset($state->colWidthOverride[$name])) {
                $widths[$i] = max(1, (int) $state->colWidthOverride[$name]);
                continue;
            }
            $max = mb_strlen($name);
            $fmt = ColumnFormats::get($state->formatOf($name));
            foreach ($sample as $row) {
                $val = $row[$name] ?? null;
                if ($val === null) {
                    $len = 4;
                } else {
                    $s = (string) $val;
                    // Measure the displayed width (a formatted value, e.g. epoch →
                    // datetime, is wider than the raw int) so it isn't clipped (§6).
                    $len = mb_strlen($fmt !== null && $s !== '' ? $fmt->display($s) : $s);
                }
                if ($len > $max) {
                    $max = $len;
                }
            }
            if ($state->sortCol === $name) {
                $max += 2;
            }
            $widths[$i] = max(self::MIN_COL_WIDTH, min(self::MAX_COL_WIDTH, $max));
        }
        return $widths;
    }

    /**
     * @param list<int> $widths
     * @return list<array{0:int,1:int,2:int}> [colIdx, width, xOffsetWithinGrid]
     */
    private function visibleColumns(array $widths, int $gridW, int $offset): array
    {
        $visible = [];
        $x = 0;
        for ($i = $offset; $i < count($widths); $i++) {
            $cw = $widths[$i];
            if ($x + $cw > $gridW && $visible !== []) {
                break;
            }
            $visible[] = [$i, $cw, $x];
            $x += $cw + 1; // +1 for the column separator space
        }
        return $visible;
    }

    /** @param list<int> $widths */
    private function ensureColVisible(State $state, array $widths, int $gridW): void
    {
        if ($state->selectedCol < $state->colOffset) {
            $state->colOffset = $state->selectedCol;
            return;
        }
        // Advance the offset until the selected column fits in the window.
        while (true) {
            $visible = $this->visibleColumns($widths, $gridW, $state->colOffset);
            $lastIdx = $visible === [] ? $state->colOffset : $visible[count($visible) - 1][0];
            if ($state->selectedCol <= $lastIdx || $state->colOffset >= $state->selectedCol) {
                break;
            }
            $state->colOffset++;
        }
    }

    private function ensureRowVisible(State $state, int $visibleRows): void
    {
        if ($state->selectedRow < 0) {
            return;
        }
        if ($state->selectedRow < $state->rowOffset) {
            $state->rowOffset = $state->selectedRow;
        } elseif ($state->selectedRow >= $state->rowOffset + $visibleRows) {
            $state->rowOffset = $state->selectedRow - $visibleRows + 1;
        }
        $state->rowOffset = max(0, $state->rowOffset);
    }

    // --- popups (§6) -------------------------------------------------------

    /**
     * Data View Configuration (§6) — the one grid-configuration menu. The title
     * names the column its top block acts on, because the same popup answers
     * both "this column" and "this whole table" and the reader needs to know
     * which column "Hide column" means.
     */
    private function drawLayoutPopup(Screen $screen, State $state, int $w, int $h): void
    {
        $items = $state->layoutPopupView;
        if ($items === []) {
            return;
        }
        $name = $state->visibleColumnNames()[$state->popupCol] ?? '';
        $title = $name === ''
            ? ' Data View Configuration '
            : ' Data View Configuration — ' . $name . ' ';
        $labels = self::quickKeyLabels($items);
        $disabled = array_map(static fn (array $it): bool => !$it['enabled'], $items);
        $state->modalRect = $this->drawMenu($screen, $w, $h, $title, $labels, $state->layoutItem, $disabled);
    }

    private function drawSystemMenu(Screen $screen, State $state, int $w, int $h): void
    {
        $items = $state->systemMenuView;
        if ($items === []) {
            return;
        }
        $labels = self::quickKeyLabels($items);
        $disabled = array_map(static fn (array $it): bool => !$it['enabled'], $items);
        $state->modalRect = $this->drawMenu($screen, $w, $h, ' System ', $labels, $state->systemMenuItem, $disabled);
    }

    /**
     * Menu labels with their quick key in front — `2) Sort DESC` — from
     * {@see State::menuQuickKeys()}. Separators stay null.
     *
     * @param  list<array{label:?string,...}> $items
     * @return list<?string>
     */
    private static function quickKeyLabels(array $items): array
    {
        $keys = State::menuQuickKeys($items);
        $labels = [];
        foreach ($items as $i => $it) {
            $label = $it['label'];
            $labels[] = $label === null ? null : (isset($keys[$i]) ? $keys[$i] . ') ' : '   ') . $label;
        }
        return $labels;
    }

    /**
     * Full-screen Data Dictionary viewer (§11): a two-panel split — tables on the
     * left, the selected table's fields on the right — driven by one search box
     * that matches table names, field names and comments.
     */
    private function drawDdViewer(Screen $screen, State $state, int $w, int $h): void
    {
        $bg = $this->theme->style('popup');
        $border = $this->theme->style('popup_border');
        $titleStyle = $this->theme->style('popup_title');

        // Blank the whole screen so the base UI underneath can't show through.
        for ($y = 0; $y < $h; $y++) {
            $screen->fill(0, $y, $w, ' ', $bg);
        }
        if ($h < 8 || $w < 30) {
            $screen->put(0, 0, 'Terminal too small for Data Dictionary', $this->theme->style('status_error'));
            return;
        }

        // Title bar — shows how many tables are in view (filtered). When the file
        // is locked by another program, flip the whole bar to a warning so it's
        // impossible to miss that edits are disabled (§11).
        $tableCount = count($state->ddTables);
        if ($state->ddLocked) {
            $warn = ' ⚠  DD file is open in another program — editing disabled until it is closed ';
            $screen->put(0, 0, $this->pad($warn, $w), $this->theme->style('status_error'));
        } else {
            $title = ' Data Dictionary — ' . $tableCount . ' table(s)';
            if ($state->ddFilter !== '') {
                $title .= ' matching "' . $state->ddFilter . '"';
            }
            $screen->put(0, 0, $this->pad($title . ' ', $w), $titleStyle);
            // Right-aligned note when the live database has tables the DD doesn't
            // document (§11) — they can't appear in the list below (it is built from
            // the DD), so this is the only thing telling the user they exist. Drawn
            // only when it fits clear of the title. Suppressed while locked: the
            // warning owns the bar, and adding tables is disabled anyway.
            $newCount = count($state->ddNewTables);
            if ($newCount > 0) {
                $note = ' ⊕ ' . $newCount . ' new table(s) — Ctrl+N to list & add ';
                $noteX = $w - mb_strlen($note);
                if ($noteX > mb_strlen($title) + 2) {
                    $screen->put($noteX, 0, $note, $this->theme->style('dd_badge_new'));
                }
            }
        }

        // One search box spans the full width and drives BOTH panels: it matches
        // table names, field names and comments (§11). Always the focus for typing.
        $this->drawDdFilter($screen, 0, 1, $w, 'Search', $state->ddFilter, true, $state->ddFilterCur);

        $hints = ' Ctrl+←/→ panel · ↑/↓ navigate · Enter open · Ctrl+I detail · type to search · Esc clear/close ';
        if ($state->ddDiffReady) {
            $hints .= '· Ctrl+U sync · Δ differs ✗ not in DB ';
        }
        // Ctrl+N is advertised by the title-bar note instead: this line already runs
        // to ~133 chars with the diff legend on, so anything appended here is cut
        // off before it can be read on a normal-width terminal.
        $screen->put(0, $h - 1, $this->pad($hints, $w), $this->theme->style('status'));

        $headerRow = 2;                        // per-panel header
        $listTop = 3;                          // first list row
        $bottom = $h - 2;                      // last content row
        $leftW = max(24, min(40, intdiv($w, 3)));
        $sepX = $leftW;
        // Vertical separator between panels (spans headers + lists).
        for ($y = $headerRow; $y <= $bottom; $y++) {
            $screen->put($sepX, $y, '│', $border);
        }

        $tablesActive = $state->ddPanel === 0;

        // --- left panel: tables --------------------------------------------
        $screen->put(0, $headerRow, $this->pad(' Tables (' . $tableCount . ')', $leftW), $tablesActive ? $titleStyle : $bg);
        $state->ddTablePageRows = $bottom - $listTop + 1;
        $this->drawDdList(
            $screen,
            0,
            $listTop,
            $leftW,
            $bottom,
            $state->ddTables,
            $state->ddTableSel,
            $tablesActive,
            $state->ddTableOffset,
        );

        // Overlay a schema-diff badge on the right edge of each visible table
        // row: 'Δ' when fields differ, '✗' when the DD table has no live
        // counterpart (§11). Only when a live schema was available to diff.
        if ($state->ddDiffReady) {
            for ($i = 0; $i < $state->ddTablePageRows; $i++) {
                $idx = $state->ddTableOffset + $i;
                if ($idx >= $tableCount) {
                    break;
                }
                $badge = $state->ddTableBadges[$idx] ?? '';
                if ($badge === 'diff') {
                    $screen->put($leftW - 1, $listTop + $i, 'Δ', $this->theme->style('dd_badge_diff'));
                } elseif ($badge === 'orphan') {
                    $screen->put($leftW - 1, $listTop + $i, '✗', $this->theme->style('dd_badge_orphan'));
                }
            }
        }

        // --- right panel: fields of the selected table ---------------------
        $rx = $sepX + 1;
        $rw = $w - $rx;
        // Header: selected table + its description, with a schema-diff summary
        // prepended (so the counts + undocumented column names stay visible even
        // when a long description would otherwise truncate them away, §11).
        $head = $state->ddCurrentTable;
        if ($state->ddDescription !== '') {
            $head .= ' — ' . $state->ddDescription;
        }
        if ($head === '') {
            $head = $state->ddFilter !== '' ? '(no match)' : '';
        }
        $summary = $state->ddDiffReady ? $this->ddDiffSummary($state) : '';
        if ($summary !== '') {
            $head = $head === '' ? $summary : $summary . '  ·  ' . $head;
        }
        $screen->put($rx, $headerRow, ' ' . $this->truncate($head, $rw - 1), !$tablesActive ? $titleStyle : $bg);

        // Field rows: name / type / comment.
        $fieldRows = array_map(
            fn (array $f): string => $this->ddFieldLine($f, $rw - 1),
            $state->ddFields,
        );
        $state->ddFieldPageRows = $bottom - $listTop + 1;
        $this->drawDdList($screen, $rx, $listTop, $rw, $bottom, $fieldRows, $state->ddFieldSel, !$tablesActive, $state->ddFieldOffset);

        // Per-field badges + a one-line diff summary above the field list (§11).
        if ($state->ddDiffReady && $state->ddCurrentTable !== '') {
            for ($i = 0; $i < $state->ddFieldPageRows; $i++) {
                $idx = $state->ddFieldOffset + $i;
                if ($idx >= count($state->ddFields)) {
                    break;
                }
                $st = $state->ddFieldStatus[strtolower((string) ($state->ddFields[$idx]['name'] ?? ''))] ?? '';
                if ($st === 'changed') {
                    $screen->put($w - 1, $listTop + $i, 'Δ', $this->theme->style('dd_badge_diff'));
                } elseif ($st === 'extra') {
                    $screen->put($w - 1, $listTop + $i, '✗', $this->theme->style('dd_badge_orphan'));
                }
            }
        }

        // Inline comment editor overlays the centre when open (§11).
        if ($state->ddCommentEdit && $state->ddCommentEditor !== null) {
            $this->drawDdCommentEdit($screen, $state, $w, $h);
        }
        // Read-only foreign-keys view overlays the centre when open (§11).
        if ($state->ddFkView) {
            $this->drawDdFkView($screen, $state, $w, $h);
        }
        // Read-only single-field detail view (Ctrl+I / Tab) overlays the centre (§11).
        if ($state->ddRowView) {
            $this->drawTitledTextBox($screen, $w, $h, $state->ddRowTitle, $state->ddRowText);
        }
        // Add-undocumented-tables popup (Ctrl+N) overlays the centre (§11).
        if ($state->ddNewView) {
            $this->drawDdNewTables($screen, $state, $w, $h);
        }
    }

    /**
     * The Ctrl+N "add undocumented tables" popup (§11): a tick list of the live
     * tables the DD doesn't document. Scrolls when there are more than fit, and
     * carries its own keys on the bottom line since it owns input while open.
     */
    private function drawDdNewTables(Screen $screen, State $state, int $w, int $h): void
    {
        $tables = $state->ddNewTables;
        $total = count($tables);
        if ($total === 0) {
            return;
        }
        // Keep the box (and its border/title/hint chrome) inside the screen.
        $rows = max(1, min($total, $h - 6));
        // Ensure-visible scrolling, same rule as drawDdList: the offset only moves
        // when the selection leaves the window.
        $off = $state->ddNewOffset;
        if ($state->ddNewSel < $off) {
            $off = $state->ddNewSel;
        } elseif ($state->ddNewSel >= $off + $rows) {
            $off = $state->ddNewSel - $rows + 1;
        }
        $state->ddNewOffset = max(0, min($off, max(0, $total - $rows)));

        $labels = [];
        for ($i = 0; $i < $rows; $i++) {
            $idx = $state->ddNewOffset + $i;
            if ($idx >= $total) {
                break;
            }
            $name = $tables[$idx];
            $labels[] = (isset($state->ddNewChecked[$name]) ? '[x] ' : '[ ] ') . $name;
        }

        $shown = $state->ddNewOffset + count($labels);
        $title = $total . ' table(s) not in the dictionary';
        if ($total > count($labels)) {
            $title .= ' — showing ' . ($state->ddNewOffset + 1) . '-' . $shown;
        }

        // Keys go on the bottom border so the popup is self-explanatory. Enter acts
        // on the ticked tables, or the highlighted one when nothing is ticked — say
        // which, since that difference is invisible otherwise.
        $hint = count($state->ddNewChecked) > 0
            ? ' Space tick · Ctrl+A all · Enter add ticked · Esc cancel '
            : ' Space tick · Ctrl+A all · Enter add selected · Esc cancel ';
        // Size the box for the hint too, or it would be too narrow to print it (the
        // table names are typically shorter than the key list).
        $minInner = min(max(16, mb_strlen($hint)), max(16, $w - 6));

        [$x, $y, $boxW, $boxH] = $this->drawMenu(
            $screen,
            $w,
            $h,
            $title,
            $labels,
            $state->ddNewSel - $state->ddNewOffset,
            [],
            $minInner,
        );

        if (mb_strlen($hint) <= $boxW - 2) {
            $screen->put($x + 1, $y + $boxH - 1, $hint, $this->theme->style('popup_title'));
        }
    }

    /** Centered multi-line editor for a field's DD comment (§11). */
    private function drawDdCommentEdit(Screen $screen, State $state, int $w, int $h): void
    {
        $editor = $state->ddCommentEditor;
        if ($editor === null) {
            return;
        }
        // Share the grid cell editor's framed path (§9): selection highlight, the
        // scrollbars, and the title-bar-move / grip-resize chrome all come for free,
        // and the box remembers where the user dragged it via its own ddEdit* fields.
        $title = 'Comment: ' . $state->ddCurrentTable . '.' . $state->ddCommentField;
        $this->drawEditorFrame(
            $screen,
            $state,
            $w,
            $h,
            $title,
            $editor,
            $state->ddEditX,
            $state->ddEditY,
            $state->ddEditW,
            $state->ddEditH,
        );
    }

    /**
     * The Assign-DD picker (§11). Stage 0 is a single-line directory editor;
     * stage 1 is a scrolling list of the `.csv` files found there. Centered box.
     */
    private function drawDdPicker(Screen $screen, State $state, int $w, int $h): void
    {
        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $title = $this->theme->style('popup_title');
        $boxW = min($w - 2, 76);

        if ($state->ddPickerStage === 0) {
            $editor = $state->ddPickerEditor;
            if ($editor === null) {
                return;
            }
            $boxH = 5;
            $x = max(0, intdiv($w - $boxW, 2));
            $y = max(1, intdiv($h - $boxH, 2));

            $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
            $screen->put($x + 2, $y, ' Assign Data Dictionary ', $title);
            for ($i = 1; $i <= 3; $i++) {
                $screen->put($x, $y + $i, '│', $border);
                $screen->put($x + $boxW - 1, $y + $i, '│', $border);
                $screen->fill($x + 1, $y + $i, $boxW - 2, ' ', $bg);
            }

            // Directory input row with a horizontally scrolled view + cursor.
            $textX = $x + 2;
            $avail = $boxW - 4;
            $text = $editor->text();
            $cursor = $editor->cursor();
            $scroll = $cursor > $avail - 1 ? $cursor - ($avail - 1) : 0;
            $screen->put($textX, $y + 1, mb_substr($text, $scroll, $avail), $this->theme->style('input_text'));
            $this->drawFieldSelection($screen, $editor, 0, $textX, $y + 1, $scroll, $avail, $this->theme->style('input_selection'));
            $under = mb_substr($text, $cursor, 1);
            $screen->put($textX + ($cursor - $scroll), $y + 1, $under === '' ? ' ' : $under, $this->theme->style('cursor'));
            // Publish this field's geometry so mouse click/drag can place & extend
            // the caret like the CLI (§4). Single line at row $y+1.
            $state->editFieldRegion = ['top' => $y + 1, 'left' => $textX, 'width' => $avail, 'rows' => 1, 'vScroll' => 0, 'curRow' => 0, 'curHScroll' => $scroll];

            if ($state->ddPickerMessage !== '') {
                $screen->put($textX, $y + 2, $this->truncate($state->ddPickerMessage, $avail), $this->theme->style('status_error'));
            }
            $screen->put($textX, $y + 3, $this->truncate('Enter: list .csv files · Esc: cancel', $avail), $bg);
            $screen->put($x, $y + 4, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
            return;
        }

        // Stage 1: file list.
        $count = count($state->ddPickerFiles);
        $listRows = max(1, min($count, max(3, $h - 8)));
        $boxH = $listRows + 5; // top, dir, list, hint, bottom (+1)
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, ' Pick a dictionary (.csv) ', $title);
        for ($i = 1; $i < $boxH - 1; $i++) {
            $screen->put($x, $y + $i, '│', $border);
            $screen->put($x + $boxW - 1, $y + $i, '│', $border);
            $screen->fill($x + 1, $y + $i, $boxW - 2, ' ', $bg);
        }
        $textX = $x + 2;
        $avail = $boxW - 4;
        $screen->put($textX, $y + 1, $this->truncate($state->ddPickerDir, $avail), $bg);
        $this->drawDdList($screen, $textX, $y + 2, $avail, $y + 1 + $listRows, $state->ddPickerFiles, $state->ddPickerSel, true, $state->ddPickerOffset);
        $hint = $state->ddPickerMessage !== '' ? $state->ddPickerMessage : 'Enter: assign · Esc: back';
        $hintStyle = $state->ddPickerMessage !== '' ? $this->theme->style('status_error') : $bg;
        $screen->put($textX, $y + $boxH - 2, $this->truncate($hint, $avail), $hintStyle);
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
    }

    /**
     * One field row: a PK marker, name (padded), type (padded), then the comment.
     * The caller pads/truncates the whole line to the panel width.
     */
    private function ddFieldLine(array $field, int $width): string
    {
        // The read-only foreign-keys pseudo-row reads as a labelled note, not a
        // name/type/comment column triplet (§11).
        if ($field['fk'] ?? false) {
            $fk = str_replace("\n", ' ↵ ', (string) ($field['comment'] ?? ''));
            return '↳ Foreign Keys: ' . $fk;
        }
        $nameW = max(8, min(22, intdiv($width, 3)));
        $typeW = max(6, min(16, intdiv($width, 4)));
        $key = ($field['key'] ?? '') === 'PRI' ? '*' : ' ';
        // Collapse a multi-line comment to one preview row; '↵' marks the breaks.
        $comment = str_replace("\n", ' ↵ ', (string) ($field['comment'] ?? ''));
        return $key . $this->pad((string) ($field['name'] ?? ''), $nameW) . ' '
            . $this->pad((string) ($field['type'] ?? ''), $typeW) . ' '
            . $comment;
    }

    /**
     * One-line schema-diff summary for the selected table (§11): undocumented
     * live columns (with names), DD fields whose type differs, and DD fields the
     * live table doesn't have. '' when the table matches the live schema.
     */
    private function ddDiffSummary(State $state): string
    {
        if (($state->ddTableBadges[$state->ddTableSel] ?? '') === 'orphan') {
            return '✗ not in live database';
        }
        $changed = 0;
        $extra = 0;
        foreach ($state->ddFieldStatus as $st) {
            if ($st === 'changed') {
                $changed++;
            } elseif ($st === 'extra') {
                $extra++;
            }
        }
        $parts = [];
        $missing = $state->ddMissingFields;
        if ($missing !== []) {
            $names = array_slice($missing, 0, 4);
            $more = count($missing) - count($names);
            $parts[] = count($missing) . ' undocumented ('
                . implode(', ', $names) . ($more > 0 ? ', +' . $more : '') . ')';
        }
        if ($changed > 0) {
            $parts[] = $changed . ' changed';
        }
        if ($extra > 0) {
            $parts[] = $extra . ' not in DB';
        }
        return $parts === [] ? '' : 'Δ ' . implode(' · ', $parts);
    }

    /** Draw a panel's filter line, with the cursor shown when the panel is active. */
    private function drawDdFilter(Screen $screen, int $x, int $y, int $w, string $label, string $text, bool $active, int $cursor): void
    {
        $style = $active ? $this->theme->style('popup_title') : $this->theme->style('popup');
        $prefix = $label . ': ';
        $screen->put($x, $y, $this->pad($prefix . $text, $w - 1), $style);
        if ($active) {
            $cx = $x + mb_strlen($prefix) + $cursor;
            $under = mb_substr($text, $cursor, 1);
            $screen->put($cx, $y, $under === '' ? ' ' : $under, $this->theme->style('cursor'));
        }
    }

    /**
     * Draw a scrolling list into [x, yTop]..(width, yBottom), keeping $sel visible.
     * Selected row is highlighted; the active panel's selection is brighter.
     *
     * @param list<string> $items
     */
    private function drawDdList(Screen $screen, int $x, int $yTop, int $w, int $yBottom, array $items, int $sel, bool $active, int &$off): void
    {
        $rows = $yBottom - $yTop + 1;
        if ($rows < 1) {
            return;
        }
        $n = count($items);
        // Ensure-visible scrolling: keep $off (the top visible row) stable, moving
        // it only when the selection leaves the window. This makes the cursor walk
        // within the page and the list scroll only at the top/bottom edges — not
        // the selection pinned to the last row while the whole list slides (§11).
        if ($sel < $off) {
            $off = $sel;
        } elseif ($sel >= $off + $rows) {
            $off = $sel - $rows + 1;
        }
        $off = max(0, min($off, max(0, $n - $rows)));

        $bg = $this->theme->style('popup');
        $selStyle = $active ? $this->theme->style('popup_selected') : new Style(fg: 'cyan');
        $innerW = max(1, $w - 1);
        for ($i = 0; $i < $rows; $i++) {
            $idx = $off + $i;
            $y = $yTop + $i;
            if ($idx >= $n) {
                break;
            }
            $style = $idx === $sel ? $selStyle : $bg;
            $screen->put($x, $y, ' ' . $this->pad($items[$idx], $innerW), $style);
        }
    }

    /**
     * JSON viewer/editor as a centered popup (§10). Renders the flattened tree
     * with indentation, expand markers and syntax-tinted values; long or
     * multi-line rows **wrap** (with a hanging indent) instead of truncating. The
     * selected node's line(s) are inverted. "Invalid JSON" mode shows the raw
     * value + why. A node-edit overlay is drawn last when editing a scalar leaf.
     */
    private function drawJsonViewer(Screen $screen, State $state, int $w, int $h): void
    {
        // Invalid-JSON mode is just a centered text box (raw value + reason, §10).
        if ($state->jsonInvalid) {
            $this->drawTitledTextBox(
                $screen,
                $w,
                $h,
                'Not JSON — ' . $state->jsonColumn,
                $state->jsonInvalidMessage . "\n\n" . $state->jsonRaw . "\n\n(any key to close)",
            );
            return;
        }

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');

        if ($w < 28 || $h < 8) {
            $this->drawDialog($screen, $w, $h, ['Terminal too small for JSON view']);
            return;
        }

        $boxW = min($w - 4, 100);
        $iw = $boxW - 4;                 // text area (1 border + 1 pad each side)

        // Wrap every node to the inner width, tagging each visual line with its
        // node index so the selected node highlights across all its wrapped lines.
        // An expanded container's row opens with `{`/`[`; its closing brace gets a
        // line of its own after its last descendant. Closers are display only:
        // nodeIdx null, so they are never selected or highlighted.
        $vis = [];
        $open = []; // stack of [depth, closing brace] for expanded containers
        $closeTo = function (int $depth) use (&$open, &$vis, $bg, $iw): void {
            while ($open !== [] && end($open)[0] >= $depth) {
                [$d, $brace] = array_pop($open);
                $runs = [[str_repeat(' ', max(0, min($d * 2, $iw - 1))), $bg], [$brace, $this->jsonValueStyle('object')]];
                $vis[] = ['nodeIdx' => null, 'segs' => $runs];
            }
        };
        foreach ($state->jsonNodes as $idx => $node) {
            $closeTo($node['depth']);
            $hang = max(0, min($node['depth'] * 2 + 2, $iw - 8));
            foreach ($this->jsonWrapRuns($this->jsonNodeRuns($node, $bg), $iw, $hang, $bg) as $segs) {
                $vis[] = ['nodeIdx' => $idx, 'segs' => $segs];
            }
            if ($node['container'] && $node['expanded']) {
                $open[] = [$node['depth'], $node['value'] === '[' ? ']' : '}'];
            }
        }
        $closeTo(0);
        $total = count($vis);

        $maxBoxH = $h - 2;
        $ih = max(1, min($total, $maxBoxH - 3));   // content rows
        $state->jsonPageRows = $ih;
        $boxH = $ih + 3;                            // top border + content + hint + bottom
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(0, intdiv($h - $boxH, 2));
        $sel = $state->jsonSel;

        // Ensure-visible scroll, keyed to the selected node's first/last vis line.
        $selStart = null;
        $selEnd = null;
        foreach ($vis as $li => $L) {
            if ($L['nodeIdx'] === $sel) {
                $selStart ??= $li;
                $selEnd = $li;
            }
        }
        $off = $state->jsonOffset;
        if ($selStart !== null) {
            if ($selEnd >= $off + $ih) {
                $off = $selEnd - $ih + 1;
            }
            if ($selStart < $off) {
                $off = $selStart;
            }
        }
        $off = max(0, min($off, max(0, $total - $ih)));
        $state->jsonOffset = $off;

        // Top border + title.
        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $this->jsonTitle($state, $boxW - 4), $this->theme->style('popup_title'));

        // Content rows.
        $selStyle = $this->theme->style('json_selected');
        for ($i = 0; $i < $ih; $i++) {
            $ry = $y + 1 + $i;
            $screen->put($x, $ry, '│', $border);
            $screen->put($x + $boxW - 1, $ry, '│', $border);
            $screen->fill($x + 1, $ry, $boxW - 2, ' ', $bg);
            $li = $off + $i;
            if ($li >= $total) {
                continue;
            }
            $L = $vis[$li];
            if ($L['nodeIdx'] === $sel) {
                $plain = '';
                foreach ($L['segs'] as [$t]) {
                    $plain .= $t;
                }
                $screen->put($x + 2, $ry, $this->pad($plain, $iw), $selStyle);
            } else {
                $cx = $x + 2;
                foreach ($L['segs'] as [$t, $st]) {
                    $screen->put($cx, $ry, $t, $st);
                    $cx += mb_strlen($t);
                }
            }
        }

        // Hint row + bottom border. A notice — why a `j` press did nothing —
        // takes the row for one keystroke, because it answers the key just
        // pressed and the hints are always one keystroke away again.
        $hy = $y + 1 + $ih;
        $screen->put($x, $hy, '│', $border);
        $screen->put($x + $boxW - 1, $hy, '│', $border);
        if ($state->jsonNotice !== '') {
            $screen->put($x + 1, $hy, $this->pad(' ' . $state->jsonNotice, $boxW - 2), $this->theme->style('json_notice'));
        } else {
            $hint = ' ↑↓ move · Enter edit/fold · j nested · a add-in · A add-after · d delete · Esc '
                . ($state->jsonPath === [] ? 'close' : 'back');
            $screen->put($x + 1, $hy, $this->pad($hint, $boxW - 2), $bg);
        }
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        if ($state->jsonEdit && $state->jsonEditor !== null) {
            $this->drawJsonNodeEdit($screen, $state, $w, $h);
        }
    }

    /**
     * The viewer's title: the column, then one crumb per embedded document the
     * user has opened with `j` (§10).
     *
     * When it will not fit, the **tail** is kept — the level on screen is what
     * the title is for, and the path to it is the part the user already walked.
     */
    private function jsonTitle(State $state, int $width): string
    {
        $title = ' JSON — ' . $state->jsonColumn;
        foreach ($state->jsonPath as $crumb) {
            $title .= ' › ' . $crumb;
        }
        $title .= ' ';
        if ($width > 3 && mb_strlen($title) > $width) {
            return ' …' . mb_substr($title, mb_strlen($title) - ($width - 2));
        }
        return $this->truncate($title, $width);
    }

    /**
     * The styled runs for one JSON node: indent, expand marker, key, then value
     * (syntax-tinted by type). Fed to {@see jsonWrapRuns()}.
     *
     * @param array{depth:int,label:string,value:string,type:string,container:bool,expanded:bool,childCount:int,editable:bool} $node
     * @return list<array{0:string,1:Style}>
     */
    private function jsonNodeRuns(array $node, Style $bg): array
    {
        $marker = $node['container'] ? ($node['expanded'] ? '▾ ' : '▸ ') : '  ';
        $runs = [];
        if ($node['depth'] > 0) {
            $runs[] = [str_repeat('  ', $node['depth']), $bg];
        }
        $runs[] = [$marker, $this->theme->style('json_marker')];
        if ($node['label'] !== '') {
            $runs[] = [$node['label'], $this->theme->style('json_key')];
            $runs[] = [': ', $this->theme->style('json_marker')];
        }
        $runs[] = [$node['value'], $this->jsonValueStyle($node['type'])];
        return $runs;
    }

    /**
     * Wrap a list of styled runs into visual lines no wider than $iw, breaking on
     * width and on any literal newline; continuation lines carry a $hang-wide
     * hanging indent so wrapped text stays visually under its row. Character-level
     * wrap (fine for JSON tokens). Each returned line is a list of styled segments.
     *
     * @param list<array{0:string,1:Style}> $runs
     * @return list<list<array{0:string,1:Style}>>
     */
    private function jsonWrapRuns(array $runs, int $iw, int $hang, Style $bg): array
    {
        $iw = max(1, $iw);
        $hang = max(0, min($hang, $iw - 1));
        $lines = [];
        $cur = [];
        $curLen = 0;
        $newLine = function () use (&$lines, &$cur, &$curLen, $hang, $bg): void {
            $lines[] = $cur;
            $cur = [];
            $curLen = 0;
            if ($hang > 0) {
                $cur[] = [str_repeat(' ', $hang), $bg];
                $curLen = $hang;
            }
        };
        foreach ($runs as [$text, $style]) {
            $physical = preg_split("/\r\n|\r|\n/", $text) ?: [''];
            foreach ($physical as $pi => $seg) {
                if ($pi > 0) {
                    $newLine(); // forced break at a literal newline
                }
                $len = mb_strlen($seg);
                $i = 0;
                while ($i < $len) {
                    if ($curLen >= $iw) {
                        $newLine();
                    }
                    $take = min($iw - $curLen, $len - $i);
                    $cur[] = [mb_substr($seg, $i, $take), $style];
                    $curLen += $take;
                    $i += $take;
                }
            }
        }
        $lines[] = $cur;
        return $lines;
    }

    private function jsonValueStyle(string $type): Style
    {
        return match ($type) {
            'string' => $this->theme->style('json_string'),
            'number' => $this->theme->style('json_number'),
            'bool'   => $this->theme->style('json_bool'),
            'null'   => $this->theme->style('json_null'),
            default  => $this->theme->style('json_brace'), // object/array braces
        };
    }

    /** Centered single-line editor for a JSON scalar node's value (§10). */
    private function drawJsonNodeEdit(Screen $screen, State $state, int $w, int $h): void
    {
        $editor = $state->jsonEditor;
        if ($editor === null) {
            return;
        }
        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');

        $boxW = min($w - 2, 80);
        $avail = $boxW - 4;
        $boxH = 4;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $newKey = $state->jsonEditKind === 'newKey';
        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $title = $newKey ? ' New key ' : ' Edit ' . $state->jsonEditLabel . ' (as JSON) ';
        $screen->put($x + 2, $y, $this->truncate($title, $boxW - 4), $this->theme->style('popup_title'));

        // Single text row with horizontal-scroll cursor tracking.
        $ly = $y + 1;
        $screen->put($x, $ly, '│', $border);
        $screen->put($x + $boxW - 1, $ly, '│', $border);
        $screen->fill($x + 1, $ly, $boxW - 2, ' ', $bg);
        $text = $editor->text();
        $cur = $editor->cursor();
        $hScroll = $cur > $avail - 1 ? $cur - ($avail - 1) : 0;
        $screen->put($x + 2, $ly, mb_substr($text, $hScroll, $avail), $this->theme->style('input_text'));
        $this->drawFieldSelection($screen, $editor, 0, $x + 2, $ly, $hScroll, $avail, $this->theme->style('input_selection'));
        $under = mb_substr($text, $cur, 1);
        $screen->put($x + 2 + ($cur - $hScroll), $ly, $under === '' ? ' ' : $under, $this->theme->style('cursor'));
        // Publish geometry for mouse click/drag caret placement (§4). Single line.
        $state->editFieldRegion = ['top' => $ly, 'left' => $x + 2, 'width' => $avail, 'rows' => 1, 'vScroll' => 0, 'curRow' => 0, 'curHScroll' => $hScroll];

        $hy = $y + 2;
        $screen->put($x, $hy, '│', $border);
        $screen->put($x + $boxW - 1, $hy, '│', $border);
        $editHint = $newKey
            ? ' Enter: name it, then set its value · Esc cancel'
            : ' Enter save · Esc cancel  ("text", 42, true, null)';
        $screen->put($x + 1, $hy, $this->pad($editHint, $boxW - 2), $bg);

        $screen->put($x, $y + 3, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
    }

    /**
     * The widest a completion popup's second column may be, in characters.
     *
     * A macro's value can be a whole statement, and a popup as wide as the
     * longest one would cover the query it is being typed into. This is "enough
     * to recognise it by", not "enough to read it" — the viewer (§23) is where a
     * value is read in full.
     */
    private const COMPLETION_DETAIL_MAX = 48;

    /**
     * Floating completion list anchored just above the input cursor (§4), or
     * below it when there isn't room above. Scrolls to keep the selection
     * visible.
     *
     * Items may carry a **second column** — what a macro expands to (§23).
     * It is separated by a `│` rather than by spaces, because the two halves are
     * different kinds of thing: the left is what Tab will type for you, the
     * right is only there so twenty names beginning `agy-` are twenty
     * distinguishable choices. Anything that does not fit is cut with an `…`,
     * and the whole column is dropped before the names are, when the terminal is
     * too narrow for both.
     */
    private function drawCompletion(Screen $screen, State $state, int $w, int $h): void
    {
        $items = $state->completionItems;
        $n = count($items);
        $details = count($state->completionDetails) === $n ? $state->completionDetails : [];

        // The list can be empty (the filter matched nothing) — the popup still
        // shows so the user can backspace to refill it (§4); a placeholder line
        // takes the place of the items.
        $placeholder = '(no matches)';

        $maxVisible = max(1, min(max($n, 1), min(10, $h - 4)));
        // Header shows the popup title plus the live filter the user is typing,
        // so refining the search is visible (§4).
        $header = $state->completionTitle;
        if ($state->completionFilter !== '') {
            $header = ($header !== '' ? $header . ' ▸ ' : '') . $state->completionFilter;
        }
        $innerW = mb_strlen($header);
        if ($n === 0) {
            $innerW = max($innerW, mb_strlen($placeholder));
        }
        $nameW = 0;
        foreach ($items as $it) {
            $nameW = max($nameW, mb_strlen($it));
        }
        $innerW = max($innerW, $nameW);

        // The detail column is priced separately: names first, then whatever is
        // left of the terminal, then the cap — so a narrow window keeps the list
        // usable and simply loses the previews.
        $detailW = 0;
        if ($details !== []) {
            $widest = 0;
            foreach ($details as $d) {
                $widest = max($widest, mb_strlen($d));
            }
            $room = ($w - 6) - $nameW - 3; // 3 = ` │ `
            $detailW = max(0, min($widest, self::COMPLETION_DETAIL_MAX, $room));
            if ($detailW > 0) {
                $innerW = max($innerW, $nameW + 3 + $detailW);
            }
        }
        $innerW = max(8, min($innerW, $w - 6));
        if (mb_strlen($header) > $innerW) {
            $header = mb_substr($header, 0, $innerW);
        }
        $boxW = $innerW + 2;
        $boxH = $maxVisible + 2;

        $cx = $state->inputCursorX >= 0 ? $state->inputCursorX : 1;
        $cy = $state->inputCursorY >= 0 ? $state->inputCursorY : $h - 2;
        $x = max(0, min($cx, $w - $boxW));
        $top = $cy - $boxH;                       // sit above the cursor
        if ($top < 1) {
            $top = min($cy + 1, $h - $boxH);     // fall back to below
        }
        $top = max(0, $top);

        $sel = $state->completionIdx;
        $off = 0;
        if ($sel >= $maxVisible) {
            $off = $sel - $maxVisible + 1;
        }
        $off = min($off, max(0, $n - $maxVisible));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');

        $screen->put($x, $top, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        if ($header !== '') {
            $screen->put($x + 1, $top, ' ' . $header . ' ', $this->theme->style('popup_title'));
        }
        for ($i = 0; $i < $maxVisible; $i++) {
            $row = $top + 1 + $i;
            $idx = $off + $i;
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            if ($n === 0) {
                // Show the placeholder on the first row, blanks below.
                $text = $i === 0 ? $placeholder : '';
                $screen->put($x + 1, $row, $this->pad($text, $boxW - 2), $bg);
                continue;
            }
            if ($idx >= $n) {
                $screen->put($x + 1, $row, str_repeat(' ', $boxW - 2), $bg);
                continue;
            }
            $style = $idx === $sel ? $this->theme->style('popup_selected') : $bg;
            if ($detailW <= 0) {
                $screen->put($x + 1, $row, $this->pad($items[$idx], $boxW - 2), $style);
                continue;
            }
            // The separator and the value share the row's style so the selection
            // bar stays one unbroken block; the `│` is what makes the two halves
            // read as two halves.
            $line = $this->pad($items[$idx], $nameW) . ' │ '
                . $this->truncate($details[$idx], $detailW);
            $screen->put($x + 1, $row, $this->pad($line, $boxW - 2), $style);
        }
        $screen->put($x, $top + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        // More items than rows: a scrollbar on the right border, the same
        // glyphs as the editor's, so a ten-row list of forty says so (§4).
        if ($n > $maxVisible && $maxVisible >= 2) {
            [$thumb, $pos] = $this->scrollThumb($maxVisible, $maxVisible, $n, $off);
            for ($i = 0; $i < $maxVisible; $i++) {
                $on = $i >= $pos && $i < $pos + $thumb;
                $screen->put(
                    $x + $boxW - 1,
                    $top + 1 + $i,
                    $on ? '█' : '░',
                    $this->theme->style($on ? 'scrollbar_thumb' : 'scrollbar'),
                );
            }
        }

        $state->modalRect = [$x, $top, $boxW, $boxH];
    }

    /**
     * Centered keyboard menu. A null label renders as a separator rule and is
     * never the selection (the caller skips them when moving). Items flagged in
     * $disabled (parallel to $labels) render greyed and are never highlighted —
     * the caller is responsible for skipping them during navigation (§12).
     *
     * @param list<?string> $labels
     * @param list<bool>    $disabled
     * @return array{0:int,1:int,2:int,3:int} the box rect [x, y, w, h]
     */
    private function drawMenu(Screen $screen, int $w, int $h, string $title, array $labels, int $selected, array $disabled = [], int $minInnerW = 16): array
    {
        $innerW = mb_strlen($title);
        foreach ($labels as $label) {
            $innerW = max($innerW, $label === null ? 0 : mb_strlen($label));
        }
        $innerW = max($innerW, $minInnerW);
        $boxW = $innerW + 4;
        $boxH = count($labels) + 2;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        // Greyed style for disabled entries (the dedicated theme token arrives
        // with the theme system, backlog J; darkGray is a safe default).
        $disabledStyle = new Style(fg: 'darkGray');

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $title, $this->theme->style('popup_title'));

        foreach ($labels as $i => $label) {
            $row = $y + 1 + $i;
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            if ($label === null) {
                $screen->put($x + 1, $row, str_repeat('─', $boxW - 2), $border);
                continue;
            }
            $isDisabled = $disabled[$i] ?? false;
            if ($i === $selected) {
                // Always show the cursor; on a disabled row keep the text greyed
                // (a selectable-but-not-activatable hint).
                $style = $isDisabled
                    ? $this->theme->style('popup_selected')->withFg('darkGray')
                    : $this->theme->style('popup_selected');
            } elseif ($isDisabled) {
                $style = $disabledStyle;
            } else {
                $style = $bg;
            }
            $screen->put($x + 1, $row, ' ' . $this->pad($label, $innerW) . ' ', $style);
        }

        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        return [$x, $y, $boxW, $boxH];
    }

    /**
     * Context-sensitive help overlay (§15). Lists the shortcuts relevant to the
     * current focus — CLI editing vs DATA grid navigation.
     */
    private function drawHelp(Screen $screen, State $state, int $w, int $h): void
    {
        if ($state->focus === State::FOCUS_DATA) {
            $title = ' DATA shortcuts ';
            $lines = [
                '↑ ↓ ← →     Move selection',
                'PgUp/PgDn   Scroll a page',
                'Home/End    First / last row',
                'Up on row 0 Select the header row',
                'Enter       Edit cell → UPDATE goes to the CLI to run/cancel',
                'Ctrl+N      Set the cell to NULL (same UPDATE → CLI)',
                'Del         Delete the selected row (count + confirm first)',
                'Ctrl+Del    Mark mode: checkbox column for deleting several',
                'Enter/Space Tick the row under the cursor (mark mode)',
                'i           Detail view for the selected row',
                'v           Expand the cell in a movable inspector',
                'j / J       JSON viewer for the cell (j again = nested blob)',
                'Ctrl+C      Copy cell to clipboard',
                'Ctrl+Alt+C  Copy row to clipboard',
                'Ctrl+Alt+O  Export the view to a CSV in the current dir',
                'Ctrl+Alt+S  Unmoved columns A–Z / result order (not saved)',
                'Ctrl+L      Data View Configuration (this column + the table)',
                'Alt+1…9     Switch data view',
                'Ctrl+Pg↑/↓  Previous / next data view (wraps at 1 and 9)',
                'F5          Re-run this view\'s query, exactly as typed',
                '/           System menu',
                'Ctrl+D      Toggle DATA / CLI focus',
                'Esc         Back to the CLI (closes any popup first)',
            ];
        } else {
            $title = ' CLI shortcuts ';
            $lines = [
                'Enter       Run (; ]G, or a ?macro ending the line), else new line',
                'Alt+Enter   Additional line always, even after a `;`',
                ']1 … ]9     Run into data view 1-9 and switch to it',
                '            ]G vertical · [ and \\ work too · a trailing ; is fine',
                'Alt+1…9     Switch data view',
                'Ctrl+Pg↑/↓  Previous / next data view (wraps at 1 and 9)',
                'Tab         Autocomplete tables/fields · ? then Tab for macros',
                '?name       Expand a macro; any arguments follow it on the line',
                '?{name}     Ask for this value when the statement runs',
                '            (a ?name inside quotes is literal — nothing expands)',
                '↑ / ↓       History (typed) · move between lines',
                'Alt+↑ ↓     History (executed SQL)',
                'F5          Re-run this view\'s query, exactly as typed',
                'Shift+move  Select text (←→ Home End Ctrl+←→ ↑↓)',
                'Ctrl+A      Select all · drag with the mouse to select',
                'Ctrl+C / V  Copy selection · paste (replaces selection)',
                '/           System menu  (// for a literal /; 1-9 pick items)',
                'word?       Data dictionary hint',
                'clear/cls   Clear the data view',
                'exit/quit   Quit the app',
                'Ctrl+L      Data View Configuration (this column + the table)',
                'Ctrl+D      Toggle DATA / CLI focus',
            ];
        }
        $lines[] = '';
        // Documented in both focuses because the state it applies to has no
        // focus: while a query runs the keyboard does one thing, whichever side
        // the user was on when they submitted it (§21). Omitted entirely where
        // the driver cannot cancel — a build without mysqli blocks inside the
        // query, so Esc would do nothing and listing it would be a lie.
        if ($state->canCancel) {
            $lines[] = 'Esc         Cancel a running query';
        } else {
            // Said rather than silently left out: the one place someone looks
            // for the key is here, and "not listed" doesn't tell them why.
            $lines[] = '(Esc-cancel needs the mysqli driver — see php sqli.php --lint)';
        }
        $lines[] = 'Ctrl+Alt+Z/X Quit  (or type exit/quit)';
        $lines[] = 'F1 / Esc    Close this help';

        $innerW = mb_strlen($title);
        foreach ($lines as $l) {
            $innerW = max($innerW, mb_strlen($l));
        }
        $innerW = min(max($innerW, 20), max(20, $w - 6));
        $boxW = $innerW + 4;
        $boxH = count($lines) + 2;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $title, $this->theme->style('popup_title'));
        foreach ($lines as $i => $line) {
            $row = $y + 1 + $i;
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            $screen->put($x + 1, $row, ' ' . $this->pad($line, $innerW) . ' ', $bg);
        }
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        $state->modalRect = [$x, $y, $boxW, $boxH];
    }

    /** Inline cell editor drawn over the selected grid cell (§9). */
    private function drawCellEdit(Screen $screen, State $state): void
    {
        $gl = $state->gridLayout;
        $editor = $state->cellEditor;
        if ($gl === null || $editor === null) {
            return;
        }
        $cellX = null;
        $cellW = 0;
        foreach ($gl->columns as $c) {
            if ($c['idx'] === $state->selectedCol) {
                $cellX = $c['x'];
                $cellW = $c['w'];
                break;
            }
        }
        if ($cellX === null) {
            return;
        }
        $cellY = $gl->dataTop + ($state->selectedRow - $gl->rowOffset);
        if ($cellY < $gl->dataTop || $cellY >= $gl->dataTop + $gl->dataRows) {
            return; // selected row scrolled out of view
        }

        $style = $this->theme->style('cell_edit');
        $cursorStyle = $this->theme->style('cursor');

        $lines = $editor->lines();
        [$curRow, $curCol] = $editor->cursorRowCol();
        $n = count($lines);

        // The editor overlays the cell and grows DOWN as Enter adds lines, so it
        // reads as editing in place. It stays within the data area: if the cell is
        // near the bottom and the text outgrows the room below, the box keeps its
        // top on the cell row and scrolls its lines to keep the cursor visible (§9).
        $areaBottom = $gl->dataTop + $gl->dataRows - 1;
        $maxRows = $areaBottom - $cellY + 1;
        $visRows = max(1, min($n, $maxRows));
        $vScroll = $curRow >= $visRows ? $curRow - $visRows + 1 : 0;
        $vScroll = min($vScroll, max(0, $n - $visRows));

        for ($i = 0; $i < $visRows; $i++) {
            $ly = $cellY + $i;
            $li = $vScroll + $i;
            $line = $lines[$li] ?? '';
            // Horizontal scroll tracks the cursor only on the cursor's own line.
            $hScroll = ($li === $curRow && $curCol > $cellW - 1) ? $curCol - ($cellW - 1) : 0;
            $screen->put($cellX, $ly, $this->pad(mb_substr($line, $hScroll, $cellW), $cellW), $style);
            $this->drawFieldSelection($screen, $editor, $li, $cellX, $ly, $hScroll, $cellW, $this->theme->style('input_selection'));
            if ($li === $curRow) {
                $under = mb_substr($line, $curCol, 1);
                $screen->put($cellX + ($curCol - $hScroll), $ly, $under === '' ? ' ' : $under, $cursorStyle);
            }
        }

        // Publish geometry so mouse click/drag can place & extend the caret (§4).
        $curHScroll = $curCol > $cellW - 1 ? $curCol - ($cellW - 1) : 0;
        $state->editFieldRegion = ['top' => $cellY, 'left' => $cellX, 'width' => $cellW, 'rows' => $visRows, 'vScroll' => $vScroll, 'curRow' => $curRow, 'curHScroll' => $curHScroll];
    }

    /**
     * The movable, NON-modal cell inspector: one column's full value (word-wrapped,
     * scrollable) in a box the user can drag anywhere. It stays open while the grid
     * keeps its controls — the value re-derives here every frame from the pinned
     * column ({@see State::$cellViewCol}) and the currently selected row, so moving
     * the selection updates it. The same box is the future home of the JSON viewer.
     */
    private function drawCellInspector(Screen $screen, State $state, int $w, int $h): void
    {
        $result = $state->result;
        if ($result === null) {
            return;
        }
        $col = $state->cellViewCol;
        $order = $state->displayOrder();
        $rowPos = $order === [] ? 0 : max(0, min(max(0, $state->selectedRow), count($order) - 1));
        $rawIdx = $order[$rowPos] ?? null;
        $raw = $rawIdx === null
            ? null
            : (array_key_exists($col, $state->pending[$rawIdx] ?? [])
                ? $state->pending[$rawIdx][$col]
                : ($result->rows[$rawIdx][$col] ?? null));
        $value = $rawIdx === null ? '(no rows)' : ($raw === null ? 'NULL' : (string) $raw);
        // Show the formatted value (e.g. epoch → local datetime) like the grid (§6).
        $fmt = ColumnFormats::get($state->formatOf($col));
        if ($fmt !== null && $rawIdx !== null && $raw !== null && $value !== '') {
            $value = $fmt->display($value);
        }

        $wrapW = min(76, max(24, $w - 8));
        $lines = explode("\n", wordwrap($value, $wrapW, "\n", true));

        if ($state->cellMoveMode) {
            $title = '✥ ' . $col . '  ·  MOVE';
            $footer = '↑↓←→ move · Enter/Esc/m done';
        } else {
            $title = $col . '  ·  row ' . ($rowPos + 1);
            $footer = 'e edit · m move (or drag title) · wheel scroll · v close';
        }
        $innerW = max(mb_strlen($title), mb_strlen($footer));
        foreach ($lines as $l) {
            $innerW = max($innerW, mb_strlen($l));
        }

        $bodyH = max(1, min(count($lines), max(1, $h - 8)));
        // Renderer owns the scroll clamp (content height is only known here, §5).
        $maxScroll = max(0, count($lines) - $bodyH);
        $state->cellViewScroll = max(0, min($state->cellViewScroll, $maxScroll));

        [$bx, $by, $bw, $bh] = $this->drawOverlayFrame(
            $screen, $w, $h, $title, $footer, $innerW, $bodyH, $state->cellPopupX, $state->cellPopupY,
        );
        $style = $this->theme->style('popup');
        for ($i = 0; $i < $bh; $i++) {
            $li = $state->cellViewScroll + $i;
            if ($li >= count($lines)) {
                break;
            }
            $screen->put($bx, $by + $i, $this->pad($lines[$li], $bw), $style);
        }
        $state->cellPopupRect = [$bx - 2, $by - 3, $bw + 4, $bh + 6];
    }

    /** The inspector's cell being edited (`e`), drawn in the inspector's spot (§9). */
    private function drawCellInspectorEdit(Screen $screen, State $state, int $w, int $h): void
    {
        $editor = $state->cellEditor;
        if ($editor === null) {
            return;
        }
        $title = $state->cellViewCol . '  ·  editing';
        [$bx, $by, $bw, $bh] = $this->drawEditorFrame(
            $screen, $state, $w, $h, $title, $editor, $state->cellPopupX, $state->cellPopupY,
        );
        $state->cellPopupRect = [$bx - 2, $by - 3, $bw + 4, $bh + 6];
    }

    /**
     * The quick (Enter) cell editor drawn as a roomy centered box instead of the
     * inline overlay, when the value is multi-line or wider than its column (§9).
     */
    private function drawCellEditPopup(Screen $screen, State $state, int $w, int $h): void
    {
        $editor = $state->cellEditor;
        if ($editor === null) {
            return;
        }
        // Position and size come from State when the user has dragged them (§9).
        $this->drawEditorFrame(
            $screen,
            $state,
            $w,
            $h,
            $state->cellEditCol,
            $editor,
            $state->cellEditX,
            $state->cellEditY,
            $state->cellEditW,
            $state->cellEditH,
        );
    }

    /**
     * Frame + editor body shared by the popup editor and the inspector editor. The
     * editor logic is unchanged from the inline overlay — only the geometry differs:
     * a wide box that grows down, the cursor line scrolling horizontally if it still
     * overruns the box. Returns the body text rect [x, y, w, h] (§9).
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function drawEditorFrame(Screen $screen, State $state, int $w, int $h, string $title, LineEditor $editor, int $atX, int $atY, int $ovrW = 0, int $ovrH = 0): array
    {
        $lines = $editor->lines();
        [$curRow, $curCol] = $editor->cursorRowCol();

        $footer = '↵ new line · Ctrl+S save · Esc cancel';
        $longest = 0;
        foreach ($lines as $l) {
            $longest = max($longest, mb_strlen($l));
        }
        if ($ovrW > 0) {
            $innerW = $ovrW; // user-dragged width wins; drawOverlayFrame clamps it
        } else {
            // Cap the width so one very long line can't blow past the screen; the
            // body then scrolls horizontally within the box.
            $innerW = max(mb_strlen($title), mb_strlen($footer), $longest);
            $innerW = min($innerW, min(76, $w - 6));
        }

        $n = count($lines);
        $bodyH = $ovrH > 0 ? $ovrH : max(1, min($n, max(1, $h - 8)));

        [$bx, $by, $bw, $bh] = $this->drawOverlayFrame($screen, $w, $h, $title, $footer, $innerW, $bodyH, $atX, $atY);

        // Vertical scroll keeps the cursor row visible as the text grows.
        $vScroll = $curRow >= $bh ? $curRow - $bh + 1 : 0;
        $vScroll = min($vScroll, max(0, $n - $bh));
        // Horizontal scroll is UNIFORM across the body: every line shifts together,
        // as a text editor does. (The inline in-cell overlay scrolls only the cursor
        // line — fine for one cell-wide row, but in a box it strands the other lines
        // at column 0 and makes a horizontal scrollbar meaningless.)
        $hScroll = $curCol > $bw - 1 ? $curCol - ($bw - 1) : 0;

        $style = $this->theme->style('cell_edit');
        $cursorStyle = $this->theme->style('cursor');
        for ($i = 0; $i < $bh; $i++) {
            $li = $vScroll + $i;
            if ($li >= $n) {
                break;
            }
            $line = $lines[$li];
            $screen->put($bx, $by + $i, $this->pad(mb_substr($line, $hScroll, $bw), $bw), $style);
            $this->drawFieldSelection($screen, $editor, $li, $bx, $by + $i, $hScroll, $bw, $this->theme->style('input_selection'));
            if ($li === $curRow) {
                $under = mb_substr($line, $curCol, 1);
                $screen->put($bx + ($curCol - $hScroll), $by + $i, $under === '' ? ' ' : $under, $cursorStyle);
            }
        }

        $this->drawEditorScrollbars($screen, $bx, $by, $bw, $bh, $n, $vScroll, $longest, $hScroll);

        // Publish geometry so mouse click/drag can place & extend the caret (§4) and
        // so the title bar / resize grip can be hit-tested (§9).
        $state->editFieldRegion = [
            'top' => $by, 'left' => $bx, 'width' => $bw, 'rows' => $bh,
            'vScroll' => $vScroll, 'curRow' => $curRow, 'curHScroll' => $hScroll,
            'hUniform' => true,
        ];
        $state->editorFrameRect = [$bx - 2, $by - 3, $bw + 4, $bh + 6];

        return [$bx, $by, $bw, $bh];
    }

    /**
     * Scrollbars for the framed editor (§9), same glyphs as the data view's: a
     * vertical track on the right border when the text is taller than the body, a
     * horizontal one on the rule below the body when a line is wider than it. Also
     * paints the resize grip in the bottom-right corner.
     */
    private function drawEditorScrollbars(Screen $screen, int $bx, int $by, int $bw, int $bh, int $totalRows, int $vScroll, int $longest, int $hScroll): void
    {
        $track = $this->theme->style('scrollbar');
        $thumbStyle = $this->theme->style('scrollbar_thumb');

        if ($totalRows > $bh && $bh >= 2) {
            $x = $bx + $bw + 1; // the box's right border
            [$thumb, $pos] = $this->scrollThumb($bh, $bh, $totalRows, $vScroll);
            for ($i = 0; $i < $bh; $i++) {
                $on = $i >= $pos && $i < $pos + $thumb;
                $screen->put($x, $by + $i, $on ? '█' : '░', $on ? $thumbStyle : $track);
            }
        }

        if ($longest > $bw && $bw >= 2) {
            $y = $by + $bh; // the ├───┤ rule under the body
            [$thumb, $pos] = $this->scrollThumb($bw, $bw, $longest, $hScroll);
            for ($i = 0; $i < $bw; $i++) {
                $on = $i >= $pos && $i < $pos + $thumb;
                $screen->put($bx + $i, $y, $on ? '█' : '░', $on ? $thumbStyle : $track);
            }
        }

        // Resize grip: the bottom-right corner of the box, replacing the '┘'.
        $screen->put($bx + $bw + 1, $by + $bh + 2, '◢', $this->theme->style('popup_border'));
    }

    /**
     * Draw a bordered overlay: a centered title over a rule, a $bodyH-row body area
     * the caller fills, then a rule and a centered footer hint. Positioned at
     * ($atX,$atY), or centered when either is negative; always clamped fully on
     * screen. Returns the body's text rect [x, y, w, h] (§9).
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function drawOverlayFrame(Screen $screen, int $w, int $h, string $title, string $footer, int $innerW, int $bodyH, int $atX = -1, int $atY = -1): array
    {
        $innerW = max(24, min($innerW, $w - 6));
        $bodyH = max(1, min($bodyH, max(1, $h - 8)));
        $boxW = $innerW + 4;
        $boxH = $bodyH + 6; // top border, title, rule, body, rule, footer, bottom border
        $x = $atX < 0 ? intdiv($w - $boxW, 2) : $atX;
        $y = $atY < 0 ? intdiv($h - $boxH, 2) : $atY;
        $x = max(0, min($x, max(0, $w - $boxW)));
        $y = max(1, min($y, max(1, $h - $boxH)));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $barStyle = $this->theme->style('popup_title');

        // Top border, centered title, rule.
        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $this->putBarLine($screen, $x, $y + 1, $boxW, $innerW, $title, $border, $barStyle);
        $screen->put($x, $y + 2, '├' . str_repeat('─', $boxW - 2) . '┤', $border);

        // Body: side borders + blank interior for the caller to overwrite.
        for ($i = 0; $i < $bodyH; $i++) {
            $ry = $y + 3 + $i;
            $screen->put($x, $ry, '│', $border);
            $screen->put($x + $boxW - 1, $ry, '│', $border);
            $screen->put($x + 1, $ry, str_repeat(' ', $innerW + 2), $bg);
        }

        // Rule, centered footer hint, bottom border.
        $ruleY = $y + 3 + $bodyH;
        $screen->put($x, $ruleY, '├' . str_repeat('─', $boxW - 2) . '┤', $border);
        $this->putBarLine($screen, $x, $ruleY + 1, $boxW, $innerW, $footer, $border, $barStyle);
        $screen->put($x, $ruleY + 2, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        return [$x + 2, $y + 3, $innerW, $bodyH];
    }

    /** One bordered row with $text centered in $innerW, used for title/footer bars. */
    private function putBarLine(Screen $screen, int $x, int $y, int $boxW, int $innerW, string $text, Style $border, Style $style): void
    {
        $t = mb_strlen($text) > $innerW ? mb_substr($text, 0, $innerW) : $text;
        $padL = max(0, intdiv($innerW - mb_strlen($t), 2));
        $screen->put($x, $y, '│', $border);
        $screen->put($x + $boxW - 1, $y, '│', $border);
        $screen->put($x + 1, $y, ' ' . $this->pad(str_repeat(' ', $padL) . $t, $innerW) . ' ', $style);
    }

    /**
     * Centered message dialog. The last line is highlighted when more than one
     * line is given (the confirm options row). Reused by the confirm modal and
     * the PK-missing popup (§5/§9/§18).
     *
     * @param list<string> $lines
     * @return array{0:int,1:int,2:int,3:int} the box rect [x, y, w, h]
     */
    private function drawDialog(Screen $screen, int $w, int $h, array $lines): array
    {
        $innerW = 20;
        foreach ($lines as $l) {
            $innerW = max($innerW, mb_strlen($l));
        }
        $innerW = min($innerW, max(10, $w - 6));
        $boxW = $innerW + 4;
        $boxH = count($lines) + 2;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        foreach ($lines as $i => $line) {
            $row = $y + 1 + $i;
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            $style = (count($lines) > 1 && $i === count($lines) - 1)
                ? $this->theme->style('popup_title')
                : $bg;
            $screen->put($x + 1, $row, ' ' . $this->pad($line, $innerW) . ' ', $style);
        }
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        return [$x, $y, $boxW, $boxH];
    }

    /**
     * The `word?` DD field-hint popup (§11). With a title it shows the
     * "table.field" heading **centered** over a separator rule, then the wrapped
     * comment below so the comment reads clearly; with no title (the error cases)
     * it's just the message. Returns the box rect [x, y, w, h] for dismissal.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function drawDdHint(Screen $screen, State $state, int $w, int $h): array
    {
        return $this->drawTitledTextBox($screen, $w, $h, $state->ddHintTitle, $state->ddHintMessage);
    }

    /**
     * Centered box with an optional centered title over a rule, then word-wrapped
     * body text in the `dd_comment` colour box (plain bg when untitled). Shared by
     * the `word?` hint and the read-only foreign-keys view (§11).
     *
     * @return array{0:int,1:int,2:int,3:int} the box rect [x, y, w, h]
     */
    private function drawTitledTextBox(Screen $screen, int $w, int $h, string $title, string $message): array
    {
        $wrapW = min(60, max(20, $w - 8));
        $lines = explode("\n", wordwrap($message, $wrapW, "\n", true));
        $hasTitle = $title !== '';

        $innerW = 20;
        foreach ($lines as $l) {
            $innerW = max($innerW, mb_strlen($l));
        }
        if ($hasTitle) {
            $innerW = max($innerW, mb_strlen($title));
        }
        $innerW = min($innerW, max(10, $w - 6));
        $boxW = $innerW + 4;
        $boxH = count($lines) + 2 + ($hasTitle ? 2 : 0); // borders [+ title + rule]
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $row = $y + 1;
        if ($hasTitle) {
            $t = mb_strlen($title) > $innerW ? mb_substr($title, 0, $innerW) : $title;
            $padL = max(0, intdiv($innerW - mb_strlen($t), 2));
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            $screen->put($x + 1, $row, ' ' . $this->pad(str_repeat(' ', $padL) . $t, $innerW) . ' ', $this->theme->style('popup_title'));
            $row++;
            $screen->put($x, $row, '├' . str_repeat('─', $boxW - 2) . '┤', $border);
            $row++;
        }
        // Real comments get a distinct coloured box so they're easy to spot; the
        // error/no-title cases keep the plain popup background.
        $bodyStyle = $hasTitle ? $this->theme->style('dd_comment') : $bg;
        foreach ($lines as $line) {
            $screen->put($x, $row, '│', $border);
            $screen->put($x + $boxW - 1, $row, '│', $border);
            $screen->put($x + 1, $row, ' ' . $this->pad($line, $innerW) . ' ', $bodyStyle);
            $row++;
        }
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);

        return [$x, $y, $boxW, $boxH];
    }

    /** Read-only foreign-keys view popup inside the DD viewer (§11). */
    private function drawDdFkView(Screen $screen, State $state, int $w, int $h): void
    {
        $this->drawTitledTextBox(
            $screen,
            $w,
            $h,
            'Foreign Keys — ' . $state->ddCurrentTable,
            $state->ddFkText !== '' ? $state->ddFkText : '(none defined)',
        );
    }

    // --- theme editor (§13) -------------------------------------------------

    /**
     * The theme editor: a modal panel listing the 16 Base16 slots as two columns
     * of eight, over a strip that exercises the element tokens.
     *
     * Deliberately *not* a full-screen mode. The App re-themes the Renderer on
     * every keystroke, so the header, grid, borders and status line drawn behind
     * this panel are the real UI in the edited palette — the preview strip only
     * covers tokens the live screen may not currently be showing (badges, JSON
     * tints, a selected cell with no result loaded).
     */
    private function drawThemeEditor(Screen $screen, State $state, int $w, int $h): void
    {
        $name = $state->themeName !== '' ? $state->themeName : 'untitled';
        $title = ' Theme: ' . $name . ($state->themeDirty ? ' •' : '') . ' ';
        // Kept under 74 chars so it survives an 80-column terminal intact. The
        // active channel isn't named here — the highlighted hex pair shows it.
        $footer = '↑↓ slot · ←→ ±1 · shift ±16 · Tab RGB · e hex · l load · s save · Esc';

        // Wide enough that both columns keep their role text readable; the frame
        // clamps to the screen, so a narrow terminal just truncates the roles.
        [$bx, $by, $bw, $bh] = $this->drawOverlayFrame($screen, $w, $h, $title, $footer, 92, 12);

        $bg = $this->theme->style('popup');
        $colW = intdiv($bw - 1, 2);
        $slots = Scheme::SLOTS;

        // Two columns of eight: base00-07 left, base08-0F right.
        for ($i = 0; $i < 16; $i++) {
            $row = $i % 8;
            if ($row >= $bh) {
                break;
            }
            $x = $bx + ($i < 8 ? 0 : $colW + 1);
            $this->drawThemeSlot($screen, $state, $x, $by + $row, $colW, $slots[$i], $i);
        }

        // Token strip: the elements a live screen might not be showing right now.
        $stripY = $by + 9;
        if ($stripY + 1 < $by + $bh) {
            $screen->put($bx, $stripY - 1, str_repeat('─', $bw), $this->theme->style('grid_rule'));
            $this->drawTokenStrip($screen, $bx, $stripY, $bw, [
                [' CLI ', 'header_tag_cli'], [' DATA ', 'header_tag_data'], ['[note]', 'header_comment'],
                [' db.table ', 'header_db'],
                [' copied ', 'header_flash'], ['column', 'grid_header'], ['row', 'row_even'],
                [' selected ', 'selected_cell'], [' row ', 'selected_row'], ['NULL', 'cell_null'],
                ['pending', 'cell_pending'], [' edit ', 'cell_edit'],
            ]);
            $this->drawTokenStrip($screen, $bx, $stripY + 1, $bw, [
                ['Δ', 'dd_badge_diff'], ['✗', 'dd_badge_orphan'], ['⊕', 'dd_badge_new'],
                ['"key"', 'json_key'], ['"string"', 'json_string'], ['42', 'json_number'],
                ['true', 'json_bool'], ['null', 'json_null'], ['status', 'status'],
                ['error', 'status_error'], [' RO ', 'status_ro'], [' L ', 'layout_indicator'],
            ]);
        }

        // Footer note: saved path, error, or the pending-overwrite prompt.
        $msgY = $by + $bh - 1;
        if ($state->themeMessage !== '') {
            $style = $state->themeMessageError ? $this->theme->style('status_error') : $bg;
            $screen->put($bx, $msgY, $this->pad($state->themeMessage, $bw), $style);
        }

        // Sub-modals, drawn over the panel.
        if ($state->themeHexEdit && $state->themeHexEditor !== null) {
            $this->drawTextPrompt($screen, $state, $w, $h, ' Hex for ' . $slots[$state->themeSel] . ' ', $state->themeHexEditor, 'Enter: apply · Esc: cancel');
        } elseif ($state->themeSaveAs && $state->themeSaveEditor !== null) {
            $hint = $state->themeOverwriteArmed
                ? 'File exists — Enter again to overwrite · Esc: cancel'
                : 'Enter: save · Esc: cancel';
            $this->drawTextPrompt($screen, $state, $w, $h, ' Save theme as ', $state->themeSaveEditor, $hint);
        } elseif ($state->themePicker) {
            $this->drawThemePicker($screen, $state, $w, $h);
        }
    }

    /**
     * One slot row: selection marker, slot name, a background swatch of the
     * actual colour, the hex with the active R/G/B pair highlighted, and what
     * the slot drives in the UI.
     */
    private function drawThemeSlot(Screen $screen, State $state, int $x, int $y, int $w, string $slot, int $idx): void
    {
        $bg = $this->theme->style('popup');
        $selected = $idx === $state->themeSel;
        $hex = $state->themeColors[$slot] ?? '';

        $screen->put($x, $y, $selected ? '▸' : ' ', $selected ? $this->theme->style('popup_title') : $bg);
        $screen->put($x + 1, $y, $slot, $selected ? $this->theme->style('popup_selected') : $bg);
        // A background swatch reads truer than a glyph in the colour: this is
        // exactly how the slot will look behind text.
        $screen->put($x + 8, $y, '    ', new Style(bg: $hex));

        // Hex, with the channel ←/→ is steering picked out so the mode is visible.
        $digits = ltrim($hex, '#');
        $hx = $x + 13;
        $screen->put($hx, $y, '#', $bg);
        for ($c = 0; $c < 3; $c++) {
            $pair = mb_substr($digits, $c * 2, 2);
            $style = ($selected && $c === $state->themeChannel) ? $this->theme->style('popup_selected') : $bg;
            $screen->put($hx + 1 + $c * 2, $y, $pair === '' ? '??' : $pair, $style);
        }

        $roleX = $x + 21;
        $roleW = $w - ($roleX - $x) - 1;
        if ($roleW > 0) {
            $screen->put($roleX, $y, $this->pad(Theme::SLOT_ROLES[$slot] ?? '', $roleW), $this->theme->style('status'));
        }
    }

    /**
     * Lay out a row of styled chips left to right with a single-space gap,
     * clipping whole chips at the right edge (a half-drawn chip reads as a bug).
     *
     * @param list<array{0:string,1:string}> $chips [text, token]
     */
    private function drawTokenStrip(Screen $screen, int $x, int $y, int $w, array $chips): void
    {
        $col = $x;
        foreach ($chips as [$text, $token]) {
            $len = mb_strlen($text);
            if ($col + $len > $x + $w) {
                break;
            }
            $screen->put($col, $y, $text, $this->theme->style($token));
            $col += $len + 1;
        }
    }

    /** A small centred single-line entry box (hex / save-as) over the panel. */
    /**
     * A centred one-line text prompt: title, an editable field, a hint. Shared
     * by the theme editor's hex/save-as entries and the header-comment prompt —
     * three questions with the same shape, so they get the same box.
     */
    private function drawTextPrompt(Screen $screen, State $state, int $w, int $h, string $title, LineEditor $editor, string $hint): void
    {
        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $boxW = min($w - 2, 54);
        $boxH = 5;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $title, $this->theme->style('popup_title'));
        for ($i = 1; $i <= 3; $i++) {
            $screen->put($x, $y + $i, '│', $border);
            $screen->put($x + $boxW - 1, $y + $i, '│', $border);
            $screen->fill($x + 1, $y + $i, $boxW - 2, ' ', $bg);
        }

        $textX = $x + 2;
        $avail = $boxW - 4;
        $text = $editor->text();
        $cursor = $editor->cursor();
        $scroll = $cursor > $avail - 1 ? $cursor - ($avail - 1) : 0;
        $screen->put($textX, $y + 1, mb_substr($text, $scroll, $avail), $this->theme->style('input_text'));
        $this->drawFieldSelection($screen, $editor, 0, $textX, $y + 1, $scroll, $avail, $this->theme->style('input_selection'));
        $under = mb_substr($text, $cursor, 1);
        $screen->put($textX + ($cursor - $scroll), $y + 1, $under === '' ? ' ' : $under, $this->theme->style('cursor'));
        $state->editFieldRegion = ['top' => $y + 1, 'left' => $textX, 'width' => $avail, 'rows' => 1, 'vScroll' => 0, 'curRow' => 0, 'curHScroll' => $scroll];

        $screen->put($textX, $y + 3, $this->truncate($hint, $avail), $bg);
        $screen->put($x, $y + 4, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
    }

    /**
     * A list of every scheme discoverable on the search path. Serves both the
     * editor's `l` (load a starting palette) and the standalone Select Theme
     * picker, which differ only in wording.
     */
    private function drawThemePicker(Screen $screen, State $state, int $w, int $h, string $title = ' Load scheme ', string $hint = 'Enter: load · Esc: cancel'): void
    {
        $items = $state->themePickerItems;
        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $boxW = min($w - 2, 44);
        $listRows = max(1, min(max(1, count($items)), max(3, $h - 10)));
        $boxH = $listRows + 4;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $title, $this->theme->style('popup_title'));
        for ($i = 1; $i < $boxH - 1; $i++) {
            $screen->put($x, $y + $i, '│', $border);
            $screen->put($x + $boxW - 1, $y + $i, '│', $border);
            $screen->fill($x + 1, $y + $i, $boxW - 2, ' ', $bg);
        }
        if ($items === []) {
            $screen->put($x + 2, $y + 1, $this->truncate('No scheme files found.', $boxW - 4), $this->theme->style('status_error'));
        } else {
            $this->drawDdList($screen, $x + 2, $y + 1, $boxW - 4, $y + $listRows, $items, $state->themePickerSel, true, $state->themePickerOffset);
        }
        $screen->put($x + 2, $y + $boxH - 2, $this->truncate($hint, $boxW - 4), $bg);
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
    }

    /**
     * The generic single-select chooser (§16): a titled list of options — used
     * for picking where to create a `.sqli/` config and the first-save "where
     * should sqli save its state?" prompt. Wider than the theme picker because
     * the items are directory paths.
     */
    private function drawChooser(Screen $screen, State $state, int $w, int $h): void
    {
        $items = $state->chooserItems;
        $border = $this->theme->style('popup_border');
        $bg = $this->theme->style('popup');
        $widest = mb_strlen($state->chooserTitle) + 4;
        foreach ($items as $it) {
            $widest = max($widest, mb_strlen($it) + 6);
        }
        $widest = max($widest, mb_strlen($state->chooserHint) + 4);
        $boxW = min($w - 2, max(44, $widest));
        $listRows = max(1, min(max(1, count($items)), max(3, $h - 10)));
        $boxH = $listRows + 4;
        $x = max(0, intdiv($w - $boxW, 2));
        $y = max(1, intdiv($h - $boxH, 2));

        $screen->put($x, $y, '┌' . str_repeat('─', $boxW - 2) . '┐', $border);
        $screen->put($x + 2, $y, $state->chooserTitle, $this->theme->style('popup_title'));
        for ($i = 1; $i < $boxH - 1; $i++) {
            $screen->put($x, $y + $i, '│', $border);
            $screen->put($x + $boxW - 1, $y + $i, '│', $border);
            $screen->fill($x + 1, $y + $i, $boxW - 2, ' ', $bg);
        }
        $this->drawDdList($screen, $x + 2, $y + 1, $boxW - 4, $y + $listRows, $items, $state->chooserSel, true, $state->chooserOffset);
        $screen->put($x + 2, $y + $boxH - 2, $this->truncate($state->chooserHint, $boxW - 4), $bg);
        $screen->put($x, $y + $boxH - 1, '└' . str_repeat('─', $boxW - 2) . '┘', $border);
    }

    /**
     * A macro's name as the viewer's list writes it: `ganl (1)` when it takes
     * arguments, the bare name when it does not, and `·prod` after either when
     * the definition came from an `[env:…]` section (§23).
     *
     * The env marker is on the row rather than in a column of its own because
     * it is the exception: most macros are the same everywhere, and the ones
     * that are not are exactly the ones worth a mark as the list is scanned.
     *
     * @param array{name:string,value:string,args?:int,env?:?string} $row
     */
    private static function macroLabel(array $row): string
    {
        $n = (int) ($row['args'] ?? 0);
        $label = $n > 0 ? $row['name'] . ' (' . $n . ')' : $row['name'];
        $env = $row['env'] ?? null;
        return $env !== null && $env !== '' ? $label . ' ·' . $env : $label;
    }

    /**
     * How the selected macro is called: `?ganl <arg1>`. The same shape the
     * refusal message uses when too few arguments are given, so what the viewer
     * shows and what the error asks for are one sentence in two places.
     *
     * @param array{name:string,value:string,args?:int,env?:?string} $row
     */
    private static function macroCall(array $row): string
    {
        $call = '?' . $row['name'];
        for ($i = 1; $i <= (int) ($row['args'] ?? 0); $i++) {
            $call .= ' <arg' . $i . '>';
        }
        $env = $row['env'] ?? null;
        return $env !== null && $env !== '' ? $call . '  [env:' . $env . ']' : $call;
    }

    /**
     * The read-only macros viewer (§23): where the definitions came from,
     * the name→value list, and the selected value in full underneath.
     *
     * A macro that takes arguments carries its arity in the list — `ganl (1)`
     * — and its call shape in the detail heading. How many words a macro
     * wants is not something its value makes obvious at a glance, and it is
     * exactly what a refusal at submit time would be about.
     *
     * The detail strip is the reason this is not just a list. A value may be a
     * whole WHERE clause or a whole statement, and the one-line preview in the
     * list has to truncate it — so the question the viewer exists to answer
     * ("what will `?recent` actually put on my line?") would go unanswered
     * for exactly the macros worth asking it about.
     */
    private function drawMacrosView(Screen $screen, State $state, int $w, int $h): void
    {
        $rows = $state->macrosRows;
        $n = count($rows);

        $nameW = 4;
        foreach ($rows as $r) {
            $nameW = max($nameW, mb_strlen(self::macroLabel($r)));
        }
        $nameW = min($nameW, 30);

        $title = ' Macros' . ($n > 0 ? " ($n)" : '') . ' ';
        $footer = '↑↓ move · r: reload from disk · Esc: close';
        $innerW = min(max(58, $nameW + 42), max(30, $w - 6));
        $wantList = max(1, min(max($n, 1), max(3, $h - 14)));

        // The env line is a second head line, and only exists when some layer
        // uses `[env:…]` sections (§23) — a file that scopes nothing does not
        // pay a row for saying so.
        $headH = $state->macrosScope !== '' ? 2 : 1;

        [$bx, $by, $innerW, $bodyH] = $this->drawOverlayFrame(
            $screen,
            $w,
            $h,
            $title,
            $footer,
            $innerW,
            // source line (+ env line) + rule + list + rule + detail
            $headH + 2 + $wantList + ($h >= 20 ? 3 : 1),
        );

        // Re-derive the split from the height the frame actually granted — it
        // clamps to the terminal, so a short window must still leave a usable
        // list rather than a negative one.
        $detailH = $bodyH >= 9 ? 3 : 1;
        $listH = max(1, $bodyH - $headH - 2 - $detailH);

        $dim = $this->theme->style('macro_source');
        $nameStyle = $this->theme->style('macro_name');
        $valStyle = $this->theme->style('macro_value');
        $selStyle = $this->theme->style('popup_selected');

        // Line 1: where these came from, or what stopped them coming.
        $problem = $state->macrosProblem;
        $screen->put(
            $bx,
            $by,
            $this->pad(
                $problem !== '' ? $problem : self::elideMiddle($state->macrosSource, $innerW),
                $innerW,
            ),
            $problem !== '' ? $this->theme->style('status_error') : $dim,
        );
        if ($headH === 2) {
            $screen->put(
                $bx,
                $by + 1,
                $this->pad($this->truncate($state->macrosScope, $innerW), $innerW),
                $dim,
            );
        }
        $screen->put($bx, $by + $headH, str_repeat('─', $innerW), $dim);

        $listTop = $by + $headH + 1;
        if ($n === 0) {
            // The line above already names the file — this only has to say that
            // it was read and had nothing in it.
            $screen->put($bx, $listTop, $this->pad('No macros defined yet.', $innerW), $dim);
        } else {
            // Ensure-visible scrolling, same rule as the DD lists: the cursor
            // walks inside the page and the page moves only at its edges (§11).
            $sel = max(0, min($state->macrosSel, $n - 1));
            if ($sel < $state->macrosOffset) {
                $state->macrosOffset = $sel;
            } elseif ($sel >= $state->macrosOffset + $listH) {
                $state->macrosOffset = $sel - $listH + 1;
            }
            $state->macrosOffset = max(0, min($state->macrosOffset, max(0, $n - $listH)));

            for ($i = 0; $i < $listH; $i++) {
                $idx = $state->macrosOffset + $i;
                if ($idx >= $n) {
                    break;
                }
                $y = $listTop + $i;
                if ($idx === $sel) {
                    // The selected row is one highlight across both columns, so
                    // it reads as a row rather than as two tinted fragments.
                    $screen->put(
                        $bx,
                        $y,
                        $this->pad(
                            $this->pad(self::macroLabel($rows[$idx]), $nameW) . '  ' . $rows[$idx]['value'],
                            $innerW,
                        ),
                        $selStyle,
                    );
                    continue;
                }
                $screen->put($bx, $y, $this->pad(self::macroLabel($rows[$idx]), $nameW), $nameStyle);
                $screen->put(
                    $bx + $nameW,
                    $y,
                    $this->pad('  ' . $rows[$idx]['value'], max(1, $innerW - $nameW)),
                    $valStyle,
                );
            }
        }

        // The rule doubles as the detail heading, so the selected name is stated
        // once rather than repeated above a second copy of itself.
        $ruleY = $listTop + $listH;
        $sel = $n > 0 ? max(0, min($state->macrosSel, $n - 1)) : -1;
        $heading = $sel >= 0 ? '── ' . self::macroCall($rows[$sel]) . ' ' : '';
        $screen->put($bx, $ruleY, $heading, $dim);
        $screen->put(
            $bx + mb_strlen($heading),
            $ruleY,
            str_repeat('─', max(0, $innerW - mb_strlen($heading))),
            $dim,
        );

        if ($sel < 0) {
            return;
        }
        $lines = explode("\n", wordwrap(
            str_replace(["\r\n", "\r"], "\n", $rows[$sel]['value']),
            max(8, $innerW),
            "\n",
            true,
        ));
        for ($i = 0; $i < $detailH; $i++) {
            $text = $lines[$i] ?? '';
            // A value taller than the strip says so on its last line rather than
            // ending mid-word with no sign that there is more.
            if ($i === $detailH - 1 && count($lines) > $detailH) {
                $text = $this->truncate($text, max(1, $innerW - 2)) . ' …';
            }
            $screen->put($bx, $ruleY + 1 + $i, $this->pad($text, $innerW), $valStyle);
        }
    }

    private function looksJson(string $value): bool
    {
        $t = trim($value);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
            return false;
        }
        json_decode($t);
        return json_last_error() === JSON_ERROR_NONE;
    }

    // --- text helpers ------------------------------------------------------

    private function pad(string $text, int $width): string
    {
        $text = str_replace(["\n", "\r", "\t"], ' ', $text);
        if (mb_strlen($text) > self::CELL_TRUNCATE) {
            $text = mb_substr($text, 0, self::CELL_TRUNCATE);
        }
        if (mb_strlen($text) > $width) {
            return $width > 1 ? mb_substr($text, 0, $width - 1) . '…' : mb_substr($text, 0, $width);
        }
        return $text . str_repeat(' ', $width - mb_strlen($text));
    }

    /**
     * Shorten to `$max` by removing the middle, keeping both ends.
     *
     * For identifiers whose two ends carry the meaning and whose middle is
     * boilerplate — a connection label being the case in point. Falls back to
     * plain end-truncation when there is not enough room to keep two halves.
     */
    public static function elideMiddle(string $text, int $max): string
    {
        $len = mb_strlen($text);
        if ($max <= 0 || $len <= $max) {
            return $text;
        }
        if ($max < 8) {
            return mb_substr($text, 0, max(0, $max - 1)) . '…';
        }
        // Bias to the tail: it holds `:port/database`, which changes more often
        // than the host does and is what a mis-targeted query gets noticed by.
        $keepTail = intdiv($max - 1, 2);
        $keepHead = $max - 1 - $keepTail;
        return mb_substr($text, 0, $keepHead) . '…' . mb_substr($text, $len - $keepTail);
    }

    private function truncate(string $text, int $width): string
    {
        $text = str_replace(["\n", "\r", "\t"], ' ', $text);
        if ($width <= 0) {
            return '';
        }
        return mb_strlen($text) > $width ? mb_substr($text, 0, $width - 1) . '…' : $text;
    }
}
