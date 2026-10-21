<?php

declare(strict_types=1);

namespace Sqli\Core;

use Sqli\Db\QueryResult;
use Sqli\Input\LineEditor;

/**
 * Mutable application state shared between the event loop and the renderer.
 *
 * Deliberately a plain data holder: behavior lives in App and the views, not
 * here. The few methods present are pure view-model derivations (visible columns
 * after hiding, display row order after sorting) that both App and Renderer need
 * to agree on.
 */
final class State
{
    public const FOCUS_CLI = 'cli';
    public const FOCUS_DATA = 'data';

    /**
     * Number of output data views (§20). One connection, one CLI, nine grids:
     * Alt+1…Alt+9 switch between them and `]1`…`]9` (or `[N`, `\N`) terminates a
     * submission into one. Only ever one is on screen, which is why this costs a
     * tab strip and a property swap rather than the pane/clipping machinery a
     * split view needs.
     *
     * **Nine is the ceiling this constant can safely hold.** Two call sites
     * interpolate it into a single-character context — the `[1-N]` regex class in
     * {@see App::terminatorPattern()}, and the `char <= (string) TAB_COUNT`
     * guard in {@see App::handleKey()}. At 10 the class `[1-10]` silently means
     * "1 or 0" and the `\2`…`\9` suffixes stop terminating at all. Going past
     * nine means replacing those with an explicit digit map, not just editing
     * this number.
     */
    public const TAB_COUNT = 9;

    /**
     * The per-view properties swapped on a tab switch — the ones that must
     * survive leaving a tab and coming back to it (§20).
     *
     * Everything NOT named here is deliberately global: modals, popups, the
     * theme editor, the DD viewer, history, focus and connection identity all
     * belong to the app, not to a grid. `sortedOrder` is private, which is why
     * capture/restore live on State rather than in App.
     *
     * Getting this list wrong is the one real failure mode of the whole feature
     * — a missing entry leaks one view's setting into another — so
     * tests/tabs_test.php pins it against the class's own property list.
     *
     * @var list<string>
     */
    public const VIEW_PROPS = [
        // The result itself and what produced it
        'result', 'lastSql', 'lastTyped', 'currentTable',
        // Selection and scroll
        'selectedRow', 'selectedCol', 'rowOffset', 'colOffset', 'rangeRow', 'rangeCol',
        // Layout customization (§6)
        'hiddenCols', 'colWidthOverride', 'colOrder', 'layoutFields', 'layoutUnsaved', 'colAlpha', 'colFormats', 'ddColFormats',
        'sortCol', 'sortDir', 'sortedOrder',
        // Output mode (§5/§8)
        'vertical', 'verticalScroll', 'detail', 'detailFieldIdx', 'detailVisibleFields',
        // Un-run cell edits (§9)
        'pending',
        // Rows marked for deletion and the mark-mode toggle (§22)
        'deleteMarks', 'markMode',
        // The status line describes *this* result, so it travels with it — as
        // does the row cap that shaped it (§5)
        'statusLine', 'statusIsError', 'autoLimitedAt',
    ];

    /**
     * Which data view is on screen, 0-based (§20). Global, not per-view — it is
     * the thing being switched, so it must never be part of {@see VIEW_PROPS}.
     */
    public int $activeTab = 0;

    /**
     * Tab strip model published by App: one entry per view, '' meaning the view
     * has never held a result and should draw as an empty tab.
     *
     * @var list<string>
     */
    public array $tabLabels = [];

    /**
     * Column popup menu: [label, action]; a null label is a separator (§6).
     *
     * @var list<array{0:?string,1:?string}>
     */
    public string $focus = self::FOCUS_CLI;

    /**
     * A short note the user pins to the header, drawn in brackets right after
     * the CLI/DATA tag and in its own colour — "which window is this?" answered
     * on the row that already answers "which server is this?".
     *
     * Seeded from `--comment` (so a config `envs` block can label a server PROD
     * once and for good) and editable live from the system menu. Session-scoped:
     * what is typed in the menu is not written back to the config file.
     *
     * Global, never per-view: it describes the connection/window, not a grid, so
     * it is deliberately absent from {@see VIEW_PROPS} (§20).
     */
    public string $comment = '';

    /** The system-menu "Header Comment" prompt (a one-line editor). */
    public bool $commentEdit = false;
    public ?LineEditor $commentEditor = null;

    // Transient notice shown centered on the top header line (e.g. clipboard
    // copy confirmation, §19). Visible while microtime() < $flashUntil.
    public string $flashMessage = '';
    public float $flashUntil = 0.0;

    // "Please wait" message shown while a known-slow synchronous op runs (e.g.
    // loading the Data Dictionary CSV). Non-empty = draw it, topmost. The App
    // paints one frame with this set, runs the op, then clears it.
    // By default it's drawn as a plain one-line notice just above the status/help
    // line (unobtrusive for routine queries). When $busyModal is set it's a
    // centered, bordered dialog instead — reserved for the initial DB connect,
    // which can block for the full TCP timeout and deserves the emphasis.
    public string $busyMessage = '';
    public bool $busyModal = false;

    // A user statement running asynchronously (§21), which is the only kind that
    // can be cancelled. Unlike $busyMessage — painted once and then frozen,
    // because the process is blocked inside the driver — the frame loop keeps
    // running while these are set, so the notice can count, spin, and be
    // answered with Esc.
    //
    // These are connection-wide, not per-view, and so are deliberately NOT in
    // VIEW_PROPS: one connection runs one statement at a time, and a query that
    // followed the user from tab to tab would be a query on a second connection
    // (§20). Alt+N is refused while one is in flight for exactly that reason.
    public bool $queryRunning = false;
    public float $queryStarted = 0.0;
    /** Cancel sent, waiting for the server to notice. The notice says so. */
    public bool $queryCancelling = false;

