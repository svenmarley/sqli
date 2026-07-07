<?php

declare(strict_types=1);

namespace Sqli2\Core;

use Sqli2\Db\QueryResult;
use Sqli2\Input\LineEditor;

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
     * Column popup menu: [label, action]; a null label is a separator (§6).
     *
     * @var list<array{0:?string,1:?string}>
     */
    public const COLUMN_POPUP = [
        ['Sort ASC', 'sort_asc'],
        ['Sort DESC', 'sort_desc'],
        ['Clear sort', 'sort_clear'],
        [null, null],
        ['Move column', 'move'],
        ['Change width', 'width'],
        ['Fit to content', 'fit'],
        ['Fit all to content', 'fit_all'],
        [null, null],
        ['Copy column', 'copy_col'],
        ['Hide column', 'hide'],
    ];

    public string $focus = self::FOCUS_CLI;

    // Transient notice shown centered on the top header line (e.g. clipboard
    // copy confirmation, §19). Visible while microtime() < $flashUntil.
    public string $flashMessage = '';
    public float $flashUntil = 0.0;

    // Centered "please wait" popup shown while a known-slow synchronous op runs
    // (e.g. loading the Data Dictionary CSV). Non-empty = draw it, topmost. The
    // App paints one frame with this set, runs the op, then clears it.
    public string $busyMessage = '';

    public ?QueryResult $result = null;
    public string $lastSql = '';
    public string $currentTable = '';

    // Result grid selection / scroll. selectedRow indexes the *display* order
    // (after sort); selectedCol indexes the *visible* columns (after hiding).
    public int $selectedRow = 0;   // -1 = header row selected
    public int $selectedCol = 0;
    public int $rowOffset = 0;
    public int $colOffset = 0;

    // Layout customization (§6), persisted per db+table by App.
    /** @var list<string> hidden column names */
    public array $hiddenCols = [];
    /** @var array<string,int> column name => override width */
    public array $colWidthOverride = [];
    /** @var list<string> full display order of columns; [] = natural order */
    public array $colOrder = [];
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

    // Vertical output mode (\G, §5): line-scrolled, no edit/JSON/detail.
    public bool $vertical = false;
    public int $verticalScroll = 0;

    // Column popup (§6).
    public bool $columnPopup = false;
    public int $popupCol = 0;
    public int $popupItem = 0;

    // Layout popup (§6). The item list is dynamic (it includes one "Unhide: …"
    // entry per hidden column), built by App and published here for the renderer.
    public bool $layoutPopup = false;
    public int $layoutItem = 0;
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
    // Inline comment editor inside the DD viewer (Enter on a field, §11).
    public bool $ddCommentEdit = false;
    public ?LineEditor $ddCommentEditor = null;
    public string $ddCommentField = '';
    // Read-only foreign-keys view (Enter on the `--Foreign-Keys--` row, §11).
    // The FK summary isn't editable here, only viewable; any key dismisses it.
    public bool $ddFkView = false;
    public string $ddFkText = '';

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
    public int $completionStart = 0;
    public string $completionTitle = '';
    /** The partial token being typed; shown at the top of the popup and used to filter. */
    public string $completionFilter = '';

    // Cursor cell published by the renderer (anchors the completion popup).
    public int $inputCursorX = -1;
    public int $inputCursorY = -1;

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
    /** @var array<int,array<string,string>> rawRowIdx => (colName => newValue) */
    public array $pending = [];

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
    // indicator is the vertical "Layout Config" label down the right margin
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

    // Context-sensitive help overlay (F1, §15).
    public bool $help = false;

    // Data Dictionary field-hint popup (`word?` in the CLI, §11). Dismissed on
    // the next keypress, which also strips the triggering `?`.
    public bool $ddHint = false;
    public string $ddHintTitle = '';   // "table.field" heading; '' = plain message box
    public string $ddHintMessage = '';

    public string $statusLine = '';
    public bool $statusIsError = false;

    public bool $readOnly = false;
    public string $connectionLabel = '';
    /** Active database name, centered in the header so it's easy to see. */
    public string $currentDatabase = '';
    public string $version = '';

    /** @var list<string> typed-form history (plain Up/Down, §4) */
    public array $history = [];
    /** @var list<string> executed-form history (Shift+Up/Down, §4) */
    public array $execHistory = [];

    public bool $running = true;

    public function hasPending(): bool
    {
        return $this->pending !== [];
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

    /** Number of selectable columns (visible, i.e. not hidden). */
    public function totalCols(): int
    {
        return count($this->visibleColumnNames());
    }

    /**
     * @return list<string> all result columns in display order (colOrder applied;
     * any columns not named in colOrder are appended in result order)
     */
    public function orderedColumnNames(): array
    {
        if ($this->result === null) {
            return [];
        }
        if ($this->colOrder === []) {
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
        foreach ($this->result->columns as $name) {
            if (!isset($seen[$name])) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return list<string> display-ordered columns minus the hidden ones */
    public function visibleColumnNames(): array
    {
        $ordered = $this->orderedColumnNames();
        if ($this->hiddenCols === [] || $ordered === []) {
            return $ordered;
        }
        $hidden = array_flip($this->hiddenCols);
        return array_values(array_filter($ordered, static fn (string $n): bool => !isset($hidden[$n])));
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

    /** True when any layout customization is active (drives the indicator). */
    public function layoutActive(): bool
    {
        return $this->hiddenCols !== []
            || $this->colWidthOverride !== []
            || $this->colOrder !== []
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
