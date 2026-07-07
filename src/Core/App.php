<?php

declare(strict_types=1);

namespace Sqli2\Core;

use includes\my;
use Sqli2\Config\Config;
use Sqli2\Config\Settings;
use Sqli2\Db\Database;
use Sqli2\Db\DataDictionary;
use Sqli2\Db\FieldValidator;
use Sqli2\Db\QueryResult;
use Sqli2\Db\Schema;
use Sqli2\Db\SchemaDiff;
use Sqli2\Input\InputBuffer;
use Sqli2\Input\LineEditor;
use Sqli2\Json\JsonNode;
use Sqli2\Json\JsonTree;
use Sqli2\Render\Renderer;
use Sqli2\Render\Theme;
use Sqli2\Sql\Completion;
use Sqli2\Sql\CompletionResult;
use Sqli2\Sql\Normalizer;
use Sqli2\Terminal\Ansi;
use Sqli2\Terminal\Key;
use Sqli2\Terminal\KeyEvent;
use Sqli2\Terminal\MouseEvent;
use Sqli2\Terminal\Screen;
use Sqli2\Terminal\Terminal;

/**
 * Application kernel: owns the event loop and wires the subsystems together.
 *
 * Intentionally thin. Input handling delegates to small, focused methods;
 * rendering is the Renderer's job; persistence is Settings'. As the spec is
 * filled in, new handlers attach here without this class becoming the
 * god-object the original grew into.
 */