    /**
     * Whether this connection's driver can cancel a running query at all — set
     * once at startup from the Database.
     *
     * It gates what is *offered*, not just what works. On a build without
     * mysqli there is no clock, no spinner and no Esc hint, and F1 does not
     * list the binding: none of it is true there, and a key advertised in help
     * that does nothing is worse than one that was never mentioned.
     */
    public bool $canCancel = false;

    public ?QueryResult $result = null;
    public string $lastSql = '';
    /**
     * What the user typed to paint this view, as typed: the `?macro`, the
     * `SELECT FROM t …` form, no auto-LIMIT, terminator stripped, and a whole
     * multi-statement submission when it came from one. F5 puts it back on the
     * CLI. {@see $lastSql} is what the server was sent.
     */
    public string $lastTyped = '';
    public string $currentTable = '';

    // Result grid selection / scroll. selectedRow indexes the *display* order
    // (after sort); selectedCol indexes the *visible* columns (after hiding).
    public int $selectedRow = 0;   // -1 = header row selected
    public int $selectedCol = 0;
    public int $rowOffset = 0;
    public int $colOffset = 0;

    /**
     * Shift+arrow cell range in the grid (§19): the corner the range was
     * started from, or null when there is no range. The other corner is always
     * the cursor (selectedRow/selectedCol), so moving back toward the anchor
     * shrinks the range. Row -1 is the header row: a range that reaches it
     * copies the field names as its first line.
     */
    public ?int $rangeRow = null;
    public ?int $rangeCol = null;

    /**
     * Ctrl+Alt+F field filter (§6): while non-null, the grid shows only the
     * visible columns whose name contains this text (case-insensitive). A
     * modal of the grid, never saved and never part of a view: it ends on
     * Esc / Ctrl+Alt+F, before anything can switch tabs.
     */
    public ?string $colFilter = null;
    /** The last filter text refused for matching nothing, for the status line ('' = none). */
    public string $colFilterMiss = '';

    // Layout customization (§6), persisted per db+table by App.
    /** @var list<string> hidden column names */
    public array $hiddenCols = [];
    /** @var array<string,int> column name => override width */
    public array $colWidthOverride = [];
    /**
     * The columns the user has placed, in display order — a **prefix** of the
     * display, persisted per db+table. Every column not named here is
     * "unmoved" and follows it in result order (or A–Z, {@see $colAlpha}).
     * [] = nothing placed. A move saves everything up to the moved column, so
     * whatever order stood before it (A–Z included) comes back next time.
     *
     * @var list<string>
     */
    public array $colOrder = [];

    /**
     * Which saved order and hidden set this view reads and writes (§6): `*`
     * for a query that names no columns, otherwise the columns the query
     * named, in its order. Widths and formats are per table and ignore it.
     */
    public string $layoutFields = '*';

    /**
     * True when this view's layout is never written to disk (§6): the query
     * mixed a bare `*` with named columns (`SELECT id, *`), or is sqli
     * re-running such a view. Widths, order, hidden columns and formats
     * changed here last only as long as the result does. Saved table-wide
     * widths and formats still apply on the way in; a saved order and hidden
     * set do not, since this field list never has one on file.
     */
    public bool $layoutUnsaved = false;

    /**
     * Ctrl+Alt+S (§6): show the unmoved columns — the ones after
     * {@see $colOrder} — A–Z instead of in result order. Session-only, never
     * persisted; a move is what saves an order.
     */
    public bool $colAlpha = false;
    /**
     * Manual per-column format overrides (from the column popup, saved as Layout
     * Config). A value of '' means the user explicitly turned a DD-suggested
     * format *off* for that column — distinct from having no entry (§6, backlog S).
     * @var array<string,string> column name => display format id ('' = off)
     */
    public array $colFormats = [];
    /**
     * DD-declared default formats for the current table's columns (§6/§11), from
     * the DD `Format` column. Applied automatically; a {@see $colFormats} entry
     * for the same column overrides this.
     * @var array<string,string> column name => display format id
     */
    public array $ddColFormats = [];
    public ?string $sortCol = null;
    public string $sortDir = 'asc';

    // Interactive "change width" mode (§6): Up/Down resize the selected column.
    public bool $widthAdjust = false;
    // Interactive "move column" mode (§6): arrows relocate the selected column.
    public bool $moveMode = false;
    /** @var list<int>|null cached display order (raw row indices); null = natural */
    private ?array $sortedOrder = null;

    // Detail view (§8).
    public bool $detail = false;
    public int $detailFieldIdx = 0;
    /** Visible field rows the detail view drew last frame, for full-page paging. */
    public int $detailVisibleFields = 0;
    /**
     * `S` in the detail view: list the fields A–Z instead of in result order.
     * A preference, not part of a view, so it is deliberately absent from
     * VIEW_PROPS and holds across tabs and re-opens for the session.
     */
    public bool $detailSorted = false;

    // Vertical output mode (\G, §5): line-scrolled, no edit/JSON/detail.
    public bool $vertical = false;
    public int $verticalScroll = 0;

    // Data View Configuration popup (§6) — the single grid-configuration menu,
    // opened with Ctrl+L or by clicking a column header. Its item list is
    // dynamic (a per-column block scoped to $popupCol, then the table-wide
    // resets, then one "Unhide: …"/format entry per hidden or formatted column),
    // built by App and published here so the renderer stays a pure reader.
    public bool $layoutPopup = false;
    public int $layoutItem = 0;
    /** The column the per-column half of the popup acts on (visible index). */
    public int $popupCol = 0;
    /** @var list<array{label:?string,action:?string,enabled:bool}> */
    public array $layoutPopupView = [];

    // System menu (§12), opened with `/`. Items + enabled-state are built by App
    // (some entries depend on subsystems not yet loaded); this just holds the
    // open flag and the highlighted row.
    public bool $systemMenu = false;
    public int $systemMenuItem = 0;
    /** File-based debug logging on/off (mirrors includes\my::$enabled). */
    public bool $debugLogging = true;
    /**
     * Menu model published by App for the renderer while the menu is open.
     * @var list<array{label:string,action:string,enabled:bool}>
     */
    public array $systemMenuView = [];

    /**
     * The quick keys of a keyboard menu (§6, §12). An item that names its own
     * `key` — `m` for Move column — keeps it. The rest are numbered in order:
     * `1`–`9`, then whatever of `a`–`z` no item has claimed. Separators (null
     * label) get none and are not counted, so the numbers run on across a
     * rule. One function for the renderer's `2) ` prefixes and App's key
     * handling, so the label and the key can never disagree.
     *
     * @param  list<array{label:?string,key?:string,...}> $items
     * @return array<int,string> item index => key
     */
    public static function menuQuickKeys(array $items): array
    {
        $own = [];
        foreach ($items as $i => $item) {
            if (($item['label'] ?? null) !== null && ($item['key'] ?? '') !== '') {
                $own[$i] = $item['key'];
            }
        }
        $pool = array_values(array_diff(
            array_merge(array_map('strval', range(1, 9)), range('a', 'z')),
            $own,
        ));
        $keys = [];
        $n = 0;
        foreach ($items as $i => $item) {
            if (($item['label'] ?? null) === null) {
                continue;
            }
            if (isset($own[$i])) {
                $keys[$i] = $own[$i];
            } elseif (isset($pool[$n])) {
                $keys[$i] = $pool[$n++];
            }
        }
        return $keys;
    }

    // Theme editor (§13), opened from the system menu. A modal panel over the
    // live UI: every keystroke rebuilds the Theme from $themeColors and hands it
    // to the Renderer, so the whole screen behind the panel recolours as you
    // edit. App owns the palette and the file I/O; this is the view model.
    public bool $themeEditor = false;
    public string $themeName = '';          // scheme being edited (title + save default)
    /** @var array<string,string> slot name ('base00'…) => '#rrggbb', the live palette */
    public array $themeColors = [];
    public int $themeSel = 0;               // selected slot, 0-15
    public int $themeChannel = 0;           // 0 = R, 1 = G, 2 = B — what ←/→ nudges
    public bool $themeDirty = false;        // unsaved edits (gates the Esc confirm)
    public bool $themeEscArmed = false;     // one Esc pressed with edits pending
    public string $themeMessage = '';       // footer note (saved path, error, prompt)
    public bool $themeMessageError = false;
    // Direct hex entry for the selected slot (`e` / Enter).
    public bool $themeHexEdit = false;
    public ?LineEditor $themeHexEditor = null;
    // Save-as name entry (`s`): prefilled with themeName, so Enter overwrites.
    public bool $themeSaveAs = false;
    public ?LineEditor $themeSaveEditor = null;
    public bool $themeOverwriteArmed = false; // target exists; Enter again confirms
    // Standalone "Select Theme" picker (system menu): just switch themes, no
    // editing. Shares the picker fields below; arrowing through it previews each
    // scheme on the live UI, Enter keeps it, Esc restores the one you had.
    public bool $themeSelect = false;
    // Load-a-scheme picker (`l`) inside the editor: start from any scheme.
    public bool $themePicker = false;
    /** @var list<string> */
    public array $themePickerItems = [];
    public int $themePickerSel = 0;
    public int $themePickerOffset = 0;

    // Generic single-select chooser popup (a titled list + Enter/Esc), used for
    // "Create Local sqli Config" (pick the directory) and the "where should
    // sqli save its state?" prompt when no `.sqli/` exists anywhere yet. The
    // purpose tag tells App what Enter means.
    public ?string $chooser = null;        // 'create_config' | 'state_dir'
    public string $chooserTitle = '';
    public string $chooserHint = '';
    /** @var list<string> display labels; App maps selection index to meaning */
    public array $chooserItems = [];
    public int $chooserSel = 0;
    public int $chooserOffset = 0;

    // Macros viewer (§23): a read-only look at what `?name` will expand
    // to, opened from the system menu. Read-only because the macro file is
    // edited outside sqli — `r` re-reads it from disk, and that is the only
    // thing in here that changes anything. App publishes the rows below each
    // time it opens or reloads; the renderer is a pure reader.
    public bool $macrosView = false;
    /** @var list<array{name:string,value:string,args:int}> name-sorted; args = arity */
    public array $macrosRows = [];
    public int $macrosSel = 0;
    public int $macrosOffset = 0;
    /** Where the macros came from / would come from, one line per layer. */
    public string $macrosSource = '';
    /**
     * The env line of the macros viewer (§23) — which environment this session
     * is, what it took from an `[env:…]` section and what it could not reach.
     * Empty when no layer uses env sections, so the row costs nothing to files
     * that do not have any.
     */
    public string $macrosScope = '';
    /** Load complaint (bad JSON, unusable name) or '' — drawn in the error colour. */
    public string $macrosProblem = '';