final class App
{
    private const MODIFYING = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
        'GRANT', 'REVOKE', 'LOAD', 'CALL',
    ];
    private const SIZE_POLL_INTERVAL = 0.3; // seconds between terminal-size checks
    // After a resize we keep forcing full redraws for a short settle window: the
    // terminal finishes resizing its viewport a few ms after FFI reports the new
    // size, so a single repaint can leave the freshly-grown rows blank.
    private const RESIZE_SETTLE = 0.4;      // seconds to keep repainting after a resize
    private const FLASH_SECONDS = 3.0;      // how long a top-line notice (e.g. copy) stays up
    private const FRAME_SLEEP_US = 8000;    // ~8ms idle pause; only diffs are flushed
    private const DD_LOCK_POLL = 2.0;       // seconds between DD file-lock checks while the viewer is open
    private const AUTO_LIMIT = 1000;        // rows a SELECT/WITH is auto-capped at when it has no LIMIT (§5)

    private Terminal $terminal;
    private Screen $screen;
    private Renderer $renderer;
    private State $state;
    private InputBuffer $input;
    private Normalizer $normalizer;
    private Completion $completion;
    private Schema $schema;

    private int $historyIdx = 0;
    private string $historySaved = '';
    private int $execHistoryIdx = 0;
    private string $execHistorySaved = '';
    private float $lastSizeCheck = 0.0;
    private float $resizeRedrawUntil = 0.0;
    /** Next time (microtime) to re-probe whether the DD file is locked elsewhere. */
    private float $ddLockNextCheck = 0.0;
    /** DSR-size-report cycle state (non-FFI/pipe path): keep exactly one in flight. */
    private bool $dsrInFlight = false;
    private float $lastDsrRequest = 0.0;

    /** Which scrollbar (if any) a left-drag is currently steering: '', 'v', or 'h' (§6). */
    private string $scrollDrag = '';

    /** Cursor-to-origin offset [dx,dy] while dragging the cell inspector, or null (§9). */
    private ?array $popupDrag = null;

    /** The loaded Data Dictionary for the current database, or null (§11). */
    private ?DataDictionary $dictionary = null;

    /** The parsed JSON document being viewed/edited, or null (§10). */
    private ?JsonTree $jsonTree = null;
    /**
     * Flattened JSON rows (with live JsonNode refs) for the open document, so a
     * key/toggle/edit acts on the exact node the user selected. The renderer gets
     * a node-stripped projection via State (see publishJson()).
     * @var list<array{node:\Sqli2\Json\JsonNode,depth:int,label:string,value:string,type:string,container:bool,expanded:bool,childCount:int,editable:bool}>
     */
    private array $jsonRows = [];

    /** Where a not-yet-named new object member will be inserted (§10 add flow). */
    private ?JsonNode $jsonAddContainer = null;
    private int $jsonAddPos = 0;

    /**
     * The single in-flight cell edit whose UPDATE currently sits on the CLI, or
     * null. Editing is immediate-to-CLI (§9): confirming an edit stages the new
     * value in the grid and drops its UPDATE on the CLI to run or cancel; only one
     * cell is ever in flight. Keys: rawIdx, col, sql (the exact UPDATE on the CLI).
     * @var array{rawIdx:int,col:string,sql:string}|null
     */
    private ?array $autoUpdate = null;

    /**
     * Schema-diff results cached per table (lower-cased name) so the DD viewer
     * doesn't re-run SHOW COLUMNS every frame. Cleared on USE and on DD reload.
     * @var array<string,array{fieldStatus:array<string,string>,missing:list<string>,changed:list<string>,extra:list<string>,hasDiff:bool}|null>
     */
    private array $ddDiffCache = [];

    /** Original typed DELETE/UPDATE held while the dangerous-query confirm is open (§5). */
    private ?array $pendingDangerous = null;

    /** Column order / selection captured on entering "move column" mode, for Esc revert. */
    private ?array $moveSavedOrder = null;
    private int $moveSavedCol = 0;

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly string $version,
    ) {
        $this->terminal = new Terminal();
        $this->input = new InputBuffer();
        $this->state = new State();
        $this->normalizer = new Normalizer();
        $this->completion = new Completion();
        $this->schema = new Schema($database);
        $this->renderer = new Renderer(new Theme($settings->getTheme()));
    }

    public function run(): int
    {
        $this->state->version = $this->version;
        $this->state->readOnly = $this->config->isFlag('read-only');
        $this->state->history = $this->settings->getHistory();
        $this->historyIdx = count($this->state->history);
        $this->state->execHistory = $this->settings->getExecHistory();
        $this->execHistoryIdx = count($this->state->execHistory);

        $this->terminal->open();
        // Restore the terminal even on a fatal error or fatal signal path.
        register_shutdown_function(fn () => $this->terminal->close());

        // Measure the initial viewport. Prefer the platform API (Windows FFI
        // srWindow) when available — it is the true visible window for both grow
        // and shrink. Otherwise fall back to the DSR cursor-position report.
        $platformSize = $this->terminal->hasPlatformSize();
        [$w, $h] = $platformSize
            ? $this->terminal->size()
            : $this->terminal->measureSize();
        my::lineout('FILE:FILE', 'init size', $w . 'x' . $h, 'platformSize', $platformSize);
        $this->screen = new Screen($w, $h);

        $this->connect();

        // Only load a Data Dictionary at startup when one is explicitly passed via
        // --dictionary. A saved per-db association is loaded lazily, the first time
        // the user actually opens a DD feature (§11) — not eagerly on launch.
        $cliDict = $this->config->getString('dictionary');
        if ($cliDict !== '') {
            $this->loadDictionary($this->database->currentDatabase(), $cliDict);
        }

        $this->loop();

        $this->terminal->close();
        return 0;
    }

    private function loop(): void
    {
        $this->draw();
        while ($this->state->running) {
            $events = $this->terminal->input()->poll();
            foreach ($events as $event) {
                if ($event instanceof KeyEvent) {
                    $this->handleKey($event);
                } elseif ($event instanceof MouseEvent) {
                    $this->handleMouse($event);
                }
                if (!$this->state->running) {
                    break;
                }
            }

            // poll() drains console resize records; if one arrived, repaint right
            // away (force past the size-poll throttle) so resize feels instant.
            $this->maybeResize($this->terminal->takeResizeSignal());
            // While the DD viewer is open, watch (throttled) for the file being
            // locked/freed by another program so the banner + edit gate stay live.
            if ($this->state->ddViewer) {
                $this->pollDdLock();
            }
            // Within the post-resize settle window, keep repainting the whole
            // screen each frame so the bottom rows reappear once the terminal has
            // finished growing its viewport (see RESIZE_SETTLE).
            if ($this->resizeRedrawUntil > 0.0) {
                if (microtime(true) < $this->resizeRedrawUntil) {
                    $this->screen->invalidate();
                } else {
                    $this->resizeRedrawUntil = 0.0;
                }
            }
            $this->draw();
            usleep(self::FRAME_SLEEP_US);
        }
    }

    private function draw(): void
    {
        // Publish the live system-menu model (labels + enabled-state can change
        // between frames) so the renderer stays a pure reader of State (§12).
        if ($this->state->systemMenu) {
            $this->state->systemMenuView = $this->systemMenuItems();
        }
        // Likewise the Layout popup (its hidden-column entries are dynamic, §6).
        if ($this->state->layoutPopup) {
            $this->state->layoutPopupView = $this->layoutPopupItems();
        }
        // The DD viewer's filtered lists are recomputed each frame (§11).
        if ($this->state->ddViewer) {
            $this->ddPublish();
        }
        $this->flushFrame();
    }

    /** Render the current State to the terminal as one frame (paint + flush). */
    private function flushFrame(): void
    {
        $this->renderer->render($this->screen, $this->state, $this->input);
        $this->terminal->write($this->screen->flush());
    }

    /**
     * Run a known-slow synchronous operation behind a centered "please wait"
     * popup. The main loop is blocked while $fn runs, so we paint one frame with
     * the popup and flush it to the terminal *before* calling $fn, then clear it.
     * The next normal frame from run() erases the popup. Returns $fn's result.
     *
     * For a multi-step op that can report progress, see {@see warmDdDiffs()},
     * which drives {@see $busyMessage} + {@see flushFrame()} itself between steps.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withBusy(string $message, callable $fn): mixed
    {
        $this->state->busyMessage = $message;
        $this->flushFrame();
        try {
            return $fn();
        } finally {
            $this->state->busyMessage = '';
        }
    }

    private function maybeResize(bool $force = false): void
    {
        // On Windows (FFI) the platform srWindow is the visible window for both
        // grow and shrink, so prefer it. A resize console event ($force) applies
        // immediately; otherwise we poll at most every SIZE_POLL_INTERVAL as a
        // safety net. The DSR cursor-clamp is only a fallback for POSIX/VT
        // terminals with no platform API: it under-reports height right after the
        // window grows (the alt-screen buffer lags), which left the CLI/status
        // stranded at the old bottom row with a blank band below.
        if ($this->terminal->hasPlatformSize()) {
            $now = microtime(true);
            if (!$force && $now - $this->lastSizeCheck < self::SIZE_POLL_INTERVAL) {
                return;
            }
            $this->lastSizeCheck = $now;
            [$w, $h] = $this->terminal->size();
            $this->applySize($w, $h);
            return;
        }

        // No platform API (pipe/Git-Bash path): drive resize with an async DSR
        // cursor-clamp report. Keep EXACTLY ONE report in flight — each reply
        // immediately triggers the next request — so the reply itself keeps waking
        // the blocking read. (A fixed-interval poll deadlocks here: after the lone
        // reply wakes the loop, the throttle suppresses the next request, and the
        // blocking read then has nothing left to wake it until a keystroke.)
        $report = $this->terminal->input()->takeSizeReport();
        if ($report !== null) {
            $this->dsrInFlight = false;
            $this->applySize($report[0], $report[1]);
        }
        $now = microtime(true);
        // Send the next request when none is pending, or recover if a reply was
        // lost (no answer within a short grace period).
        if (!$this->dsrInFlight || $now - $this->lastDsrRequest > 0.5) {
            $this->terminal->requestSizeReport();
            $this->dsrInFlight = true;
            $this->lastDsrRequest = $now;
        }
    }

    /** Resize the screen buffer to a new viewport and force a clean repaint. */
    private function applySize(int $w, int $h): void
    {
        if ($w === $this->screen->width() && $h === $this->screen->height()) {
            return;
        }
        my::lineout('FILE:FILE', 'resize', $this->screen->width() . 'x' . $this->screen->height() . ' -> ' . $w . 'x' . $h);
        $this->screen->resize($w, $h);
        // Re-assert auto-wrap-off (some terminals reset DECAWM on a geometry
        // change), then force a full repaint into the new viewport. We do NOT emit
        // CLEAR_SCREEN: invalidate() makes the next flush overwrite every cell, so
        // the repaint reflows in place instead of blanking first (the visible
        // "flash old then correct" on a fast/event-driven resize).
        $this->terminal->write(Ansi::AUTOWRAP_OFF);
        $this->screen->invalidate();
        // Open the settle window so the loop keeps repainting until the
        // terminal's viewport has caught up with the new size.
        $this->resizeRedrawUntil = microtime(true) + self::RESIZE_SETTLE;
    }

    private function connect(): void
    {
        try {
            $this->database->connect();
            $this->state->connectionLabel = $this->database->getConnectionLabel();
            $this->state->currentDatabase = $this->database->currentDatabase();
            $this->state->statusLine = 'Connected · ' . $this->state->connectionLabel;
        } catch (\Throwable $e) {
            $this->state->connectionLabel = '';
            $this->state->currentDatabase = '';
            $this->state->statusLine = 'Connection failed: ' . $e->getMessage();
            $this->state->statusIsError = true;
            my::lineout('FILE:FILE', 'connect failed', $e->getMessage());
        }
    }

    // --- Data Dictionary (§11) ---------------------------------------------

    /**
     * (Re)load the Data Dictionary for a database. An explicit path (from
     * --dictionary) takes precedence and is persisted as the association for
     * that db; otherwise the path saved in sqli.json for the db is used. Clears
     * the dictionary when nothing applies.
     */
    private function loadDictionary(string $db, ?string $explicit = null): void
    {
        $path = null;
        if ($explicit !== null && $explicit !== '') {
            $path = $explicit;
            if ($db !== '') {
                $this->settings->setDictionary($db, $explicit);
            }
        } elseif ($db !== '') {
            $path = $this->settings->getDictionary($db);
        }

        $this->dictionary = ($path !== null && $path !== '')
            ? $this->withBusy('Loading Data Dictionary …', static fn () => DataDictionary::load($path))
            : null;

        if ($path !== null && $path !== '' && $this->dictionary === null) {
            my::lineout('FILE:FILE', 'DD load failed', $path);
        }
    }

    /**
     * Lazy-load the DD from the current db's saved association, if it isn't
     * already loaded. Called the moment the user opens a DD feature (viewer,
     * `word?` hint) so nothing is read from disk until actually asked for (§11).
     */
    private function ensureDictionaryLoaded(): void
    {
        if ($this->dictionary !== null) {
            return;
        }
        $db = $this->database->currentDatabase();
        $saved = $db !== '' ? $this->settings->getDictionary($db) : null;
        if ($saved !== null && $saved !== '') {
            $this->dictionary = $this->withBusy(
                'Loading Data Dictionary …',
                static fn () => DataDictionary::load($saved),
            );
        }
    }

    /**
     * True when a DD is loaded, or one is associated with the current db and can
     * be lazy-loaded on demand. Gates the "Show Data Dictionary" menu entry (§11).
     */
    private function dictionaryAvailable(): bool
    {
        if ($this->dictionary !== null) {
            return true;
        }
        $db = $this->database->currentDatabase();
        $saved = $db !== '' ? $this->settings->getDictionary($db) : null;
        return $saved !== null && $saved !== '';
    }

    // --- input handling ----------------------------------------------------

    private function handleKey(KeyEvent $e): void
    {
        // Exit is by typing `exit`/`quit` on the CLI (handled in onEnter), not a
        // key chord. Ctrl+C is now "copy selected cell" in DATA focus (handled in
        // handleDataKey, §19); it no longer quits.

        // Ctrl+Alt+Z (and Ctrl+Alt+X) quit from anywhere (any mode/focus), with the
        // usual unsaved-changes prompt. Decoded as ESC + 0x1a/0x18 → Char 'z'/'x'
        // ctrl+alt.
        if ($e->key === Key::Char && $e->ctrl && $e->alt
            && in_array(strtolower($e->char), ['z', 'x'], true)) {
            $this->requestQuit();
            return;
        }

        // Help overlay is a soft modal: any key closes it (§15).
        if ($this->state->help) {
            $this->state->help = false;
            return;
        }

        // The DD field-hint popup is dismissed by the next key, which also strips
        // the triggering `?` from the buffer (§11). The key itself is consumed.
        if ($this->state->ddHint) {
            $this->state->ddHint = false;
            $editor = $this->input->editor();
            if (mb_substr($editor->text(), max(0, $editor->cursor() - 1), 1) === '?') {
                $editor->backspace();
            }
            return;
        }

        // Top-level modals: a confirm or PK popup blocks everything else.
        if ($this->state->confirm) {
            $this->handleConfirmKey($e);
            return;
        }
        if ($this->state->pkPopup) {
            $this->handlePkPopupKey($e);
            return;
        }
        if ($this->state->cellEdit) {
            $this->handleCellEditKey($e);
            return;
        }
        // The cell inspector (`v`) is deliberately NON-modal: it does not gate the
        // key handler, so the grid keeps its controls and the popup tracks the
        // selection. Its own keys (`v` toggle, `e` edit) live in handleDataKey.

        // The Assign-DD picker is a small modal opened from the system menu (§11).
        if ($this->state->ddPicker) {
            $this->handleDdPickerKey($e);
            return;
        }

        // The Data Dictionary viewer is a full-screen mode that owns all input
        // until closed (§11).
        if ($this->state->ddViewer) {
            $this->handleDdViewerKey($e);
            return;
        }

        // Modal overlays consume input until dismissed (innermost first).
        if ($this->state->columnPopup) {
            $this->handleColumnPopupKey($e);
            return;
        }
        if ($this->state->layoutPopup) {
            $this->handleLayoutPopupKey($e);
            return;
        }
        if ($this->state->systemMenu) {
            $this->handleSystemMenuKey($e);
            return;
        }
        // The JSON viewer is a full-screen mode that owns input; it sits AHEAD of
        // the detail view in the Esc-stack, since it can be opened from there and
        // its Esc should return to the detail view, not close both (§10).
        if ($this->state->jsonView) {
            $this->handleJsonKey($e);
            return;
        }
        if ($this->state->detail) {
            $this->handleDetailKey($e);
            return;
        }

        // "Move column" mode captures the arrow keys to relocate the selected
        // column until Enter (commit) / Esc (revert) (§6).
        if ($this->state->moveMode) {
            $this->handleMoveKey($e);
            return;
        }

        // "Change width" mode captures the arrow keys to resize/retarget the
        // selected column until Enter/Esc (§6).
        if ($this->state->widthAdjust) {
            $this->handleWidthAdjustKey($e);
            return;
        }

        // Completion popup is a soft modal: it consumes navigation/insert keys;
        // any other key closes it and is then handled normally (live editing).
        if ($this->state->completion) {
            if ($this->handleCompletionKey($e)) {
                return;
            }
            $this->state->completion = false;
        }

        // Ctrl+D toggles focus. An in-flight cell edit already lives on the CLI as
        // an UPDATE (§9), so there is nothing to prompt about when switching.
        if ($e->isChar() && $e->ctrl && strtolower($e->char) === 'd') {
            $this->state->focus = $this->state->focus === State::FOCUS_CLI
                ? State::FOCUS_DATA
                : State::FOCUS_CLI;
            return;
        }

        // Ctrl+L opens the Layout popup (also reachable via the right-margin
        // label, a click, or the system menu, §6).
        if ($e->isChar() && $e->ctrl && strtolower($e->char) === 'l') {
            if ($this->state->hasResult()) {
                $this->openLayoutPopup();
            }
            return;
        }

        // F1 opens the context-sensitive help overlay (§15).
        if ($e->key === Key::F1) {
            $this->state->help = true;
            return;
        }

        // F2 opens the column popup for the selected column (any focus, §6).
        if ($e->key === Key::F2) {
            $this->openColumnPopup($this->state->selectedCol);
            return;
        }

        // `/` opens the system menu (§12), in either focus. To type a literal
        // `/` (SQL like `a/b` or `/* … */`), press it twice: the first opens the
        // menu, the second closes it and emits a single `/` onto the CLI line
        // (handled in handleSystemMenuKey's double-slash case).
        if ($e->isChar() && !$e->ctrl && !$e->alt && $e->char === '/') {
            $this->openSystemMenu();
            return;
        }

        if ($this->state->focus === State::FOCUS_DATA) {
            $this->handleDataKey($e);
            return;
        }
        $this->handleCliKey($e);
    }

    private function handleCliKey(KeyEvent $e): void
    {
        $editor = $this->input->editor();
        switch ($e->key) {
            case Key::Char:
                if ($e->ctrl || $e->alt) {
                    return; // unhandled control combo
                }
                $editor->insert($e->char);
                $this->resetHistoryNav();
                // `word?` triggers the DD field-hint popup (§11).
                if ($e->char === '?') {
                    $this->openDdHint();
                }
                break;
            case Key::Backspace:
                // Ctrl+Backspace deletes the previous word (§4). Otherwise, at
                // column 0 merge into the line above; else delete a char.
                if ($e->ctrl) {
                    $editor->deleteWordLeft();
                } elseif (!$this->input->backspaceMerge()) {
                    $editor->backspace();
                }
                break;
            case Key::Delete:
                // Ctrl+Del deletes the current word (§4).
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Home:
                $editor->home();
                break;
            case Key::End:
                $editor->end();
                break;
            case Key::Up:
                // Shift+Up browses the executed-SQL history (§4). Plain Up moves
                // the active pushed line; from the top line it enters typed
                // history browse mode.
                if ($e->shift) {
                    $this->execHistoryPrev();
                } elseif (!$this->input->moveUp()) {
                    $this->historyPrev();
                }
                break;
            case Key::Down:
                // Shift+Down walks forward through executed-SQL history (§4).
                if ($e->shift) {
                    $this->execHistoryNext();
                } elseif ($this->inHistory()) {
                    $this->historyNext();
                } else {
                    $this->input->moveDown();
                }
                break;
            case Key::Escape:
                // If the CLI holds an in-flight cell edit's UPDATE, Esc cancels the
                // edit: revert the grid value and return to the data view (§9).
                // Otherwise it just clears all pushed lines and the buffer (§4).
                if ($this->autoUpdate !== null) {
                    $this->cancelInFlight();
                } else {
                    $this->input->clearAll();
                    $this->resetHistoryNav();
                }
                break;
            case Key::Tab:
                $this->openCompletion();
                break;
            case Key::Enter:
                $this->onEnter();
                break;
            default:
                break;
        }
    }

    private function inHistory(): bool
    {
        return $this->historyIdx < count($this->state->history);
    }

    private function handleDataKey(KeyEvent $e): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        // While moving the inspector, the arrows reposition it instead of the grid.
        if ($this->state->cellView && $this->state->cellMoveMode) {
            $this->handleCellMoveKey($e);
            return;
        }
        // Vertical output scrolls by line; there is no cell selection (§5).
        if ($this->state->vertical) {
            $this->handleVerticalKey($e);
            return;
        }
        $maxRow = $this->state->maxRow();
        $maxCol = $this->state->totalCols() - 1;

        switch ($e->key) {
            case Key::Up:
                // Ctrl+Up jumps to the first row in the current column (§7).
                if ($e->ctrl) {
                    $this->state->selectedRow = 0;
                } elseif ($this->state->selectedRow > 0) {
                    $this->state->selectedRow--;
                } elseif ($this->state->selectedRow === 0) {
                    $this->state->selectedRow = -1; // header row
                }
                break;
            case Key::Down:
                // Ctrl+Down jumps to the last row in the current column (§7).
                if ($e->ctrl) {
                    $this->state->selectedRow = $maxRow;
                } elseif ($this->state->selectedRow === -1) {
                    $this->state->selectedRow = 0;
                } elseif ($this->state->selectedRow < $maxRow) {
                    $this->state->selectedRow++;
                }
                break;
            case Key::Left:
                // Ctrl+Left pages one screen of columns to the left (§7).
                if ($e->ctrl) {
                    $this->pageColumns(-1);
                } elseif ($this->state->selectedCol > 0) {
                    $this->state->selectedCol--;
                }
                break;
            case Key::Right:
                // Ctrl+Right pages one screen of columns to the right (§7).
                if ($e->ctrl) {
                    $this->pageColumns(1);
                } elseif ($this->state->selectedCol < $maxCol) {
                    $this->state->selectedCol++;
                }
                break;
            case Key::Home:
                // Home → first column of the current row; Ctrl+Home → first cell.
                $this->state->selectedCol = 0;
                if ($e->ctrl) {
                    $this->state->selectedRow = 0;
                }
                break;
            case Key::End:
                // End → last column of the current row; Ctrl+End → last cell.
                $this->state->selectedCol = $maxCol;
                if ($e->ctrl) {
                    $this->state->selectedRow = $maxRow;
                }
                break;
            case Key::PageUp:
                $this->state->selectedRow = max(0, $this->state->selectedRow - $this->gridPageStep());
                break;
            case Key::PageDown:
                $this->state->selectedRow = min($maxRow, $this->state->selectedRow + $this->gridPageStep());
                break;
            case Key::Escape:
                // Esc closes the (non-modal) cell inspector, like every other popup
                // (§9). Nothing else in the plain grid consumes Esc.
                if ($this->state->cellView) {
                    $this->state->cellView = false;
                    $this->state->cellMoveMode = false;
                }
                break;
            case Key::Enter:
                // Header row → column popup; data row → JSON editor if the value is
                // a JSON document, else the inline cell editor (§9/§10).
                if ($this->state->selectedRow === -1) {
                    $this->openColumnPopup($this->state->selectedCol);
                } else {
                    $this->openCellOrJson();
                }
                break;
            case Key::Char:
                // Cell edits go straight to the CLI as an UPDATE now (§9), so there
                // is no separate "generate UPDATE" key.
                if ($e->ctrl && $e->alt && strtolower($e->char) === 'c') {
                    // Ctrl+Alt+C → copy the whole selected row (tab-separated) (§19).
                    $this->copyRow();
                } elseif ($e->ctrl && !$e->alt && strtolower($e->char) === 'c') {
                    // Ctrl+C → copy the selected cell value to the clipboard (§19).
                    $this->copyCell();
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'i') {
                    // i → toggle the detail view for the selected row (§8). Not
                    // available in vertical (\G) output. Replaces the old
                    // empty-Enter-on-CLI toggle so CLI Enter only submits.
                    if ($this->state->selectedRow >= 0 && !$this->state->vertical) {
                        $this->state->detail = !$this->state->detail;
                        $this->state->detailFieldIdx = 0;
                    }
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'j') {
                    // j/J → open the JSON viewer on the selected cell (§10).
                    $this->openJsonFromData();
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'v') {
                    // v → toggle the movable cell inspector on the selected column;
                    // pressing it on a different column re-pins it there (§9).
                    $this->toggleCellView();
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'e' && $this->state->cellView) {
                    // e → turn the open inspector into an editor for its cell (§9).
                    $this->beginCellViewEdit();
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'm' && $this->state->cellView) {
                    // m → enter move mode: arrows reposition the inspector (§9).
                    $this->state->cellMoveMode = true;
                }
                break;
            default:
                break;
        }
    }

    /**
     * One PageUp/PageDown step in the result grid: a full screen of visible data
     * rows, published by the renderer each frame (GridLayout->dataRows). Falls
     * back to 10 only before the first frame has been drawn.
     */
    private function gridPageStep(): int
    {
        $rows = $this->state->gridLayout?->dataRows ?? 0;
        return max(1, $rows > 0 ? $rows : 10);
    }

    /**
     * One PageUp/PageDown step in the detail view: a full screen of visible field
     * rows, published by the renderer each frame (State->detailVisibleFields).
     * Falls back to 10 only before the first frame has been drawn.
     */
    private function detailPageStep(): int
    {
        $fields = $this->state->detailVisibleFields;
        return max(1, $fields > 0 ? $fields : 10);
    }

    /** Line scrolling for vertical (\G) output; renderer clamps the offset. */
    private function handleVerticalKey(KeyEvent $e): void
    {
        switch ($e->key) {
            case Key::Up:
                $this->state->verticalScroll = max(0, $this->state->verticalScroll - 1);
                break;
            case Key::Down:
                $this->state->verticalScroll++;
                break;
            case Key::PageUp:
                $this->state->verticalScroll = max(0, $this->state->verticalScroll - 10);
                break;
            case Key::PageDown:
                $this->state->verticalScroll += 10;
                break;
            case Key::Home:
                $this->state->verticalScroll = 0;
                break;
            case Key::End:
                $this->state->verticalScroll = PHP_INT_MAX; // clamped on render
                break;
            default:
                break;
        }
    }

    /** Enter "move column" mode for a visible column index, snapshotting state for Esc revert (§6). */
    private function enterMoveMode(int $visibleColIdx): void
    {
        if (!$this->state->hasResult() || $this->state->totalCols() === 0) {
            return;
        }
        $this->state->selectedCol = max(0, min($visibleColIdx, $this->state->totalCols() - 1));
        $this->moveSavedOrder = $this->state->colOrder;
        $this->moveSavedCol = $this->state->selectedCol;
        $this->state->moveMode = true;
    }

    /**
     * "Move column" mode (§6): Left/Right move one position, Home/End jump to
     * first/last, Enter commits, Esc reverts to the position on entry.
     */
    private function handleMoveKey(KeyEvent $e): void
    {
        $name = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        switch ($e->key) {
            case Key::Left:
                if ($name !== null) {
                    $this->moveColumn($name, 'left');
                }
                break;
            case Key::Right:
                if ($name !== null) {
                    $this->moveColumn($name, 'right');
                }
                break;
            case Key::Home:
                if ($name !== null) {
                    $this->moveColumn($name, 'home');
                }
                break;
            case Key::End:
                if ($name !== null) {
                    $this->moveColumn($name, 'end');
                }
                break;
            case Key::Enter:
                $this->state->moveMode = false;
                $this->moveSavedOrder = null;
                break;
            case Key::Escape:
                // Put the column back where it was on entry.
                $this->state->colOrder = $this->moveSavedOrder ?? [];
                $this->state->selectedCol = $this->moveSavedCol;
                $this->persistColOrder();
                $this->state->moveMode = false;
                $this->moveSavedOrder = null;
                break;
            default:
                break;
        }
    }

    /**
     * "Change width" mode (§6): Up/Right widen, Down/Left narrow (the horizontal
     * arrows are aliased to the vertical ones), Enter/Esc exit.
     */
    private function handleWidthAdjustKey(KeyEvent $e): void
    {
        $name = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        switch ($e->key) {
            case Key::Up:
            case Key::Right:
                if ($name !== null) {
                    $this->adjustWidth($name, 1);
                }
                break;
            case Key::Down:
            case Key::Left:
                if ($name !== null) {
                    $this->adjustWidth($name, -1);
                }
                break;
            case Key::Enter:
            case Key::Escape:
                $this->state->widthAdjust = false;
                break;
            default:
                break;
        }
    }

    private function handleMouse(MouseEvent $e): void
    {
        // The Assign-DD picker is keyboard-driven; swallow mouse so clicks can't
        // leak through to the grid beneath it (§11).
        if ($this->state->ddPicker) {
            return;
        }

        // The JSON viewer is a full-screen keyboard mode; the wheel scrolls its
        // node list, and every other mouse event is swallowed so nothing leaks to
        // the grid beneath it (§10).
        if ($this->state->jsonView) {
            if (!$this->state->jsonInvalid && !$this->state->jsonEdit
                && ($e->kind === MouseEvent::SCROLL_UP || $e->kind === MouseEvent::SCROLL_DOWN)) {
                $d = $e->kind === MouseEvent::SCROLL_UP ? -1 : 1;
                $last = max(0, count($this->state->jsonNodes) - 1);
                $this->state->jsonSel = max(0, min($last, $this->state->jsonSel + $d));
            }
            return;
        }

        if ($e->kind === MouseEvent::SCROLL_UP || $e->kind === MouseEvent::SCROLL_DOWN) {
            // The wheel scrolls the cell inspector when the pointer is over it, in
            // any focus — the grid's own wheel-scroll is untouched elsewhere (§9).
            if ($this->pointInPopup($e->x, $e->y)) {
                $d = $e->kind === MouseEvent::SCROLL_UP ? -1 : 1;
                $this->state->cellViewScroll = max(0, $this->state->cellViewScroll + $d);
                return;
            }
            // Scrolling the data view requires DATA focus — in CLI mode the wheel
            // does nothing in the grid.
            if (!$this->state->hasResult() || $this->state->focus !== State::FOCUS_DATA) {
                return;
            }
            $delta = $e->kind === MouseEvent::SCROLL_UP ? -1 : 1;
            if ($this->state->vertical) {
                $this->state->verticalScroll = max(0, $this->state->verticalScroll + $delta);
                return;
            }
            $this->state->selectedRow = max(0, min($this->state->maxRow(), $this->state->selectedRow + $delta));
            return;
        }

        // A left-drag moves the inspector if one was grabbed, else steers whichever
        // scrollbar the press latched onto (§6/§9).
        if ($e->kind === MouseEvent::LEFT_DRAG) {
            if ($this->popupDrag !== null) {
                $this->moveCellPopup($e->x, $e->y);
            } else {
                $this->dragScrollbar($e);
            }
            return;
        }
        if ($e->kind !== MouseEvent::LEFT_DOWN) {
            return;
        }
        // A fresh press ends any prior drag latch before deciding what was hit.
        $this->scrollDrag = '';
        $this->popupDrag = null;

        // The inline editor stays modal: a click does nothing beneath it (§6).
        if ($this->state->cellEdit) {
            return;
        }
        // The non-modal inspector: a press on its title bar grabs it for dragging;
        // a press anywhere else on it is swallowed so the grid beneath is left
        // alone. Presses OUTSIDE it fall through to normal grid interaction (§9).
        if ($this->state->cellView && $this->pointInPopup($e->x, $e->y)) {
            $r = $this->state->cellPopupRect;
            if ($r !== null && $e->y <= $r[1] + 1) {
                $this->popupDrag = [$e->x - $r[0], $e->y - $r[1]];
            }
            return;
        }

        // The column move/width modes have no box; a click ends them like Esc.
        if ($this->state->moveMode) {
            $this->handleMoveKey(KeyEvent::special(Key::Escape));
            return;
        }
        if ($this->state->widthAdjust) {
            $this->handleWidthAdjustKey(KeyEvent::special(Key::Escape));
            return;
        }

        // A click outside the topmost dismissable popup/dialog closes it like
        // Esc; clicks while one is open never fall through to the grid (§6).
        if ($this->dismissableModalOpen()) {
            $r = $this->state->modalRect;
            $inside = $r !== null
                && $e->x >= $r[0] && $e->x < $r[0] + $r[2]
                && $e->y >= $r[1] && $e->y < $r[1] + $r[3];
            if (!$inside) {
                $this->dismissTopModal();
            }
            return;
        }

        // Click anywhere on the vertical "Layout Config" label (right margin)
        // opens the Layout popup (§6).
        if ($this->state->layoutIndicatorW > 0
            && $e->x >= $this->state->layoutIndicatorX
            && $e->x < $this->state->layoutIndicatorX + $this->state->layoutIndicatorW
            && $e->y >= $this->state->layoutIndicatorY
            && $e->y < $this->state->layoutIndicatorY + $this->state->layoutIndicatorH) {
            $this->openLayoutPopup();
            return;
        }

        // Everything below is data-view interaction (scrollbars, header/cell
        // selection). It is only live when the data view has focus — a click in
        // the DV while in CLI mode does nothing (use Ctrl+D to focus it first).
        if ($this->state->focus !== State::FOCUS_DATA) {
            return;
        }

        // A press on either scrollbar track jumps that window and latches a drag
        // so the thumb keeps following the cursor (§6).
        if ($this->pressOnScrollbar($e)) {
            return;
        }

        $gl = $this->state->gridLayout;
        if ($gl === null || !$this->state->hasResult()) {
            return;
        }
        $hitCol = $gl->columnAt($e->x);

        // Header click → open the column popup for that column (§6).
        if ($e->y === $gl->headerY) {
            if ($hitCol !== null) {
                $this->state->selectedCol = $hitCol;
                $this->openColumnPopup($hitCol);
            }
            return;
        }

        // Data click → select the row (and column when one is hit).
        if ($e->y >= $gl->dataTop && $e->y < $gl->dataTop + $gl->dataRows) {
            $row = $gl->rowOffset + ($e->y - $gl->dataTop);
            if ($row <= $this->state->maxRow()) {
                $this->state->selectedRow = $row;
                if ($hitCol !== null) {
                    $this->state->selectedCol = $hitCol;
                }
            }
        }
    }

    /**
     * Hit-test a left press against the scrollbar tracks. On a hit, jump that
     * window to the clicked position and latch a drag so subsequent motion keeps
     * steering the same bar. Returns true when the press was consumed (§6).
     */
    private function pressOnScrollbar(MouseEvent $e): bool
    {
        $s = $this->state;
        if ($s->vScrollLen > 0 && $e->x === $s->vScrollX
            && $e->y >= $s->vScrollTop && $e->y < $s->vScrollTop + $s->vScrollLen) {
            $this->scrollDrag = 'v';
            $this->jumpVScroll($e->y);
            return true;
        }
        if ($s->hScrollLen > 0 && $e->y === $s->hScrollY
            && $e->x >= $s->hScrollLeft && $e->x < $s->hScrollLeft + $s->hScrollLen) {
            $this->scrollDrag = 'h';
            $this->jumpHScroll($e->x);
            return true;
        }
        return false;
    }

    /** Continue steering the latched scrollbar as the cursor drags (§6). */
    private function dragScrollbar(MouseEvent $e): void
    {
        if ($this->scrollDrag === 'v' && $this->state->vScrollLen > 0) {
            $this->jumpVScroll($e->y);
        } elseif ($this->scrollDrag === 'h' && $this->state->hScrollLen > 0) {
            $this->jumpHScroll($e->x);
        }
    }

    /** Map a vertical-track Y to a row window and park the selection at its top. */
    private function jumpVScroll(int $y): void
    {
        $s = $this->state;
        $pos = max(0, min($s->vScrollLen - 1, $y - $s->vScrollTop));
        $off = $this->trackOffset($pos, $s->vScrollLen, $s->vScrollTotal, $s->vScrollVisible);
        $s->rowOffset = $off;
        $s->selectedRow = max(0, min($s->maxRow(), $off));
    }

    /** Map a horizontal-track X to a column window and park the selection at its left. */
    private function jumpHScroll(int $x): void
    {
        $s = $this->state;
        $pos = max(0, min($s->hScrollLen - 1, $x - $s->hScrollLeft));
        $off = $this->trackOffset($pos, $s->hScrollLen, $s->hScrollTotal, $s->hScrollVisible);
        $s->colOffset = $off;
        $s->selectedCol = $off;
    }

    /** Proportional map of a track position [0..trackLen-1] to an offset [0..total-visible]. */
    private function trackOffset(int $pos, int $trackLen, int $total, int $visible): int
    {
        $maxOff = max(0, $total - $visible);
        if ($trackLen <= 1 || $maxOff === 0) {
            return 0;
        }
        return max(0, min($maxOff, (int) round($pos / ($trackLen - 1) * $maxOff)));
    }

    /** True when a popup/dialog that can be closed by an outside click is open (§6). */
    private function dismissableModalOpen(): bool
    {
        return $this->state->confirm
            || $this->state->pkPopup
            || $this->state->help
            || $this->state->completion
            || $this->state->columnPopup
            || $this->state->layoutPopup
            || $this->state->systemMenu;
    }

    /** Dismiss the topmost dismissable modal as if Esc were pressed (§6). */
    private function dismissTopModal(): void
    {
        if ($this->state->confirm) {
            $this->handleConfirmKey(KeyEvent::special(Key::Escape));
        } elseif ($this->state->pkPopup) {
            $this->closePkPopup();
        } elseif ($this->state->help) {
            $this->state->help = false;
        } elseif ($this->state->completion) {
            $this->state->completion = false;
        } elseif ($this->state->columnPopup) {
            $this->state->columnPopup = false;
        } elseif ($this->state->layoutPopup) {
            $this->state->layoutPopup = false;
        } elseif ($this->state->systemMenu) {
            $this->state->systemMenu = false;
        }
    }

    // --- detail view (§8) --------------------------------------------------

    private function handleDetailKey(KeyEvent $e): void
    {
        $result = $this->state->result;
        if ($result === null) {
            $this->state->detail = false;
            return;
        }
        $maxField = $result->columnCount() - 1;
        switch ($e->key) {
            case Key::Up:
                $this->state->detailFieldIdx = max(0, $this->state->detailFieldIdx - 1);
                break;
            case Key::Down:
                $this->state->detailFieldIdx = min($maxField, $this->state->detailFieldIdx + 1);
                break;
            case Key::Home:
                $this->state->detailFieldIdx = 0;
                break;
            case Key::End:
                $this->state->detailFieldIdx = $maxField;
                break;
            case Key::PageUp:
                $this->state->detailFieldIdx = max(0, $this->state->detailFieldIdx - $this->detailPageStep());
                break;
            case Key::PageDown:
                $this->state->detailFieldIdx = min($maxField, $this->state->detailFieldIdx + $this->detailPageStep());
                break;
            case Key::Escape:
            case Key::Enter:
                // Esc closes the innermost window; with no JSON viewer open yet
                // that is the detail view. Enter also closes.
                $this->state->detail = false;
                break;
            case Key::Char:
                // `i` toggles the detail view closed (mirrors opening it from
                // DATA focus, §8). `j`/`J` opens the JSON viewer on the selected
                // field, layered over the detail view (§10).
                if (!$e->ctrl && !$e->alt && strtolower($e->char) === 'i') {
                    $this->state->detail = false;
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'j') {
                    $this->openJsonFromDetail();
                }
                break;
            default:
                break;
        }
    }

    // --- column & layout popups (§6) ---------------------------------------

    private function openColumnPopup(int $visibleColIdx): void
    {
        if (!$this->state->hasResult() || $this->state->totalCols() === 0) {
            return;
        }
        $this->state->popupCol = max(0, min($visibleColIdx, $this->state->totalCols() - 1));
        $this->state->popupItem = 0; // first item is selectable
        $this->state->columnPopup = true;
    }

    private function openLayoutPopup(): void
    {
        $this->state->layoutPopup = true;
        $this->state->layoutItem = 0;
    }

    // --- system menu (§12) -------------------------------------------------

    /**
     * Build the system-menu model fresh each time it's needed so labels and
     * enabled-state stay live (debug on/off, "Show DD" greyed until a DD loads,
     * "Layout Config" only with a result). Assign-DD and Theme are present but
     * disabled until their subsystems land (backlog H-pickers / J).
     *
     * @return list<array{label:string,action:string,enabled:bool}>
     */
    public function systemMenuItems(): array
    {
        return [
            ['label' => 'Assign Data Dictionary', 'action' => 'assign_dd', 'enabled' => true],
            ['label' => 'Show Data Dictionary',   'action' => 'show_dd',   'enabled' => $this->dictionaryAvailable()],
            ['label' => 'Theme',                  'action' => 'theme',     'enabled' => false],
            ['label' => 'Debug Logging: ' . ($this->state->debugLogging ? 'on' : 'off'),
                'action' => 'debug_logging', 'enabled' => true],
            ['label' => 'Layout Config', 'action' => 'layout_config', 'enabled' => $this->state->hasResult()],
        ];
    }

    private function openSystemMenu(): void
    {
        $this->state->systemMenu = true;
        // Land on the first enabled item.
        $this->state->systemMenuItem = 0;
        foreach ($this->systemMenuItems() as $i => $it) {
            if ($it['enabled']) {
                $this->state->systemMenuItem = $i;
                break;
            }
        }
    }

    private function handleSystemMenuKey(KeyEvent $e): void
    {
        $items = $this->systemMenuItems();
        switch ($e->key) {
            case Key::Up:
                $this->state->systemMenuItem = $this->menuMove($items, $this->state->systemMenuItem, -1);
                break;
            case Key::Down:
                $this->state->systemMenuItem = $this->menuMove($items, $this->state->systemMenuItem, 1);
                break;
            case Key::Escape:
                $this->state->systemMenu = false;
                break;
            case Key::Enter:
                $sel = $items[$this->state->systemMenuItem] ?? null;
                if ($sel !== null && $sel['enabled']) {
                    $this->state->systemMenu = false;
                    $this->applySystemMenuAction($sel['action']);
                }
                break;
            case Key::Char:
                // A second `/` right after the one that opened the menu means the
                // user actually wanted a literal slash: close the menu and drop a
                // single `/` onto the CLI line they're editing (CLI focus only).
                if ($e->char === '/' && !$e->ctrl && !$e->alt) {
                    $this->state->systemMenu = false;
                    if ($this->state->focus === State::FOCUS_CLI) {
                        $this->input->editor()->insert('/');
                        $this->resetHistoryNav();
                    }
                }
                break;
            default:
                break;
        }
    }

    /**
     * Step the selection to the next item, skipping only **separators** (null
     * label). Disabled items are still landable so the cursor always moves (a
     * menu with a single enabled item would otherwise feel dead); Enter handlers
     * guard `enabled`, so a disabled item just can't be activated.
     *
     * @param list<array{label:?string,action:?string,enabled:bool}> $items
     */
    private function menuMove(array $items, int $cur, int $dir): int
    {
        $n = count($items);
        $i = $cur;
        for ($step = 0; $step < $n; $step++) {
            $i = ($i + $dir + $n) % $n;
            if (($items[$i]['label'] ?? null) !== null) {
                return $i;
            }
        }
        return $cur;
    }

    private function applySystemMenuAction(string $action): void
    {
        switch ($action) {
            case 'debug_logging':
                $this->toggleDebugLogging();
                break;
            case 'layout_config':
                if ($this->state->hasResult()) {
                    $this->openLayoutPopup();
                }
                break;
            case 'show_dd':
                $this->openDdViewer();
                break;
            case 'assign_dd':
                $this->openDdPicker();
                break;
            // theme (backlog J) wires in once the theme system exists; its entry
            // stays disabled until then.
            default:
                break;
        }
    }

    private function toggleDebugLogging(): void
    {
        $this->state->debugLogging = !$this->state->debugLogging;
        my::$enabled = $this->state->debugLogging;
        if ($this->state->debugLogging) {
            $this->state->statusLine = 'Debug logging ON · ' . my::$debugLogPath;
        } else {
            $this->state->statusLine = 'Debug logging OFF';
        }
        $this->state->statusIsError = false;
    }

    // --- Data Dictionary viewer (§11) --------------------------------------

    private const DD_PAGE = 10;

    private function openDdViewer(): void
    {
        $this->ensureDictionaryLoaded();
        if ($this->dictionary === null) {
            $this->state->statusLine = 'No data dictionary for this database — assign one first.';
            $this->state->statusIsError = true;
            return;
        }
        // Recompute diffs from scratch this session so they reflect the current
        // live schema (it may have changed via DDL since the last open).
        $this->ddDiffCache = [];
        // Diffing every DD table against the live schema runs a SHOW COLUMNS per
        // table on first open (they cache after), which can take a while on a big
        // DD. Do it all now behind a busy popup so the badges are ready and the
        // viewer is responsive once it's up — rather than the grid stuttering as
        // each row diffs itself. (Only shows the popup when real queries are due.)
        $this->warmDdDiffs();
        // Seed the lock indicator now and force the first poll on the next frame.
        $this->state->ddLocked = $this->dictionary->isLocked();
        $this->ddLockNextCheck = microtime(true) + self::DD_LOCK_POLL;
        $this->state->ddViewer = true;
        $this->state->ddFkView = false;
        $this->state->ddPanel = 0;
        $this->state->ddFilter = '';
        $this->state->ddFilterCur = 0;
        // Land on the table from the current single-table SELECT, if the DD knows
        // it; otherwise start at the top (§11).
        $this->state->ddTableSel = $this->ddIndexOfTable($this->state->currentTable);
        $this->state->ddFieldSel = 0;
        $this->state->ddTableOffset = 0;
        $this->state->ddFieldOffset = 0;
    }

    /**
     * Throttled check of whether the DD file is locked by another program (§11).
     * When it transitions from locked → free and the file changed while we were
     * locked out, refresh from disk so the viewer shows the latest content.
     */
    private function pollDdLock(): void
    {
        if ($this->dictionary === null) {
            return;
        }
        $now = microtime(true);
        if ($now < $this->ddLockNextCheck) {
            return;
        }
        $this->ddLockNextCheck = $now + self::DD_LOCK_POLL;

        $was = $this->state->ddLocked;
        $this->state->ddLocked = $this->dictionary->isLocked();
        if ($was && !$this->state->ddLocked && $this->dictionary->isStale()) {
            $this->dictionary->reload();
            $this->ddDiffCache = [];
            $this->state->statusLine = 'DD file freed — refreshed from disk.';
            $this->state->statusIsError = false;
        }
    }

    /** Index of $table in the (unfiltered) DD table list, case-insensitive; 0 if absent. */
    private function ddIndexOfTable(string $table): int
    {
        if ($table === '' || $this->dictionary === null) {
            return 0;
        }
        foreach ($this->dictionary->tableNames() as $i => $name) {
            if (strcasecmp($name, $table) === 0) {
                return $i;
            }
        }
        return 0;
    }

    /**
     * @return list<string> tables to show: when the search is active, any table
     * whose name/description matches, or that has a field whose name/comment
     * matches (§11).
     */
    private function ddFilteredTables(): array
    {
        if ($this->dictionary === null) {
            return [];
        }
        $all = $this->dictionary->tableNames();
        $needle = $this->state->ddFilter;
        if ($needle === '') {
            return array_values($all);
        }
        $out = [];
        foreach ($all as $table) {
            if ($this->ddTableHasMatch($table, $needle)) {
                $out[] = $table;
            }
        }
        return $out;
    }

    /** True if the table's own name or description contains the needle. */
    private function ddTableMatchesDirect(string $table, string $needle): bool
    {
        if (stripos($table, $needle) !== false) {
            return true;
        }
        $desc = $this->dictionary?->description($table) ?? '';
        return $desc !== '' && stripos($desc, $needle) !== false;
    }

    /** True if the table matches directly or via any of its fields. */
    private function ddTableHasMatch(string $table, string $needle): bool
    {
        if ($this->ddTableMatchesDirect($table, $needle)) {
            return true;
        }
        foreach ($this->dictionary?->fields($table) ?? [] as $f) {
            if ($this->ddFieldMatches($f, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{name:string,type:string,comment:string,nullable:string,key:string,default:string} $f
     */
    private function ddFieldMatches(array $f, string $needle): bool
    {
        return stripos($f['name'], $needle) !== false
            || stripos($f['comment'], $needle) !== false;
    }

    /** The currently selected table name (after filtering), or ''. */
    private function ddCurrentTable(): string
    {
        $tables = $this->ddFilteredTables();
        if ($tables === []) {
            return '';
        }
        $sel = max(0, min($this->state->ddTableSel, count($tables) - 1));
        return $tables[$sel];
    }

    /**
     * @return list<array{name:string,type:string,comment:string,nullable:string,key:string,default:string}>
     */
    private function ddFilteredFields(): array
    {
        $table = $this->ddCurrentTable();
        if ($table === '' || $this->dictionary === null) {
            return [];
        }
        $fields = $this->dictionary->fields($table);
        $needle = $this->state->ddFilter;
        // Show every field when there's no search, or when the table itself matched
        // (found by name/description → show its whole field list). Otherwise narrow
        // to the fields whose name or comment matches.
        $showAll = $needle === '' || $this->ddTableMatchesDirect($table, $needle);
        $out = $showAll
            ? $fields
            : array_values(array_filter(
                $fields,
                fn (array $f): bool => $this->ddFieldMatches($f, $needle),
            ));

        // Append the read-only foreign-keys summary as a final pseudo-row (§11):
        // viewable, never editable, and excluded from the schema diff.
        $fk = $this->dictionary->foreignKeys($table);
        if ($fk !== '' && ($showAll || stripos($fk, $needle) !== false
                || stripos('foreign keys', $needle) !== false)) {
            $out[] = $this->ddFkRow($fk);
        }
        return $out;
    }

    /**
     * The synthetic field row that represents a table's foreign-keys summary.
     * The `fk` flag marks it read-only so Enter opens a viewer (not the comment
     * editor) and the renderer can style/label it distinctly.
     *
     * @return array{name:string,type:string,comment:string,nullable:string,key:string,default:string,fk:bool}
     */
    private function ddFkRow(string $fk): array
    {
        return [
            'name' => '-- Foreign Keys --', 'type' => '', 'comment' => $fk,
            'nullable' => '', 'key' => '', 'default' => '', 'fk' => true,
        ];
    }

    /** Publish the filtered lists + selection for the renderer (called in draw). */
    private function ddPublish(): void
    {
        $tables = $this->ddFilteredTables();
        $this->state->ddTableSel = $this->clampIndex($this->state->ddTableSel, count($tables));
        $this->state->ddTables = $tables;
        $table = $tables[$this->state->ddTableSel] ?? '';
        $this->state->ddCurrentTable = $table;
        $this->state->ddDescription = ($table !== '' && $this->dictionary !== null)
            ? $this->dictionary->description($table)
            : '';
        $fields = $this->ddFilteredFields();
        $this->state->ddFieldSel = $this->clampIndex($this->state->ddFieldSel, count($fields));
        $this->state->ddFields = $fields;

        $this->ddPublishDiff($tables, $table);
    }

    /**
     * Publish schema-diff badges for the table list + field-level status for the
     * selected table (§11). All diffs are pre-warmed into {@see $ddDiffCache} by
     * {@see warmDdDiffs()} when the viewer opens, so these are cheap cache reads —
     * no per-frame windowing. Skipped entirely with no live schema.
     *
     * @param list<string> $tables filtered table names (parallel to badges)
     */
    private function ddPublishDiff(array $tables, string $selectedTable): void
    {
        $this->state->ddDiffReady = $this->ddDiffAvailable();
        $badges = [];
        $fieldStatus = [];
        $missing = [];
        if ($this->state->ddDiffReady) {
            foreach ($tables as $i => $t) {
                $badges[$i] = $this->ddBadgeFor($t);
            }
            if ($selectedTable !== '') {
                $diff = $this->ddDiffForTable($selectedTable);
                if ($diff !== null) {
                    $fieldStatus = $diff['fieldStatus'];
                    $missing = $diff['missing'];
                }
            }
        }
        $this->state->ddTableBadges = $badges;
        $this->state->ddFieldStatus = $fieldStatus;
        $this->state->ddMissingFields = $missing;
    }

    /** True when a live connection can supply schema for diffing (any table back). */
    private function ddDiffAvailable(): bool
    {
        return $this->schema->tables() !== [];
    }

    /** '' = matches / 'diff' = field-level differences / 'orphan' = no live table. */
    private function ddBadgeFor(string $table): string
    {
        $diff = $this->ddDiffForTable($table);
        if ($diff === null) {
            return 'orphan';
        }
        return $diff['hasDiff'] ? 'diff' : '';
    }

    /**
     * Diff every DD table against the live schema up front (populating
     * {@see $ddDiffCache}), shown behind a "please wait" popup. Called on viewer
     * open so all badges are ready and the viewer doesn't stutter as each visible
     * row diffs itself. The popup is only shown when at least one table still
     * needs a real `SHOW COLUMNS` round-trip (first open, or after a `USE` reset);
     * on a warm re-open the recompute is pure/cached, so no popup flash.
     */
    private function warmDdDiffs(): void
    {
        if ($this->dictionary === null) {
            return;
        }
        $ddTables = $this->dictionary->tableNames();

        // Will warming actually hit the DB? If the table list isn't loaded yet it
        // certainly will (SHOW TABLES + every SHOW COLUMNS). Otherwise, check for
        // any live DD table whose columns aren't cached. hasTable() below is safe
        // to call only once tables() is known (else it would query outside the
        // popup) — the isLoaded() short-circuit guarantees that.
        $needsQuery = !$this->schema->isLoaded();
        if (!$needsQuery) {
            foreach ($ddTables as $t) {
                if ($this->schema->hasTable($t) && !$this->schema->isDescribed($t)) {
                    $needsQuery = true;
                    break;
                }
            }
        }

        // Warm re-open: everything's cached, so this is a pure recompute — no popup.
        if (!$needsQuery) {
            foreach ($ddTables as $t) {
                $this->ddDiffForTable($t);
            }
            return;
        }

        // Real queries are due. Each table is a separate SHOW COLUMNS, so we can
        // repaint an advancing "Loading Data Dictionary   NN%" between them. The
        // message stays user-facing — it doesn't expose that we diff the schema.
        $total = max(1, count($ddTables));
        $done = 0;
        $lastPct = -1;
        $this->state->busyMessage = 'Loading Data Dictionary …';
        $this->flushFrame();
        try {
            foreach ($ddTables as $t) {
                $this->ddDiffForTable($t); // SHOW COLUMNS only if live + uncached
                $done++;
                $pct = intdiv($done * 100, $total);
                if ($pct !== $lastPct) { // only repaint when the number changes
                    $lastPct = $pct;
                    $this->state->busyMessage = 'Loading Data Dictionary   ' . $pct . '%';
                    $this->flushFrame();
                }
            }
        } finally {
            $this->state->busyMessage = '';
        }
    }

    /**
     * Diff one DD table against the live schema, cached per table. Null when the
     * table has no live counterpart (an orphaned DD entry).
     *
     * @return array{fieldStatus:array<string,string>,missing:list<string>,changed:list<string>,extra:list<string>,hasDiff:bool}|null
     */
    private function ddDiffForTable(string $table): ?array
    {
        $key = strtolower($table);
        if (array_key_exists($key, $this->ddDiffCache)) {
            return $this->ddDiffCache[$key];
        }
        if ($this->dictionary === null || !$this->schema->hasTable($table)) {
            return $this->ddDiffCache[$key] = null;
        }
        return $this->ddDiffCache[$key] = SchemaDiff::compare(
            $this->dictionary->fields($table),
            $this->schema->describeRows($table),
        );
    }

    private function clampIndex(int $i, int $count): int
    {
        return $count <= 0 ? 0 : max(0, min($i, $count - 1));
    }

    private function handleDdViewerKey(KeyEvent $e): void
    {
        // The inline comment editor (Enter on a field) owns input while open.
        if ($this->state->ddCommentEdit) {
            $this->handleDdCommentKey($e);
            return;
        }

        // The read-only foreign-keys view is a quick look — any key dismisses it.
        if ($this->state->ddFkView) {
            $this->state->ddFkView = false;
            return;
        }

        $onTables = $this->state->ddPanel === 0;
        switch ($e->key) {
            case Key::Enter:
                // Enter on the tables panel drills into the fields panel; on the
                // fields panel it opens the inline comment editor — except on the
                // read-only foreign-keys row, which opens a viewer instead (§11).
                if ($onTables) {
                    $this->state->ddPanel = 1;
                } else {
                    $field = $this->ddFilteredFields()[$this->state->ddFieldSel] ?? null;
                    if ($field !== null && ($field['fk'] ?? false)) {
                        $this->openDdFkView((string) $field['comment']);
                    } else {
                        $this->openDdCommentEdit();
                    }
                }
                break;
            case Key::Escape:
                // Clear the search first; close only when it's already empty.
                if ($this->state->ddFilter !== '') {
                    $this->state->ddFilter = '';
                    $this->state->ddFilterCur = 0;
                    $this->ddOnFilterChanged();
                } else {
                    $this->state->ddViewer = false;
                }
                break;
            case Key::Left:
                if ($e->ctrl) {
                    $this->state->ddPanel = 0;
                } else {
                    $this->ddMoveFilterCursor(-1);
                }
                break;
            case Key::Right:
                if ($e->ctrl) {
                    $this->state->ddPanel = 1;
                } else {
                    $this->ddMoveFilterCursor(1);
                }
                break;
            case Key::Up:
                $this->ddMoveSelection(-1);
                break;
            case Key::Down:
                $this->ddMoveSelection(1);
                break;
            case Key::PageUp:
                $this->ddMoveSelection(-$this->ddPageStep());
                break;
            case Key::PageDown:
                $this->ddMoveSelection($this->ddPageStep());
                break;
            case Key::Home:
                $this->ddSetSelection(0);
                break;
            case Key::End:
                $this->ddSetSelection(PHP_INT_MAX);
                break;
            case Key::Backspace:
                $this->ddBackspaceFilter();
                break;
            case Key::Char:
                // Printable characters extend the single search box; ctrl/alt
                // combos are ignored here.
                if (!$e->ctrl && !$e->alt && $e->char !== '') {
                    $this->ddInsertFilter($e->char);
                }
                break;
            default:
                break;
        }
    }

    /**
     * One PageUp/PageDown step: a full visible page of the active panel. Prefer
     * the exact panel height the renderer published; if it hasn't published yet
     * (e.g. before the first viewer frame), derive it from the live screen height
     * using the same geometry the renderer uses (list starts at row 3, ends at
     * h-2 → h-4 rows). Only if even that is unavailable do we fall back to the
     * DD_PAGE constant — so paging is never silently stuck at that small default.
     */
    private function ddPageStep(): int
    {
        $rows = $this->state->ddPanel === 0
            ? $this->state->ddTablePageRows
            : $this->state->ddFieldPageRows;
        if ($rows <= 0) {
            $rows = $this->screen->height() - 4;
        }
        return max(1, $rows > 0 ? $rows : self::DD_PAGE);
    }

    private function ddMoveSelection(int $delta): void
    {
        if ($this->state->ddPanel === 0) {
            $n = count($this->ddFilteredTables());
            $this->state->ddTableSel = max(0, min(max(0, $n - 1), $this->state->ddTableSel + $delta));
            $this->state->ddFieldSel = 0; // new table → reset the field panel
        } else {
            $n = count($this->ddFilteredFields());
            $this->state->ddFieldSel = max(0, min(max(0, $n - 1), $this->state->ddFieldSel + $delta));
        }
    }

    private function ddSetSelection(int $idx): void
    {
        if ($this->state->ddPanel === 0) {
            $n = count($this->ddFilteredTables());
            $this->state->ddTableSel = $this->clampIndex($idx, $n);
            $this->state->ddFieldSel = 0;
        } else {
            $n = count($this->ddFilteredFields());
            $this->state->ddFieldSel = $this->clampIndex($idx, $n);
        }
    }

    private function ddMoveFilterCursor(int $dir): void
    {
        $len = mb_strlen($this->state->ddFilter);
        $this->state->ddFilterCur = max(0, min($len, $this->state->ddFilterCur + $dir));
    }

    private function ddInsertFilter(string $ch): void
    {
        $cur = $this->state->ddFilterCur;
        $f = $this->state->ddFilter;
        $this->state->ddFilter = mb_substr($f, 0, $cur) . $ch . mb_substr($f, $cur);
        $this->state->ddFilterCur = $cur + 1;
        $this->ddOnFilterChanged();
    }

    private function ddBackspaceFilter(): void
    {
        $cur = $this->state->ddFilterCur;
        if ($cur <= 0) {
            return;
        }
        $f = $this->state->ddFilter;
        $this->state->ddFilter = mb_substr($f, 0, $cur - 1) . mb_substr($f, $cur);
        $this->state->ddFilterCur = $cur - 1;
        $this->ddOnFilterChanged();
    }

    /** After the search text changes, restart both lists at the top (offsets self-heal). */
    private function ddOnFilterChanged(): void
    {
        $this->state->ddTableSel = 0;
        $this->state->ddFieldSel = 0;
    }

    /** Open the inline comment editor for the selected field (§11). */
    /** Open the read-only foreign-keys view for the selected table (§11). */
    private function openDdFkView(string $text): void
    {
        $this->state->ddFkText = $text;
        $this->state->ddFkView = true;
    }

    private function openDdCommentEdit(): void
    {
        if ($this->dictionary === null) {
            return;
        }
        // Don't open an editor we can't save: the file is held open elsewhere.
        if ($this->state->ddLocked || $this->dictionary->isLocked()) {
            $this->state->ddLocked = true;
            $this->state->statusLine = 'DD file is open in another program — editing disabled until it is closed.';
            $this->state->statusIsError = true;
            return;
        }
        $table = $this->ddCurrentTable();
        $fields = $this->ddFilteredFields();
        $field = $fields[$this->state->ddFieldSel] ?? null;
        if ($table === '' || $field === null) {
            return;
        }
        // The foreign-keys summary row is read-only — never edit it as a comment.
        if ($field['fk'] ?? false) {
            $this->openDdFkView((string) $field['comment']);
            return;
        }
        $editor = new LineEditor();
        $editor->setText($field['comment']);
        $editor->end();
        $this->state->ddCommentEditor = $editor;
        $this->state->ddCommentField = $field['name'];
        $this->state->ddCommentEdit = true;
    }

    private function handleDdCommentKey(KeyEvent $e): void
    {
        $editor = $this->state->ddCommentEditor;
        if ($editor === null) {
            $this->state->ddCommentEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->closeDdCommentEdit();
                break;
            case Key::Enter:
                // Enter adds a line; Ctrl+S saves (the editor-window convention,
                // §11). DD comments are multi-line and round-trip as quoted CSV.
                $editor->insertNewline();
                break;
            case Key::Backspace:
                $e->ctrl ? $editor->deleteWordLeft() : $editor->backspace();
                break;
            case Key::Delete:
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Up:
                $editor->upLine();
                break;
            case Key::Down:
                $editor->downLine();
                break;
            case Key::Home:
                // Home → start of the current line; Ctrl+Home → very start.
                $e->ctrl ? $editor->home() : $editor->lineHome();
                break;
            case Key::End:
                // End → end of the current line; Ctrl+End → very end.
                $e->ctrl ? $editor->end() : $editor->lineEnd();
                break;
            case Key::Char:
                // Ctrl+S commits the edit (the shared editor-window save key, §11).
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    $this->saveDdComment();
                } elseif (!$e->ctrl && !$e->alt && $e->char !== '') {
                    $editor->insert($e->char);
                }
                break;
            default:
                break;
        }
    }

    /** Write the edited comment into the DD and save the CSV immediately (§11). */
    private function saveDdComment(): void
    {
        $editor = $this->state->ddCommentEditor;
        if ($editor !== null && $this->dictionary !== null) {
            $table = $this->ddCurrentTable();
            $field = $this->state->ddCommentField;
            if ($table !== '' && $field !== '') {
                $new = $editor->text();
                $old = $this->dictionary->comment($table, $field) ?? '';
                if ($new === $old) {
                    // Nothing changed → don't rewrite the CSV or claim a save (§9).
                    $this->state->statusLine = 'DD comment unchanged · ' . $table . '.' . $field;
                    $this->state->statusIsError = false;
                } else {
                    $this->dictionary->setComment($table, $field, $new);
                    $status = $this->dictionary->save();
                    if ($status === DataDictionary::SAVE_LOCKED) {
                        // Someone opened the file during the edit. Keep the editor
                        // (and the typed text) open so Ctrl+S retries once it frees.
                        $this->state->ddLocked = true;
                        $this->state->statusLine = 'DD file is open in another program — Ctrl+S again once it is closed.';
                        $this->state->statusIsError = true;
                        return;
                    }
                    switch ($status) {
                        case DataDictionary::SAVE_OK:
                            $this->state->statusLine = 'DD comment saved · ' . $table . '.' . $field;
                            $this->state->statusIsError = false;
                            break;
                        case DataDictionary::SAVE_MERGED:
                            // Our cell was re-applied over a copy someone else had
                            // changed; refresh diffs so the viewer reflects it.
                            $this->ddDiffCache = [];
                            $this->state->statusLine = 'DD comment saved (merged external changes) · ' . $table . '.' . $field;
                            $this->state->statusIsError = false;
                            break;
                        default:
                            $this->state->statusLine = 'DD save failed — check file permissions';
                            $this->state->statusIsError = true;
                            break;
                    }
                }
            }
        }
        $this->closeDdCommentEdit();
    }

    private function closeDdCommentEdit(): void
    {
        $this->state->ddCommentEdit = false;
        $this->state->ddCommentEditor = null;
        $this->state->ddCommentField = '';
    }

    // --- Assign Data Dictionary picker (§11) -------------------------------

    /**
     * Open the two-stage Assign-DD picker. Stage 0 takes a directory path
     * (seeded from the current db's saved DD directory, else the working dir);
     * stage 1 lists the `.csv` files there to pick from.
     */
    private function openDdPicker(): void
    {
        $db = $this->database->currentDatabase();
        $saved = $db !== '' ? $this->settings->getDictionary($db) : null;
        $dir = ($saved !== null && $saved !== '') ? dirname($saved) : (getcwd() ?: '.');

        $editor = new LineEditor();
        $editor->setText($dir);
        $editor->end();
        $this->state->ddPickerEditor = $editor;
        $this->state->ddPicker = true;
        $this->state->ddPickerStage = 0;
        $this->state->ddPickerDir = '';
        $this->state->ddPickerFiles = [];
        $this->state->ddPickerSel = 0;
        $this->state->ddPickerOffset = 0;
        $this->state->ddPickerMessage = $db === ''
            ? 'No database selected — the choice applies to this session only.'
            : '';
    }

    private function closeDdPicker(): void
    {
        $this->state->ddPicker = false;
        $this->state->ddPickerEditor = null;
        $this->state->ddPickerFiles = [];
        $this->state->ddPickerMessage = '';
    }

    private function handleDdPickerKey(KeyEvent $e): void
    {
        if ($this->state->ddPickerStage === 0) {
            $this->handleDdPickerDirKey($e);
        } else {
            $this->handleDdPickerFileKey($e);
        }
    }

    /** Stage 0: editing the directory path. Enter scans it for `.csv` files. */
    private function handleDdPickerDirKey(KeyEvent $e): void
    {
        $editor = $this->state->ddPickerEditor;
        if ($editor === null) {
            $this->closeDdPicker();
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->closeDdPicker();
                break;
            case Key::Enter:
                $this->ddPickerScanDir($editor->text());
                break;
            case Key::Backspace:
                $e->ctrl ? $editor->deleteWordLeft() : $editor->backspace();
                break;
            case Key::Delete:
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Home:
                $editor->home();
                break;
            case Key::End:
                $editor->end();
                break;
            case Key::Char:
                if (!$e->ctrl && !$e->alt && $e->char !== '') {
                    $editor->insert($e->char);
                }
                break;
            default:
                break;
        }
    }

    /** Stage 1: choosing a file. Up/Down move, Enter assigns, Esc returns to entry. */
    private function handleDdPickerFileKey(KeyEvent $e): void
    {
        $files = $this->state->ddPickerFiles;
        switch ($e->key) {
            case Key::Escape:
                // Back to directory entry rather than closing outright.
                $this->state->ddPickerStage = 0;
                $this->state->ddPickerMessage = '';
                break;
            case Key::Up:
                $this->state->ddPickerSel = $this->clampIndex($this->state->ddPickerSel - 1, count($files));
                break;
            case Key::Down:
                $this->state->ddPickerSel = $this->clampIndex($this->state->ddPickerSel + 1, count($files));
                break;
            case Key::PageUp:
                $this->state->ddPickerSel = $this->clampIndex($this->state->ddPickerSel - self::DD_PAGE, count($files));
                break;
            case Key::PageDown:
                $this->state->ddPickerSel = $this->clampIndex($this->state->ddPickerSel + self::DD_PAGE, count($files));
                break;
            case Key::Home:
                $this->state->ddPickerSel = 0;
                break;
            case Key::End:
                $this->state->ddPickerSel = $this->clampIndex(count($files) - 1, count($files));
                break;
            case Key::Enter:
                $this->ddPickerAssign();
                break;
            default:
                break;
        }
    }

    /** Validate the typed directory and load its `.csv` list, or report why not. */
    private function ddPickerScanDir(string $dir): void
    {
        $raw = $dir;
        $dir = $this->normalizePathInput($dir);
        // Diagnostic (2026-07-03): the exact bytes reaching is_dir, to catch a
        // seed-concatenation or stray-character paste issue. json_encode reveals
        // hidden prefixes / control chars / whitespace the on-screen box hides.
        my::lineout(
            'FILE:FILE',
            'ddPickerScanDir',
            'raw=' . json_encode($raw),
            'norm=' . json_encode($dir),
            'len=' . mb_strlen($dir),
            'is_dir=' . (is_dir($dir) ? 'Y' : 'N'),
            'os=' . PHP_OS_FAMILY,
            'sep=' . DIRECTORY_SEPARATOR,
        );
        if ($dir === '') {
            $this->state->ddPickerMessage = 'Enter a directory path.';
            return;
        }
        if (!is_dir($dir)) {
            // Char count leads so it stays visible even if the path tail is
            // truncated — a count larger than expected exposes hidden text.
            $this->state->ddPickerMessage = sprintf('Not a directory (%d chars): %s', mb_strlen($dir), $dir);
            return;
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_file($dir . DIRECTORY_SEPARATOR . $name)
                && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'csv') {
                $files[] = $name;
            }
        }
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        if ($files === []) {
            $this->state->ddPickerMessage = 'No .csv files in: ' . $dir;
            return;
        }
        $this->state->ddPickerDir = rtrim($dir, '/\\');
        $this->state->ddPickerFiles = $files;
        $this->state->ddPickerSel = 0;
        $this->state->ddPickerStage = 1;
        $this->state->ddPickerMessage = '';
    }

    /**
     * Clean a user-entered filesystem path. Trims whitespace and strips a single
     * surrounding matched pair of quotes — Windows Explorer's "Copy as path"
     * wraps the path in double quotes, which would otherwise fail `is_dir()`.
     */
    private function normalizePathInput(string $path): string
    {
        $path = trim($path);
        $len = strlen($path);
        if ($len >= 2) {
            $first = $path[0];
            $last = $path[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $path = substr($path, 1, -1);
            }
        }
        $path = trim($path);

        // Running under WSL/Linux PHP, a Windows drive path (`D:\a\b`, e.g. pasted
        // from Windows Explorer) is meaningless — is_dir() just fails. Translate it
        // to the `/mnt/<drive>/a/b` auto-mount form so paste-from-Windows works.
        // Gated to Linux (separator '/'), so native Windows/Git-Bash is untouched.
        if (DIRECTORY_SEPARATOR === '/' && preg_match('#^([A-Za-z]):[\\\\/](.*)$#', $path, $m)) {
            $path = '/mnt/' . strtolower($m[1]) . '/' . str_replace('\\', '/', $m[2]);
        }

        return $path;
    }

    /** Load + persist the selected `.csv` as this db's dictionary (§11). */
    private function ddPickerAssign(): void
    {
        $files = $this->state->ddPickerFiles;
        $name = $files[$this->state->ddPickerSel] ?? null;
        if ($name === null) {
            return;
        }
        $path = $this->state->ddPickerDir . DIRECTORY_SEPARATOR . $name;
        $this->loadDictionary($this->database->currentDatabase(), $path);
        if ($this->dictionary !== null) {
            $this->state->statusLine = 'Data dictionary assigned · ' . $name
                . ' (' . count($this->dictionary->tableNames()) . ' tables)';
            $this->state->statusIsError = false;
            $this->closeDdPicker();
        } else {
            // loadDictionary already logged the failure; keep the picker open.
            $this->state->ddPickerMessage = 'Failed to load ' . $name . ' — not a valid dictionary CSV.';
        }
    }

    /**
     * `word?` in the CLI shows that field's DD comment for the table in the FROM
     * clause (§11). Called right after the `?` was inserted; leaves `?` as a
     * literal when it isn't preceded by a word (the popup never opens).
     */
    private function openDdHint(): void
    {
        $editor = $this->input->editor();
        $before = mb_substr($editor->text(), 0, $editor->cursor()); // includes the '?'
        if (preg_match('/([\p{L}\p{N}_]+)\?$/u', $before, $m) !== 1) {
            return;
        }
        $word = $m[1];

        // The hint is a DD feature → lazy-load the association now (§11).
        $this->ensureDictionaryLoaded();

        // ddHintTitle is the "table.field" heading (centered, separator below);
        // ddHintMessage is the comment body. A blank title = a plain message box.
        $this->state->ddHintTitle = '';
        if ($this->dictionary === null) {
            $this->state->ddHintMessage =
                'No data dictionary for this database — assign one from the system menu (press /).';
        } else {
            $table = $this->extractTableFromSql($this->input->combinedText());
            if ($table === '') {
                $this->state->ddHintMessage = "No FROM table to look up '$word' in.";
            } else {
                $comment = $this->dictionary->comment($table, $word);
                if ($comment === null) {
                    $this->state->ddHintMessage = "No data dictionary entry for $table.$word";
                } else {
                    $this->state->ddHintTitle = $table . '.' . $word;
                    $this->state->ddHintMessage = $comment === '' ? '(no comment)' : $comment;
                }
            }
        }
        $this->state->ddHint = true;
    }

    private function handleColumnPopupKey(KeyEvent $e): void
    {
        switch ($e->key) {
            case Key::Up:
                $this->state->popupItem = $this->popupMove(State::COLUMN_POPUP, $this->state->popupItem, -1);
                break;
            case Key::Down:
                $this->state->popupItem = $this->popupMove(State::COLUMN_POPUP, $this->state->popupItem, 1);
                break;
            case Key::Escape:
                $this->state->columnPopup = false;
                break;
            case Key::Enter:
                $action = State::COLUMN_POPUP[$this->state->popupItem][1] ?? null;
                $this->state->columnPopup = false;
                if ($action !== null) {
                    $this->applyColumnAction($action);
                }
                break;
            default:
                break;
        }
    }

    private function handleLayoutPopupKey(KeyEvent $e): void
    {
        $items = $this->layoutPopupItems();
        switch ($e->key) {
            case Key::Up:
                $this->state->layoutItem = $this->menuMove($items, $this->state->layoutItem, -1);
                break;
            case Key::Down:
                $this->state->layoutItem = $this->menuMove($items, $this->state->layoutItem, 1);
                break;
            case Key::Escape:
                $this->state->layoutPopup = false;
                break;
            case Key::Enter:
                $sel = $items[$this->state->layoutItem] ?? null;
                if ($sel === null || !$sel['enabled'] || $sel['action'] === null) {
                    break;
                }
                $action = $sel['action'];
                // Unhiding a single column keeps the popup open so several can be
                // unhidden in a row; everything else closes it.
                $keepOpen = str_starts_with($action, 'unhide:');
                $this->applyLayoutActionStr($action);
                if ($keepOpen) {
                    $this->state->layoutItem = $this->clampMenuIndex(
                        $this->layoutPopupItems(),
                        $this->state->layoutItem,
                    );
                } else {
                    $this->state->layoutPopup = false;
                }
                break;
            default:
                break;
        }
    }

    /**
     * Step the popup selection past separators (null-action items).
     *
     * @param list<array{0:?string,1:?string}> $items
     */
    private function popupMove(array $items, int $cur, int $dir): int
    {
        $n = count($items);
        $i = $cur;
        for ($step = 0; $step < $n; $step++) {
            $i = ($i + $dir + $n) % $n;
            if (($items[$i][1] ?? null) !== null) {
                return $i;
            }
        }
        return $cur;
    }

    private function applyColumnAction(string $action): void
    {
        $cols = $this->state->visibleColumnNames();
        $name = $cols[$this->state->popupCol] ?? null;
        if ($name === null) {
            return;
        }
        switch ($action) {
            case 'sort_asc':
                $this->state->sortCol = $name;
                $this->state->sortDir = 'asc';
                $this->state->applySort();
                break;
            case 'sort_desc':
                $this->state->sortCol = $name;
                $this->state->sortDir = 'desc';
                $this->state->applySort();
                break;
            case 'sort_clear':
                $this->state->sortCol = null;
                $this->state->applySort();
                break;
            case 'move':
                // Target the popup's column, then enter the move mode (§6).
                $this->enterMoveMode($this->state->popupCol);
                break;
            case 'width':
                // Target the popup's column, then enter the resize mode (§6).
                $this->state->selectedCol = $this->state->popupCol;
                $this->state->widthAdjust = true;
                break;
            case 'fit':
                $this->state->colWidthOverride[$name] = $this->naturalWidth($name);
                $this->persistWidths();
                break;
            case 'fit_all':
                // Fit every visible column to its content, capped at 40 chars.
                foreach ($this->state->visibleColumnNames() as $vn) {
                    $this->state->colWidthOverride[$vn] = min(40, $this->naturalWidth($vn));
                }
                $this->persistWidths();
                break;
            case 'copy_col':
                $this->copyColumn();
                break;
            case 'hide':
                $this->hideColumn($name);
                break;
        }
    }

    /**
     * Build the Layout popup model. The reset actions are always present; when
     * any columns are hidden, a separator is followed by "Show all hidden
     * columns" and one "Unhide: <name>" entry per hidden column so they can be
     * restored individually (§6).
     *
     * @return list<array{label:?string,action:?string,enabled:bool}>
     */
    public function layoutPopupItems(): array
    {
        // Spell out the active sort on the "Clear sort" entry so the user can see
        // what it would clear (and disable it when nothing is sorted).
        $sortLabel = 'Clear sort';
        if ($this->state->sortCol !== null) {
            $arrow = $this->state->sortDir === 'desc' ? '↓' : '↑';
            $sortLabel = 'Clear sort (' . $this->state->sortCol . ' ' . $arrow . ')';
        }
        $items = [
            ['label' => 'Reset column widths', 'action' => 'reset_widths', 'enabled' => true],
            ['label' => 'Reset column order',  'action' => 'reset_order',  'enabled' => true],
            ['label' => $sortLabel,            'action' => 'clear_sort',   'enabled' => $this->state->sortCol !== null],
            ['label' => 'Reset all',           'action' => 'reset_all',    'enabled' => true],
        ];
        if ($this->state->hiddenCols !== []) {
            $items[] = ['label' => null, 'action' => null, 'enabled' => false];
            $items[] = ['label' => 'Show all hidden columns', 'action' => 'unhide_all', 'enabled' => true];
            foreach ($this->state->hiddenCols as $name) {
                $items[] = ['label' => 'Unhide: ' . $name, 'action' => 'unhide:' . $name, 'enabled' => true];
            }
        }
        return $items;
    }

    private function applyLayoutActionStr(string $action): void
    {
        if (str_starts_with($action, 'unhide:')) {
            $name = substr($action, strlen('unhide:'));
            $this->state->hiddenCols = array_values(array_filter(
                $this->state->hiddenCols,
                static fn (string $n): bool => $n !== $name,
            ));
            $this->persistHidden();
            $this->clampSelectedCol();
            return;
        }
        switch ($action) {
            case 'unhide_all':
                $this->state->hiddenCols = [];
                $this->persistHidden();
                break;
            case 'reset_widths':
                $this->state->colWidthOverride = [];
                $this->persistWidths();
                break;
            case 'reset_order':
                $this->state->colOrder = [];
                $this->persistColOrder();
                break;
            case 'clear_sort':
                $this->state->sortCol = null;
                $this->state->applySort();
                break;
            case 'reset_all':
                $this->state->hiddenCols = [];
                $this->state->colWidthOverride = [];
                $this->state->colOrder = [];
                $this->state->sortCol = null;
                $this->state->applySort();
                $this->persistHidden();
                $this->persistWidths();
                $this->persistColOrder();
                break;
        }
        $this->clampSelectedCol();
    }

    /**
     * Clamp a menu index into range and snap off a separator onto the nearest
     * real item — used after a menu's item list shrinks.
     *
     * @param list<array{label:?string,action:?string,enabled:bool}> $items
     */
    private function clampMenuIndex(array $items, int $idx): int
    {
        $n = count($items);
        if ($n === 0) {
            return 0;
        }
        $idx = max(0, min($idx, $n - 1));
        if (($items[$idx]['label'] ?? null) !== null) {
            return $idx;
        }
        return $this->menuMove($items, $idx, -1);
    }

    private function adjustWidth(string $name, int $delta): void
    {
        $current = $this->state->colWidthOverride[$name] ?? $this->naturalWidth($name);
        $this->state->colWidthOverride[$name] = max(1, min(200, $current + $delta));
        $this->persistWidths();
    }

    /**
     * Reorder a visible column left/right/first/last (§6). Operates on the
     * visible sequence the user sees, then rebuilds the full column order with
     * hidden columns appended. Persisted per db+table; the selection follows.
     */
    private function moveColumn(string $name, string $dir): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        $vis = $this->state->visibleColumnNames();
        $i = array_search($name, $vis, true);
        if ($i === false) {
            return;
        }
        $n = count($vis);
        $newI = match ($dir) {
            'left'  => max(0, $i - 1),
            'right' => min($n - 1, $i + 1),
            'home'  => 0,
            'end'   => $n - 1,
            default => $i,
        };
        if ($newI === $i) {
            return;
        }
        array_splice($vis, $i, 1);
        array_splice($vis, $newI, 0, [$name]);

        // Hidden columns aren't shown; park them after the visible order. When a
        // column is later unhidden it reappears at the end — predictable enough.
        $full = array_merge($vis, $this->state->hiddenCols);
        $this->state->colOrder = $full === $this->state->result->columns ? [] : $full;
        $this->state->selectedCol = $newI;
        $this->persistColOrder();
    }

    private function hideColumn(string $name): void
    {
        if (!in_array($name, $this->state->hiddenCols, true)) {
            $this->state->hiddenCols[] = $name;
            $this->persistHidden();
        }
        $this->clampSelectedCol();
    }

    /** Widest header/content length for a column, used by "Fit to content". */
    private function naturalWidth(string $name): int
    {
        $result = $this->state->result;
        if ($result === null) {
            return 3;
        }
        $max = mb_strlen($name);
        foreach ($result->rows as $row) {
            $v = $row[$name] ?? null;
            $len = $v === null ? 4 : mb_strlen((string) $v);
            if ($len > $max) {
                $max = $len;
            }
        }
        return max(1, $max);
    }

    /**
     * Page the column selection by one screen-width of columns (§7). Lands on
     * the first column that was just off-screen in the travel direction, so
     * repeated presses sweep quickly. Uses last frame's published geometry.
     */
    private function pageColumns(int $dir): void
    {
        $max = $this->state->totalCols() - 1;
        if ($max < 0) {
            return;
        }
        $gl = $this->state->gridLayout;
        if ($gl === null || $gl->columns === []) {
            $this->state->selectedCol = max(0, min($max, $this->state->selectedCol + $dir * 5));
            return;
        }
        $idxs = array_map(static fn (array $c): int => $c['idx'], $gl->columns);
        $pageSize = max(1, count($idxs));
        // Move the scroll offset by a whole screen of columns and park the
        // selection at the new left edge, so the renderer keeps it visible
        // without nudging the offset back by a single column (§7).
        $newOffset = $dir > 0 ? max($idxs) + 1 : min($idxs) - $pageSize;
        $newOffset = max(0, min($newOffset, $max));
        $this->state->colOffset = $newOffset;
        $this->state->selectedCol = $newOffset;
    }

    private function clampSelectedCol(): void
    {
        $vc = $this->state->totalCols();
        if ($this->state->selectedCol >= $vc) {
            $this->state->selectedCol = max(0, $vc - 1);
        }
    }

    private function persistWidths(): void
    {
        $db = $this->database->currentDatabase();
        if ($db === '' || $this->state->currentTable === '') {
            return;
        }
        $this->settings->setColWidths($db, $this->state->currentTable, $this->state->colWidthOverride);
    }

    private function persistHidden(): void
    {
        $db = $this->database->currentDatabase();
        if ($db === '' || $this->state->currentTable === '') {
            return;
        }
        $this->settings->setHiddenCols($db, $this->state->currentTable, array_values($this->state->hiddenCols));
    }

    private function persistColOrder(): void
    {
        $db = $this->database->currentDatabase();
        if ($db === '' || $this->state->currentTable === '') {
            return;
        }
        $this->settings->setColOrder($db, $this->state->currentTable, array_values($this->state->colOrder));
    }

    /**
     * Resolve a table's column names for mixed-star expansion (§5). Prefer the
     * Data Dictionary when it knows the table (§11) — no DB round-trip — and fall
     * back to a `SHOW COLUMNS` query otherwise.
     *
     * @return list<string>
     */
    private function resolveColumns(string $table): array
    {
        if ($this->dictionary !== null && $this->dictionary->hasTable($table)) {
            $cols = $this->dictionary->columns($table);
            if ($cols !== []) {
                return $cols;
            }
        }
        return $this->schema->columns($table);
    }

    /**
     * Best-effort table name from a SQL statement (FROM / INTO / UPDATE).
     * Shared by per-table layout persistence and, later, tab completion + DD.
     */
    private function extractTableFromSql(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Decide whether a data-modifying statement needs the dangerous-query confirm
     * (§5). Returns the popup message when confirmation is required, or null when
     * the statement is safe to run immediately. DELETE/UPDATE are nuanced (only a
     * broad WHERE prompts); every other modifying command always prompts.
     */
    private function dangerCheck(string $sql, string $type): ?string
    {
        if ($type === 'DELETE' || $type === 'UPDATE') {
            return $this->dangerCheckDml($sql, $type);
        }
        return $this->dangerCheckDdl($sql, $type);
    }

    /**
     * DELETE/UPDATE: confirm only when the statement is broad — no WHERE clause,
     * or a WHERE that doesn't reference a primary-key column. A PK-scoped
     * statement runs immediately (returns null). The message reports the query
     * type, table, whether a WHERE was present, and an estimated affected count.
     */
    private function dangerCheckDml(string $sql, string $type): ?string
    {
        $table = $this->extractTableFromSql($sql);
        $hasWhere = preg_match('/\bWHERE\b(.*)$/is', $sql, $m) === 1;
        $whereClause = $hasWhere ? $m[1] : '';

        $refsPk = false;
        if ($hasWhere && $table !== '') {
            foreach ($this->schema->primaryKey($table) as $col) {
                // The PK column must appear as an operand in the WHERE, not just
                // anywhere in the text (e.g. inside another column's name).
                $pat = '/(^|[^A-Za-z0-9_`])`?' . preg_quote($col, '/')
                    . '`?\s*(=|<|>|!|\bIN\b|\bIS\b|\bLIKE\b|\bBETWEEN\b)/i';
                if (preg_match($pat, $whereClause) === 1) {
                    $refsPk = true;
                    break;
                }
            }
        }

        if ($hasWhere && $refsPk) {
            return null; // scoped to specific rows by PK — safe to run
        }

        $count = $this->estimateAffected($table, $hasWhere ? $whereClause : null);
        $tbl = $table !== '' ? '`' . $table . '`' : '(unknown table)';
        $reason = $hasWhere ? 'WHERE present but no PK column' : 'no WHERE clause (all rows)';
        $est = $count === null ? 'an unknown number of' : '~' . number_format($count);

        return $type . ' on ' . $tbl . ': ' . $reason . ' — affects ' . $est . ' row(s). Run anyway?';
    }

    /**
     * Every non-DELETE/UPDATE modifying command always prompts (§5, user override
     * 2026-06-22). Best-effort table name; a row-count estimate only where it is
     * well defined (TRUNCATE / DROP TABLE affect the whole table).
     */
    private function dangerCheckDdl(string $sql, string $type): ?string
    {
        $table = $this->affectedTable($sql, $type);
        $on = $table !== '' ? ' `' . $table . '`' : '';

        // A specific, human description of exactly what this statement will do.
        $desc = match ($type) {
            'INSERT'   => 'adds row(s) to' . ($on !== '' ? $on : ' a table'),
            'REPLACE'  => 'inserts/overwrites row(s) in' . ($on !== '' ? $on : ' a table'),
            'LOAD'     => 'bulk-loads data into' . ($on !== '' ? $on : ' a table'),
            'TRUNCATE' => 'removes ALL rows from' . ($on !== '' ? $on : ' a table'),
            'DROP'     => 'destroys' . ($on !== '' ? $on : ' a database object'),
            'ALTER'    => 'changes the schema of' . ($on !== '' ? $on : ' a database object'),
            'CREATE'   => 'creates' . ($on !== '' ? $on : ' a database object'),
            'RENAME'   => 'renames' . ($on !== '' ? $on : ' a table'),
            'GRANT'    => 'grants privileges',
            'REVOKE'   => 'revokes privileges',
            'CALL'     => 'executes stored procedure' . $on,
            default    => 'modifies the database',
        };
        $msg = $type . ': ' . $desc;

        // A row-count estimate is only meaningful when the whole table is hit.
        if (($type === 'TRUNCATE' || $type === 'DROP') && $table !== '') {
            $count = $this->estimateAffected($table, null);
            if ($count !== null) {
                $msg .= ' — ~' . number_format($count) . ' row(s)';
            }
        }
        return $msg . '. Run anyway?';
    }

    /** Best-effort affected table name for a modifying statement (§5). */
    private function affectedTable(string $sql, string $type): string
    {
        $pat = match ($type) {
            'INSERT', 'REPLACE' => '/\bINTO\s+`?([A-Za-z0-9_]+)`?/i',
            'LOAD'              => '/\bINTO\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
            'CALL'              => '/\bCALL\s+`?([A-Za-z0-9_]+)`?/i',
            'TRUNCATE'          => '/\bTRUNCATE\s+(?:TABLE\s+)?`?([A-Za-z0-9_]+)`?/i',
            'DROP'              => '/\bDROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
            'ALTER'             => '/\bALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
            'RENAME'            => '/\bRENAME\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i',
            'CREATE'            => '/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
            default             => '',
        };
        if ($pat === '') {
            return $this->extractTableFromSql($sql);
        }
        return preg_match($pat, $sql, $m) === 1 ? $m[1] : '';
    }

    /**
     * Estimate how many rows a DELETE/UPDATE will touch via `SELECT COUNT(*)`
     * (§5). Returns null when the table is unknown or the count query fails.
     */
    private function estimateAffected(string $table, ?string $whereClause): ?int
    {
        if ($table === '') {
            return null;
        }
        $countSql = 'SELECT COUNT(*) AS c FROM `' . $table . '`';
        if ($whereClause !== null && trim($whereClause) !== '') {
            // Drop any ORDER BY / LIMIT tail — meaningless (and an error) on an
            // aggregate COUNT, but the predicate before it still scopes the count.
            $predicate = trim(preg_replace('/\b(ORDER\s+BY|LIMIT)\b.*$/is', '', $whereClause) ?? $whereClause);
            if ($predicate !== '') {
                $countSql .= ' WHERE ' . $predicate;
            }
        }
        // COUNT(*) can scan a large table for several seconds, and this runs
        // *before* the danger-confirm dialog appears — so cover it with a popup.
        $res = $this->withBusy(
            'Counting affected rows, please wait …',
            fn () => $this->database->query($countSql),
        );
        if ($res->hasError() || $res->rows === []) {
            return null;
        }
        $val = $res->rows[0]['c'] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    // --- cell editing & pending changes (§9) ------------------------------

    private function openCellEdit(): void
    {
        if ($this->state->readOnly) {
            $this->state->statusLine = '[RO] Read-only mode — editing is blocked';
            $this->state->statusIsError = true;
            return;
        }
        if (!$this->state->hasResult() || $this->state->selectedRow < 0) {
            return;
        }
        $col = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($col === null || $rawIdx === null) {
            return;
        }
        if ($this->blockIfPrimaryKey($col)) {
            return;
        }
        $this->warnIfForeignKey($col);
        $current = $this->state->pending[$rawIdx][$col]
            ?? $this->state->result->rows[$rawIdx][$col]
            ?? null;
        $value = $current === null ? '' : (string) $current;
        $editor = new LineEditor();
        $editor->setText($value);
        $this->state->cellEditor = $editor;
        $this->state->cellEditRow = $rawIdx;
        $this->state->cellEditCol = $col;
        // A multi-line or over-wide value edits in a roomy popup box; short single-
        // line values keep the quick inline overlay (§9).
        $this->state->cellEditPopup = $this->cellNeedsPopup($value);
        $this->state->cellEdit = true;
    }

    /**
     * Whether a cell's value is too big for the cramped inline editor and should
     * open in the popup box instead: any hard newline, or wider than the cell's
     * rendered column (taken from the last frame's published layout, §9).
     */
    private function cellNeedsPopup(string $value): bool
    {
        if (str_contains($value, "\n")) {
            return true;
        }
        $gl = $this->state->gridLayout;
        if ($gl !== null) {
            foreach ($gl->columns as $c) {
                if ($c['idx'] === $this->state->selectedCol) {
                    return mb_strlen($value) > $c['w'];
                }
            }
        }
        return false;
    }

    /**
     * Toggle the movable cell inspector on the selected column (§9). Pressing `v`
     * on the column it is already pinned to closes it; on any other column it
     * re-pins there (keeping the popup's on-screen position so it doesn't jump).
     */
    private function toggleCellView(): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        $col = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        if ($col === null) {
            return;
        }
        if ($this->state->cellView && $this->state->cellViewCol === $col) {
            $this->state->cellView = false;
            $this->state->cellMoveMode = false;
            return;
        }
        $this->state->cellViewCol = $col;
        $this->state->cellViewScroll = 0;
        $this->state->cellView = true;
    }

    /** `e` in the inspector: start editing its pinned cell at the current row (§9). */
    private function beginCellViewEdit(): void
    {
        $col = $this->state->cellViewCol;
        $rawIdx = $this->currentViewRawIdx();
        if ($col === '' || $rawIdx === null) {
            return;
        }
        if ($this->blockIfPrimaryKey($col)) {
            return;
        }
        $this->warnIfForeignKey($col);
        $current = $this->state->pending[$rawIdx][$col]
            ?? ($this->state->result->rows[$rawIdx][$col] ?? null);
        $editor = new LineEditor();
        $editor->setText($current === null ? '' : (string) $current);
        $this->state->cellEditor = $editor;
        $this->state->cellEditRow = $rawIdx;
        $this->state->cellEditCol = $col;
        $this->state->cellEditFromView = true;
        $this->state->cellMoveMode = false;
        $this->state->cellEdit = true;
    }

    /** Arrow keys reposition the inspector while move mode is active (§9). */
    private function handleCellMoveKey(KeyEvent $e): void
    {
        switch ($e->key) {
            case Key::Up:
                $this->nudgeCellPopup(0, -1);
                break;
            case Key::Down:
                $this->nudgeCellPopup(0, 1);
                break;
            case Key::Left:
                $this->nudgeCellPopup(-1, 0);
                break;
            case Key::Right:
                $this->nudgeCellPopup(1, 0);
                break;
            case Key::Enter:
            case Key::Escape:
                $this->state->cellMoveMode = false;
                break;
            case Key::Char:
                if (!$e->ctrl && !$e->alt && strtolower($e->char) === 'm') {
                    $this->state->cellMoveMode = false;
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'v') {
                    $this->state->cellMoveMode = false;
                    $this->state->cellView = false;
                }
                break;
            default:
                break;
        }
    }

    /**
     * Shift the inspector by (dx,dy) cells. Seeds from the last drawn position the
     * first time (while still auto-centred); the renderer clamps it on screen.
     */
    private function nudgeCellPopup(int $dx, int $dy): void
    {
        $r = $this->state->cellPopupRect;
        $x = $this->state->cellPopupX >= 0 ? $this->state->cellPopupX : (int) ($r[0] ?? 0);
        $y = $this->state->cellPopupY >= 0 ? $this->state->cellPopupY : (int) ($r[1] ?? 1);
        $this->state->cellPopupX = max(0, $x + $dx);
        $this->state->cellPopupY = max(1, $y + $dy);
    }

    /** Raw row index the inspector currently reflects (follows the selection). */
    private function currentViewRawIdx(): ?int
    {
        $order = $this->state->displayOrder();
        if ($order === []) {
            return null;
        }
        return $order[max(0, $this->state->selectedRow)] ?? $order[0] ?? null;
    }

    /** Whether screen point (x,y) is inside the inspector's published rect (§9). */
    private function pointInPopup(int $x, int $y): bool
    {
        $r = $this->state->cellPopupRect;
        return $this->state->cellView && $r !== null
            && $x >= $r[0] && $x < $r[0] + $r[2]
            && $y >= $r[1] && $y < $r[1] + $r[3];
    }

    /** Reposition the inspector under a title-bar drag; the renderer clamps it. */
    private function moveCellPopup(int $x, int $y): void
    {
        if ($this->popupDrag === null) {
            return;
        }
        [$dx, $dy] = $this->popupDrag;
        $this->state->cellPopupX = max(0, $x - $dx);
        $this->state->cellPopupY = max(1, $y - $dy);
    }

    private function handleCellEditKey(KeyEvent $e): void
    {
        $editor = $this->state->cellEditor;
        if ($editor === null) {
            $this->state->cellEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Char:
                // Ctrl+S commits the edit (the shared editor-window save key, §9);
                // plain characters are inserted. Enter adds a line (below).
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    $this->confirmCellEdit();
                } elseif (!$e->ctrl && !$e->alt) {
                    $editor->insert($e->char);
                }
                break;
            case Key::Backspace:
                $e->ctrl ? $editor->deleteWordLeft() : $editor->backspace();
                break;
            case Key::Delete:
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Up:
                $editor->upLine();
                break;
            case Key::Down:
                $editor->downLine();
                break;
            case Key::Home:
                $e->ctrl ? $editor->home() : $editor->lineHome();
                break;
            case Key::End:
                $e->ctrl ? $editor->end() : $editor->lineEnd();
                break;
            case Key::Enter:
                // Enter adds a line; the cell editor grows downward (§9).
                $editor->insertNewline();
                break;
            case Key::Escape:
                $this->closeCellEdit();
                break;
            default:
                break;
        }
    }

    private function confirmCellEdit(): void
    {
        $editor = $this->state->cellEditor;
        $rawIdx = $this->state->cellEditRow;
        $col = $this->state->cellEditCol;
        if ($editor === null || $rawIdx < 0 || $col === '') {
            $this->closeCellEdit();
            return;
        }
        $new = $editor->text();
        $orig = $this->state->result->rows[$rawIdx][$col] ?? null;
        $origStr = $orig === null ? '' : (string) $orig;

        // Type-check the value against the live column before committing. On a
        // mismatch, keep the editor open with the error so the user can fix it
        // in place rather than losing what they typed (§9).
        if ($new !== $origStr && ($err = $this->validateCellValue($col, $new)) !== null) {
            $this->state->statusLine = $err;
            $this->state->statusIsError = true;
            return;
        }

        $this->closeCellEdit();
        if ($new === $origStr) {
            // No net change — drop any edit that was in flight (§9).
            $this->cancelInFlight();
        } else {
            $this->stageEdit($rawIdx, $col, $new);
        }
    }

    /** Tear down the cell-edit overlay state (shared by commit and cancel, §9). */
    private function closeCellEdit(): void
    {
        $this->state->cellEdit = false;
        $this->state->cellEditor = null;
        $this->state->cellEditPopup = false;
        $this->state->cellEditFromView = false;
    }

    /**
     * Validate a proposed value for $col against the live column type. Returns an
     * error message, or null when the value fits (or the column isn't part of the
     * current table's live schema, e.g. a joined/computed column we can't type).
     */
    private function validateCellValue(string $col, string $value): ?string
    {
        $table = $this->state->currentTable;
        if ($table === '') {
            return null;
        }
        $row = $this->schema->describeColumn($table, $col);
        return $row === null ? null : FieldValidator::validate($row, $value);
    }

    /** True (and sets a status error) when $col is a primary key and can't be edited. */
    private function blockIfPrimaryKey(string $col): bool
    {
        $table = $this->state->currentTable;
        if ($table === '') {
            return false;
        }
        $row = $this->schema->describeColumn($table, $col);
        if ($row !== null && ($row['Key'] ?? '') === 'PRI') {
            $this->state->statusLine = 'Cannot edit primary-key column `' . $col . '`';
            $this->state->statusIsError = true;
            return true;
        }
        return false;
    }

    /** If $col is a foreign key, warn (non-blocking) which table it references. */
    private function warnIfForeignKey(string $col): void
    {
        $table = $this->state->currentTable;
        if ($table === '') {
            return;
        }
        $fk = $this->schema->foreignKey($table, $col);
        if ($fk !== null) {
            $this->state->statusLine = '⚠ `' . $col . '` is a foreign key → '
                . $fk['table'] . '.' . $fk['column'] . ' — value must exist there';
            $this->state->statusIsError = false;
        }
    }

    /**
     * Stage a single confirmed cell change (§9): revert whatever edit was in
     * flight (only one at a time), show the new value in the grid, build its
     * UPDATE and drop it on the CLI to run or cancel. Focus moves to the CLI so
     * the user can review + submit. A missing PK aborts (PK popup / error).
     */
    private function stageEdit(int $rawIdx, string $col, string $newValue): void
    {
        // The in-flight UPDATE runs directly (bypassing execute's guards), so the
        // read-only block has to be enforced here (§17).
        if ($this->state->readOnly) {
            $this->state->statusLine = '[RO] Read-only mode — editing is blocked';
            $this->state->statusIsError = true;
            return;
        }
        $this->revertInFlight();
        $this->state->pending[$rawIdx][$col] = $newValue;
        $sql = $this->buildUpdate($rawIdx); // one pending col → single-cell UPDATE
        if ($sql === null) {
            // Can't form the UPDATE (missing PK etc.) — undo the staged value.
            $this->revertPendingCell($rawIdx, $col);
            return;
        }
        $this->autoUpdate = ['rawIdx' => $rawIdx, 'col' => $col, 'sql' => $sql];
        $this->input->setSingle($sql);
        // Surface the CLI: close the modal detail view and the (non-modal) cell
        // inspector so the UPDATE is reachable to run or cancel.
        $this->state->detail = false;
        $this->state->cellView = false;
        $this->state->cellMoveMode = false;
        $this->state->focus = State::FOCUS_CLI;
        $this->resetHistoryNav();
        $this->state->statusLine = 'UPDATE ready — Enter to run, Esc to cancel';
        $this->state->statusIsError = false;
    }

    /** Revert the in-flight edit's grid value and forget the link (no CLI change). */
    private function revertInFlight(): void
    {
        if ($this->autoUpdate === null) {
            return;
        }
        $this->revertPendingCell($this->autoUpdate['rawIdx'], $this->autoUpdate['col']);
        $this->autoUpdate = null;
    }

    /**
     * Cancel the in-flight edit outright: revert the grid value, clear the CLI
     * line, and return focus to the data view. Used by Esc-on-CLI and by editing
     * a cell back to its original value.
     */
    private function cancelInFlight(): void
    {
        if ($this->autoUpdate === null) {
            return;
        }
        $this->revertInFlight();
        $this->input->setSingle('');
        $this->resetHistoryNav();
        $this->state->focus = State::FOCUS_DATA;
        $this->state->statusLine = 'Edit cancelled';
        $this->state->statusIsError = false;
    }

    /** Drop a single staged cell value (reverting the grid to the DB value). */
    private function revertPendingCell(int $rawIdx, string $col): void
    {
        unset($this->state->pending[$rawIdx][$col]);
        if (($this->state->pending[$rawIdx] ?? null) === []) {
            unset($this->state->pending[$rawIdx]);
        }
    }

    /**
     * Run the in-flight UPDATE against the DB without disturbing the result grid,
     * then fold the new value into the grid so it shows persistently. On error the
     * staged value is reverted. Clears the CLI + in-flight link either way (§9).
     */
    private function runAutoUpdate(): void
    {
        $au = $this->autoUpdate;
        $this->autoUpdate = null;
        if ($au === null) {
            return;
        }
        $val = $this->state->pending[$au['rawIdx']][$au['col']] ?? null;
        $res = $this->database->query(rtrim($au['sql'], ';'));
        if ($res->hasError()) {
            $this->revertPendingCell($au['rawIdx'], $au['col']);
            $this->setError('UPDATE failed: ' . ($res->error ?? 'unknown error'));
        } else {
            // Commit the value into the (readonly) result by rebuilding it, then
            // drop the transient staged value — the grid now shows the saved data.
            if ($val !== null) {
                $this->foldResultCell($au['rawIdx'], $au['col'], $val);
            }
            $this->revertPendingCell($au['rawIdx'], $au['col']);
            $this->state->statusLine = 'Row updated ('
                . $res->affectedRows . ' row' . ($res->affectedRows === 1 ? '' : 's') . ')';
            $this->state->statusIsError = false;
        }
        $this->input->setSingle('');
        $this->resetHistoryNav();
        $this->screen->invalidate();
    }

    /** Rebuild the readonly result with one cell replaced (fold a saved edit). */
    private function foldResultCell(int $rawIdx, string $col, string $val): void
    {
        $r = $this->state->result;
        if ($r === null || !isset($r->rows[$rawIdx])) {
            return;
        }
        $rows = $r->rows;
        $rows[$rawIdx][$col] = $val;
        $this->state->result = new QueryResult(
            $r->columns,
            $rows,
            $r->affectedRows,
            $r->elapsed,
            $r->error,
            $r->isSelect,
        );
    }

    /** True when $sql is (ignoring a trailing `;`/space) the in-flight UPDATE. */
    private function isInFlightSubmit(string $sql): bool
    {
        if ($this->autoUpdate === null) {
            return false;
        }
        $norm = static fn (string $s): string => rtrim(trim($s), '; ');
        return $norm($sql) === $norm($this->autoUpdate['sql']);
    }

    // --- JSON viewer / editor (§10) ----------------------------------------

    /**
     * Enter on a data cell: if its value is a JSON **document** open the JSON
     * editor, otherwise the normal cell editor (§9). A bare scalar that happens
     * to be valid JSON (`42`, `"x"`) is NOT a document, so it edits normally.
     */
    private function openCellOrJson(): void
    {
        $sel = $this->selectedCell();
        if ($sel !== null && JsonTree::isDocument($sel['value'])) {
            $this->openJsonView($sel['col'], $sel['rawIdx'], $sel['value']);
        } else {
            $this->openCellEdit();
        }
    }

    /** The selected grid cell as [col, rawIdx, value], or null if none. */
    private function selectedCell(): ?array
    {
        if (!$this->state->hasResult() || $this->state->selectedRow < 0) {
            return null;
        }
        $col = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($col === null || $rawIdx === null) {
            return null;
        }
        return ['col' => $col, 'rawIdx' => $rawIdx, 'value' => $this->cellValueAt($rawIdx, $col)];
    }

    /** `j`/`J` in DATA focus: open the JSON viewer on the selected cell (§10). */
    private function openJsonFromData(): void
    {
        $sel = $this->selectedCell();
        if ($sel !== null) {
            $this->openJsonView($sel['col'], $sel['rawIdx'], $sel['value']);
        }
    }

    /** `j`/`J` in the detail view: open the JSON viewer on the selected field (§10). */
    private function openJsonFromDetail(): void
    {
        $result = $this->state->result;
        if ($result === null) {
            return;
        }
        $col = $result->columns()[$this->state->detailFieldIdx] ?? null;
        $rawIdx = $this->currentViewRawIdx();
        if ($col === null || $rawIdx === null) {
            return;
        }
        $this->openJsonView($col, $rawIdx, $this->cellValueAt($rawIdx, $col));
    }

    /**
     * Open the JSON viewer for a value. A valid JSON document opens the tree; an
     * explicit view of a non-document shows the "invalid JSON" mode with the raw
     * string and why (§10). (The Enter-edit path never reaches the invalid mode —
     * it only calls this once isDocument() is true.)
     */
    private function openJsonView(string $col, int $rawIdx, string $value): void
    {
        $this->state->jsonColumn = $col;
        $this->state->jsonRow = $rawIdx;
        $this->state->jsonEdit = false;
        $this->state->jsonEditor = null;
        $tree = JsonTree::parse($value);
        if ($tree === null) {
            $this->jsonTree = null;
            $this->jsonRows = [];
            $this->state->jsonNodes = [];
            $this->state->jsonInvalid = true;
            $this->state->jsonRaw = $value;
            $this->state->jsonInvalidMessage = $this->jsonInvalidReason($value);
        } else {
            $this->jsonTree = $tree;
            $this->state->jsonInvalid = false;
            $this->state->jsonSel = 0;
            $this->state->jsonOffset = 0;
            $this->publishJson();
        }
        $this->state->jsonView = true;
    }

    /** Human explanation for the invalid-JSON mode (§10). */
    private function jsonInvalidReason(string $value): string
    {
        if (trim($value) === '') {
            return 'The value is empty — nothing to show as JSON.';
        }
        json_decode($value);
        if (json_last_error() === JSON_ERROR_NONE) {
            return 'This is a JSON scalar, not an object or array — edit it normally instead.';
        }
        return 'Not valid JSON: ' . json_last_error_msg() . '.';
    }

    /** Rebuild the flattened rows and publish the node-stripped view to State. */
    private function publishJson(): void
    {
        if ($this->jsonTree === null) {
            $this->jsonRows = [];
            $this->state->jsonNodes = [];
            return;
        }
        $this->jsonRows = $this->jsonTree->rows();
        $nodes = [];
        foreach ($this->jsonRows as $r) {
            $nodes[] = [
                'depth' => $r['depth'], 'label' => $r['label'], 'value' => $r['value'],
                'type' => $r['type'], 'container' => $r['container'], 'expanded' => $r['expanded'],
                'childCount' => $r['childCount'], 'editable' => $r['editable'],
            ];
        }
        $this->state->jsonNodes = $nodes;
        $this->state->jsonSel = $this->clampIndex($this->state->jsonSel, count($nodes));
    }

    private function closeJson(): void
    {
        // Closing = "done editing this field": if the document changed vs the DB
        // value, drop its UPDATE on the CLI like any other cell edit (§9/§10). Node
        // edits were already committed to the tree, so Esc-to-close commits them.
        if (!$this->state->jsonInvalid && $this->jsonTree !== null && $this->state->jsonRow >= 0) {
            $rawIdx = $this->state->jsonRow;
            $col = $this->state->jsonColumn;
            $encoded = $this->jsonTree->encode();
            $orig = $this->state->result->rows[$rawIdx][$col] ?? null;
            $origStr = $orig === null ? '' : (string) $orig;
            $origNorm = JsonTree::isDocument($origStr)
                ? (JsonTree::parse($origStr)?->encode() ?? $origStr)
                : $origStr;
            // Reset the viewer state before staging (stageEdit moves focus to CLI).
            $this->resetJsonState();
            if ($encoded !== $origNorm) {
                $this->stageEdit($rawIdx, $col, $encoded);
            } else {
                $this->cancelInFlight();
            }
            return;
        }
        $this->resetJsonState();
    }

    private function resetJsonState(): void
    {
        $this->state->jsonView = false;
        $this->state->jsonInvalid = false;
        $this->state->jsonEdit = false;
        $this->state->jsonEditor = null;
        $this->state->jsonEditKind = '';
        $this->jsonAddContainer = null;
        $this->jsonTree = null;
        $this->jsonRows = [];
        $this->state->jsonNodes = [];
    }

    /** One PageUp/PageDown step in the JSON tree (a full visible page, §10). */
    private function jsonPageStep(): int
    {
        $rows = $this->state->jsonPageRows;
        return max(1, $rows > 0 ? $rows : 10);
    }

    private function handleJsonKey(KeyEvent $e): void
    {
        // The inline node-value editor owns input while open.
        if ($this->state->jsonEdit) {
            $this->handleJsonEditKey($e);
            return;
        }
        // Invalid-JSON mode is a quick look — any key dismisses it.
        if ($this->state->jsonInvalid) {
            $this->closeJson();
            return;
        }
        $count = count($this->state->jsonNodes);
        $last = max(0, $count - 1);
        switch ($e->key) {
            case Key::Up:
                $this->state->jsonSel = max(0, $this->state->jsonSel - 1);
                break;
            case Key::Down:
                $this->state->jsonSel = min($last, $this->state->jsonSel + 1);
                break;
            case Key::Home:
                $this->state->jsonSel = 0;
                break;
            case Key::End:
                $this->state->jsonSel = $last;
                break;
            case Key::PageUp:
                $this->state->jsonSel = max(0, $this->state->jsonSel - $this->jsonPageStep());
                break;
            case Key::PageDown:
                $this->state->jsonSel = min($last, $this->state->jsonSel + $this->jsonPageStep());
                break;
            case Key::Delete:
                $this->jsonDeleteSelected();
                break;
            case Key::Escape:
                $this->closeJson();
                break;
            case Key::Enter:
                // Container → toggle collapse; scalar leaf → edit its value (§10).
                $this->jsonActivateSelected();
                break;
            case Key::Char:
                if ($e->ctrl || $e->alt) {
                    break;
                }
                switch ($e->char) {
                    case ' ':
                        $this->jsonToggleSelected();
                        break;
                    case '+':
                        $this->jsonSetSelectedExpanded(true);
                        break;
                    case '-':
                        $this->jsonSetSelectedExpanded(false);
                        break;
                    case '*':
                        $this->jsonTree?->setExpandedAll(true);
                        $this->publishJson();
                        break;
                    case '/':
                        $this->jsonTree?->setExpandedAll(false);
                        $this->state->jsonSel = 0;
                        $this->publishJson();
                        break;
                    case 'a':
                        // Add a child inside the selected container (§10).
                        $this->jsonAdd(child: true);
                        break;
                    case 'A':
                        // Add a sibling after the selected node, same level (§10).
                        $this->jsonAdd(child: false);
                        break;
                    case 'd':
                    case 'D':
                        $this->jsonDeleteSelected();
                        break;
                    default:
                        break;
                }
                break;
            default:
                break;
        }
    }

    /** The JsonNode under the current selection, or null. */
    private function selectedJsonNode(): ?\Sqli2\Json\JsonNode
    {
        return $this->jsonRows[$this->state->jsonSel]['node'] ?? null;
    }

    private function jsonActivateSelected(): void
    {
        $row = $this->jsonRows[$this->state->jsonSel] ?? null;
        if ($row === null || $this->jsonTree === null) {
            return;
        }
        if ($row['container']) {
            $this->jsonTree->toggle($row['node']);
            $this->publishJson();
        } else {
            $this->openJsonNodeEdit();
        }
    }

    private function jsonToggleSelected(): void
    {
        $node = $this->selectedJsonNode();
        if ($node !== null && $this->jsonTree !== null) {
            $this->jsonTree->toggle($node);
            $this->publishJson();
        }
    }

    private function jsonSetSelectedExpanded(bool $expanded): void
    {
        $node = $this->selectedJsonNode();
        if ($node !== null && $node->isContainer()) {
            $node->expanded = $expanded;
            $this->publishJson();
        }
    }

    /** Open the single-line editor for the selected scalar leaf's value (§10). */
    private function openJsonNodeEdit(): void
    {
        $row = $this->jsonRows[$this->state->jsonSel] ?? null;
        if ($row === null || $row['container']) {
            return;
        }
        $editor = new LineEditor();
        $editor->setText(JsonTree::encodeScalar($row['node']->scalar));
        $this->state->jsonEditor = $editor;
        $this->state->jsonEditLabel = $row['label'] !== '' ? $row['label'] : '(value)';
        $this->state->jsonEditKind = '';
        $this->state->jsonEdit = true;
    }

    /** Locate a node in the freshly-published rows and select it (§10). */
    private function jsonSelectNode(JsonNode $node): void
    {
        foreach ($this->jsonRows as $i => $r) {
            if ($r['node'] === $node) {
                $this->state->jsonSel = $i;
                return;
            }
        }
    }

    /** `d`/Delete: remove the selected node and everything under it (§10). */
    private function jsonDeleteSelected(): void
    {
        if ($this->jsonTree === null) {
            return;
        }
        $node = $this->selectedJsonNode();
        if ($node !== null && $this->jsonTree->deleteNode($node)) {
            $this->publishJson(); // clamps jsonSel onto the surviving rows
        }
    }

    /**
     * Add a new element (§10). $child = insert inside the selected container;
     * otherwise insert as a sibling right after the selected node. Into an array
     * the null element is inserted immediately and its value editor opens; into an
     * object we first prompt for the member key (which then flows to the value).
     */
    private function jsonAdd(bool $child): void
    {
        if ($this->jsonTree === null) {
            return;
        }
        $sel = $this->selectedJsonNode();
        if ($sel === null) {
            return;
        }

        // Resolve the target container + insert position.
        if ($child && $sel->isContainer()) {
            $container = $sel;
            $pos = count($sel->children);
        } else {
            // Sibling (or a child-add on a leaf): insert into the parent after $sel.
            $container = $this->jsonTree->parentOf($sel);
            if ($container === null) {
                // The root is selected and has no parent: fall back to a child add.
                if (!$sel->isContainer()) {
                    return;
                }
                $container = $sel;
                $pos = count($sel->children);
            } else {
                $pos = $this->jsonTree->indexIn($container, $sel) + 1;
            }
        }

        if ($container->type === JsonNode::OBJECT) {
            // A new object member needs a key: prompt for it, then the value.
            $this->jsonAddContainer = $container;
            $this->jsonAddPos = $pos;
            $editor = new LineEditor();
            $this->state->jsonEditor = $editor;
            $this->state->jsonEditLabel = 'new key';
            $this->state->jsonEditKind = 'newKey';
            $this->state->jsonEdit = true;
            return;
        }

        // Array: insert a null element now, then edit its value.
        $node = $this->jsonTree->addNode($container, $pos, null);
        $this->publishJson();
        if ($node !== null) {
            $this->jsonSelectNode($node);
            $this->openJsonNodeEdit();
        }
    }

    private function handleJsonEditKey(KeyEvent $e): void
    {
        $editor = $this->state->jsonEditor;
        if ($editor === null) {
            $this->state->jsonEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Char:
                if (!$e->ctrl && !$e->alt) {
                    $editor->insert($e->char);
                }
                break;
            case Key::Backspace:
                $e->ctrl ? $editor->deleteWordLeft() : $editor->backspace();
                break;
            case Key::Delete:
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Home:
                $editor->lineHome();
                break;
            case Key::End:
                $editor->lineEnd();
                break;
            case Key::Enter:
                // The node value is single-line, so Enter confirms (§10).
                $this->confirmJsonNodeEdit();
                break;
            case Key::Escape:
                // Cancelling the key prompt for a new member simply abandons the
                // add (no node was created yet); a value edit just closes.
                $this->state->jsonEdit = false;
                $this->state->jsonEditor = null;
                $this->state->jsonEditKind = '';
                $this->jsonAddContainer = null;
                break;
            default:
                break;
        }
    }

    private function confirmJsonNodeEdit(): void
    {
        $editor = $this->state->jsonEditor;
        if ($editor === null) {
            $this->state->jsonEdit = false;
            $this->state->jsonEditKind = '';
            return;
        }

        // Two-step object add: this confirm supplied the new member's key. Create
        // the (null) member, then flow straight into editing its value.
        if ($this->state->jsonEditKind === 'newKey') {
            $key = $this->stripQuotes(trim($editor->text()));
            $container = $this->jsonAddContainer;
            $this->jsonAddContainer = null;
            $this->state->jsonEditKind = '';
            if ($key !== '' && $container !== null && $this->jsonTree !== null) {
                $node = $this->jsonTree->addNode($container, $this->jsonAddPos, $key);
                $this->publishJson();
                if ($node !== null) {
                    $this->jsonSelectNode($node);
                    $this->openJsonNodeEdit(); // reopens as a value edit on the new node
                    return;
                }
            }
            $this->state->jsonEdit = false;
            $this->state->jsonEditor = null;
            return;
        }

        $node = $this->selectedJsonNode();
        if ($node !== null && $this->jsonTree !== null) {
            $this->jsonTree->setScalar($node, $editor->text());
            $this->publishJson();
        }
        $this->state->jsonEdit = false;
        $this->state->jsonEditor = null;
        $this->state->jsonEditKind = '';
    }

    /** Strip one surrounding matched pair of single or double quotes. */
    private function stripQuotes(string $s): string
    {
        $len = mb_strlen($s);
        if ($len >= 2) {
            $first = mb_substr($s, 0, 1);
            $last = mb_substr($s, -1);
            if (($first === '"' || $first === "'") && $first === $last) {
                return mb_substr($s, 1, $len - 2);
            }
        }
        return $s;
    }

    /**
     * Build a WHERE-qualified UPDATE for one row's pending changes (§9). Returns
     * null and either sets a status error or opens the PK-missing popup when the
     * statement can't be formed.
     */
    private function buildUpdate(int $rawIdx, bool $popupOnMissing = true): ?string
    {
        $table = $this->state->currentTable;
        $changes = $this->state->pending[$rawIdx] ?? [];
        $row = $this->state->result->rows[$rawIdx] ?? null;
        if ($changes === [] || $row === null) {
            return null;
        }
        if ($table === '') {
            $this->setError('Cannot build UPDATE: table is unknown');
            return null;
        }
        $pk = $this->schema->primaryKey($table);
        if ($pk === []) {
            $this->setError('Cannot build UPDATE: no primary key on ' . $table);
            return null;
        }
        $missing = [];
        foreach ($pk as $k) {
            if (!array_key_exists($k, $row)) {
                $missing[] = $k;
            }
        }
        if ($missing !== []) {
            if ($popupOnMissing) {
                $this->openPkPopup($missing);
            } else {
                $this->setError('Primary key not in result: ' . implode(', ', $missing));
            }
            return null;
        }

        $set = [];
        foreach ($changes as $col => $val) {
            $set[] = '`' . $col . '` = ' . $this->sqlQuote($val);
        }
        $where = [];
        foreach ($pk as $k) {
            $where[] = '`' . $k . '` = ' . $this->sqlQuote((string) ($row[$k] ?? ''));
        }
        return 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $where) . ';';
    }

    private function sqlQuote(string $v): string
    {
        if ($v === 'NULL') {
            return 'NULL';
        }
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
    }

    // --- PK-missing popup (§5) ---------------------------------------------

    /** @param list<string> $missing */
    private function openPkPopup(array $missing): void
    {
        $this->state->pkPopupCols = $missing;
        $this->state->pkPopupMessage = 'Primary key not in result: ' . implode(', ', $missing)
            . '. Re-run the query with it added?  [Y/N]';
        $this->state->pkPopup = true;
    }

    private function handlePkPopupKey(KeyEvent $e): void
    {
        $ch = $e->key === Key::Char ? strtolower($e->char) : '';
        if ($ch === 'y') {
            $cols = $this->state->pkPopupCols;
            $this->closePkPopup();
            $this->rerunWithPk($cols);
            return;
        }
        if ($ch === 'n' || $e->key === Key::Escape) {
            $this->closePkPopup();
        }
    }

    private function closePkPopup(): void
    {
        $this->state->pkPopup = false;
        $this->state->pkPopupCols = [];
        $this->state->pkPopupMessage = '';
    }

    /** @param list<string> $cols */
    private function rerunWithPk(array $cols): void
    {
        $sql = $this->state->lastSql;
        if ($sql === '' || $cols === []) {
            return;
        }
        $add = [];
        foreach ($cols as $c) {
            if (preg_match('/(^|[\s,`(])' . preg_quote($c, '/') . '([\s,`)]|$)/i', $sql) !== 1) {
                $add[] = $c;
            }
        }
        $prefix = $add === [] ? '' : implode(', ', $add) . ', ';
        $new = preg_replace('/^(\s*SELECT\s+)/i', '${1}' . $prefix, $sql, 1);
        if ($new !== null) {
            $this->execute($new);
        }
    }

    // --- confirm modal (§5, §9, §18) ---------------------------------------

    /**
     * @param list<array{0:string,1:string}> $options
     */
    private function openConfirm(string $kind, string $message, array $options): void
    {
        $this->state->confirm = true;
        $this->state->confirmKind = $kind;
        $this->state->confirmMessage = $message;
        $this->state->confirmOptions = $options;
    }

    private function closeConfirm(): void
    {
        $this->state->confirm = false;
        $this->state->confirmKind = '';
        $this->state->confirmMessage = '';
        $this->state->confirmOptions = [];
    }

    private function handleConfirmKey(KeyEvent $e): void
    {
        $key = $e->key === Key::Escape ? 'esc' : ($e->key === Key::Char ? strtolower($e->char) : '');
        $kind = $this->state->confirmKind;

        if ($kind === 'dangerous_query') {
            if ($key === 'y') {
                $this->closeConfirm();
                $q = $this->pendingDangerous;
                $this->pendingDangerous = null;
                if ($q !== null) {
                    $this->execute((string) $q['sql'], (bool) $q['vertical'], confirmedDangerous: true);
                }
            } elseif ($key === 'n' || $key === 'c' || $key === 'esc') {
                $this->closeConfirm();
                $this->pendingDangerous = null;
            }
            return;
        }

        if ($kind === 'clear_view') {
            if ($key === 'y') {
                $this->closeConfirm();
                $this->clearView();
            } elseif ($key === 'n' || $key === 'c' || $key === 'esc') {
                $this->closeConfirm();
            }
            return;
        }
    }

    private function requestQuit(): void
    {
        // Any un-run cell edit is just abandoned (it never touched the DB); its
        // UPDATE was sitting on the CLI for the user to run or cancel (§9).
        $this->revertInFlight();
        $this->state->running = false;
    }

    /**
     * `clear`/`cls` CLI command: drop the current result and return to the empty
     * data view. An un-run cell edit is abandoned (its UPDATE was on the CLI).
     */
    private function requestClear(): void
    {
        $this->revertInFlight();
        $this->clearView();
    }

    /** Reset the result/grid state back to the empty "type a query" view. */
    private function clearView(): void
    {
        $this->state->result = null;
        $this->state->lastSql = '';
        $this->state->currentTable = '';
        $this->state->detail = false;
        $this->state->vertical = false;
        $this->state->selectedRow = 0;
        $this->state->selectedCol = 0;
        $this->state->rowOffset = 0;
        $this->state->colOffset = 0;
        $this->state->sortCol = null;
        $this->state->applySort();
        $this->state->pending = [];
        $this->state->focus = State::FOCUS_CLI;
        $this->state->statusLine = 'Data view cleared';
        $this->state->statusIsError = false;
        $this->input->clearAll();
        // A blank result reshapes the whole screen; force a clean repaint.
        $this->screen->invalidate();
        $this->resetHistoryNav();
    }

    private function setError(string $message): void
    {
        $this->state->statusLine = $message;
        $this->state->statusIsError = true;
    }

    // --- clipboard copy (§19) ---------------------------------------------

    /** The value shown for a cell (pending edit if any, else the DB value). */
    private function cellValueAt(int $rawIdx, string $col): string
    {
        $v = $this->state->pending[$rawIdx][$col]
            ?? ($this->state->result->rows[$rawIdx][$col] ?? null);
        return $v === null ? '' : (string) $v;
    }

    /** Push $data to the system clipboard via OSC 52 and confirm to the user. */
    private function copyToClipboard(string $data, string $what): void
    {
        // OSC 52 paints nothing, so no Screen::invalidate() is needed.
        $this->terminal->write(Ansi::clipboardSet($data));
        $msg = 'Copied ' . $what . ' to clipboard';
        // Prominent, transient confirmation on the top line (§19) plus the
        // status line for good measure.
        $this->state->flashMessage = $msg;
        $this->state->flashUntil = microtime(true) + self::FLASH_SECONDS;
        $this->state->statusLine = $msg;
        $this->state->statusIsError = false;
    }

    /** Copy the selected cell's value (§19, #1). */
    private function copyCell(): void
    {
        if (!$this->state->hasResult() || $this->state->selectedRow < 0) {
            return;
        }
        $col = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($col === null || $rawIdx === null) {
            return;
        }
        $this->copyToClipboard($this->cellValueAt($rawIdx, $col), "cell '" . $this->qualifiedField($col) . "'");
    }

    /** Copy the selected row as tab-separated visible-column values (§19, #2). */
    private function copyRow(): void
    {
        if (!$this->state->hasResult() || $this->state->selectedRow < 0) {
            return;
        }
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($rawIdx === null) {
            return;
        }
        $cols = $this->state->visibleColumnNames();
        $vals = array_map(fn (string $c): string => $this->cellValueAt($rawIdx, $c), $cols);
        $this->copyToClipboard(implode("\t", $vals), 'row (' . count($cols) . ' cols)');
    }

    /** Copy the popup column's values (display order), one per line (§19, #2). */
    private function copyColumn(): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        $name = $this->state->visibleColumnNames()[$this->state->popupCol] ?? null;
        if ($name === null) {
            return;
        }
        $vals = [];
        foreach ($this->state->displayOrder() as $rawIdx) {
            $vals[] = $this->cellValueAt($rawIdx, $name);
        }
        $this->copyToClipboard(implode("\n", $vals), "column '" . $this->qualifiedField($name) . "' (" . count($vals) . ' rows)');
    }

    /** Prefix a field name with the active table (`table.field`) when known. */
    private function qualifiedField(string $col): string
    {
        return $this->state->currentTable !== ''
            ? $this->state->currentTable . '.' . $col
            : $col;
    }

    // --- tab completion (§4) ----------------------------------------------

    /** Open the popup from a Tab press; no-op if there's no completion context. */
    private function openCompletion(): void
    {
        // The first Tab triggers the initial schema load (SHOW TABLES, then a
        // SHOW COLUMNS per referenced table) which can stall on a big database.
        // Show a busy popup for that first fetch only; once the schema is cached
        // later Tabs are instant, so no popup (and no flash) then.
        $res = $this->schema->isLoaded()
            ? $this->analyzeCompletion()
            : $this->withBusy('Loading schema, please wait …', fn () => $this->analyzeCompletion());
        if ($res === null) {
            $this->state->completion = false;
            return;
        }
        $this->state->completion = true;
        $this->applyCompletion($res);
    }

    /**
     * Re-run completion after the filter changed (a typed char or backspace).
     * Unlike {@see openCompletion()} this keeps the popup open even when the
     * filter now matches nothing — the list just goes empty so the user can
     * backspace to refill it (§4).
     */
    private function refreshCompletion(): void
    {
        $res = $this->analyzeCompletion();
        if ($res === null) {
            // Context no longer yields any candidate pool: keep the box, empty
            // the list, and track the now-typed token so backspace can refill.
            $this->state->completionItems = [];
            $this->state->completionIdx = 0;
            $this->state->completionFilter = $this->currentToken($this->state->completionStart);
            return;
        }
        $this->applyCompletion($res);
    }

    private function analyzeCompletion(): ?CompletionResult
    {
        $editor = $this->input->editor();
        return $this->completion->analyze(
            $editor->text(),
            $editor->cursor(),
            fn (): array => $this->schema->tables(),
            fn (string $t): array => $this->schema->columns($t),
        );
    }

    private function applyCompletion(CompletionResult $res): void
    {
        $this->state->completionItems = $res->items;
        $this->state->completionIdx = 0;
        $this->state->completionStart = $res->start;
        $this->state->completionTitle = $res->title;
        // The partial token currently under the cursor — what we filtered by.
        // Shown live at the top of the popup so the user can refine it (§4).
        $this->state->completionFilter = $this->currentToken($res->start);
    }

    /** The token text from $start to the cursor (the live filter). */
    private function currentToken(int $start): string
    {
        $editor = $this->input->editor();
        return mb_substr($editor->text(), $start, max(0, $editor->cursor() - $start));
    }

    /** @return bool true if the key was consumed by the popup */
    private function handleCompletionKey(KeyEvent $e): bool
    {
        $n = count($this->state->completionItems);
        switch ($e->key) {
            case Key::Up:
                if ($n > 0) {
                    $this->state->completionIdx = ($this->state->completionIdx - 1 + $n) % $n;
                }
                return true;
            case Key::Down:
                if ($n > 0) {
                    $this->state->completionIdx = ($this->state->completionIdx + 1) % $n;
                }
                return true;
            case Key::PageUp:
                $this->state->completionIdx = max(0, $this->state->completionIdx - 8);
                return true;
            case Key::PageDown:
                $this->state->completionIdx = min(max(0, $n - 1), $this->state->completionIdx + 8);
                return true;
            case Key::Escape:
                $this->state->completion = false;
                return true;
            case Key::Enter:
            case Key::Tab:
                // Nothing to insert when the filter matches nothing — just close.
                if ($n === 0) {
                    $this->state->completion = false;
                    return true;
                }
                $this->insertCompletion();
                return true;
            case Key::Char:
                // Typing a word character extends the filter live: insert it,
                // then re-analyze so the list narrows (§4). Anything else
                // (space, punctuation, ctrl/alt combos) closes the popup and is
                // handled normally.
                if ($e->ctrl || $e->alt || !$this->isWordChar($e->char)) {
                    return false;
                }
                $this->input->editor()->insert($e->char);
                $this->resetHistoryNav();
                $this->refreshCompletion();
                return true;
            case Key::Backspace:
                // Backspace shortens the filter while there's still a token to
                // trim — even down to an empty box, which refills as soon as a
                // matching char is typed. Once it would reach before the token
                // start, let the popup close and the keystroke fall through.
                if ($e->ctrl || $this->input->editor()->cursor() <= $this->state->completionStart) {
                    return false;
                }
                $this->input->editor()->backspace();
                $this->refreshCompletion();
                return true;
            default:
                return false;
        }
    }

    /** True if $c is a single word character (letter, digit, or underscore). */
    private function isWordChar(string $c): bool
    {
        return $c !== '' && preg_match('/^[\p{L}\p{N}_]$/u', $c) === 1;
    }

    private function insertCompletion(): void
    {
        $item = $this->state->completionItems[$this->state->completionIdx] ?? null;
        $this->state->completion = false;
        if ($item === null) {
            return;
        }
        $editor = $this->input->editor();
        $text = $editor->text();
        $start = $this->state->completionStart;
        $cursor = $editor->cursor();
        if ($start < 0 || $start > $cursor) {
            return; // buffer changed under us; skip
        }
        $new = mb_substr($text, 0, $start) . $item . mb_substr($text, $cursor);
        $editor->setText($new, $start + mb_strlen($item));
        $this->resetHistoryNav();
    }

    // --- query submission --------------------------------------------------

    private function onEnter(): void
    {
        $active = rtrim($this->input->editor()->text());

        // Enter on the CLI only ever submits — an empty buffer has nothing to
        // submit, so it does nothing. (The detail view is now toggled from DATA
        // focus with `i`; Enter no longer changes the data view.)
        if ($active === '' && $this->input->lineCount() === 1) {
            return;
        }

        // Single-line CLI commands (optionally `;`-terminated): `exit`/`quit`
        // quit the app, `clear`/`cls` clear the data view. The pending-changes
        // guard runs first for both (§17/§18).
        if ($this->input->lineCount() === 1) {
            $cmd = strtolower(rtrim($active, "; \t"));
            if ($cmd === 'exit' || $cmd === 'quit') {
                $this->requestQuit();
                return;
            }
            if ($cmd === 'clear' || $cmd === 'cls') {
                $this->requestClear();
                return;
            }
        }

        // A `;` or `\G` on the active line terminates and submits. `\G` selects
        // vertical output mode (rendered as normal until §5 lands).
        $terminator = null;
        if (str_ends_with($active, ';')) {
            $terminator = ';';
        } elseif (preg_match('/\\\\[Gg]$/', $active)) {
            $terminator = '\\G';
        }

        if ($terminator !== null) {
            // Build the SQL without mutating the buffer, so Cancel can keep it.
            $sql = $this->stripTerminator($this->input->combinedText(), $terminator);
            if ($sql !== '') {
                $this->submit($sql, $terminator === '\\G');
            }
            return;
        }

        // No terminator: push this line up and continue on a fresh line below.
        $this->input->pushLine();
        $this->resetHistoryNav();
    }

    private function stripTerminator(string $combined, string $terminator): string
    {
        $s = rtrim($combined);
        if ($terminator === ';') {
            return rtrim(substr($s, 0, -1));
        }
        return rtrim((string) preg_replace('/\\\\[Gg]$/', '', $s));
    }

    /**
     * Submit whatever is on the CLI. If it is the in-flight edit's UPDATE, run it
     * specially so the result grid survives and the value folds in (§9). Anything
     * else means the user moved on, so the un-run edit is reverted first.
     */
    private function submit(string $sql, bool $vertical): void
    {
        if ($this->isInFlightSubmit($sql)) {
            $this->runAutoUpdate();
            return;
        }
        // Running some other statement abandons any un-submitted cell edit.
        $this->revertInFlight();
        $this->execute($sql, $vertical);
    }

    private function execute(string $sql, bool $vertical = false, bool $confirmedDangerous = false): void
    {
        if ($sql === '') {
            return;
        }

        // Normalize input conveniences before anything else inspects the SQL
        // (§5): first inverted FROM…SELECT, then mixed-star expansion. The
        // normalized form is what runs; history keeps the *original* typed form
        // so Up-arrow restores what was typed.
        $original = $sql;
        $norm = $this->normalizer->normalize($sql);
        $sql = $norm->sql;
        $star = $this->normalizer->expandMixedStar($sql, fn (string $t): array => $this->resolveColumns($t));
        $sql = $star->sql;

        $notes = array_filter([$norm->changed ? $norm->note : '', $star->changed ? $star->note : '']);

        $first = strtoupper(preg_split('/\s+/', $sql, 2)[0] ?? '');
        if ($this->state->readOnly && in_array($first, self::MODIFYING, true)) {
            $this->state->statusLine = '[RO] Read-only mode — ' . $first . ' is blocked';
            $this->state->statusIsError = true;
            return;
        }

        // Dangerous-query guard (§5): every data-modifying command needs explicit
        // confirmation before it touches the database. DELETE/UPDATE are nuanced
        // (only a broad statement prompts); all other modifying commands always
        // prompt. We hold the *original typed* form so the confirmed re-run
        // normalizes identically and history stays faithful.
        if (!$confirmedDangerous && in_array($first, self::MODIFYING, true)) {
            $message = $this->dangerCheck($sql, $first);
            if ($message !== null) {
                $this->pendingDangerous = ['sql' => $original, 'vertical' => $vertical];
                $this->openConfirm(
                    'dangerous_query',
                    $message,
                    [['Y', 'Run anyway'], ['N', 'Cancel']],
                );
                return;
            }
        }

        // Auto-cap row-returning queries at AUTO_LIMIT rows (§5). This mutates the
        // SQL actually sent to the DB (and thus the executed-SQL history via
        // Shift+Up/Down), but never the typed-form history — $original is pushed
        // untouched below, so plain Up/Down shows exactly what the user typed.
        $autoLimited = false;
        $sql = $this->applyAutoLimit($sql, $first, $autoLimited);
        if ($autoLimited) {
            $notes[] = 'auto-limited to ' . self::AUTO_LIMIT . ' rows';
        }

        $result = $this->database->query($sql);
        $this->state->result = $result;
        // A new result reshapes the whole grid (row count, gutter width, column
        // set). Force a full repaint so nothing from the previous result can
        // survive as leftover characters under the differential renderer.
        $this->screen->invalidate();
        $this->state->lastSql = $sql;
        $this->state->selectedRow = 0;
        $this->state->selectedCol = 0;
        $this->state->rowOffset = 0;
        $this->state->colOffset = 0;

        // A fresh result clears transient overlays and sort, and sets the
        // output mode (\G → vertical, §5).
        $this->state->detail = false;
        $this->state->columnPopup = false;
        $this->state->layoutPopup = false;
        $this->state->sortCol = null;
        $this->state->sortDir = 'asc';
        $this->state->vertical = $vertical;
        $this->state->verticalScroll = 0;
        $this->state->cellEdit = false;
        $this->state->cellEditor = null;
        $this->state->cellEditPopup = false;
        $this->state->cellEditFromView = false;
        $this->state->cellView = false;
        $this->state->cellViewCol = '';
        $this->state->cellViewScroll = 0;
        $this->state->cellMoveMode = false;
        $this->state->cellPopupX = -1;
        $this->state->cellPopupY = -1;
        $this->popupDrag = null;
        $this->state->widthAdjust = false;
        $this->state->moveMode = false;
        $this->moveSavedOrder = null;
        $this->state->pending = [];

        // Restore saved per-table layout (widths, hidden columns) for this query.
        $this->state->currentTable = $this->extractTableFromSql($sql);
        $db = $this->database->currentDatabase();
        if ($this->state->currentTable !== '' && $db !== '') {
            $this->state->colWidthOverride = $this->settings->getColWidths($db, $this->state->currentTable);
            $this->state->hiddenCols = $this->settings->getHiddenCols($db, $this->state->currentTable);
            $this->state->colOrder = $this->settings->getColOrder($db, $this->state->currentTable);
        } else {
            $this->state->colWidthOverride = [];
            $this->state->hiddenCols = [];
            $this->state->colOrder = [];
        }
        $this->state->applySort();

        // Track USE so the connection label reflects the active database.
        if ($first === 'USE' && !$result->hasError()
            && preg_match('/^USE\s+`?(\w+)`?/i', $sql, $m)) {
            $this->database->setCurrentDatabase($m[1]);
            $this->state->connectionLabel = $this->database->getConnectionLabel();
            $this->state->currentDatabase = $this->database->currentDatabase();
            $this->schema->reset(); // schema cache is per-database
            // DD is per-database too (§11); drop the old one and let it lazy-load
            // for the new db the next time a DD feature is opened.
            $this->dictionary = null;
            $this->ddDiffCache = [];
        }

        if ($result->hasError()) {
            $this->state->statusLine = 'Error: ' . (string) $result->error;
            $this->state->statusIsError = true;
        } else {
            // Surface the normalization note(s) when a rewrite happened (§5);
            // otherwise leave it blank so the row/col/time stats show.
            $this->state->statusLine = implode(' · ', $notes);
            $this->state->statusIsError = false;
        }

        $this->pushHistory($original);
        $this->pushExecHistory($sql);
        $this->input->clearAll();
        $this->resetHistoryNav();
    }

    /**
     * Auto-cap a row-returning query at AUTO_LIMIT rows (§5). Appends `LIMIT N`
     * to a SELECT/WITH statement that carries no LIMIT of its own; leaves every
     * other statement (and any query that already limits itself, including via a
     * subquery) untouched. $applied reports whether a cap was actually added so
     * the caller can surface a status note.
     */
    private function applyAutoLimit(string $sql, string $first, bool &$applied): string
    {
        $applied = false;
        if ($first !== 'SELECT' && $first !== 'WITH') {
            return $sql;
        }
        // A LIMIT anywhere (even a subquery) makes us defer — appending a second
        // top-level LIMIT could be wrong or a syntax error. Conservative on purpose.
        if (preg_match('/\bLIMIT\b/i', $sql)) {
            return $sql;
        }
        $applied = true;
        return rtrim($sql) . ' LIMIT ' . self::AUTO_LIMIT;
    }

    // --- history navigation ------------------------------------------------

    private function pushHistory(string $sql): void
    {
        $first = strtoupper(preg_split('/\s+/', $sql, 2)[0] ?? '');
        $entry = in_array($first, self::MODIFYING, true) ? '{safety} ' . $sql : $sql;

        $h = $this->state->history;
        if ($h !== [] && end($h) === $entry) {
            return; // no consecutive duplicate
        }
        $h[] = $entry;
        $this->state->history = $h;
        $this->settings->setHistory($h);
    }

    private function historyPrev(): void
    {
        if ($this->state->history === []) {
            return;
        }
        if ($this->historyIdx === count($this->state->history)) {
            $this->historySaved = $this->input->combinedText();
        }
        if ($this->historyIdx > 0) {
            $this->historyIdx--;
            $this->input->setSingle($this->state->history[$this->historyIdx]);
        }
    }

    private function historyNext(): void
    {
        if ($this->historyIdx >= count($this->state->history)) {
            return;
        }
        $this->historyIdx++;
        if ($this->historyIdx === count($this->state->history)) {
            $this->input->setSingle($this->historySaved);
        } else {
            $this->input->setSingle($this->state->history[$this->historyIdx]);
        }
    }

    private function resetHistoryNav(): void
    {
        $this->historyIdx = count($this->state->history);
        $this->historySaved = '';
        $this->execHistoryIdx = count($this->state->execHistory);
        $this->execHistorySaved = '';
    }

    // --- executed-SQL history (Shift+Up/Down, §4) --------------------------

    /** Record the normalized SQL actually sent to the server. */
    private function pushExecHistory(string $sql): void
    {
        if ($sql === '') {
            return;
        }
        $h = $this->state->execHistory;
        if ($h !== [] && end($h) === $sql) {
            return; // no consecutive duplicate
        }
        $h[] = $sql;
        $this->state->execHistory = $h;
        $this->settings->setExecHistory($h);
    }

    private function execHistoryPrev(): void
    {
        if ($this->state->execHistory === []) {
            return;
        }
        if ($this->execHistoryIdx === count($this->state->execHistory)) {
            $this->execHistorySaved = $this->input->combinedText();
        }
        if ($this->execHistoryIdx > 0) {
            $this->execHistoryIdx--;
            $this->input->setSingle($this->state->execHistory[$this->execHistoryIdx]);
        }
    }

    private function execHistoryNext(): void
    {
        if ($this->execHistoryIdx >= count($this->state->execHistory)) {
            return;
        }
        $this->execHistoryIdx++;
        if ($this->execHistoryIdx === count($this->state->execHistory)) {
            $this->input->setSingle($this->execHistorySaved);
        } else {
            $this->input->setSingle($this->state->execHistory[$this->execHistoryIdx]);
        }
    }
}