    // A `?{name}` question (§23): the CLI stops on Enter and asks for each
    // value the statement wants, then runs it. One line of input, so it is a
    // LineEditor like the header-comment prompt rather than anything larger;
    // the submission itself waits in App, since nothing here needs to know
    // what it is holding.
    public bool $promptAsk = false;
    public ?LineEditor $promptEditor = null;
    /** The name being asked for, and the framed title that shows it. */
    public string $promptName = '';
    public string $promptTitle = '';

    // Data Dictionary viewer (§11). Two panels (tables | fields), each with a
    // live filter. App publishes the filtered lists below each frame; the
    // renderer is a pure reader.
    public bool $ddViewer = false;
    public int $ddPanel = 0;            // 0 = tables panel, 1 = fields panel
    // One search box drives both panels: it matches table names, field names AND
    // comments, surfacing any table/field that matches (§11).
    public string $ddFilter = '';
    public int $ddFilterCur = 0;        // cursor within the search text
    public int $ddTableSel = 0;         // selection index into the filtered table list
    public int $ddFieldSel = 0;
    // Scroll offsets (top visible row) for each panel — persisted so the
    // selection moves within the window and only scrolls at the edges (§11).
    public int $ddTableOffset = 0;
    public int $ddFieldOffset = 0;
    // Visible rows per panel, published by the renderer; drives PageUp/Down.
    public int $ddTablePageRows = 0;
    public int $ddFieldPageRows = 0;
    // Published view model (filtered): table names + the selected table's fields.
    /** @var list<string> */
    public array $ddTables = [];
    /** @var list<array{name:string,type:string,comment:string,nullable:string,key:string,default:string}> */
    public array $ddFields = [];
    public string $ddCurrentTable = '';
    public string $ddDescription = '';
    // True while the DD file is held open by another program (e.g. Excel). App
    // polls this every few seconds; the renderer shows a banner and edits are
    // blocked until the file is freed (§11).
    public bool $ddLocked = false;
    // Schema-diff badges (§11). Computed lazily against the live schema and
    // published per frame; empty/false when no live connection so the viewer
    // still works fully offline.
    public bool $ddDiffReady = false;
    /** @var array<int,string> ddTables index => '' | 'diff' | 'orphan' */
    public array $ddTableBadges = [];
    /** @var array<string,string> lower-cased field name => 'ok' | 'changed' | 'extra' */
    public array $ddFieldStatus = [];
    /** @var list<string> live columns of the selected table not documented in the DD */
    public array $ddMissingFields = [];
    // Inline comment editor inside the DD viewer (Enter on a field, §11). Drawn
    // through the same framed-editor path as the grid's cell editor, so it gets the
    // same mouse selection + title-bar move + grip resize (§9/§11).
    public bool $ddCommentEdit = false;
    public ?LineEditor $ddCommentEditor = null;
    public string $ddCommentField = '';
    // User-set geometry for the DD comment box, kept separate from the cell editor's
    // so a drag in one context never resizes the other. -1 / 0 = auto (centred,
    // sized to content); the renderer clamps everything on screen. See cellEditX.
    public int $ddEditX = -1;
    public int $ddEditY = -1;
    public int $ddEditW = 0;      // inner text width
    public int $ddEditH = 0;      // body rows
    // Read-only foreign-keys view (Enter on the `--Foreign-Keys--` row, §11).
    // The FK summary isn't editable here, only viewable; any key dismisses it.
    public bool $ddFkView = false;
    public string $ddFkText = '';

    // Read-only single-field detail view (Ctrl+I / Tab in the DD viewer, §11) —
    // the DD analog of the data view's `i` detail: every column of the selected
    // field stacked as label:value in a popup. Any key dismisses it.
    public bool $ddRowView = false;
    public string $ddRowTitle = '';
    public string $ddRowText = '';

    // Undocumented live tables — tables the database has that the DD doesn't
    // document (§11). Published per frame alongside the badges; drives the
    // "N new table(s)" note on the viewer's title bar and the Ctrl+N add popup.
    // Empty when there's no live schema to compare against.
    /** @var list<string> live table names absent from the DD, sorted A-Z */
    public array $ddNewTables = [];
    public bool $ddNewView = false;     // the Ctrl+N add popup is open
    public int $ddNewSel = 0;           // selection index into ddNewTables
    public int $ddNewOffset = 0;        // first visible row (popup scroll)
    /** @var array<string,bool> table name => ticked for creation */
    public array $ddNewChecked = [];

    // Assign Data Dictionary picker (system menu, §11). Two stages: type a
    // directory (stage 0, LineEditor), then pick a .csv from it (stage 1, list).
    public bool $ddPicker = false;
    public int $ddPickerStage = 0;            // 0 = directory entry, 1 = file pick
    public ?LineEditor $ddPickerEditor = null;
    public string $ddPickerDir = '';          // confirmed directory (stage 1)
    /** @var list<string> .csv file names found in ddPickerDir */
    public array $ddPickerFiles = [];
    public int $ddPickerSel = 0;
    public int $ddPickerOffset = 0;           // top visible row of the file list
    public string $ddPickerMessage = '';      // error / hint shown in the box

    // Tab completion popup (§4).
    public bool $completion = false;
    public int $completionIdx = 0;
    /** @var list<string> */
    public array $completionItems = [];
    /**
     * A second, non-inserted column beside the items — what each macro expands
     * to (§23). Parallel to $completionItems, or empty when the proposal has
     * nothing to add (tables, columns, keywords: the name is the description).
     *
     * @var list<string>
     */
    public array $completionDetails = [];
    public int $completionStart = 0;
    public string $completionTitle = '';
    /** The partial token being typed; shown at the top of the popup and used to filter. */
    public string $completionFilter = '';

    // Cursor cell published by the renderer (anchors the completion popup).
    public int $inputCursorX = -1;
    public int $inputCursorY = -1;

    // CLI input region geometry published by the renderer each frame, so mouse
    // clicks/drags can be mapped back to a [line, column] in the buffer (§4).
    public int $cliTop = -1;          // first screen row of the input region
    public int $cliBottom = -1;       // last screen row of the input region
    public int $cliTextX = 0;         // column where line text starts (after the prompt)
    public int $cliLineStart = 0;     // buffer line index shown on cliTop
    public int $cliActiveScroll = 0;  // horizontal scroll applied to the active line

    // Drawn geometry of whichever modal edit field is currently open, so mouse
    // click/drag can place and extend the caret there just like the CLI (§4).
    // Republished by the renderer each frame (null when no such field is drawn).
    // Keys: top, left, width, rows, vScroll, curRow, curHScroll.
    // @var array{top:int,left:int,width:int,rows:int,vScroll:int,curRow:int,curHScroll:int}|null
    public ?array $editFieldRegion = null;

    // Inline cell edit + pending changes (§9).
    public bool $cellEdit = false;
    public ?LineEditor $cellEditor = null;
    public int $cellEditRow = -1;   // raw row index under edit
    public string $cellEditCol = '';

    // Cell inspector (§9): a NON-modal, movable popup showing one column's full
    // value (multi-line and all). Opened with `v`; it stays open while the grid
    // keeps its controls, and its content re-derives every frame from the pinned
    // column + the currently selected row (so scrolling rows updates it).
    public bool $cellView = false;
    public string $cellViewCol = '';   // pinned column NAME; row follows selection
    public int $cellViewScroll = 0;
    // `m` toggles a move mode where the arrow keys reposition the popup instead of
    // navigating the grid; Enter/Esc/m exit it (§9).
    public bool $cellMoveMode = false;
    // Top-left of the inspector; -1 = auto-centre until the user drags it (§9).
    public int $cellPopupX = -1;
    public int $cellPopupY = -1;
    // Whole-box rect [x,y,w,h] published by the renderer for mouse hit-testing.
    public ?array $cellPopupRect = null;
    // `e` inside the inspector edits the pinned cell; on save/cancel we return to
    // the inspector rather than closing outright.
    public bool $cellEditFromView = false;
    // editPopup routes the *quick* (Enter) editor to a centred box instead of the
    // cramped inline overlay when the value is multi-line or wider than its cell.
    public bool $cellEditPopup = false;
    // User-set geometry for that framed editor (§9): drag its title bar to move,
    // drag the bottom-right grip to resize. Kept across edits — once you have sized
    // the box for a wide column, the next cell in it should not undo that. -1 / 0 =
    // auto (centred, sized to content); the renderer clamps everything on screen.
    public int $cellEditX = -1;
    public int $cellEditY = -1;
    public int $cellEditW = 0;      // inner text width
    public int $cellEditH = 0;      // body rows
    /** @var array{0:int,1:int,2:int,3:int}|null whole-box rect [x,y,w,h] for hit-testing */
    public ?array $editorFrameRect = null;
    /**
     * rawRowIdx => (colName => newValue). A null value is a staged SQL NULL
     * (Ctrl+N, §9), so test with array_key_exists(), not `??`.
     *
     * @var array<int,array<string,?string>>
     */
    public array $pending = [];

    // Row deletion (§22). Rows marked for deletion, as a set of *raw* row indices
    // — raw, not display, so a re-sort cannot move a mark onto a different record.
    // A mark is visible in the grid (the row is tinted; in mark mode it also gets
    // a checkbox in the gutter) and is what the generated DELETE is built from.
    /** @var array<int,true> rawRowIdx => true */
    public array $deleteMarks = [];

    // Multi-row selection mode, entered with Ctrl+Del (§22). While on, the grid
    // grows a checkbox gutter and Enter/Space toggle the row under the cursor
    // instead of opening the cell editor. Del deletes everything marked.
    public bool $markMode = false;

    // JSON viewer / editor (§10). Opened with `j`/`J` (view) on a cell/detail
    // field, or by Enter-editing a cell whose value is a JSON document. The App
    // owns the JsonTree; it publishes the flattened, indentation-aware rows here
    // (node refs stripped) so the renderer stays a pure State reader.
    public bool $jsonView = false;
    public string $jsonColumn = '';     // column name shown in the title
    public int $jsonRow = -1;           // raw row index the value came from
    public int $jsonSel = 0;            // selected index into $jsonNodes
    public int $jsonOffset = 0;         // top visible row (scroll)
    public int $jsonPageRows = 0;       // visible rows, published by the renderer
    /** @var list<array{depth:int,label:string,value:string,type:string,container:bool,expanded:bool,childCount:int,editable:bool}> */
    public array $jsonNodes = [];
    /**
     * Breadcrumb of embedded documents opened with `j` from inside the viewer
     * (§10): one label per level below the cell, so the title can say which
     * document is on screen. Empty at the top level. The App owns the matching
     * stack of trees; this is only what the renderer needs to name the place.
     *
     * @var list<string>
     */
    public array $jsonPath = [];
    /**
     * One-shot line shown in place of the viewer's key hints — why a `j` press
     * did nothing. Cleared on the next key, so it is never stale.
     */
    public string $jsonNotice = '';

    // Table view of a separated-value cell (`t`, §10a). App owns the SplitTable
    // and publishes what the renderer needs here, as it does for the JSON tree.
    // A modal of the app, not part of a data view.
    public bool $splitView = false;
    public string $splitColumn = '';
    /** @var list<string> */
    public array $splitHeaders = [];
    /** @var list<list<string>> one list of cells per row */
    public array $splitCells = [];
    /** @var list<bool> per row: new, or a field in it changed */
    public array $splitChanged = [];
    public int $splitSel = 0;           // selected row
    public int $splitCol = 0;           // selected field
    public int $splitOffset = 0;        // first row on screen, kept by the renderer
    public int $splitColOffset = 0;     // first field on screen, kept by the renderer
    public int $splitPageRows = 0;      // rows on screen, published by the renderer
    /** One-shot line in place of the key hints, cleared on the next key. */
    public string $splitNotice = '';
    // The single-line field editor (Enter saves, Esc cancels).
    public bool $splitEdit = false;
    public ?LineEditor $splitEditor = null;
    // The definition editor: a framed multi-line box (Ctrl+S saves).
    public bool $splitDefEdit = false;
    public ?LineEditor $splitDefEditor = null;
    public string $splitDefTitle = '';
    // "Invalid JSON" mode: an explicit j/J on a value that is not a JSON document
    // shows the raw string + why (§10). (Enter-edit falls back to the cell editor
    // instead, so it never lands here.)
    public bool $jsonInvalid = false;
    public string $jsonRaw = '';
    public string $jsonInvalidMessage = '';
    // Inline node editor (§10). Reuses LineEditor. $jsonEditKind is '' for editing
    // a scalar leaf's value, or 'newKey' while entering the key of a new object
    // member (which then flows into a value edit).
    public bool $jsonEdit = false;
    public ?LineEditor $jsonEditor = null;
    public string $jsonEditLabel = '';  // label of the node being edited (for the box title)
    public string $jsonEditKind = '';   // '' = value edit, 'newKey' = new object key

    // Reusable Y/N/Cancel confirm modal (§5, §9, §18).
    public bool $confirm = false;
    public string $confirmKind = '';
    public string $confirmMessage = '';
    /** @var list<array{0:string,1:string}> [key, label] */
    public array $confirmOptions = [];

    // PK-missing popup (§5): re-run last query with PK columns added.
    public bool $pkPopup = false;
    public string $pkPopupMessage = '';
    /** @var list<string> */
    public array $pkPopupCols = [];

    // Published each frame by the renderer for click mapping. The layout
    // indicator is the vertical "Layout Config" label over the left border
    // (§6): X/Y is its top cell, W its column count (1), H its height.
    public ?GridLayout $gridLayout = null;
    public int $layoutIndicatorX = -1;
    public int $layoutIndicatorY = -1;
    public int $layoutIndicatorW = 0;
    public int $layoutIndicatorH = 0;

    // Scrollbar track geometry, published by the renderer for click/drag mapping
    // (§6). A *ScrollLen of 0 means that bar wasn't drawn this frame (no overflow).
    // Vertical bar: column vScrollX, rows vScrollTop..+vScrollLen; maps vScrollVisible
    // of vScrollTotal rows. Horizontal bar: row hScrollY, cols hScrollLeft..+hScrollLen.
    public int $vScrollX = -1;
    public int $vScrollTop = -1;
    public int $vScrollLen = 0;
    public int $vScrollTotal = 0;
    public int $vScrollVisible = 0;
    public int $hScrollY = -1;
    public int $hScrollLeft = -1;
    public int $hScrollLen = 0;
    public int $hScrollTotal = 0;
    public int $hScrollVisible = 0;

    // Bounds of the topmost dismissable popup/dialog box, published by the
    // renderer for outside-click dismissal (§6). null = no such box this frame.
    /** @var array{0:int,1:int,2:int,3:int}|null [x, y, w, h] */
    public ?array $modalRect = null;

    // Bounds of the query-error block in the result box, published by the
    // renderer so a click there can copy the error (§19). Both of its lines are
    // truncated to the box on screen, so the clipboard is the only way to get
    // the whole message out. null = no error showing this frame.
    /** @var array{0:int,1:int,2:int,3:int}|null [x, y, w, h] */
    public ?array $errorRect = null;

    // Context-sensitive help overlay (F1, §15).
    public bool $help = false;

    // Data Dictionary field-hint popup (`word?` in the CLI, §11). Dismissed on
    // the next keypress, which also strips the triggering `?`.
    public bool $ddHint = false;
    public string $ddHintTitle = '';   // "table.field" heading; '' = plain message box
    public string $ddHintMessage = '';
    // Only the `word?` hint strips a `?` when dismissed; the same box also
    // carries plain notices (System menu → Write Log) that must not.
    public bool $ddHintStripsMark = false;

    public string $statusLine = '';
    public bool $statusIsError = false;

    // The row cap that actually bit on this result, or 0 when none did (§5).
    // Set only when the query came back holding the full cap, because a SELECT
    // that returns four rows was capped in name only and saying so buried the
    // count the user asked for. Non-zero makes the status line's row/col/time
    // stats carry an "auto-limited" marker rather than replacing them.
    public int $autoLimitedAt = 0;

    public bool $readOnly = false;

    /**
     * The env in force that nobody typed — `devel (default)` or `devel
     * ($SQLI_ENV)` — or ''. Drawn in the header beside `[RO]` so the settings it
     * brought along are never an invisible union with the command line.
     */
    public string $envUntyped = '';

    /**
     * Whether the connection is alive, gone, or being reopened (§2).
     *
     * Connection-wide rather than per-view — one connection serves every data
     * view — and so deliberately not in VIEW_PROPS.
     *
     * The screen has to say this because the grid cannot: after the server drops
     * an idle session, every row from the last query is still on display and
     * still correct, so nothing about the picture changes and the only sign is
     * an error on the next statement. A `wait_timeout` of 300 seconds, which is
     * what the log caught in the wild, makes that the normal way a session ends.
     */
    public const LINK_OK = '';
    public const LINK_LOST = 'lost';
    public const LINK_RELINKING = 'relinking';

    /**
     * A statement is waiting to go out again by itself: the connection died
     * under it, the statement provably never ran, and sqli is about to reopen
     * and re-send it. Distinct from RELINKING, which is a connection being
     * reopened for something the user just typed — here there is nothing for
     * them to do, and saying so is the whole point.
     */
    public const LINK_RESENDING = 'resending';
    public string $linkState = self::LINK_OK;

    /**
     * When the header's `!db` marker may stop being drawn (§2).
     *
     * A loss sqli handles by itself is silent and can be over in well under a
     * second — inside the time it takes to look up from the keyboard. A warning
     * nobody was in front of is not a warning, so the marker outlives the fault
     * by a moment.
     *
     * A deadline rather than a flag, and pushed forward on every frame the link
     * is bad rather than set once on a transition: the reconnect inside
     * execute() moves `linkState` without the frame loop ever seeing it, and a
     * flag that has to be cleared by a transition nobody observed is exactly the
     * red banner over a working connection this file already warns about.
     * Connection-wide, so not in VIEW_PROPS.
     */
    public float $linkLingerUntil = 0.0;

    public string $connectionLabel = '';
    /**
     * The driver the connection was opened with — `mysqli` or `pdo` — shown at
     * the head of the header's connection label. The same two words the `driver`
     * option takes, so what is on screen is what you would type to pin it.
     */
    public string $driverName = '';
    /**
     * Is the live session encrypted (§2)? Derived once a frame from the cipher
     * the server negotiated — the plaintext retry and the 2006 reconnect can
     * both change it mid-session, so this is never set and left. The header
     * shows the *fact* (`TLS` / `no TLS`), not the cipher: which suite was
     * agreed answers a different question, and it is in `debug.log` and the
     * connection log for whoever is asking it.
     */
    public bool $secure = false;
    /** Active database name, centered in the header so it's easy to see. */
    public string $currentDatabase = '';
    public string $version = '';

    /** @var list<string> typed-form history (plain Up/Down, §4) */
    public array $history = [];
    /** @var list<string> executed-form history (Shift+Up/Down, §4) */
    public array $execHistory = [];

    public bool $running = true;

    /**
     * Snapshot the per-view properties so another view can take the screen (§20).
     *
     * Reflection rather than 24 hand-written lines on purpose: the list lives in
     * exactly one place ({@see VIEW_PROPS}), so capture and restore can never
     * drift apart and add a bleed between views.
     *
     * @return array<string,mixed>
     */
    public function captureView(): array
    {
        $out = [];
        foreach (self::VIEW_PROPS as $name) {
            $p = new \ReflectionProperty(self::class, $name);
            $out[$name] = $p->getValue($this);
        }
        return $out;
    }

    /** @param array<string,mixed> $view a snapshot from {@see captureView()} */
    public function restoreView(array $view): void
    {
        foreach (self::VIEW_PROPS as $name) {
            if (!array_key_exists($name, $view)) {
                continue;
            }
            $p = new \ReflectionProperty(self::class, $name);
            $p->setValue($this, $view[$name]);
        }
    }

    /**
     * The view an untouched tab shows: whatever a fresh State starts with (§20).
     * Derived rather than declared, so a new per-view property with a default
     * gets that default in every empty tab without a second edit here.
     *
     * @return array<string,mixed>
     */
    public static function blankView(): array
    {
        return (new self())->captureView();
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Raw row indices marked for deletion, in *display* order (§22).
     *
     * Display order, so the generated DELETE lists its keys in the order the rows
     * appear on screen — the statement is put on the CLI to be read before it is
     * run, and a set that reads in a different order than the grid is hard to
     * check. Marks that no longer address a live row are dropped rather than
     * carried into a statement.
     *
     * @return list<int>
     */
    public function markedRawIndices(): array
    {
        if ($this->deleteMarks === []) {
            return [];
        }
        $out = [];
        foreach ($this->displayOrder() as $rawIdx) {
            if (isset($this->deleteMarks[$rawIdx])) {
                $out[] = $rawIdx;
            }
        }
        return $out;
    }

    /** How many rows are currently marked for deletion (§22). */
    public function markedCount(): int
    {
        return count($this->markedRawIndices());
    }

    public function hasResult(): bool
    {
        return $this->result !== null
            && !$this->result->hasError()
            && !$this->result->isEmpty();
    }

    public function maxRow(): int
    {
        return $this->hasResult() ? $this->result->rowCount() - 1 : 0;
    }

    public function hasRange(): bool
    {
        return $this->rangeRow !== null && $this->rangeCol !== null;
    }

    public function clearRange(): void
    {
        $this->rangeRow = null;
        $this->rangeCol = null;
    }

    /**
     * The range as [firstRow, lastRow, firstCol, lastCol] (display rows, visible
     * columns, all inclusive), or null when there is none.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    public function rangeBounds(): ?array
    {
        if (!$this->hasRange()) {
            return null;
        }
        return [
            min($this->rangeRow, $this->selectedRow), max($this->rangeRow, $this->selectedRow),
            min($this->rangeCol, $this->selectedCol), max($this->rangeCol, $this->selectedCol),
        ];
    }

    /** Whether display row $row / visible column $col is inside the range. */
    public function inRange(int $row, int $col): bool
    {
        $b = $this->rangeBounds();
        return $b !== null && $row >= $b[0] && $row <= $b[1] && $col >= $b[2] && $col <= $b[3];
    }

    /** Number of selectable columns (visible, i.e. not hidden). */
    public function totalCols(): int
    {
        return count($this->visibleColumnNames());
    }

    /**
     * @return list<string> all result columns in display order (colOrder applied;
     * any columns not named in colOrder are appended in result order, or A–Z
     * when {@see $colAlpha} is on)
     */
    public function orderedColumnNames(): array
    {
        if ($this->result === null) {
            return [];
        }
        if ($this->colOrder === [] && !$this->colAlpha) {
            return $this->result->columns;
        }
        $known = array_flip($this->result->columns);
        $seen = [];
        $out = [];
        foreach ($this->colOrder as $name) {
            if (isset($known[$name]) && !isset($seen[$name])) {
                $out[] = $name;
                $seen[$name] = true;
            }
        }
        $tail = [];
        foreach ($this->result->columns as $name) {
            if (!isset($seen[$name])) {
                $tail[] = $name;
                $seen[$name] = true;
            }
        }
        if ($this->colAlpha) {
            usort($tail, 'strnatcasecmp');
        }
        return array_merge($out, $tail);
    }

    /**
     * @return list<string> display-ordered columns minus the hidden ones, and
     * minus the ones the Ctrl+Alt+F filter leaves out while it is open
     */
    public function visibleColumnNames(): array
    {
        $cols = $this->unfilteredColumnNames();
        if ($this->colFilter === null || $this->colFilter === '') {
            return $cols;
        }
        return self::filterColumns($cols, $this->colFilter);
    }

    /** @return list<string> display-ordered columns minus the hidden ones, ignoring the filter */
    public function unfilteredColumnNames(): array
    {
        $ordered = $this->orderedColumnNames();
        if ($this->hiddenCols === [] || $ordered === []) {
            return $ordered;
        }
        $hidden = array_flip($this->hiddenCols);
        return array_values(array_filter($ordered, static fn (string $n): bool => !isset($hidden[$n])));
    }

    /**
     * The columns whose name contains $text, case-insensitive, order kept.
     *
     * @param  list<string> $cols
     * @return list<string>
     */
    public static function filterColumns(array $cols, string $text): array
    {
        return array_values(array_filter(
            $cols,
            static fn (string $n): bool => mb_stripos($n, $text) !== false,
        ));
    }

    /**
     * The effective display-format id for column $col, or null (§6, backlog S).
     * Only a manual choice in {@see $colFormats} drives display; the DD's
     * {@see $ddColFormats} is a *suggestion* surfaced in the column popup, never
     * auto-applied (§11). A stored '' is treated as "not set" (legacy: it used to
     * mean "DD default off").
     */
    public function formatOf(string $col): ?string
    {
        $v = $this->colFormats[$col] ?? '';
        return $v === '' ? null : $v;
    }

    /** The DD's suggested display format for $col, or null (§11). Not applied. */
    public function ddSuggestedFormat(string $col): ?string
    {
        return $this->ddColFormats[$col] ?? null;
    }

    /**
     * The fields the detail view lists, in the order it lists them (§8):
     * result order, or A–Z (case-insensitive, natural) when $detailSorted.
     * `detailFieldIdx` indexes *this* list, so every reader goes through here.
     *
     * @return list<string>
     */
    public function detailColumns(): array
    {
        $cols = $this->result?->columns ?? [];
        if ($this->detailSorted) {
            usort($cols, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
        }
        return $cols;
    }

    /** @return list<int> raw row indices in display order (sort applied) */
    public function displayOrder(): array
    {
        if ($this->sortedOrder !== null) {
            return $this->sortedOrder;
        }
        return $this->hasResult() ? range(0, $this->result->rowCount() - 1) : [];
    }

    /** Recompute the cached sort order from sortCol/sortDir. */
    public function applySort(): void
    {
        $this->sortedOrder = null;
        if ($this->sortCol === null || !$this->hasResult()) {
            return;
        }
        $rows = $this->result->rows;
        $col = $this->sortCol;
        $sign = $this->sortDir === 'desc' ? -1 : 1;
        $order = range(0, count($rows) - 1);
        usort($order, function (int $a, int $b) use ($rows, $col, $sign): int {
            return $sign * self::compareValues($rows[$a][$col] ?? null, $rows[$b][$col] ?? null);
        });
        $this->sortedOrder = $order;
    }

    /**
     * Columns with an effective display format, in display order (§6).
     *
     * Reads through {@see formatOf()} rather than `colFormats` directly, so a
     * legacy `''` entry — which used to mean "DD default off" — doesn't count as
     * a format, and an entry for a column the current result doesn't have is
     * skipped rather than advertised.
     *
     * @return array<string,string> column name => format id
     */
    public function formattedColumns(): array
    {
        $out = [];
        foreach ($this->unfilteredColumnNames() as $name) {
            $id = $this->formatOf($name);
            if ($id !== null) {
                $out[$name] = $id;
            }
        }
        return $out;
    }

    /**
     * True when any layout customization is active (drives the indicator).
     *
     * A display format counts: it is persisted as Layout Config and cleared by
     * "Reset all" like every other item here, so leaving it out made a formatted
     * column the one customization with no indicator and no way back to it
     * except the column it happened to be on.
     */
    public function layoutActive(): bool
    {
        return $this->hiddenCols !== []
            || $this->colWidthOverride !== []
            || $this->colOrder !== []
            || $this->formattedColumns() !== []
            || $this->sortCol !== null;
    }

    /** Nulls sort last; numeric strings compare numerically, else as strings. */
    private static function compareValues(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        return strcasecmp((string) $a, (string) $b);
    }
}
