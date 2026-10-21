<?php

declare(strict_types=1);

namespace Sqli\Core;

use includes\my;
use Sqli\Config\Config;
use Sqli\Config\Settings;
use Sqli\Config\Macros;
use Sqli\Db\Database;
use Sqli\Db\DataDictionary;
use Sqli\Db\FieldValidator;
use Sqli\Db\QueryResult;
use Sqli\Db\ResultColumns;
use Sqli\Db\ResultSource;
use Sqli\Db\Schema;
use Sqli\Db\WriteLog;
use Sqli\Db\SchemaDiff;
use Sqli\Export\CsvExport;
use Sqli\Format\ColumnFormats;
use Sqli\Input\LineEditor;
use Sqli\Json\JsonNode;
use Sqli\Json\JsonTree;
use Sqli\Render\Renderer;
use Sqli\Render\Scheme;
use Sqli\Render\SchemeLoader;
use Sqli\Render\Theme;
use Sqli\Split\SplitSpec;
use Sqli\Split\SplitTable;
use Sqli\Terminal\Style;
use Sqli\Support\Path;
use Sqli\Sql\Completion;
use Sqli\Sql\CompletionResult;
use Sqli\Sql\Normalizer;
use Sqli\Sql\NormalizeResult;
use Sqli\Sql\Splitter;
use Sqli\Terminal\Ansi;
use Sqli\Terminal\Key;
use Sqli\Terminal\KeyEvent;
use Sqli\Terminal\MouseEvent;
use Sqli\Terminal\PasteEvent;
use Sqli\Terminal\Screen;
use Sqli\Terminal\Terminal;

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
    /**
     * Everything typed on the CLI that means "quit" (§18), lower-cased and with
     * any trailing `;` already stripped by the caller.
     *
     * Only `exit`/`quit` are advertised. The rest are deliberately unadvertised
     * muscle-memory aliases — vi (`:q`), sqlite/psql-style dot commands, and the
     * conversational `bye` — kept out of the help, the status hints and the spec
     * on purpose. None of them is valid SQL, so none can shadow a real statement.
     *
     * @var list<string>
     */
    private const QUIT_WORDS = ['exit', 'quit', ':q', '.exit', '.quit', 'bye'];

    /**
     * Data View Configuration actions that act on one column ({@see State::$popupCol})
     * rather than on the whole table — the half of the menu that used to be F2.
     * Everything else in that menu falls through to {@see applyLayoutActionStr()}.
     */
    private const COLUMN_ACTIONS = [
        'sort_asc', 'sort_desc', 'move', 'width', 'fit', 'fit_all',
        'format_epoch', 'copy_col', 'hide',
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
    /** MySQL's code for a statement stopped by KILL QUERY — i.e. a successful cancel (§21). */
    private const INTERRUPTED = 1317;
    /**
     * Seconds between a lost connection and the statement going back out by
     * itself (§2).
     *
     * Was a full second, which bought the time to *read* the error. There is no
     * error to read any more — a loss sqli can handle is handled in silence —
     * so what is left to buy is one frame of the `!db` marker and a moment's
     * pause before coming straight back at a server that just dropped us.
     * Waited out in the frame loop, never slept through: the clock, the spinner
     * and Esc all stay live across it.
     */
    private const RESEND_DELAY = 0.3;

    /**
     * Seconds the header's `!db` marker stays up after the link is good again
     * (§2). The whole of a handled loss can pass in under half a second; this is
     * what makes it something the user saw rather than something that merely
     * happened.
     */
    private const LINK_LINGER = 3.0;

    private Terminal $terminal;
    private Screen $screen;
    private Renderer $renderer;
    /** The theme currently driving the Renderer (swapped live by the editor, §13). */
    private Theme $theme;
    /** Theme to restore if the editor is closed without saving, or null. */
    private ?Theme $themeBackup = null;
    private State $state;
    /**
     * The CLI's multi-line SQL input. A single {@see LineEditor} holds the whole
     * buffer as `\n`-joined text (each continuation line is a "\n" grapheme), so
     * the CLI shares the one editing core with every modal field — flat-offset
     * selection, word moves, mouse mapping (§4). The executed/stored SQL keeps
     * those newlines ({@see cliText()}), because a line comment ends on one.
     */
    private LineEditor $input;
    private Normalizer $normalizer;
    private Completion $completion;
    private Schema $schema;
    /**
     * Macros (§23): the `?name` snippets — with or without arguments —
     * read from the `.sqli/` layers and expanded into a submission before it is
     * split. Edited outside sqli, so the viewer's `r` re-reads it; the app never
     * writes this file.
     */
    private Macros $macros;

    private int $historyIdx = 0;
    private string $historySaved = '';
    private int $execHistoryIdx = 0;
    private string $execHistorySaved = '';
    private float $lastSizeCheck = 0.0;
    /** Frames drawn since the last heartbeat line, and when that line was written. */
    private int $heartbeatFrames = 0;
    private float $lastHeartbeat = 0.0;
    /**
     * Worst time any single frame spent in each phase of the loop since the last
     * heartbeat. Four microtime() calls a frame buys the difference between "the
     * app is stuck" and "the app is stuck in draw", which is the whole question.
     * @var array<string,float>
     */
    private array $phaseMax = ['input' => 0.0, 'query' => 0.0, 'resize' => 0.0, 'draw' => 0.0];
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
    /** Title-bar drag of the framed cell editor: [dx,dy] into the box, or null (§9). */
    private ?array $editorDrag = null;
    /** True while the framed cell editor's corner grip is being dragged (§9). */
    private bool $editorResizing = false;

    /** True while a left-drag begun in the CLI region is extending a text selection (§4). */
    private bool $cliSelecting = false;

    /** True while a left-drag begun in a modal edit field is extending its selection (§4). */
    private bool $modalSelecting = false;

    /** The loaded Data Dictionary for the current database, or null (§11). */
    private ?DataDictionary $dictionary = null;

    /**
     * True once a lazy DD load has been attempted for the current database, so a
     * failed load (e.g. a stale/unresolvable saved path) is not retried — and its
     * "Loading Data Dictionary …" notice re-flashed — on every subsequent query.
     * Reset whenever the DD is dropped (a USE to another db).
     */
    private bool $ddLoadAttempted = false;

    /** DD file paths already archived this session (archive-before-first-edit, §11). @var list<string> */
    private array $ddArchivedPaths = [];

    /** The parsed JSON document being viewed/edited, or null (§10). */
    private ?JsonTree $jsonTree = null;

    /** The cell open in the table view (`t`, §10a), or null. */
    private ?SplitTable $splitTable = null;
    /** Which result row the table view's cell is in. */
    private int $splitRawIdx = -1;
    /** The cell's value when the table view opened: what a change is measured against. */
    private string $splitStart = '';
    /**
     * Flattened JSON rows (with live JsonNode refs) for the open document, so a
     * key/toggle/edit acts on the exact node the user selected. The renderer gets
     * a node-stripped projection via State (see publishJson()).
     * @var list<array{node:\Sqli\Json\JsonNode,depth:int,label:string,value:string,type:string,container:bool,expanded:bool,childCount:int,editable:bool}>
     */
    private array $jsonRows = [];

    /** Where a not-yet-named new object member will be inserted (§10 add flow). */
    private ?JsonNode $jsonAddContainer = null;
    private int $jsonAddPos = 0;
    /**
     * Documents descended into with `j` from inside the viewer (§10), outermost
     * first. Each frame remembers the tree left behind, the **string leaf** the
     * nested document came out of, that leaf's original text, and where the
     * cursor was — everything {@see jsonAscend()} needs to put an edited
     * document back where it was found and land the user where they left.
     *
     * @var list<array{tree:JsonTree,node:JsonNode,original:string,sel:int,offset:int}>
     */
    private array $jsonStack = [];

    /**
     * The single in-flight cell edit whose UPDATE currently sits on the CLI, or
     * null. Editing is immediate-to-CLI (§9): confirming an edit stages the new
     * value in the grid and drops its UPDATE on the CLI to run or cancel; only one
     * cell is ever in flight. Keys: rawIdx, col, sql (the exact UPDATE on the CLI).
     * @var array{rawIdx:int,col:string,sql:string}|null
     */
    private ?array $autoUpdate = null;

    /**
     * The in-flight row deletion whose DELETE currently sits on the CLI, or null
     * (§22). Deliberately the same shape as {@see $autoUpdate}: the grid builds a
     * statement, drops it on the CLI, and Enter runs it or Esc cancels it — a
     * generated DELETE is reviewed as text before it goes, exactly like a
     * generated UPDATE. Keys: sql (the exact DELETE on the CLI), rawIdxs (the
     * rows it addresses) and count (rows the confirm reported).
     * @var array{sql:string,rawIdxs:list<int>,count:int}|null
     */
    private ?array $autoDelete = null;

    /** The `log-writes` record (§25); opened on the first write of the session. */
    private ?WriteLog $writeLog = null;

    /**
     * The delete held while its confirm popup is open (§22), moved to
     * {@see $autoDelete} on Y and dropped on N. Separate from
     * {@see $pendingDangerous} because that one belongs to the CLI's own
     * dangerous-query guard and re-runs a *typed* statement through
     * {@see execute()}; this one never goes near execute().
     * @var array{sql:string,rawIdxs:list<int>,count:int}|null
     */
    private ?array $pendingDelete = null;

    /**
     * The "N rows deleted" sentence, held across the SELECT that a delete
     * re-runs (§22). {@see applyResult()} writes the reload's own status line,
     * which would otherwise be the last word on screen after a delete - and
     * "42 rows" is not what the user just asked about.
     */
    private string $deleteNote = '';

    /**
     * Schema-diff results cached per table (lower-cased name) so the DD viewer
     * doesn't re-run SHOW COLUMNS every frame. Cleared on USE and on DD reload.
     * @var array<string,array{fieldStatus:array<string,string>,missing:list<string>,changed:list<string>,extra:list<string>,hasDiff:bool}|null>
     */
    private array $ddDiffCache = [];

    /** Original typed DELETE/UPDATE held while the dangerous-query confirm is open (§5). */
    private ?array $pendingDangerous = null;

    /**
     * Statements of the current submit still waiting to run, in order (§5).
     * Multi-statement input is split client-side by {@see Splitter} and run one
     * at a time so every statement faces read-only mode and the dangerous-query
     * confirm on its own terms — the whole point of the splitter.
     * @var list<string>
     */
    private array $batchQueue = [];
    /** Statements the current submit started with; >1 means multi-statement. */
    private int $batchTotal = 0;
    /** Statements of the current submit that have finished. */
    private int $batchDone = 0;
    /** Output mode (`\G`) the whole batch runs under. */
    private bool $batchVertical = false;
    /**
     * The submission began with `!` (§5): every statement goes to the server
     * exactly as typed — no macros, no normalizing, no auto-LIMIT. The guards
     * (read-only, dangerous-query confirm) still run.
     */
    private bool $batchRaw = false;
    /**
     * Why the statement just run did not complete: '' ran, 'confirm' opened the
     * dangerous-query popup (the batch is suspended until it is answered),
     * 'async' was sent and is still running (the batch is suspended until it
     * comes back), or 'blocked' was refused outright (the rest of the batch is
     * dropped).
     *
     * 'async' and 'confirm' are the same kind of stop — the queue survives and
     * something later resumes it — which is why the cancellable path needed no
     * new state machine: {@see pumpQuery()} resumes the batch exactly where
     * {@see handleConfirmKey()} already did.
     */
    private string $batchStop = '';

    /**
     * The submission as it was **typed**, when macros (§23) made what runs
     * differ from it; null when they did not.
     *
     * Typed history keeps this rather than the expanded text, on the same
     * principle as the normalizer's `$original`: Up-arrow gives back the line
     * the user wrote — arguments and all — so re-running it re-reads the
     * macro instead of repeating whatever it happened to hold an hour ago. The executed-SQL
     * history (Alt+Up) still records what actually went to the server.
     */
    private ?string $submitTyped = null;

    /**
     * The whole CLI submission exactly as typed, for {@see State::$lastTyped}
     * (F5). Unlike {@see $submitTyped} it is always set, and it spans every
     * statement of a batch. Null outside a typed submission, so a statement
     * sqli runs on its own (the PK re-run) leaves the view's text alone.
     */
    private ?string $submissionTyped = null;

    /**
     * The submission suspended on a `?{name}` question, or null (§23).
     *
     * Holds the expanded SQL and everything {@see runSubmission()} will need
     * when the last answer comes in — the typed text for history, the output
     * mode, the target tab — because between the question and the answer the
     * user may do nothing else, but the frame loop keeps running and the
     * arguments to a half-finished call have to live somewhere.
     *
     * @var array{sql:string,typed:string,vertical:bool,tab:?int,names:list<string>,answers:array<string,string>}|null
     */
    private ?array $pendingPrompt = null;

    /**
     * "N macros expanded", waiting to be said once — consumed by the first
     * statement of the submission. Per submission, not per statement: one
     * `?cleanup` that expands into four statements is one expansion.
     */
    private string $macroNote = '';

    /**
     * The statement waiting to go out again by itself after the connection died
     * under it, and when (§2). Null when nothing is scheduled.
     *
     * It carries the *originally typed* text so the re-send normalizes exactly
     * as the first attempt did, and the confirmation the user already gave, so
     * a write they approved once is not put back in front of them.
     *
     * @var array{sql:string,vertical:bool,confirmed:bool,raw:bool,at:float}|null
     */
    private ?array $pendingResend = null;

    /**
     * One automatic attempt per statement. Cleared by a result that comes back
     * without an error and by every new submission; set when a re-send goes
     * out. Without it, a server that is genuinely down turns one dead statement
     * into a re-send loop a second wide.
     */
    private bool $resendUsed = false;

    /**
     * Set while the statement now going out is one that came back by itself, so
     * its answer can carry the marker that says where it came from. Consumed by
     * {@see execute()}, which hands it to {@see $queryContext}.
     */
    private bool $resentStatement = false;

    /**
     * Set while the statement now going out is sqli re-running the view's own
     * query — the reload after a grid delete (§22), the add-the-PK re-run — so
     * a view whose layout is not saved ({@see State::$layoutUnsaved}) stays
     * that way. Those re-runs send the *executed* SQL, where a mixed star has
     * already been expanded and no longer looks like one. Consumed by
     * {@see execute()}, which hands it to {@see $queryContext}.
     */
    private bool $rerunUnsaved = false;

    /** Set by {@see runScript()}: no terminal, so nothing may try to draw. */
    private bool $headless = false;

    /**
     * The whole of what a silently re-sent statement says about itself (§2).
     *
     * A footnote behind the row count, not a headline in front of it: a rewrite
     * note leads the status line because it changed *what ran*, and this changed
     * nothing about what ran. It replaced `reconnected · statement re-sent`
     * leading the line, which was a second sentence about an event the user was
     * deliberately not being troubled with.
     */
    public const RESENT_NOTE = 'reconnected';

    /**
     * What {@see applyResult()} needs about the statement now in flight: the
     * normalized SQL, its command keyword, the originally typed text, the
     * normalization notes, and the output mode. Parked here because on the async
     * path the two halves of {@see execute()} are separated by frames.
     *
     * @var array{sql:string,first:string,original:string,notes:list<string>,vertical:bool,capped:bool,confirmed:bool}
     */
    private array $queryContext = [
        'sql' => '', 'first' => '', 'original' => '', 'notes' => [], 'vertical' => false,
    ];

    /** Column order / selection captured on entering "move column" mode, for Esc revert. */
    private ?array $moveSavedOrder = null;
    private int $moveSavedCol = 0;

    /**
     * Parked data views, keyed by tab index (§20). The *active* view is not in
     * here — it lives in State, which is what lets every one of App's existing
     * `$this->state->selectedRow`-style references keep working untouched. A tab
     * switch is a capture of the current view into this array and a restore of
     * the target's; a tab with no entry yet shows {@see State::blankView()}.
     *
     * Deliberately not persisted: views are scratch space, so a restart starts
     * with five empty grids.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $tabViews = [];

    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly string $version,
    ) {
        $this->terminal = new Terminal();
        $this->input = new LineEditor();
        $this->state = new State();
        $this->state->tabLabels = array_fill(0, State::TAB_COUNT, '');
        $this->normalizer = new Normalizer();
        $this->completion = new Completion();
        $this->schema = new Schema($database);
        $this->macros = Macros::fromConfig($config);
        // Decide truecolor vs. 256-color fallback before styles are emitted.
        Style::setTruecolor($this->resolveTruecolor());
        $this->theme = $this->buildTheme();
        $this->renderer = new Renderer($this->theme);
    }

    /**
     * Resolve the `truecolor` option (auto|on|off) to a boolean. `auto` sniffs
     * the environment ({@see Style::detectTruecolor()}); an explicit on/off
     * overrides detection — useful when a terminal is misidentified.
     */
    private function resolveTruecolor(): bool
    {
        return match (strtolower($this->config->getString('truecolor', 'auto'))) {
            'on', 'true', '1', 'yes' => true,
            'off', 'false', '0', 'no' => false,
            default => Style::detectTruecolor([
                'COLORTERM'    => getenv('COLORTERM') ?: null,
                'WT_SESSION'   => getenv('WT_SESSION') ?: null,
                'ConEmuANSI'   => getenv('ConEmuANSI') ?: null,
                'TERM_PROGRAM' => getenv('TERM_PROGRAM') ?: null,
                'TERM'         => getenv('TERM') ?: null,
            ]),
        };
    }

    /**
     * Resolve the active theme into a {@see Theme} backed by a Base16 scheme.
     *
     * Name precedence: an explicit config/CLI `--theme` (anything other than the
     * built-in `default`) wins over the persisted UI state, so a flag or config
     * file overrides whatever was last selected interactively. The name `default`
     * (or an unresolvable one) falls back to the built-in Default Dark palette so
     * the UI is always themed, never colorless.
     *
     * Schemes are searched in the project `.sqli/themes/` dirs (closest first),
     * then the global one. Themes are user assets — sqli ships none.
     */
    private function buildTheme(): Theme
    {
        $configTheme = $this->config->getString('theme', 'default');
        $name = $configTheme !== 'default' ? $configTheme : $this->settings->getTheme();
        if ($name === '' || $name === Scheme::BUILT_IN) {
            // `<default>` asked for the in-code palette by name — no file lookup,
            // which is also the way to override a saved theme back to built-in.
            return new Theme(Scheme::BUILT_IN);
        }

        // Try to load a scheme file even for 'default' — a user may drop a
        // file named `default` to reskin it; only fall back to the built-in Default Dark
        // palette when no file resolves.
        $reason = null;
        $scheme = $this->themeLoader()->load($name, $reason);
        if ($scheme === null) {
            if ($name !== 'default') {
                my::lineout('FILE:FILE', 'theme load failed, using default', $name, (string) $reason);
            }
            return new Theme(Scheme::BUILT_IN);
        }
        return new Theme($name, $scheme);
    }

    /**
     * Scheme search path: each project `.sqli/themes/` (closest first), then
     * the global `$SQLI_HOME/.sqli/themes/`.
     */
    private function themeLoader(): SchemeLoader
    {
        return new SchemeLoader($this->config->themeDirs());
    }

    /**
     * Every theme that can be picked: the built-in `<default>` first, then the
     * discoverable scheme files. The built-in is always offered so the list is
     * never empty and there is always a way back to a known-good palette.
     *
     * @return list<string>
     */
    private function themeNames(): array
    {
        return [Scheme::BUILT_IN, ...$this->themeLoader()->available()];
    }

    /**
     * Resolve a picker name to a Scheme. `<default>` comes from code; anything
     * else goes through the file search path.
     */
    private function loadScheme(string $name, ?string &$reason = null): ?Scheme
    {
        if ($name === Scheme::BUILT_IN) {
            return Scheme::default();
        }
        return $this->themeLoader()->load($name, $reason);
    }

    public function run(): int
    {
        $this->state->version = $this->version;
        $this->state->readOnly = $this->config->isFlag('read-only');
        $this->state->envUntyped = $this->untypedEnv();
        // `--comment PROD`, or a `comment` key on the env in the config file, so
        // a server that deserves a warning carries one without anyone having to
        // remember to type it (§7).
        $this->state->comment = self::cleanComment($this->config->getString('comment'));
        // Both fixed for the life of the connection: the driver is chosen before
        // this and never changes. canCancel gates what the UI offers (§21);
        // driverName is shown in the header.
        $this->state->canCancel  = $this->database->supportsCancel();
        $this->state->driverName = $this->database->driverName();
        $this->state->history = $this->settings->getHistory();
        $this->historyIdx = count($this->state->history);
        $this->state->execHistory = $this->settings->getExecHistory();
        $this->execHistoryIdx = count($this->state->execHistory);

        $this->terminal->open();
        // Restore the terminal even on a fatal error or fatal signal path.
        register_shutdown_function(fn () => $this->terminal->close());

        // Measure the initial viewport. Prefer the platform API — Windows FFI
        // srWindow, or POSIX ioctl(TIOCGWINSZ) — when available: it is the true
        // visible window for both grow and shrink, and asking costs nothing on
        // the wire. Otherwise fall back to the DSR cursor-position report.
        $platformSize = $this->terminal->hasPlatformSize();
        [$w, $h] = $platformSize
            ? $this->terminal->size()
            : $this->terminal->measureSize();
        my::lineout('FILE:FILE', 'init size', $w . 'x' . $h, 'platformSize', $platformSize);
        $this->screen = new Screen($w, $h);

        // Normally the connection is already open: sqli.php connects on the plain
        // terminal so a failure can be read and retried before the TUI exists.
        // Adopting it here keeps the startup path honest — no second connection,
        // and no modal for work that is already done.
        //
        // The dialog remains for any caller that hands over an unconnected
        // Database. A connect to an unreachable server blocks for the full TCP
        // timeout; painting the popup first means the user sees which database
        // we're waiting on — instead of a blank TUI — and can Ctrl+C.
        if ($this->database->isConnected()) {
            $this->adoptConnection();
        } else {
            $this->withBusy(
                'Connecting to ' . $this->database->target() . ' …',
                fn () => $this->connect(),
                modal: true,
            );
        }

        // Report config migrations / registry updates: each says exactly which
        // file in which dir was touched. All go to the log; the latest one also
        // lands on the status line so it's seen without digging.
        $notices = $this->config->notices();
        foreach ($notices as $n) {
            my::lineout('FILE:FILE', 'config', $n);
        }
        if ($notices !== []) {
            $this->state->statusLine = (string) end($notices);
            $this->state->statusIsError = false;
        }
        // The selected env is defined differently by two config files (§2). sqli.php
        // said so on the plain terminal, which the alternate screen has just
        // covered, so it is said again where it will be read.
        $conflicts = $this->config->envConflicts($this->config->envChain());
        foreach ($conflicts as $c) {
            my::lineout('FILE:FILE', 'env conflict', $c);
        }
        if ($conflicts !== []) {
            $this->state->statusLine = 'warning: ' . $conflicts[0]
                . (count($conflicts) > 1 ? sprintf(' (+%d more — see --lint)', count($conflicts) - 1) : '');
            $this->state->statusIsError = true;
        }

        // Only load a Data Dictionary at startup when one is explicitly passed via
        // --dictionary. A saved per-db association is loaded lazily, the first time
        // the user actually opens a DD feature (§11) — not eagerly on launch.
        $cliDict = $this->config->getString('dictionary');
        if ($cliDict !== '') {
            $this->loadDictionary($this->database->stateKey(), $cliDict);
        }

        $this->loop();

        $this->terminal->close();
        return 0;
    }

    private function loop(): void
    {
        $this->draw();
        while ($this->state->running) {
            $phase = microtime(true);
            $events = $this->terminal->input()->poll();
            foreach ($events as $event) {
                if ($event instanceof KeyEvent) {
                    $this->handleKey($event);
                } elseif ($event instanceof MouseEvent) {
                    $this->handleMouse($event);
                } elseif ($event instanceof PasteEvent) {
                    $this->handlePaste($event);
                }
                if (!$this->state->running) {
                    break;
                }
            }
            $this->mark('input', $phase);

            // Collect a finished query before anything else looks at the frame:
            // the result reshapes the grid, and drawing it in the same pass it
            // landed is what makes a cancelled query feel like it stopped rather
            // than like it stopped a frame later (§21).
            $phase = microtime(true);
            $this->pumpQuery();
            // The header's connection notice is derived, not maintained: one
            // assignment a frame, from the only object that knows. A flag set at
            // each of the places a connection can die and cleared at each of the
            // places one can be opened is the kind of bookkeeping that ends with
            // a red banner over a working connection.
            $this->state->linkState = match (true) {
                !$this->database->isLost() => State::LINK_OK,
                // Still derived, still one assignment: a statement waiting to go
                // out again is a fact this object holds, not a flag anyone sets
                // twice. It reads as RESENDING rather than LOST because there is
                // nothing for the user to do about it.
                $this->pendingResend !== null => State::LINK_RESENDING,
                default => State::LINK_LOST,
            };
            // The marker outlives the fault. Pushed forward on every bad frame
            // rather than set on a transition: the reconnect inside execute()
            // takes `linkState` from LOST to OK without this loop running once
            // in between, so a transition here would never fire — and a deadline
            // that always expires cannot be left standing over a live link (§2).
            if ($this->state->linkState !== State::LINK_OK) {
                $this->state->linkLingerUntil = microtime(true) + self::LINK_LINGER;
            }
            // Same rule, same reason: whether the session is encrypted is asked
            // of the connection every frame rather than remembered, because the
            // plaintext retry and the reconnect can both change the answer (§2).
            $this->state->secure = $this->database->isEncrypted();
            $this->maybeResend();
            $this->mark('query', $phase);

            // poll() drains console resize records; if one arrived, repaint right
            // away (force past the size-poll throttle) so resize feels instant.
            $phase = microtime(true);
            $this->maybeResize($this->terminal->takeResizeSignal());
            $this->mark('resize', $phase);
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
            // Persistence plumbing (§16): the first save attempted with no
            // `.sqli/` anywhere opens the "save settings where?" prompt, and any
            // state-file write failure is surfaced with its path + reason.
            if ($this->settings->needsDir() && $this->state->chooser === null) {
                $this->openStateDirPrompt();
            }
            $writeError = $this->settings->takeWriteError();
            if ($writeError !== null) {
                $this->state->statusLine = $writeError;
                $this->state->statusIsError = true;
            }
            $phase = microtime(true);
            $this->draw();
            $this->mark('draw', $phase);
            $this->heartbeat();
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
        // Screen::flush() promotes back to front as it renders: once it returns,
        // the screen believes those cells are on the glass. If the write then drops
        // bytes, that belief is wrong and the difference is permanent — the cells
        // are clean, so no later frame will repaint them. This is what left a
        // half-drawn screen that only a manual resize could clear, because a resize
        // invalidates by hand. Do it here instead, where the drop is known.
        if (!$this->terminal->write($this->screen->flush())) {
            $this->screen->invalidate();
        }
    }

    /**
     * Run a known-slow synchronous operation behind a "please wait" message. The
     * main loop is blocked while $fn runs, so we paint one frame with the message
     * and flush it to the terminal *before* calling $fn, then clear it. The next
     * normal frame from run() erases it. Returns $fn's result.
     *
     * By default the message is an unobtrusive one-liner above the status/help
     * line — routine queries shouldn't fire a modal in the middle of the screen
     * every time. Pass $modal = true for the centered, bordered dialog, reserved
     * for the initial DB connect (which can block for the full TCP timeout).
     *
     * For a multi-step op that can report progress, see {@see warmDdDiffs()},
     * which drives {@see $busyMessage} + {@see flushFrame()} itself between steps.
     *
     * @macro T
     * @param callable():T $fn
     * @return T
     */
    private function withBusy(string $message, callable $fn, bool $modal = false): mixed
    {
        // Scripting mode (-e) has no screen to paint the notice on.
        if ($this->headless) {
            return $fn();
        }
        $this->state->busyMessage = $message;
        $this->state->busyModal = $modal;
        $this->flushFrame();
        try {
            return $fn();
        } finally {
            $this->state->busyMessage = '';
            $this->state->busyModal = false;
        }
    }

    private function maybeResize(bool $force = false): void
    {
        // Prefer the platform API — Windows FFI srWindow, or POSIX
        // ioctl(TIOCGWINSZ) — which is the visible window for both grow and
        // shrink and costs no terminal traffic. A resize event ($force: a console
        // record on Windows, SIGWINCH on POSIX) applies immediately; otherwise we
        // poll at most every SIZE_POLL_INTERVAL as a safety net. The DSR
        // cursor-clamp is only a fallback for terminals with neither: it
        // under-reports height right after the window grows (the alt-screen
        // buffer lags), which left the CLI/status stranded at the old bottom row
        // with a blank band below.
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

        // No platform API (a pipe, Git-Bash/MSYS, or a POSIX build without FFI):
        // drive resize with an async DSR cursor-clamp report.
        $report = $this->terminal->takeSizeReport();
        if ($report !== null) {
            $this->dsrInFlight = false;
            $this->applySize($report[0], $report[1]);
        }
        $now = microtime(true);
        // How often to ask depends on whether the loop can tick without an answer.
        //
        // Where stdin CANNOT be made non-blocking (the Windows console/pipe), the
        // DSR reply is the only thing that wakes an idle read, so exactly one
        // report must stay in flight: each reply immediately triggers the next
        // request. Throttling there deadlocks — the throttle suppresses the
        // request, and the read then has nothing left to wake it until a keystroke.
        //
        // Where stdin IS non-blocking (every POSIX tty), the loop ticks on its own
        // and the request can be throttled like the platform path. That is the
        // difference between a terminal round-trip every frame — 100+ a second,
        // for as long as sqli is open — and one every SIZE_POLL_INTERVAL.
        $due = $this->terminal->stdinIsNonBlocking()
            ? $now - $this->lastDsrRequest >= self::SIZE_POLL_INTERVAL
            : !$this->dsrInFlight;
        // Recover if a reply was lost (no answer within a short grace period).
        if ($due || $force || $now - $this->lastDsrRequest > 0.5) {
            $this->terminal->requestSizeReport();
            $this->dsrInFlight = true;
            $this->lastDsrRequest = $now;
        }
    }

    /** Record a phase duration if it is the worst seen since the last heartbeat. */
    private function mark(string $phase, float $startedAt): void
    {
        $took = microtime(true) - $startedAt;
        if ($took > $this->phaseMax[$phase]) {
            $this->phaseMax[$phase] = $took;
        }
    }

    /**
     * Once a second, while debug logging is on, write one line saying how fast the
     * loop is turning, what size it believes the screen is, and what the platform
     * says it actually is.
     *
     * It exists because the failure it was written for could not be described from
     * the outside: "resizing is broken" covers a loop that has stopped, a loop that
     * is running against a stale size, and a terminal that is being talked over.
     * The three look identical on screen and are three different bugs. A gap in
     * these timestamps is the first; `screen` disagreeing with `platform` is the
     * second; a climbing `dsrSent` is the third. Several sqli can share one log —
     * every line carries its pid.
     */
    private function heartbeat(): void
    {
        $this->heartbeatFrames++;
        if (!my::$enabled) {
            return;
        }
        $now = microtime(true);
        $elapsed = $now - $this->lastHeartbeat;
        if ($elapsed < 1.0) {
            return;
        }
        // The first call has no interval behind it, so it reports the state
        // without inventing a rate for it.
        $fps = $this->lastHeartbeat > 0.0
            ? sprintf('%.1f', $this->heartbeatFrames / $elapsed)
            : 'n/a';
        $slowest = '';
        foreach ($this->phaseMax as $phase => $took) {
            $slowest .= sprintf('%s%s=%.0fms', $slowest === '' ? '' : ' ', $phase, $took * 1000);
        }
        $this->lastHeartbeat = $now;
        $this->heartbeatFrames = 0;
        $this->phaseMax = ['input' => 0.0, 'query' => 0.0, 'resize' => 0.0, 'draw' => 0.0];
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

    /**
     * Take the app's state from a connection that is already open — the normal
     * path, since sqli.php connects before the TUI starts.
     */
    private function adoptConnection(): void
    {
        $this->state->connectionLabel = $this->database->getConnectionLabel();
        $this->state->currentDatabase = $this->database->currentDatabase();
        $this->state->secure = $this->database->isEncrypted();
        // The status line says nothing about the connection, on purpose: who,
        // where, which driver and whether it is encrypted all live on the header
        // row now (§3). Saying it twice cost the status line its first screenful
        // — the line that then has to carry row counts, notes and edit prompts —
        // to repeat something already on screen and still true.
        $this->state->statusLine = '';
        $this->state->statusIsError = false;
    }

    private function connect(): void
    {
        try {
            $this->database->connect();
            $this->adoptConnection();
        } catch (\Throwable $e) {
            $this->state->connectionLabel = '';
            $this->state->currentDatabase = '';
            $this->state->statusLine = 'Connection failed: ' . $e->getMessage() . $this->connectHint($e->getMessage());
            $this->state->statusIsError = true;
            my::lineout('FILE:FILE', 'connect failed', $e->getMessage());
        }
    }

    /**
     * Turn a connection error the user can't act on into one they can. MySQL's
     * 3159 names the server setting (`--require_secure_transport=ON`) but not
     * the client knob that answers it, and someone hitting it has no reason to
     * guess that sqli has an `ssl-mode` at all.
     *
     * Public + static so the mapping can be pinned without a server.
     */
    public static function connectHint(string $message): string
    {
        $m = strtolower($message);
        if (str_contains($m, 'insecure transport') || str_contains($m, '3159')) {
            return ' · This server requires TLS — set ssl-mode (preferred|required|verify-ca) for this connection.';
        }
        if (str_contains($m, 'ssl connection error')) {
            return ' · TLS handshake failed — check ssl-ca, or try ssl-mode=required to skip certificate verification.';
        }
        return '';
    }

    // --- Data Dictionary (§11) ---------------------------------------------

    /**
     * (Re)load the Data Dictionary for a database. An explicit path (from
     * --dictionary) takes precedence and is persisted as the association for
     * that database; otherwise the path saved in `sqli_state.json` is used.
     * Clears the dictionary when nothing applies.
     *
     * $scope is a {@see Database::stateKey()} — `host:port/db`, not a bare
     * database name, so two hosts serving a same-named database keep their own
     * dictionary association.
     */
    private function loadDictionary(string $scope, ?string $explicit = null): void
    {
        $path = null;
        if ($explicit !== null && $explicit !== '') {
            $path = $explicit;
            if ($scope !== '') {
                $this->settings->setDictionary($scope, $explicit);
            }
        } elseif ($scope !== '') {
            $path = $this->settings->getDictionary($scope);
        }

        $this->dictionary = ($path !== null && $path !== '')
            ? $this->withBusy('Loading and comparing Data Dictionary …', static fn () => DataDictionary::load($path))
            : null;
        $this->ddLoadAttempted = true; // an explicit load counts; don't re-attempt lazily

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
        if ($this->dictionary !== null || $this->ddLoadAttempted) {
            return;
        }
        $scope = $this->database->stateKey();
        $saved = $scope !== '' ? $this->settings->getDictionary($scope) : null;
        if ($saved !== null && $saved !== '') {
            $this->ddLoadAttempted = true; // don't retry a failing load every query
            $this->dictionary = $this->withBusy(
                'Loading and comparing Data Dictionary …',
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
        $scope = $this->database->stateKey();
        $saved = $scope !== '' ? $this->settings->getDictionary($scope) : null;
        return $saved !== null && $saved !== '';
    }

    // --- input handling ----------------------------------------------------

    private function handleKey(KeyEvent $e): void
    {
        // While a statement is running the keyboard does one thing: Esc asks the
        // server to stop it (§21). Everything else is swallowed here, above every
        // modal check below — including the Ctrl+Alt+Z quit, deliberately.
        //
        // This is the narrow reading of "the loop is alive again", and it is
        // narrow on purpose. Letting the rest of the app run during a query
        // means a result landing into a view the user has since left, an edit
        // committed against a grid that is about to be replaced, and a second
        // submission with nowhere to go — a much larger surface than the feature
        // being bought. Nothing here forecloses widening it later: every key
        // that would need a home is currently refused in one place.
        if ($this->state->queryRunning) {
            if ($e->key === Key::Escape) {
                $this->cancelQuery();
            }
            return;
        }

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
            $editor = $this->input;
            if ($this->state->ddHintStripsMark
                && mb_substr($editor->text(), max(0, $editor->cursor() - 1), 1) === '?') {
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

        // The theme editor owns input while open (§13). It sits above the other
        // popups: it is opened from the system menu and its own sub-modals (hex
        // entry, save-as, scheme picker) are handled inside its key handler.
        if ($this->state->themeEditor) {
            $this->handleThemeEditorKey($e);
            return;
        }
        // Standalone theme switch, also from the system menu (§13).
        if ($this->state->themeSelect) {
            $this->handleThemeSelectKey($e);
            return;
        }
        // Generic chooser popup (create-config dir picker / state-dir prompt).
        if ($this->state->chooser !== null) {
            $this->handleChooserKey($e);
            return;
        }
        // The header-comment prompt, opened from the system menu (§7).
        if ($this->state->commentEdit) {
            $this->handleCommentEditKey($e);
            return;
        }

        // A `?{name}` question, with a submission waiting behind it (§23).
        if ($this->state->promptAsk) {
            $this->handlePromptKey($e);
            return;
        }

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

        // The macros viewer, likewise, until Esc (§23).
        if ($this->state->macrosView) {
            $this->handleMacrosViewKey($e);
            return;
        }

        // The table view of a separated-value cell (§10a), and its definition
        // editor, which can sit over it or open on its own the first time.
        if ($this->state->splitDefEdit) {
            $this->handleSplitDefKey($e);
            return;
        }
        if ($this->state->splitView) {
            $this->handleSplitKey($e);
            return;
        }

        // Modal overlays consume input until dismissed (innermost first).
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

        // The Ctrl+Alt+F field filter owns the keyboard until it is closed (§6):
        // typing narrows the columns, the arrows move among what is left, and
        // nothing that rearranges columns can be reached while it is open.
        if ($this->state->colFilter !== null) {
            $this->handleColFilterKey($e);
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
            $this->input->clearSelection(); // a CLI selection doesn't carry into DATA focus (§4)
            $this->state->focus = $this->state->focus === State::FOCUS_CLI
                ? State::FOCUS_DATA
                : State::FOCUS_CLI;
            return;
        }

        // Ctrl+L opens Data View Configuration (also reachable via the
        // right-margin label, a column-header click, or the system menu, §6).
        if ($e->isChar() && $e->ctrl && strtolower($e->char) === 'l') {
            if ($this->state->hasResult()) {
                $this->openLayoutPopup($this->state->selectedCol);
            }
            return;
        }

        // Alt+1…Alt+9 switch data views, in either focus (§20). Decoded upstream
        // as ESC + the digit, so it arrives here as a Char with alt set and needs
        // nothing from the terminal layer.
        //
        // Placed below every modal guard above deliberately: while a popup, a
        // cell edit or the DD viewer owns input, those handlers have already
        // returned, so a tab switch cannot fire out from under unfinished work.
        if ($e->isChar() && $e->alt && !$e->ctrl && strlen($e->char) === 1
            && $e->char >= '1' && $e->char <= (string) State::TAB_COUNT) {
            $this->switchTab((int) $e->char - 1);
            return;
        }

        // Ctrl+PageUp / Ctrl+PageDown step through the data views and **wrap**
        // (§20): the same nine places Alt+1…9 reaches, for when you want the next
        // one rather than a particular one. Sits beside Alt+N for the same reason
        // it does — every modal handler above has already returned, so this cannot
        // fire out from under an open popup or an unfinished edit.
        //
        // It must be tested before the focus handlers below, because plain
        // PageUp/PageDown is a page of scrolling in the grid, the detail view,
        // the JSON tree and every list popup, and none of those look at Ctrl.
        if (($e->key === Key::PageUp || $e->key === Key::PageDown) && $e->ctrl && !$e->alt) {
            $this->cycleTab($e->key === Key::PageDown ? 1 : -1);
            return;
        }

        // F1 opens the context-sensitive help overlay (§15).
        if ($e->key === Key::F1) {
            $this->state->help = true;
            return;
        }

        // F5 re-runs the SQL behind the visible data view, from either focus.
        // Below every modal guard, like F1, so it cannot replace the CLI — or
        // fire a statement — out from under an open popup.
        if ($e->key === Key::F5) {
            $this->recallViewSql();
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
        $editor = $this->input;
        switch ($e->key) {
            case Key::Char:
                // Ctrl+A selects the whole CLI; Ctrl+C copies the selection (§4).
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 'a') {
                    $this->input->selectAll();
                    return;
                }
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 'c') {
                    $this->copyCliSelection();
                    return;
                }
                if ($e->ctrl || $e->alt) {
                    return; // other control combos unhandled here
                }
                // Typing over a selection replaces it (standard editor behavior).
                if ($this->input->hasSelection()) {
                    $this->input->deleteSelection();
                } else {
                    $this->input->clearSelection();
                }
                $editor->insert($e->char);
                $this->resetHistoryNav();
                // `word?` triggers the DD field-hint popup (§11).
                if ($e->char === '?') {
                    $this->openDdHint();
                }
                break;
            case Key::Backspace:
                // A selection is deleted whole; otherwise Ctrl+Backspace deletes
                // the previous word, col-0 merges up, else a plain backspace (§4).
                if ($this->input->deleteSelection()) {
                    break;
                }
                // A flat backspace at column 0 deletes the preceding "\n", which
                // merges the line into the one above — no special-case needed.
                $e->ctrl ? $editor->deleteWordLeft() : $editor->backspace();
                break;
            case Key::Delete:
                // A selection is deleted whole; else Ctrl+Del deletes the current
                // word and plain Del removes the char at the caret (§4).
                if ($this->input->deleteSelection()) {
                    break;
                }
                $e->ctrl ? $editor->deleteWordRight() : $editor->delete();
                break;
            case Key::Left:
                $this->cliSelectGuard($e);
                $e->ctrl ? $editor->wordLeft() : $editor->left();
                break;
            case Key::Right:
                $this->cliSelectGuard($e);
                $e->ctrl ? $editor->wordRight() : $editor->right();
                break;
            case Key::Home:
                // Home → start of the current line; Ctrl+Home → start of buffer.
                $this->cliSelectGuard($e);
                $e->ctrl ? $editor->home() : $editor->lineHome();
                break;
            case Key::End:
                // End → end of the current line; Ctrl+End → end of buffer.
                $this->cliSelectGuard($e);
                $e->ctrl ? $editor->end() : $editor->lineEnd();
                break;
            case Key::Up:
                // Shift+Up extends the selection up a line (at the top line it
                // selects to the line start). Alt+Up browses executed-SQL history
                // (§4). Plain Up moves the active line, then enters typed history.
                if ($e->shift) {
                    if (!$this->cliMoveUp(true)) {
                        $this->input->startSelection();
                        $editor->lineHome();
                    }
                } elseif ($e->alt) {
                    $this->input->clearSelection();
                    $this->execHistoryPrev();
                } elseif (!$this->cliMoveUp(false)) {
                    $this->historyPrev();
                }
                break;
            case Key::Down:
                // Shift+Down extends the selection down a line (at the bottom line
                // it selects to the line end). Alt+Down walks executed-SQL history
                // forward (§4).
                if ($e->shift) {
                    if (!$this->cliMoveDown(true)) {
                        $this->input->startSelection();
                        $editor->lineEnd();
                    }
                } elseif ($e->alt) {
                    $this->input->clearSelection();
                    $this->execHistoryNext();
                } elseif ($this->inHistory()) {
                    $this->historyNext();
                } else {
                    $this->cliMoveDown(false);
                }
                break;
            case Key::Escape:
                // Esc first drops an active selection; then, if the CLI holds an
                // in-flight cell edit's UPDATE, cancels the edit (revert grid +
                // return to the data view, §9); otherwise clears the buffer (§4).
                if ($this->input->hasSelection()) {
                    $this->input->clearSelection();
                } elseif ($this->autoUpdate !== null) {
                    $this->cancelInFlight();
                } elseif ($this->autoDelete !== null) {
                    $this->cancelInFlightDelete();
                } else {
                    $this->input->clear();
                    $this->resetHistoryNav();
                }
                break;
            case Key::Tab:
                $this->openCompletion();
                break;
            case Key::Enter:
                $this->onEnter($e);
                break;
            default:
                break;
        }
    }

    /**
     * Selection bookkeeping shared by the CLI caret-move keys (§4): a shifted
     * move anchors the selection (if not already) before the caret moves; an
     * unshifted move drops any selection.
     */
    private function cliSelectGuard(KeyEvent $e): void
    {
        $e->shift ? $this->input->startSelection() : $this->input->clearSelection();
    }

    /**
     * The CLI buffer as executable SQL: display lines right-trimmed and rejoined
     * with the newlines the user typed (§4/§5).
     *
     * The newlines are not cosmetic, which is why this no longer joins with a
     * space. `--` and `#` comments run to the **end of the line**, so flattening
     *
     *     SELECT id      -- the key
     *     FROM users
     *
     * into one line fed the server `SELECT id -- the key FROM users`, and the
     * comment swallowed the rest of the submission. What ran was not what was
     * typed, and it ran without complaint whenever the truncated statement was
     * still valid SQL — `SELECT id` here, which returns a column from nothing at
     * all rather than an error anyone would notice.
     *
     * Only trailing whitespace is stripped, per line: leading indentation is the
     * user's formatting and survives into history unchanged. {@see Splitter} and
     * {@see Normalizer} read newlines as ordinary whitespace and needed no
     * teaching; {@see dangerCheck()} did, but for the comments rather than the
     * newlines — see there.
     */
    private function cliText(): string
    {
        return trim(implode("\n", array_map('rtrim', $this->input->lines())));
    }

    /**
     * Move the caret up one display line within the CLI buffer. Returns false when
     * already on the top line, so the caller can fall through to history nav (§4).
     * With $select the selection is extended; otherwise it is dropped. The caret
     * keeps its column (the unified multi-line behavior shared with the modal
     * fields), rather than snapping to the line end as the old InputBuffer did.
     */
    private function cliMoveUp(bool $select): bool
    {
        if ($this->input->cursorRowCol()[0] === 0) {
            return false;
        }
        $select ? $this->input->startSelection() : $this->input->clearSelection();
        $this->input->upLine();
        return true;
    }

    /** Move the caret down one display line; false when already on the bottom line. */
    private function cliMoveDown(bool $select): bool
    {
        if ($this->input->cursorRowCol()[0] >= count($this->input->lines()) - 1) {
            return false;
        }
        $select ? $this->input->startSelection() : $this->input->clearSelection();
        $this->input->downLine();
        return true;
    }

    /**
     * Continue input on a fresh line below (Enter with no terminator, §4). Keeps
     * the whole active line intact and opens an empty line beneath it with the
     * caret on it — the old InputBuffer::pushLine() behavior, expressed on the flat
     * editor as "go to line end, then insert a hard break".
     */
    private function cliPushLine(): void
    {
        $this->input->clearSelection();
        $this->input->lineEnd();
        $this->input->insertNewline();
    }

    /** Copy the CLI selection to the clipboard, if any (Ctrl+C in CLI focus, §4/§19). */
    private function copyCliSelection(): void
    {
        $sel = $this->input->selectedText();
        if ($sel === '') {
            return;
        }
        $this->copyToClipboard($sel, 'selection (' . mb_strlen($sel) . ' chars)');
    }

    /**
     * Shared base-editing key handling for every {@see LineEditor}-backed field —
     * the CLI-grade behavior (caret moves incl. word jumps, Shift-selection,
     * Home/End, Backspace/Delete incl. word, Ctrl+A, printable insert with
     * replace-selection) made identical everywhere it's used: the Assign-DD path,
     * cell edit, DD comment, JSON node (§4). Clipboard (Ctrl+C/X, {@see
     * editorClipboardKey()}) and paste (a PasteEvent) are handled separately.
     *
     * Returns true when the key was consumed. A field calls this last, after
     * peeling off its own specials (Enter, Esc, Tab, Ctrl+S, `?`), so anything it
     * doesn't own gets the standard treatment; keys left unhandled here (e.g.
     * Up/Down in a single-line field) return false for the caller to use.
     */
    private function applyEditingKey(LineEditor $ed, KeyEvent $e, bool $multiline): bool
    {
        $sel = static function (LineEditor $ed, bool $shift): void {
            $shift ? $ed->startSelection() : $ed->clearSelection();
        };
        switch ($e->key) {
            case Key::Char:
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 'a') {
                    $ed->selectAll();
                    return true;
                }
                if ($e->ctrl || $e->alt || $e->char === '') {
                    return false; // control combos aren't plain text
                }
                $ed->insert($e->char); // insert() replaces any selection
                return true;
            case Key::Backspace:
                $e->ctrl ? $ed->deleteWordLeft() : $ed->backspace();
                return true;
            case Key::Delete:
                $e->ctrl ? $ed->deleteWordRight() : $ed->delete();
                return true;
            case Key::Left:
                $sel($ed, $e->shift);
                $e->ctrl ? $ed->wordLeft() : $ed->left();
                return true;
            case Key::Right:
                $sel($ed, $e->shift);
                $e->ctrl ? $ed->wordRight() : $ed->right();
                return true;
            case Key::Home:
                // Home → line start; Ctrl+Home (or any single-line field) → very start.
                $sel($ed, $e->shift);
                ($e->ctrl || !$multiline) ? $ed->home() : $ed->lineHome();
                return true;
            case Key::End:
                // End → line end; Ctrl+End (or any single-line field) → very end.
                $sel($ed, $e->shift);
                ($e->ctrl || !$multiline) ? $ed->end() : $ed->lineEnd();
                return true;
            case Key::Up:
                if (!$multiline) {
                    return false; // single-line fields keep Up for history/list nav
                }
                $sel($ed, $e->shift);
                $ed->upLine();
                return true;
            case Key::Down:
                if (!$multiline) {
                    return false;
                }
                $sel($ed, $e->shift);
                $ed->downLine();
                return true;
            default:
                return false;
        }
    }

    /**
     * Ctrl+C / Ctrl+X for a {@see LineEditor}-backed field: copy (or cut) the
     * selection to the system clipboard (§19). Returns true when the key was a
     * clipboard command (even with no selection, so it isn't mistaken for text).
     */
    private function editorClipboardKey(KeyEvent $e, LineEditor $ed): bool
    {
        if ($e->key !== Key::Char || !$e->ctrl || $e->alt) {
            return false;
        }
        $c = strtolower($e->char);
        if ($c !== 'c' && $c !== 'x') {
            return false;
        }
        $sel = $ed->selectedText();
        if ($sel !== '') {
            $this->copyToClipboard($sel, 'selection (' . mb_strlen($sel) . ' chars)');
            if ($c === 'x') {
                $ed->deleteSelection();
            }
        }
        return true;
    }

    /**
     * Insert bracketed-paste text at the CLI caret, replacing any selection (§4).
     * Ignored unless the CLI is the plain input target (no modal/DATA focus owns
     * input), so a paste can't leak into a popup or the grid.
     */
    private function handlePaste(PasteEvent $e): void
    {
        // Nothing owns keyboard input while a statement is running (§21), so
        // there is no field for a paste to route to.
        if ($this->state->queryRunning) {
            return;
        }

        // Paste routes to whichever field owns keyboard input, so it works
        // everywhere you can type — the CLI and every modal edit field, including
        // the Assign-DD path picker (§4/§19). Multi-line fields (cell edit, DD
        // comment) keep pasted newlines; single-line fields (the DD path, a JSON
        // key/value) drop them so a stray newline can't corrupt the value.
        $s = $this->state;
        $modalEd = $this->activeModalEditor();
        if ($modalEd !== null) {
            // Multi-line fields (cell edit, DD comment) keep pasted newlines;
            // single-line fields (DD path, JSON node) drop them.
            $multiline = $modalEd === $s->cellEditor || $modalEd === $s->ddCommentEditor;
            $modalEd->insertText($e->text, allowNewlines: $multiline);
            return;
        }

        if ($s->focus !== State::FOCUS_CLI || $this->cliInputBlocked()) {
            return;
        }
        $this->input->insertText($e->text);
        $this->resetHistoryNav();
    }

    /** True when a modal/overlay currently owns keyboard input, so the CLI is not editable. */
    private function cliInputBlocked(): bool
    {
        $s = $this->state;
        return $s->help || $s->ddHint || $s->confirm || $s->pkPopup || $s->cellEdit
            || $s->ddPicker || $s->ddViewer || $s->layoutPopup
            || $s->systemMenu || $s->jsonView || $s->detail || $s->moveMode
            || $s->widthAdjust || $s->completion || $s->themeEditor || $s->themeSelect
            || $s->macrosView || $s->chooser !== null || $s->commentEdit || $s->promptAsk;
    }

    /** Whether screen row $y falls within the drawn CLI input region (§4). */
    private function pointInCli(int $y): bool
    {
        return $this->state->cliTop >= 0
            && $y >= $this->state->cliTop
            && $y <= $this->state->cliBottom;
    }

    /**
     * Map a screen (x, y) inside the CLI region to a buffer [lineIndex, column]
     * (§4). Only the active line is horizontally scrolled, so other lines map at
     * scroll 0. The result is a display [line, column]; the caller maps it to a
     * flat grapheme offset via {@see LineEditor::offsetAt()}.
     *
     * @return array{0:int,1:int}
     */
    private function cliPosAt(int $x, int $y): array
    {
        $li = $this->state->cliLineStart + ($y - $this->state->cliTop);
        $scroll = $li === $this->input->cursorRowCol()[0] ? $this->state->cliActiveScroll : 0;
        $col = $scroll + max(0, $x - $this->state->cliTextX);
        return [$li, $col];
    }

    private function inHistory(): bool
    {
        return $this->historyIdx < count($this->state->history);
    }

    private function handleDataKey(KeyEvent $e): void
    {
        // An empty grid has no keys of its own, but Esc still gets you out of it.
        if (!$this->state->hasResult()) {
            if ($e->key === Key::Escape) {
                $this->state->focus = State::FOCUS_CLI;
            }
            return;
        }
        // While moving the inspector, the arrows reposition it instead of the
        // grid — and Esc ends the move, so it has to come first.
        if ($this->state->cellView && $this->state->cellMoveMode) {
            $this->handleCellMoveKey($e);
            return;
        }

        // Esc in the grid returns to the CLI (§7).
        //
        // Safe to answer here because everything nested has already had its Esc:
        // the detail view, the JSON viewer, both popups, move and width modes,
        // the DD viewer, the theme editor and every modal all return earlier in
        // handleKey(), and the inspector move mode returns just above. The one
        // overlay still standing at this point is the non-modal cell inspector,
        // which keeps Esc for itself — closing it is the innermost thing left to
        // undo, and only once it is gone does Esc mean "leave the grid".
        //
        // Deliberately above the vertical branch as well: vertical is an output
        // mode of this view, not a window inside it, so Esc leaves from there
        // too. handleVerticalKey() ignores Esc, so nothing is taken away.
        if ($e->key === Key::Escape) {
            if ($this->state->cellView) {
                $this->state->cellView = false;
                $this->state->cellMoveMode = false;
                return;
            }
            // A Shift+arrow range (§19) is dropped before Esc means anything
            // bigger, so leaving the grid never strands one to reappear later.
            if ($this->state->hasRange()) {
                $this->state->clearRange();
                return;
            }
            // Mark mode is a mode of the grid, not a window over it, but it is
            // still the innermost thing left to undo — so Esc leaves it (and
            // drops the marks) before Esc means "leave the grid" (§22).
            if ($this->state->markMode) {
                $this->exitMarkMode();
                return;
            }
            $this->state->focus = State::FOCUS_CLI;
            return;
        }

        // Vertical output scrolls by line; there is no cell selection (§5).
        if ($this->state->vertical) {
            $this->handleVerticalKey($e);
            return;
        }
        $maxRow = $this->state->maxRow();
        $maxCol = $this->state->totalCols() - 1;

        // Shift + a move key grows or shrinks a cell range from the cell the
        // cursor started on (§19); the move itself is the ordinary one below.
        // Any other key drops the range — except Ctrl+C, which copies it.
        //
        // Once a range exists, the jump keys (Home/End, PgUp/PgDn, Ctrl+Home/
        // Ctrl+End, Ctrl+arrows) stretch it with or without Shift. Windows
        // Terminal keeps Ctrl+Shift+Home/End/PgUp/PgDn/Up/Down for its own
        // scrollback, so those chords never arrive; the unshifted keys are the
        // way through. Plain arrows still drop the range — Shift+arrow grows it.
        $isArrow = in_array($e->key, [Key::Up, Key::Down, Key::Left, Key::Right], true);
        $isJump = in_array($e->key, [Key::Home, Key::End, Key::PageUp, Key::PageDown], true)
            || ($isArrow && $e->ctrl);
        $isRangeMove = !$e->alt && (
            ($e->shift && ($isJump || $isArrow))
            || ($isJump && $this->state->hasRange())
        );
        if ($e->shift || $this->state->hasRange()) {
            // Range keys trace (§19): what the terminal actually delivers.
            my::lineout('FILE:FILE', 'range key', $e->key->name,
                ($e->ctrl ? 'C' : '') . ($e->alt ? 'A' : '') . ($e->shift ? 'S' : ''),
                $isRangeMove ? 'stretch' : 'other');
        }
        $isCopy = $e->key === Key::Char && $e->ctrl && !$e->alt && strtolower($e->char) === 'c';
        if ($isRangeMove) {
            if (!$this->state->hasRange()) {
                $this->state->rangeRow = $this->state->selectedRow;
                $this->state->rangeCol = $this->state->selectedCol;
            }
        } elseif (!$isCopy) {
            $this->state->clearRange();
        }

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
            // Escape is answered above the switch: it closes the cell inspector
            // if one is open and otherwise returns to the CLI.
            case Key::Enter:
                // Header row → column popup; data row → JSON editor if the value is
                // a JSON document, else the inline cell editor (§9/§10). In mark
                // mode Enter ticks the row's checkbox instead — the mode exists to
                // build a set of rows, and editing a cell is not part of that (§22).
                if ($this->state->selectedRow === -1) {
                    $this->openLayoutPopup($this->state->selectedCol);
                } elseif ($this->state->markMode) {
                    $this->toggleRowMark();
                } else {
                    $this->openCellOrJson();
                }
                break;
            case Key::Delete:
                // Del deletes the selected row; Ctrl+Del opens (or closes) the
                // checkbox column for deleting several at once (§22). Inside mark
                // mode Del is what commits the marked set.
                if ($e->ctrl) {
                    $this->toggleMarkMode();
                } else {
                    $this->requestDelete();
                }
                break;
            case Key::Char:
                // Cell edits go straight to the CLI as an UPDATE now (§9), so there
                // is no separate "generate UPDATE" key.
                if ($e->ctrl && $e->alt && strtolower($e->char) === 'c') {
                    // Ctrl+Alt+C → copy the whole selected row (tab-separated) (§19).
                    $this->copyRow();
                } elseif ($e->ctrl && $e->alt && strtolower($e->char) === 'o') {
                    // Ctrl+Alt+O → export the arranged grid to a CSV in the cwd (§19).
                    $this->exportCsv();
                } elseif ($e->ctrl && $e->alt && strtolower($e->char) === 's') {
                    // Ctrl+Alt+S → unmoved columns A–Z / back to result order (§6).
                    $this->toggleColAlpha();
                } elseif ($e->ctrl && $e->alt && strtolower($e->char) === 'f') {
                    // Ctrl+Alt+F → filter the columns by name (§6).
                    $this->openColFilter();
                } elseif ($e->ctrl && !$e->alt && strtolower($e->char) === 'c') {
                    // Ctrl+C → copy the Shift+arrow range as CSV, or else the
                    // selected cell value, to the clipboard (§19).
                    $this->copyRangeOrCell();
                } elseif ($e->ctrl && !$e->alt && strtolower($e->char) === 'n') {
                    // Ctrl+N → set the selected cell to SQL NULL (§9).
                    $this->setCellNull();
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
                } elseif (!$e->ctrl && !$e->alt && $e->char === 't') {
                    // t → the selected cell as a table, split by its column's
                    // saved separators (§10a).
                    $this->openSplitFromData();
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
                } elseif (!$e->ctrl && !$e->alt && $e->char === ' ' && $this->state->markMode) {
                    // Space → tick the row's checkbox, alongside Enter (§22). Space
                    // is the conventional key for a checkbox and costs nothing here:
                    // the grid has no text entry, so it was doing nothing.
                    $this->toggleRowMark();
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

    // --- field filter (Ctrl+Alt+F, §6) ---------------------------------------

    /** Open the field filter on the grid, empty, with the cursor where it is. */
    private function openColFilter(): void
    {
        if (!$this->state->hasResult() || $this->state->vertical) {
            return;
        }
        $this->state->colFilter = '';
        $this->state->colFilterMiss = '';
    }

    /**
     * Close the filter: every column comes back, and the cursor stays on the
     * field it was on, wherever that field sits in the full list.
     */
    private function closeColFilter(): void
    {
        $name = $this->selectedColumnName();
        $this->state->colFilter = null;
        $this->state->colFilterMiss = '';
        $this->selectColumnNamed($name);
    }

    /**
     * Change the filter text. A change that would leave no field at all is
     * refused and the status line says so: an empty grid has nothing to move
     * to, and the text that did match is the more useful one to keep.
     */
    private function setColFilter(string $text): void
    {
        if ($text !== '' && State::filterColumns($this->state->unfilteredColumnNames(), $text) === []) {
            $this->state->colFilterMiss = $text;
            return;
        }
        $name = $this->selectedColumnName();
        $this->state->colFilter = $text;
        $this->state->colFilterMiss = '';
        $this->state->colOffset = 0;
        $this->selectColumnNamed($name);
    }

    /** The name of the column under the cursor, or null with none. */
    private function selectedColumnName(): ?string
    {
        return $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
    }

    /** Put the cursor on column $name if it is shown, else on the first column. */
    private function selectColumnNamed(?string $name): void
    {
        $idx = $name === null ? false : array_search($name, $this->state->visibleColumnNames(), true);
        $this->state->selectedCol = $idx === false ? 0 : (int) $idx;
    }

    /**
     * Keys while the field filter is open (§6). Printable keys and Backspace
     * edit the text; the arrows, Home/End and the page keys move as they do in
     * the grid, but only among the matching fields. Esc and Ctrl+Alt+F close
     * it, leaving the cursor on the field it is on. Everything else — Enter
     * and moving columns among it — is swallowed.
     */
    private function handleColFilterKey(KeyEvent $e): void
    {
        $text = (string) $this->state->colFilter;
        // Enter is swallowed: the filter stays open on it (user's call).
        if ($e->key === Key::Escape
            || ($e->isChar() && $e->ctrl && $e->alt && strtolower($e->char) === 'f')) {
            $this->closeColFilter();
            return;
        }
        if ($e->key === Key::Backspace) {
            if ($text !== '') {
                $this->setColFilter(mb_substr($text, 0, -1));
            }
            return;
        }
        if ($e->isChar() && !$e->ctrl && !$e->alt) {
            $this->setColFilter($text . $e->char);
            return;
        }
        $nav = [Key::Up, Key::Down, Key::Left, Key::Right, Key::Home, Key::End, Key::PageUp, Key::PageDown];
        if (in_array($e->key, $nav, true)) {
            // The grid's own navigation, which already counts only what
            // visibleColumnNames() returns — the filtered list.
            $this->handleDataKey($e);
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
     * "Change width" mode (§6): Right/Left widen/narrow by 1, Up/Down by 5 (the horizontal
     * arrows fine-tune, the vertical ones move fast), Enter/Esc exit.
     */
    private function handleWidthAdjustKey(KeyEvent $e): void
    {
        $name = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        switch ($e->key) {
            case Key::Up:
            case Key::Right:
                // Right/Left fine-tune by 1; Up/Down move fast by 5.
                if ($name !== null) {
                    $this->adjustWidth($name, $e->key === Key::Right ? 1 : 5);
                }
                break;
            case Key::Down:
            case Key::Left:
                if ($name !== null) {
                    $this->adjustWidth($name, $e->key === Key::Left ? -1 : -5);
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

    /**
     * The modal edit field that currently owns keyboard input, or null when none
     * is open. Single source of truth for routing paste, clipboard, and mouse to
     * the active field (§4). Ordered by which overlay sits on top.
     */
    private function activeModalEditor(): ?LineEditor
    {
        $s = $this->state;
        if ($s->cellEdit && $s->cellEditor !== null) {
            return $s->cellEditor;
        }
        if ($s->ddCommentEdit && $s->ddCommentEditor !== null) {
            return $s->ddCommentEditor;
        }
        if ($s->ddPicker && $s->ddPickerStage === 0 && $s->ddPickerEditor !== null) {
            return $s->ddPickerEditor;
        }
        if ($s->jsonEditor !== null) {
            return $s->jsonEditor;
        }
        if ($s->splitDefEdit && $s->splitDefEditor !== null) {
            return $s->splitDefEditor;
        }
        if ($s->splitEdit && $s->splitEditor !== null) {
            return $s->splitEditor;
        }
        return null;
    }

    /**
     * Move/resize the framed cell editor with the mouse (§9): press its title bar to
     * drag the box, press the bottom-right grip to resize it, and a LEFT_DRAG
     * continues whichever was grabbed. Returns true when the event was chrome — the
     * caller then leaves the text alone.
     *
     * Two framed editors are movable: the roomy (Enter) cell-edit popup and the DD
     * viewer's comment box. The inline in-cell overlay has no chrome, and the
     * inspector's editor is positioned by the inspector itself.
     */
    private function handleEditorFrameMouse(MouseEvent $e): bool
    {
        $r = $this->state->editorFrameRect;
        // Both framed editors are movable/resizable: the grid's roomy (Enter) cell
        // editor and the DD viewer's comment editor. Each keeps its own geometry.
        $framed = ($this->state->cellEdit && $this->state->cellEditPopup)
            || $this->state->ddCommentEdit;
        if (!$framed || $r === null) {
            return false;
        }
        [$rx, $ry, $rw, $rh] = $r;

        if ($e->kind === MouseEvent::LEFT_DRAG) {
            if ($this->editorDrag !== null) {
                [$dx, $dy] = $this->editorDrag;
                $this->setEditorFramePos(max(0, $e->x - $dx), max(1, $e->y - $dy));
                return true;
            }
            if ($this->editorResizing) {
                // Drag the corner to where the box's bottom-right should land; the
                // frame is 4 cols / 6 rows of chrome around the text body.
                $this->setEditorFrameSize(max(24, $e->x - $rx + 1 - 4), max(1, $e->y - $ry + 1 - 6));
                return true;
            }
            return false;
        }
        if ($e->kind !== MouseEvent::LEFT_DOWN) {
            return false;
        }

        $this->editorDrag = null;
        $this->editorResizing = false;
        // A press on the chrome is not a press on the text: drop any selection latch
        // left by an earlier press in the body, so releasing a chrome grab can never
        // leave a drag silently selecting text.
        $this->modalSelecting = false;

        // Grip first: it sits on the border, which the title-bar test would miss
        // anyway, but order it explicitly so the corner is never ambiguous.
        if ($e->x === $rx + $rw - 1 && $e->y === $ry + $rh - 1) {
            // Pin the current position before the size starts changing, or an
            // auto-centred box would drift as it grows.
            $this->pinEditorFrame($rx, $ry);
            $this->editorResizing = true;
            return true;
        }
        if ($e->y === $ry + 1 && $e->x >= $rx && $e->x < $rx + $rw) {
            $this->editorDrag = [$e->x - $rx, $e->y - $ry];
            $this->pinEditorFrame($rx, $ry);
            return true;
        }
        return false;
    }

    /** Freeze an auto-centred editor box at where it is currently drawn (§9). */
    private function pinEditorFrame(int $rx, int $ry): void
    {
        $s = $this->state;
        if ($s->ddCommentEdit) {
            if ($s->ddEditX < 0) {
                $s->ddEditX = $rx;
            }
            if ($s->ddEditY < 0) {
                $s->ddEditY = $ry;
            }
            return;
        }
        if ($s->cellEditX < 0) {
            $s->cellEditX = $rx;
        }
        if ($s->cellEditY < 0) {
            $s->cellEditY = $ry;
        }
    }

    /** Move the active framed editor, writing to its own geometry store (§9/§11). */
    private function setEditorFramePos(int $x, int $y): void
    {
        if ($this->state->ddCommentEdit) {
            $this->state->ddEditX = $x;
            $this->state->ddEditY = $y;
            return;
        }
        $this->state->cellEditX = $x;
        $this->state->cellEditY = $y;
    }

    /** Resize the active framed editor, writing to its own geometry store (§9/§11). */
    private function setEditorFrameSize(int $wInner, int $hRows): void
    {
        if ($this->state->ddCommentEdit) {
            $this->state->ddEditW = $wInner;
            $this->state->ddEditH = $hRows;
            return;
        }
        $this->state->cellEditW = $wInner;
        $this->state->cellEditH = $hRows;
    }

    /**
     * CLI-grade mouse for a modal edit field: LEFT_DOWN inside the field places the
     * caret and anchors a selection; a following LEFT_DRAG extends it. A press
     * outside the field is swallowed so the field stays modal (§4). Geometry comes
     * from {@see State::$editFieldRegion}, republished by the renderer each frame.
     */
    private function handleEditFieldMouse(MouseEvent $e, LineEditor $ed): void
    {
        $r = $this->state->editFieldRegion;
        if ($e->kind === MouseEvent::LEFT_DRAG) {
            if ($this->modalSelecting && $r !== null) {
                $ed->setCursorOffset($this->editFieldOffsetAt($ed, $r, $e->x, $e->y));
            }
            return;
        }
        if ($e->kind !== MouseEvent::LEFT_DOWN) {
            return;
        }
        $this->modalSelecting = false;
        if ($r === null || !$this->pointInEditField($r, $e->x, $e->y)) {
            return; // click outside the field: swallow, stay modal
        }
        $ed->clearSelection();
        $ed->setCursorOffset($this->editFieldOffsetAt($ed, $r, $e->x, $e->y));
        $ed->startSelection(); // anchor at the click so a drag extends from here
        $this->modalSelecting = true;
    }

    /** @param array{top:int,left:int,width:int,rows:int,vScroll:int,curRow:int,curHScroll:int} $r */
    private function pointInEditField(array $r, int $x, int $y): bool
    {
        return $y >= $r['top'] && $y < $r['top'] + $r['rows']
            && $x >= $r['left'] && $x < $r['left'] + $r['width'];
    }

    /**
     * Map a screen (x, y) inside a modal edit field to a grapheme offset in its
     * editor (§4). Non-cursor lines are drawn unscrolled, so only the cursor's own
     * line carries a horizontal scroll.
     *
     * @param array{top:int,left:int,width:int,rows:int,vScroll:int,curRow:int,curHScroll:int} $r
     */
    private function editFieldOffsetAt(LineEditor $ed, array $r, int $x, int $y): int
    {
        $row = max(0, min($r['rows'] - 1, $y - $r['top']));
        $li = $r['vScroll'] + $row;
        // The framed editor scrolls every line together ('hUniform'); the inline
        // in-cell overlay scrolls only the cursor's own line.
        $lineHScroll = ($r['hUniform'] ?? false)
            ? $r['curHScroll']
            : ($li === $r['curRow'] ? $r['curHScroll'] : 0);
        $col = max(0, $x - $r['left']) + $lineHScroll;
        return $ed->offsetAt($li, $col);
    }

    private function handleMouse(MouseEvent $e): void
    {
        // Swallowed while a statement is running, for the reason given in
        // handleKey(): the grid on screen is about to be replaced, so selecting
        // in it, sorting it or opening a popup over it all lead somewhere that
        // will not exist in a moment (§21).
        if ($this->state->queryRunning) {
            return;
        }

        // Any open modal edit field gets CLI-grade mouse: a click places the caret,
        // a drag extends the selection. Handled before the per-mode swallowing
        // below so it works in the Assign-DD path, cell edit, DD comment, and JSON
        // node fields (§4).
        $modalEd = $this->activeModalEditor();
        if ($modalEd !== null) {
            // The framed editor's own chrome (title bar = move, corner grip =
            // resize) gets first refusal; anything else is text interaction (§9).
            if ($this->handleEditorFrameMouse($e)) {
                return;
            }
            $this->handleEditFieldMouse($e, $modalEd);
            return;
        }

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

        // The table view (§10a) is keyboard-driven like the JSON viewer: the
        // wheel moves the row, and nothing else reaches the grid beneath it.
        if ($this->state->splitView || $this->state->splitDefEdit) {
            if ($this->state->splitView && !$this->state->splitDefEdit
                && ($e->kind === MouseEvent::SCROLL_UP || $e->kind === MouseEvent::SCROLL_DOWN)) {
                $last = max(0, count($this->state->splitCells) - 1);
                $d = $e->kind === MouseEvent::SCROLL_UP ? -1 : 1;
                $this->state->splitSel = max(0, min($last, $this->state->splitSel + $d));
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

        // A left-drag extends a CLI selection if a press started there; else it
        // moves the inspector if one was grabbed, else steers whichever scrollbar
        // the press latched onto (§4/§6/§9).
        if ($e->kind === MouseEvent::LEFT_DRAG) {
            if ($this->cliSelecting) {
                [$li, $col] = $this->cliPosAt($e->x, $e->y);
                // setCursorOffset leaves the anchor alone → the selection grows.
                $this->input->setCursorOffset($this->input->offsetAt($li, $col));
            } elseif ($this->popupDrag !== null) {
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
        $this->cliSelecting = false;

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

        // The field filter (§6): a header click only selects that field — the
        // header's popup is where Move lives, and nothing moves while filtered.
        // Cells and scrollbars work as normal. Any other click closes the filter
        // first, as Esc would, and then does what it always does.
        if ($this->state->colFilter !== null) {
            $gl = $this->state->gridLayout;
            $s = $this->state;
            if ($gl !== null && $e->y === $gl->headerY) {
                $hit = $gl->columnAt($e->x);
                if ($hit !== null) {
                    $s->selectedCol = $hit;
                }
                return;
            }
            $inGrid = $gl !== null && $e->y >= $gl->dataTop && $e->y < $gl->dataTop + $gl->dataRows;
            $onBar = ($s->vScrollLen > 0 && $e->x === $s->vScrollX)
                || ($s->hScrollLen > 0 && $e->y === $s->hScrollY);
            if (!$inGrid && !$onBar) {
                $this->closeColFilter();
            }
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

        // A press in the CLI input region sets the caret there (and focuses the
        // CLI), starting a text selection that a subsequent drag extends (§4).
        if ($this->pointInCli($e->y)) {
            $this->state->focus = State::FOCUS_CLI;
            [$li, $col] = $this->cliPosAt($e->x, $e->y);
            $this->input->clearSelection();
            $this->input->setCursorOffset($this->input->offsetAt($li, $col));
            $this->input->startSelection();
            $this->cliSelecting = true;
            return;
        }

        // A click on a query error copies the whole thing (§19). The error is the
        // one thing on screen a user needs verbatim somewhere else — a ticket, a
        // search, a colleague — and it is also the one thing they cannot read in
        // full, since both its lines are truncated to the result box.
        if ($this->state->errorRect !== null) {
            [$ex, $ey, $ew, $eh] = $this->state->errorRect;
            if ($e->x >= $ex && $e->x < $ex + $ew && $e->y >= $ey && $e->y < $ey + $eh) {
                $this->copyErrorToClipboard();
                return;
            }
        }

        // Click anywhere on the vertical "Layout Config" label (right margin)
        // opens the Layout popup (§6).
        if ($this->state->layoutIndicatorW > 0
            && $e->x >= $this->state->layoutIndicatorX
            && $e->x < $this->state->layoutIndicatorX + $this->state->layoutIndicatorW
            && $e->y >= $this->state->layoutIndicatorY
            && $e->y < $this->state->layoutIndicatorY + $this->state->layoutIndicatorH) {
            $this->openLayoutPopup($this->state->selectedCol);
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

        // Header click → open Data View Configuration on that column (§6).
        if ($e->y === $gl->headerY) {
            if ($hitCol !== null) {
                $this->state->clearRange();
                $this->state->selectedCol = $hitCol;
                $this->openLayoutPopup($hitCol);
            }
            return;
        }

        // Data click → select the row (and column when one is hit). A click in
        // the mark-mode checkbox gutter selects the row *and* ticks it, which is
        // the whole point of the column being clickable (§22).
        if ($e->y >= $gl->dataTop && $e->y < $gl->dataTop + $gl->dataRows) {
            $row = $gl->rowOffset + ($e->y - $gl->dataTop);
            if ($row <= $this->state->maxRow()) {
                $this->state->clearRange();
                $this->state->selectedRow = $row;
                if ($gl->isMarkColumn($e->x)) {
                    $this->toggleRowMark();
                    return;
                }
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
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    // `S` toggles A–Z field order; the selection stays on the
                    // same field rather than on the same line number.
                    $name = $this->state->detailColumns()[$this->state->detailFieldIdx] ?? null;
                    $this->state->detailSorted = !$this->state->detailSorted;
                    $idx = $name === null ? false : array_search($name, $this->state->detailColumns(), true);
                    $this->state->detailFieldIdx = is_int($idx) ? $idx : 0;
                } elseif (!$e->ctrl && !$e->alt && strtolower($e->char) === 'j') {
                    $this->openJsonFromDetail();
                }
                break;
            default:
                break;
        }
    }

    // --- column & layout popups (§6) ---------------------------------------

    /**
     * Open Data View Configuration (§6), scoped to one column.
     *
     * There is one grid-configuration menu, not two: the per-column actions and
     * the table-wide resets were split across F2 and Ctrl+L, which meant knowing
     * in advance which of the two owned "sort" or "hide" before you could look
     * for it. $visibleColIdx is the column the per-column half acts on — the
     * selected one for a keypress, the clicked one for a header click.
     */
    private function openLayoutPopup(int $visibleColIdx): void
    {
        if (!$this->state->hasResult() || $this->state->totalCols() === 0) {
            return;
        }
        $this->state->popupCol = max(0, min($visibleColIdx, $this->state->totalCols() - 1));
        $this->state->layoutPopup = true;
        $this->state->layoutItem = 0;
    }

    // --- theme selection + editor (§13) ------------------------------------

    /**
     * The standalone "Select Theme" picker: switching themes without editing
     * one, which is the common case.
     *
     * Arrowing through the list applies each scheme to the live UI immediately,
     * so you choose by looking at the actual app rather than at a name. Enter
     * keeps the highlighted one and persists it; Esc puts back the theme you
     * arrived with.
     */
    private function openThemeSelect(): void
    {
        $s = $this->state;
        $s->themePickerItems = $this->themeNames();
        $idx = array_search($this->theme->name, $s->themePickerItems, true);
        $s->themePickerSel = is_int($idx) ? $idx : 0;
        $s->themePickerOffset = 0;
        $s->themeSelect = true;
        $this->themeBackup = $this->theme;
        $this->previewSelectedTheme();
    }

    /** Apply the highlighted scheme to the live UI (no persistence). */
    private function previewSelectedTheme(): void
    {
        $s = $this->state;
        $name = $s->themePickerItems[$s->themePickerSel] ?? '';
        if ($name === '') {
            return;
        }
        $reason = null;
        $scheme = $this->loadScheme($name, $reason);
        if ($scheme === null) {
            // A broken file shouldn't blank the screen mid-browse: keep whatever
            // is on and say why in the status line.
            $this->state->statusLine = 'Theme ' . $name . ' could not be loaded: ' . (string) $reason;
            $this->state->statusIsError = true;
            return;
        }
        $this->applyTheme(new Theme($name, $scheme));
    }

    private function handleThemeSelectKey(KeyEvent $e): void
    {
        $s = $this->state;
        $count = count($s->themePickerItems);
        switch ($e->key) {
            case Key::Escape:
                if ($this->themeBackup !== null) {
                    $this->applyTheme($this->themeBackup);
                }
                $this->themeBackup = null;
                $s->themeSelect = false;
                $s->themePickerItems = [];
                return;
            case Key::Up:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel - 1, $count);
                break;
            case Key::Down:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel + 1, $count);
                break;
            case Key::PageUp:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel - self::DD_PAGE, $count);
                break;
            case Key::PageDown:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel + self::DD_PAGE, $count);
                break;
            case Key::Home:
                $s->themePickerSel = 0;
                break;
            case Key::End:
                $s->themePickerSel = $this->clampIndex($count - 1, $count);
                break;
            case Key::Enter:
                $name = $s->themePickerItems[$s->themePickerSel] ?? '';
                $this->themeBackup = null;
                $s->themeSelect = false;
                $s->themePickerItems = [];
                if ($name !== '') {
                    $this->settings->setTheme($name);
                    $this->state->statusLine = 'Theme: ' . $name;
                    $this->state->statusIsError = false;
                }
                return;
            default:
                return;
        }
        $this->previewSelectedTheme();
    }

    /**
     * Open the live theme editor on the active palette.
     *
     * The editor is a modal panel, not a full-screen mode, precisely so the app
     * behind it keeps rendering: every edit rebuilds the {@see Theme} and hands
     * it to the Renderer, so the header, grid, borders and status line recolour
     * as you nudge a channel. Cheap enough to do per keystroke — a Theme is ~57
     * Style value objects derived from 16 hex strings.
     */
    private function openThemeEditor(): void
    {
        $s = $this->state;
        $this->themeBackup = $this->theme;
        $s->themeEditor = true;
        $s->themeName = $this->theme->name;
        $s->themeColors = $this->theme->scheme()->colors();
        $s->themeSel = 0;
        $s->themeChannel = 0;
        $s->themeDirty = false;
        $s->themeEscArmed = false;
        $s->themeHexEdit = false;
        $s->themeHexEditor = null;
        $s->themeSaveAs = false;
        $s->themeSaveEditor = null;
        $s->themeOverwriteArmed = false;
        $s->themePicker = false;
        $this->themeNote('Editing ' . $s->themeName . ' — the screen behind updates as you type.');
    }

    /** Close the editor, restoring the pre-edit theme when $revert (Esc). */
    private function closeThemeEditor(bool $revert): void
    {
        $s = $this->state;
        if ($revert && $this->themeBackup !== null) {
            $this->applyTheme($this->themeBackup);
        }
        $this->themeBackup = null;
        $s->themeEditor = false;
        $s->themeHexEdit = false;
        $s->themeHexEditor = null;
        $s->themeSaveAs = false;
        $s->themeSaveEditor = null;
        $s->themePicker = false;
        $s->themePickerItems = [];
        $s->themeMessage = '';
        $s->themeMessageError = false;
    }

    private function applyTheme(Theme $theme): void
    {
        $this->theme = $theme;
        $this->renderer->setTheme($theme);
    }

    /** Rebuild the live Theme from the palette under edit and apply it. */
    private function rebuildEditedTheme(): void
    {
        $name = $this->state->themeName !== '' ? $this->state->themeName : 'custom';
        $this->applyTheme(new Theme($name, new Scheme($name, $this->state->themeColors)));
    }

    private function themeNote(string $message, bool $isError = false): void
    {
        $this->state->themeMessage = $message;
        $this->state->themeMessageError = $isError;
    }

    private function handleThemeEditorKey(KeyEvent $e): void
    {
        $s = $this->state;
        // Sub-modals own input while open (innermost first).
        if ($s->themeHexEdit) {
            $this->handleThemeHexKey($e);
            return;
        }
        if ($s->themeSaveAs) {
            $this->handleThemeSaveKey($e);
            return;
        }
        if ($s->themePicker) {
            $this->handleThemePickerKey($e);
            return;
        }

        // Anything but a second Esc cancels a pending discard prompt.
        if ($e->key !== Key::Escape) {
            $s->themeEscArmed = false;
        }

        switch ($e->key) {
            case Key::Escape:
                // Closing throws away unsaved edits, so make it take two presses.
                if ($s->themeDirty && !$s->themeEscArmed) {
                    $s->themeEscArmed = true;
                    $this->themeNote('Unsaved changes — Esc again to discard, or s to save.', true);
                    return;
                }
                $this->closeThemeEditor(revert: true);
                return;
            case Key::Up:
                $s->themeSel = ($s->themeSel + 15) % 16;
                return;
            case Key::Down:
                $s->themeSel = ($s->themeSel + 1) % 16;
                return;
            // The 16 slots are drawn as two columns of eight, so ±8 is "other column".
            case Key::PageUp:
            case Key::PageDown:
                $s->themeSel = ($s->themeSel + 8) % 16;
                return;
            case Key::Left:
                $this->nudgeThemeChannel($e->shift ? -16 : -1);
                return;
            case Key::Right:
                $this->nudgeThemeChannel($e->shift ? 16 : 1);
                return;
            case Key::Tab:
                $s->themeChannel = ($s->themeChannel + 1) % 3;
                return;
            case Key::Home:
                $s->themeSel = 0;
                return;
            case Key::End:
                $s->themeSel = 15;
                return;
            case Key::Enter:
                $this->openThemeHexEdit();
                return;
            case Key::Char:
                break;
            default:
                return;
        }

        if ($e->ctrl || $e->alt) {
            return;
        }
        switch (strtolower($e->char)) {
            case 'r':
                $s->themeChannel = 0;
                break;
            case 'g':
                $s->themeChannel = 1;
                break;
            case 'b':
                $s->themeChannel = 2;
                break;
            case 'e':
                $this->openThemeHexEdit();
                break;
            case 'l':
                $this->openThemePicker();
                break;
            case 's':
                $this->openThemeSaveAs();
                break;
            default:
                break;
        }
    }

    /** Shift the selected slot's active R/G/B channel by $delta, clamped 0-255. */
    private function nudgeThemeChannel(int $delta): void
    {
        $s = $this->state;
        $slot = Scheme::SLOTS[$s->themeSel];
        $rgb = Style::parseHex($s->themeColors[$slot] ?? '') ?? [0, 0, 0];
        $rgb[$s->themeChannel] = max(0, min(255, $rgb[$s->themeChannel] + $delta));
        $this->setThemeSlot($slot, self::toHex($rgb));
    }

    private function setThemeSlot(string $slot, string $hex): void
    {
        $this->state->themeColors[$slot] = $hex;
        $this->state->themeDirty = true;
        $this->rebuildEditedTheme();
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    private function openThemeHexEdit(): void
    {
        $slot = Scheme::SLOTS[$this->state->themeSel];
        $editor = new LineEditor();
        $editor->setText($this->state->themeColors[$slot] ?? '');
        $editor->end();
        $this->state->themeHexEditor = $editor;
        $this->state->themeHexEdit = true;
    }

    private function handleThemeHexKey(KeyEvent $e): void
    {
        $editor = $this->state->themeHexEditor;
        if ($editor === null) {
            $this->state->themeHexEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->state->themeHexEdit = false;
                $this->state->themeHexEditor = null;
                return;
            case Key::Enter:
                $text = trim($editor->text());
                $rgb = Style::parseHex($text);
                if ($rgb === null) {
                    $this->themeNote('Not a hex colour: ' . $text, true);
                    return;
                }
                $this->setThemeSlot(Scheme::SLOTS[$this->state->themeSel], self::toHex($rgb));
                $this->state->themeHexEdit = false;
                $this->state->themeHexEditor = null;
                $this->themeNote('');
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
    }

    private function openThemePicker(): void
    {
        $s = $this->state;
        $s->themePickerItems = $this->themeNames();
        $s->themePickerSel = 0;
        $s->themePickerOffset = 0;
        $s->themePicker = true;
    }

    private function handleThemePickerKey(KeyEvent $e): void
    {
        $s = $this->state;
        $items = $s->themePickerItems;
        $count = count($items);
        switch ($e->key) {
            case Key::Escape:
                $s->themePicker = false;
                return;
            case Key::Up:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel - 1, $count);
                return;
            case Key::Down:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel + 1, $count);
                return;
            case Key::PageUp:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel - self::DD_PAGE, $count);
                return;
            case Key::PageDown:
                $s->themePickerSel = $this->clampIndex($s->themePickerSel + self::DD_PAGE, $count);
                return;
            case Key::Home:
                $s->themePickerSel = 0;
                return;
            case Key::End:
                $s->themePickerSel = $this->clampIndex($count - 1, $count);
                return;
            case Key::Enter:
                $this->loadThemeIntoEditor($items[$s->themePickerSel] ?? '');
                return;
            default:
                return;
        }
    }

    /**
     * Replace the palette under edit with a scheme from the search path.
     *
     * Loading is *adopted*, not previewed: the loaded scheme becomes the new
     * revert point and is persisted as the active theme. Picking a scheme by
     * name is an explicit act (you confirmed it with Enter in the picker), so a
     * later Esc should undo the tweaks you made on top of it — not silently put
     * back the theme you started the session with.
     *
     * `<default>` loads like any other scheme — it is a perfectly good starting
     * point to tweak — but has no file behind it, so saving needs a new name
     * (see {@see saveEditedTheme()}).
     */
    private function loadThemeIntoEditor(string $name): void
    {
        $s = $this->state;
        $s->themePicker = false;
        if ($name === '') {
            return;
        }
        $reason = null;
        $scheme = $this->loadScheme($name, $reason);
        if ($scheme === null) {
            $this->themeNote('Could not load ' . $name . ': ' . (string) $reason, true);
            return;
        }
        $s->themeColors = $scheme->colors();
        $s->themeName = $name;
        $s->themeDirty = false;
        $s->themeEscArmed = false;
        $this->rebuildEditedTheme();
        // Adopt it: new revert point, and it survives closing the editor.
        $this->themeBackup = $this->theme;
        $this->settings->setTheme($name);
        $this->themeNote($name === Scheme::BUILT_IN
            ? 'Loaded ' . $name . ' — built-in, so `s` must save under a new name.'
            : 'Loaded ' . $name . ' — now the active theme.');
    }

    private function openThemeSaveAs(): void
    {
        $editor = new LineEditor();
        // The built-in can't be saved over, so don't prefill a name that Enter
        // would only reject — offer 'custom' to type over instead.
        $name = $this->state->themeName;
        $editor->setText($name !== '' && $name !== Scheme::BUILT_IN ? $name : 'custom');
        $editor->end();
        $this->state->themeSaveEditor = $editor;
        $this->state->themeSaveAs = true;
        $this->state->themeOverwriteArmed = false;
        $this->themeNote('');
    }

    private function handleThemeSaveKey(KeyEvent $e): void
    {
        $editor = $this->state->themeSaveEditor;
        if ($editor === null) {
            $this->state->themeSaveAs = false;
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->state->themeSaveAs = false;
                $this->state->themeSaveEditor = null;
                $this->state->themeOverwriteArmed = false;
                return;
            case Key::Enter:
                $this->saveEditedTheme(trim($editor->text()));
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        // Editing the name invalidates a pending overwrite confirmation — it was
        // armed for whatever target the *previous* text resolved to.
        $this->state->themeOverwriteArmed = false;
        $this->applyEditingKey($editor, $e, multiline: false);
    }

    /**
     * Write the palette out as a Base16 scheme file and adopt the name.
     *
     * Saving under the name currently being edited just overwrites that scheme
     * (the expected "keep tweaking one theme" flow). Any *other* existing name
     * takes a second Enter, so a mistyped name can't silently clobber a scheme.
     *
     * `<default>` is the one name that can never be a target: it lives in code,
     * not in a file. The name validator below would reject it anyway, but it
     * gets its own message so "why won't it save?" is answered on the spot.
     */
    private function saveEditedTheme(string $name): void
    {
        $s = $this->state;
        if ($name === Scheme::BUILT_IN) {
            $this->themeNote(Scheme::BUILT_IN . ' is built in and cannot be changed — save under another name.', true);
            return;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) {
            $this->themeNote('Name must be letters, digits, dot, dash or underscore.', true);
            return;
        }
        $path = $this->themeSaveTarget($name);
        if ($name !== $s->themeName && is_file($path) && !$s->themeOverwriteArmed) {
            $s->themeOverwriteArmed = true;
            $this->themeNote($path . ' exists — Enter again to overwrite.', true);
            return;
        }

        if (!$this->writeThemeFile($path, $name, $s->themeColors)) {
            $this->themeNote('Could not write ' . $path, true);
            return;
        }

        $s->themeName = $name;
        $s->themeDirty = false;
        $s->themeEscArmed = false;
        $s->themeSaveAs = false;
        $s->themeSaveEditor = null;
        $s->themeOverwriteArmed = false;
        // Rebuild so the live Theme carries the saved name, and persist the
        // choice so the next launch starts in it.
        $this->rebuildEditedTheme();
        // Adopt it as the revert point too, exactly as loading one does —
        // otherwise the Esc that closes the editor would put the pre-edit
        // colours back on screen and silently undo what was just saved.
        $this->themeBackup = $this->theme;
        $this->settings->setTheme($name);
        $this->themeNote('Saved ' . $path);
        my::lineout('FILE:FILE', 'theme saved', $name, $path);
    }

    /**
     * Where a save under $name should land. An existing writable file for that
     * name wins, so re-saving a theme edits it in place; otherwise the first
     * dir on the search path (closest project `.sqli/themes/`, else global).
     */
    private function themeSaveTarget(string $name): string
    {
        $existing = $this->themeLoader()->find($name);
        if ($existing !== null && is_writable($existing)) {
            return $existing;
        }
        $dirs = $this->config->themeDirs();
        $dir = $dirs[0]; // always non-empty: the global themes dir is last
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Write the 16 slots as a standard Base16 scheme file — the same shape any
     * upstream scheme has, so a theme saved here is portable and reloads through
     * the identical {@see SchemeLoader} path.
     *
     * @param array<string,string> $colors
     */
    private function writeThemeFile(string $path, string $name, array $colors): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        // base00 is the ground, so its lightness is what "variant" means here.
        $ground = Style::parseHex($colors['base00'] ?? '') ?? [0, 0, 0];
        $variant = (($ground[0] + $ground[1] + $ground[2]) / 3) > 127 ? 'light' : 'dark';

        $lines = [
            '# Base16 scheme written by the sqli theme editor.',
            'system: "base16"',
            'name: "' . $name . '"',
            'author: "sqli"',
            'variant: "' . $variant . '"',
            'palette:',
        ];
        foreach (Scheme::SLOTS as $slot) {
            $lines[] = '  ' . $slot . ': "' . ($colors[$slot] ?? '#000000') . '"';
        }
        return @file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

    // --- system menu (§12) -------------------------------------------------

    /**
     * Build the system-menu model fresh each time it's needed so labels and
     * enabled-state stay live (debug on/off, "Show DD" greyed until a DD loads,
     * "Layout Config" only with a result).
     *
     * @return list<array{label:string,action:string,enabled:bool}>
     */
    public function systemMenuItems(): array
    {
        return [
            ['label' => 'Assign Data Dictionary', 'action' => 'assign_dd', 'enabled' => true],
            ['label' => 'Show Data Dictionary',   'action' => 'show_dd',   'enabled' => $this->dictionaryAvailable()],
            ['label' => 'Select Theme',           'action' => 'theme_select', 'enabled' => true],
            ['label' => 'Edit Theme',             'action' => 'theme',     'enabled' => true],
            ['label' => 'Header Comment' . ($this->state->comment !== '' ? ': ' . $this->state->comment : ''),
                'action' => 'comment', 'enabled' => true],
            ['label' => 'Macros (' . $this->macros->count() . ')', 'action' => 'macros_view', 'enabled' => true],
            ['label' => 'Create Local sqli Config', 'action' => 'create_config', 'enabled' => true],
            ['label' => 'Debug Logging: ' . ($this->state->debugLogging ? 'on' : 'off'),
                'action' => 'debug_logging', 'enabled' => true],
            ['label' => 'Data View Configuration', 'action' => 'layout_config', 'enabled' => $this->state->hasResult()],
            ['label' => 'Write Log: ' . ($this->config->isFlag('log-writes') ? 'on' : 'off'),
                'action' => 'write_log', 'enabled' => true],
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
                $this->activateSystemMenuItem($items);
                break;
            case Key::Char:
                // A second `/` right after the one that opened the menu means the
                // user actually wanted a literal slash: close the menu and drop a
                // single `/` onto the CLI line they're editing (CLI focus only).
                if ($e->char === '/' && !$e->ctrl && !$e->alt) {
                    $this->state->systemMenu = false;
                    if ($this->state->focus === State::FOCUS_CLI) {
                        $this->input->insert('/');
                        $this->resetHistoryNav();
                    }
                    break;
                }
                // Every other key belongs to the menu. `/` is no longer a suffix
                // sigil (2.39.15: `]3`, `[3`, `\3`), so nothing typed after it
                // is handed back to the line; a digit picks the item numbered
                // with it.
                $idx = $this->quickKeyIndex($items, $e);
                if ($idx !== null) {
                    $this->state->systemMenuItem = $idx;
                    $this->activateSystemMenuItem($items);
                }
                break;
            default:
                break;
        }
    }

    /**
     * Run the selected System menu item, if it is enabled; a disabled one only
     * keeps the cursor.
     *
     * @param list<array{label:string,action:string,enabled:bool}> $items
     */
    private function activateSystemMenuItem(array $items): void
    {
        $sel = $items[$this->state->systemMenuItem] ?? null;
        if ($sel !== null && $sel['enabled']) {
            $this->state->systemMenu = false;
            $this->applySystemMenuAction($sel['action']);
        }
    }

    /**
     * The item a plain keypress names by its quick key ({@see State::menuQuickKeys()}),
     * or null. Letters match either case, since the label shows lower case.
     *
     * @param list<array{label:?string,...}> $items
     */
    private function quickKeyIndex(array $items, KeyEvent $e): ?int
    {
        if ($e->key !== Key::Char || $e->ctrl || $e->alt || $e->char === '') {
            return null;
        }
        $idx = array_search(strtolower($e->char), State::menuQuickKeys($items), true);
        return $idx === false ? null : $idx;
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
                    $this->openLayoutPopup($this->state->selectedCol);
                }
                break;
            case 'show_dd':
                $this->openDdViewer();
                break;
            case 'assign_dd':
                $this->openDdPicker();
                break;
            case 'theme_select':
                $this->openThemeSelect();
                break;
            case 'theme':
                $this->openThemeEditor();
                break;
            case 'comment':
                $this->openCommentEdit();
                break;
            case 'macros_view':
                $this->openMacrosView();
                break;
            case 'create_config':
                $this->openCreateConfigChooser();
                break;
            case 'write_log':
                $this->showWriteLogLocation();
                break;
            default:
                break;
        }
    }

    /**
     * System menu → Write Log: where the write log (§25) lives, in the plain
     * message box. The directory and file name are separate lines so a long
     * path wraps without splitting the name; the path is the one
     * {@see logWrite()} would use, whether or not anything has been written yet.
     */
    private function showWriteLogLocation(): void
    {
        $path = $this->writeLog?->path() ?? $this->config->writeLogPath();
        $on = $this->config->isFlag('log-writes');
        $size = is_file($path) ? @filesize($path) : false;
        $this->state->ddHintTitle = 'Write Log';
        $this->state->ddHintMessage = 'Logging: ' . ($on ? 'on' : 'off (--no-log-writes or "log-writes": false)')
            . "\nDirectory: " . dirname($path)
            . "\nFile: " . basename($path)
            . "\n" . ($size === false ? 'Nothing written yet.' : number_format($size) . ' bytes');
        $this->state->ddHintStripsMark = false;
        $this->state->ddHint = true;
    }

    // --- macros (§23) ----------------------------------------------------

    /**
     * Open the read-only macros viewer (system menu → Macros).
     *
     * Read-only is the whole design: the file is a text file the user edits in
     * their editor, and a TUI that could also write it would be two owners of
     * one document, racing. What the viewer is *for* is the other half of that
     * arrangement — seeing what a name currently expands to without leaving
     * sqli, and picking the edit up with `r` when it is made.
     */
    private function openMacrosView(): void
    {
        $this->state->macrosView = true;
        $this->state->macrosSel = 0;
        $this->state->macrosOffset = 0;
        $this->macrosPublish();
    }

    /** Copy what {@see Macros} holds into the State the renderer reads. */
    private function macrosPublish(): void
    {
        $rows = [];
        $scoped = [];
        foreach ($this->macros->all() as $name => $value) {
            $env = $this->macros->scopeOf($name);
            if ($env !== null) {
                $scoped[$env] = ($scoped[$env] ?? 0) + 1;
            }
            $rows[] = [
                'name'  => $name,
                'value' => $value,
                'args'  => $this->macros->arity($name),
                'env'   => $env,
            ];
        }
        $this->state->macrosRows = $rows;

        // The env line (§23). Written only when some layer actually uses
        // `[env:…]` sections — the row is worth a line of the viewer for the
        // names it says are *out of reach*, and worth nothing at all to a file
        // that scopes nothing. The unreachable ones are named rather than
        // counted: "?agency-id is not here because you are on dev" is the whole
        // question this line exists to answer.
        $hidden = $this->macros->hidden();
        if ($this->macros->envsSeen() === []) {
            $this->state->macrosScope = '';
        } else {
            $env = $this->macros->env();
            $parts = [$env === null ? 'no env selected — start sqli with -E NAME' : 'env ' . $env];
            // Counted per section rather than in one lump, because more than one
            // can load: an env that `extends` another (§16) inherits its macros,
            // and crediting a parent's definitions to the selected env would name
            // a section the reader could then not find. Chain order, least
            // specific first, so it reads the way the file was applied.
            if ($scoped !== [] && $env !== null) {
                $counts = [];
                foreach ($this->macros->envChain() as $from) {
                    $n = $scoped[$from] ?? 0;
                    if ($n > 0) {
                        $counts[] = $n . ($n === 1 ? ' macro' : ' macros') . ' from [env:' . $from . ']';
                    }
                }
                $parts[] = implode(', ', $counts);
            }
            if ($hidden !== []) {
                $parts[] = count($hidden) . (count($hidden) === 1 ? ' macro' : ' macros')
                    . ' in another env, not loaded: ' . implode(', ', array_keys($hidden));
            }
            $this->state->macrosScope = implode(' · ', $parts);
        }
        $this->state->macrosSel = max(0, min($this->state->macrosSel, max(0, count($rows) - 1)));

        // Which file(s) answered — and, when none did, where one would go. An
        // empty viewer that names the path it looked in is the difference
        // between "the feature is broken" and "the file isn't there yet".
        $files = $this->macros->files();
        if ($files !== []) {
            $this->state->macrosSource = count($files) === 1
                ? $files[0]
                : implode('  ←  ', array_reverse($files)); // closest (winning) first
        } else {
            $dirs = $this->macros->dirs();
            $where = $dirs === []
                ? Macros::FILE
                : rtrim((string) end($dirs), '/\\') . DIRECTORY_SEPARATOR . Macros::FILE;
            $this->state->macrosSource = 'no ' . Macros::FILE . ' yet — create ' . $where;
        }
        $this->state->macrosProblem = implode(' · ', $this->macros->problems());
    }

    private function handleMacrosViewKey(KeyEvent $e): void
    {
        $n = count($this->state->macrosRows);
        switch ($e->key) {
            case Key::Up:
                $this->state->macrosSel = max(0, $this->state->macrosSel - 1);
                return;
            case Key::Down:
                $this->state->macrosSel = min(max(0, $n - 1), $this->state->macrosSel + 1);
                return;
            case Key::PageUp:
                $this->state->macrosSel = max(0, $this->state->macrosSel - 10);
                return;
            case Key::PageDown:
                $this->state->macrosSel = min(max(0, $n - 1), $this->state->macrosSel + 10);
                return;
            case Key::Home:
                $this->state->macrosSel = 0;
                return;
            case Key::End:
                $this->state->macrosSel = max(0, $n - 1);
                return;
            case Key::Escape:
                $this->state->macrosView = false;
                return;
            case Key::Char:
                if (!$e->ctrl && !$e->alt && strtolower($e->char) === 'r') {
                    $this->reloadMacros();
                }
                return;
            default:
                return;
        }
    }

    /**
     * Re-read every macro layer from disk and say what came back (§23).
     *
     * The count is reported even when it did not change: the point of pressing
     * `r` is to find out whether the edit just made in the other window landed,
     * and silence would answer neither way.
     */
    private function reloadMacros(): void
    {
        $this->macros->reload();
        $this->macrosPublish();
        $problems = $this->macros->problems();
        $n = $this->macros->count();
        $this->state->statusLine = $problems !== []
            ? implode(' · ', $problems)
            : 'Macros reloaded — ' . ($n === 1 ? '1 macro' : $n . ' macros');
        $this->state->statusIsError = $problems !== [];
    }

    // --- ?{name} prompts (§23) ----------------------------------------------

    /**
     * Ask the next unanswered question of the suspended submission, or run it
     * when there are none left.
     *
     * The title carries `(2 of 3)` when there is more than one, because the
     * difference between "one more question" and "four more questions" decides
     * whether the user answers or presses Esc, and a bare name does not say.
     */
    private function openNextPrompt(): void
    {
        $pending = $this->pendingPrompt;
        if ($pending === null) {
            return;
        }
        $total = count($pending['names']);
        $done = count($pending['answers']);
        if ($done >= $total) {
            $this->pendingPrompt = null;
            $this->closePrompt();
            $this->runSubmission(
                $pending['sql'],
                $pending['typed'],
                $pending['vertical'],
                $pending['tab'],
                $pending['answers'],
            );
            return;
        }
        $name = $pending['names'][$done];
        $editor = new LineEditor();
        $this->state->promptEditor = $editor;
        $this->state->promptName = $name;
        $this->state->promptTitle = ' ' . $name . ($total > 1 ? ' (' . ($done + 1) . ' of ' . $total . ')' : '') . ' ';
        $this->state->promptAsk = true;
    }

    private function handlePromptKey(KeyEvent $e): void
    {
        $editor = $this->state->promptEditor;
        if ($editor === null) {
            $this->cancelPrompt();
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                // Esc drops the whole submission, not just this question. Half a
                // set of answers is not a statement anyone asked to run, and the
                // line is still on the CLI to try again.
                $this->cancelPrompt();
                return;
            case Key::Enter:
                $pending = $this->pendingPrompt;
                if ($pending === null) {
                    $this->cancelPrompt();
                    return;
                }
                // An empty answer is an answer: `?{limit}` left blank is how a
                // fragment that is optional gets left out, and refusing to accept
                // it would need a second key to mean "nothing".
                $pending['answers'][$this->state->promptName] = $editor->text();
                $this->pendingPrompt = $pending;
                $this->closePrompt();
                $this->openNextPrompt();
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
    }

    /** Drop the question and the submission behind it. */
    private function cancelPrompt(): void
    {
        $this->pendingPrompt = null;
        $this->submissionTyped = null;
        $this->closePrompt();
        $this->state->statusLine = 'Cancelled — nothing was run';
        $this->state->statusIsError = false;
    }

    private function closePrompt(): void
    {
        $this->state->promptAsk = false;
        $this->state->promptEditor = null;
        $this->state->promptName = '';
        $this->state->promptTitle = '';
    }

    // --- the header comment (§7) --------------------------------------------

    /** Longest comment kept; the header truncates further to fit the row. */
    private const COMMENT_MAX_LEN = 40;

    /**
     * Squeeze whatever arrived — a config value, a typed line — into something a
     * single header row can hold: one line, no control characters, bounded.
     *
     * Bounded here rather than only at draw time so the system-menu label that
     * echoes it back cannot itself grow the menu off the screen.
     */
    private static function cleanComment(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > self::COMMENT_MAX_LEN
            ? rtrim(mb_substr($text, 0, self::COMMENT_MAX_LEN))
            : $text;
    }

    /**
     * "Header Comment" from the system menu: a one-line prompt prefilled with
     * the current note, so the common edit is a tweak rather than a retype.
     */
    private function openCommentEdit(): void
    {
        $editor = new LineEditor();
        $editor->setText($this->state->comment);
        $editor->end();
        $this->state->commentEditor = $editor;
        $this->state->commentEdit = true;
    }

    private function handleCommentEditKey(KeyEvent $e): void
    {
        $editor = $this->state->commentEditor;
        if ($editor === null) {
            $this->state->commentEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->closeCommentEdit();
                return;
            case Key::Enter:
                // An empty line clears it. That is the whole "remove the note"
                // gesture — a second menu item to delete a 6-character string
                // would be more UI than the thing it manages.
                $this->state->comment = self::cleanComment($editor->text());
                $this->closeCommentEdit();
                $this->state->statusLine = $this->state->comment === ''
                    ? 'Header comment cleared'
                    : 'Header comment set to ' . $this->state->comment;
                $this->state->statusIsError = false;
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
    }

    private function closeCommentEdit(): void
    {
        $this->state->commentEdit = false;
        $this->state->commentEditor = null;
    }

    // --- .sqli config creation (§16) ----------------------------------------

    /**
     * "Create Local sqli Config": pick which directory (the cwd or any of its
     * ancestors) should get a fresh `.sqli/` — for when a project deserves its
     * own config/state instead of inheriting whatever is further up the tree.
     */
    private function openCreateConfigChooser(): void
    {
        $items = [];
        foreach ($this->candidateConfigDirs() as $dir) {
            $items[] = $dir;
        }
        if ($items === []) {
            $this->state->statusLine = 'Every directory from here to the root already has a .sqli config.';
            $this->state->statusIsError = false;
            return;
        }
        $this->openChooser('create_config', ' Create .sqli config in… ', $items,
            'Enter: create here · Esc: cancel');
    }

    /**
     * The "where should sqli save its state?" prompt: opened lazily by the main
     * loop the first time something needs persisting and no `.sqli/` exists
     * anywhere (closest-project or global). Never fires at idle startup.
     */
    private function openStateDirPrompt(): void
    {
        $items = [$this->config->globalSqliDir() . '   (home — applies everywhere)'];
        foreach ($this->candidateConfigDirs() as $dir) {
            $items[] = $dir . DIRECTORY_SEPARATOR . '.sqli';
        }
        $items[] = 'Don\'t save anything this session';
        $this->openChooser('state_dir', ' No .sqli config found — save settings where? ', $items,
            'Enter: create + save · Esc: not now');
    }

    /** Dirs from the project dir (`--project`, else the cwd) to the root without a `.sqli/` (home excluded). */
    private function candidateConfigDirs(): array
    {
        $home = rtrim($this->config->globalSqliDir(), '/\\');
        $homeParent = dirname($home);
        $dirs = [];
        $dir = $this->config->projectStart();
        $prev = null;
        while ($dir !== '' && $dir !== $prev) {
            if (!is_dir($dir . DIRECTORY_SEPARATOR . '.sqli')
                && rtrim($dir, '/\\') !== $homeParent) {
                $dirs[] = $dir;
            }
            $prev = $dir;
            $dir = dirname($dir);
        }
        return $dirs;
    }

    private function openChooser(string $purpose, string $title, array $items, string $hint): void
    {
        $s = $this->state;
        $s->chooser = $purpose;
        $s->chooserTitle = $title;
        $s->chooserHint = $hint;
        $s->chooserItems = $items;
        $s->chooserSel = 0;
        $s->chooserOffset = 0;
    }

    private function closeChooser(): void
    {
        $s = $this->state;
        $s->chooser = null;
        $s->chooserItems = [];
    }

    private function handleChooserKey(KeyEvent $e): void
    {
        $s = $this->state;
        $count = count($s->chooserItems);
        switch ($e->key) {
            case Key::Escape:
                $purpose = $s->chooser;
                $this->closeChooser();
                if ($purpose === 'state_dir') {
                    // Esc = "not now": stop asking, hold state in memory.
                    $this->settings->mute();
                    $this->state->statusLine = 'Not saving settings this session.';
                    $this->state->statusIsError = false;
                }
                return;
            case Key::Up:
                $s->chooserSel = $this->clampIndex($s->chooserSel - 1, $count);
                return;
            case Key::Down:
                $s->chooserSel = $this->clampIndex($s->chooserSel + 1, $count);
                return;
            case Key::PageUp:
                $s->chooserSel = $this->clampIndex($s->chooserSel - self::DD_PAGE, $count);
                return;
            case Key::PageDown:
                $s->chooserSel = $this->clampIndex($s->chooserSel + self::DD_PAGE, $count);
                return;
            case Key::Home:
                $s->chooserSel = 0;
                return;
            case Key::End:
                $s->chooserSel = $this->clampIndex($count - 1, $count);
                return;
            case Key::Enter:
                $purpose = (string) $s->chooser;
                $sel = $s->chooserSel;
                $this->closeChooser();
                $this->applyChooser($purpose, $sel);
                return;
            default:
                return;
        }
    }

    private function applyChooser(string $purpose, int $sel): void
    {
        if ($purpose === 'create_config') {
            $dirs = $this->candidateConfigDirs();
            $dir = $dirs[$sel] ?? null;
            if ($dir !== null) {
                $this->createSqliConfigAt($dir . DIRECTORY_SEPARATOR . '.sqli', relocateState: true);
            }
            return;
        }
        if ($purpose === 'state_dir') {
            $candidates = $this->candidateConfigDirs();
            $last = count($candidates) + 1; // items: [home, ...candidates, "don't save"]
            if ($sel === $last) {
                $this->settings->mute();
                $this->state->statusLine = 'Not saving settings this session.';
                $this->state->statusIsError = false;
                return;
            }
            if ($sel === 0) {
                $this->createSqliConfigAt($this->config->globalSqliDir(), relocateState: true);
                return;
            }
            $dir = $candidates[$sel - 1] ?? null;
            if ($dir !== null) {
                $this->createSqliConfigAt($dir . DIRECTORY_SEPARATOR . '.sqli', relocateState: true);
            }
        }
    }

    /**
     * Create + seed a `.sqli/` dir, report exactly what was made where, and
     * (optionally) move the live state/formatting persistence into it. Any
     * write failure surfaces as a status-line error with the path and reason.
     */
    private function createSqliConfigAt(string $sqliDir, bool $relocateState): void
    {
        $err = $this->config->scaffoldAt($sqliDir);
        if ($err !== null) {
            $this->state->statusLine = $err;
            $this->state->statusIsError = true;
            my::lineout('FILE:FILE', 'config scaffold failed', $sqliDir, $err);
            return;
        }
        if ($relocateState) {
            $this->settings->relocate($sqliDir);
        }
        $this->state->statusLine = 'Created ' . $sqliDir
            . ' (sqli_config.json, sqli_credentials.json, themes/)';
        $this->state->statusIsError = false;
        my::lineout('FILE:FILE', 'config scaffolded', $sqliDir);
        // A write error from the relocation (e.g. read-only dir) trumps the
        // success message — surfaced on the next frame by the main loop.
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
        $this->state->ddNewView = false;
        $this->state->ddNewChecked = [];
        $this->state->ddNewSel = 0;
        $this->state->ddNewOffset = 0;
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
        $this->state->ddNewTables = $this->ddUndocumentedTables();
    }

    /** True when a live connection can supply schema for diffing (any table back). */
    private function ddDiffAvailable(): bool
    {
        return $this->schema->tables() !== [];
    }

    /**
     * Live tables the DD doesn't document, sorted A-Z (§11). The table-level
     * counterpart of SchemaDiff's field-level "missing": the DD viewer lists only
     * documented tables, so without this a new table is invisible and the user is
     * never told it needs documenting.
     *
     * Purely derived — nothing is written to the DD until the user creates the
     * entries with Ctrl+N. Empty with no live schema (nothing to compare against).
     *
     * @return list<string>
     */
    private function ddUndocumentedTables(): array
    {
        if ($this->dictionary === null || !$this->ddDiffAvailable()) {
            return [];
        }
        $documented = [];
        foreach ($this->dictionary->tableNames() as $name) {
            $documented[strtolower($name)] = true; // MySQL table names are ci
        }
        $new = [];
        foreach ($this->schema->tables() as $table) {
            if (!isset($documented[strtolower($table)])) {
                $new[] = $table;
            }
        }
        usort($new, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return $new;
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
        $this->state->busyMessage = 'Loading and comparing Data Dictionary …';
        $this->flushFrame();
        try {
            foreach ($ddTables as $t) {
                $this->ddDiffForTable($t); // SHOW COLUMNS only if live + uncached
                $done++;
                $pct = intdiv($done * 100, $total);
                if ($pct !== $lastPct) { // only repaint when the number changes
                    $lastPct = $pct;
                    $this->state->busyMessage = 'Loading and comparing Data Dictionary   ' . $pct . '%';
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

        // The add-undocumented-tables popup owns input while open (§11).
        if ($this->state->ddNewView) {
            $this->handleDdNewTablesKey($e);
            return;
        }

        // The single-field detail view stays open and navigable: Up/Down/Home/End
        // walk the table's fields (rebuilding the box for each), Esc closes it, and
        // any other key is ignored so the box holds (§11).
        if ($this->state->ddRowView) {
            switch ($e->key) {
                case Key::Escape:
                    $this->state->ddRowView = false;
                    break;
                case Key::Up:
                    $this->ddRowViewSelect($this->state->ddFieldSel - 1);
                    break;
                case Key::Down:
                    $this->ddRowViewSelect($this->state->ddFieldSel + 1);
                    break;
                case Key::Home:
                    $this->ddRowViewSelect(0);
                    break;
                case Key::End:
                    $this->ddRowViewSelect(PHP_INT_MAX);
                    break;
                default:
                    break;
            }
            return;
        }

        $onTables = $this->state->ddPanel === 0;
        switch ($e->key) {
            case Key::Tab:
                // Ctrl+I / Tab opens a read-only detail of the selected field —
                // the DD analog of the data view's `i` (§11). Uses Tab because the
                // DD viewer filters as you type, so plain `i` isn't free; Ctrl+I is
                // the same byte (0x09) as Tab, so this key serves both.
                $this->openDdRowView();
                break;
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
                // Ctrl+U syncs the selected table's structure from the live DB and
                // Ctrl+N opens the add-undocumented-tables popup (§11). Other
                // printable characters extend the single search box; remaining
                // ctrl/alt combos are ignored here.
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 'u') {
                    $this->syncDdTable();
                } elseif ($e->ctrl && !$e->alt && strtolower($e->char) === 'n') {
                    $this->openDdNewTables();
                } elseif (!$e->ctrl && !$e->alt && $e->char !== '') {
                    $this->ddInsertFilter($e->char);
                }
                break;
            default:
                break;
        }
    }

    /**
     * Sync the DD-viewer's selected table from the live schema (Ctrl+U, §11).
     * Re-fetches a fresh `SHOW COLUMNS`, rewrites the DD's structural cells while
     * preserving comments, adds undocumented live fields, and saves the CSV. Runs
     * behind a "please wait" popup; archives the DD first (opt-in) like any edit.
     */
    private function syncDdTable(): void
    {
        if ($this->dictionary === null) {
            return;
        }
        // Don't attempt a write we can't complete: the file is held open elsewhere.
        if ($this->state->ddLocked || $this->dictionary->isLocked()) {
            $this->state->ddLocked = true;
            $this->state->statusLine = 'DD file is open in another program — sync disabled until it is closed.';
            $this->state->statusIsError = true;
            return;
        }
        $table = $this->ddCurrentTable();
        if ($table === '') {
            return;
        }
        if (!$this->dictionary->hasTable($table)) {
            // The viewer only lists DD tables, so this is defensive; the spec's
            // "create a new entry" flow for an undocumented table is not yet built.
            $this->state->statusLine = 'Table ' . $table . ' is not in the data dictionary.';
            $this->state->statusIsError = true;
            return;
        }
        if (!$this->schema->hasTable($table)) {
            $this->state->statusLine = 'Table ' . $table . ' not found in the live database — cannot sync.';
            $this->state->statusIsError = true;
            return;
        }

        $this->withBusy('Syncing ' . $table . ' from live schema …', function () use ($table): void {
            $this->schema->forget($table); // force a fresh SHOW COLUMNS
            $live = $this->schema->describeRows($table);
            if ($live === []) {
                $this->state->statusLine = 'Could not read live columns for ' . $table . ' — sync aborted.';
                $this->state->statusIsError = true;
                return;
            }
            $this->archiveDdBeforeEdit();
            $result = $this->dictionary->sync($table, $live);
            $this->applySyncResult($table, $result);
        });
    }

    /**
     * Open the "add undocumented tables" popup (Ctrl+N in the DD viewer, §11).
     * Lists the live tables the DD doesn't document; nothing is written until the
     * user confirms with Enter.
     */
    private function openDdNewTables(): void
    {
        if ($this->state->ddNewTables === []) {
            $this->state->statusLine = $this->state->ddDiffReady
                ? 'Every live table is documented in the data dictionary.'
                : 'No live database to compare against.';
            $this->state->statusIsError = false;
            return;
        }
        $this->state->ddNewView = true;
        $this->state->ddNewSel = 0;
        $this->state->ddNewOffset = 0;
        $this->state->ddNewChecked = [];
    }

    /**
     * Keys for the add-undocumented-tables popup (§11): Up/Down/PageUp/PageDown/
     * Home/End move, Space ticks one, Ctrl+A ticks/unticks all, Enter creates the
     * ticked tables (or the highlighted one if none are ticked), Esc closes without
     * writing.
     */
    private function handleDdNewTablesKey(KeyEvent $e): void
    {
        $n = count($this->state->ddNewTables);
        if ($n === 0) {
            $this->state->ddNewView = false;
            return;
        }
        $move = function (int $to): void {
            $this->state->ddNewSel = max(0, min(count($this->state->ddNewTables) - 1, $to));
        };
        switch ($e->key) {
            case Key::Escape:
                $this->state->ddNewView = false;
                break;
            case Key::Up:
                $move($this->state->ddNewSel - 1);
                break;
            case Key::Down:
                $move($this->state->ddNewSel + 1);
                break;
            case Key::PageUp:
                $move($this->state->ddNewSel - $this->ddNewPageStep());
                break;
            case Key::PageDown:
                $move($this->state->ddNewSel + $this->ddNewPageStep());
                break;
            case Key::Home:
                $move(0);
                break;
            case Key::End:
                $move($n - 1);
                break;
            case Key::Enter:
                $this->createDdTables($this->ddNewSelection());
                break;
            case Key::Char:
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 'a') {
                    $this->ddNewToggleAll();
                } elseif (!$e->ctrl && !$e->alt && $e->char === ' ') {
                    $this->ddNewToggle($this->state->ddNewSel);
                }
                break;
            default:
                break;
        }
    }

    /** One PageUp/PageDown step in the add-tables popup: a full visible page. */
    private function ddNewPageStep(): int
    {
        return max(1, $this->ddNewVisibleRows());
    }

    /**
     * Rows the add-tables popup can show at once. Mirrors the renderer's geometry
     * (box borders + title + hint line), so paging matches what's on screen.
     */
    private function ddNewVisibleRows(): int
    {
        return max(1, min(count($this->state->ddNewTables), $this->screen->height() - 6));
    }

    /** Tick/untick one table in the add-tables popup. */
    private function ddNewToggle(int $idx): void
    {
        $table = $this->state->ddNewTables[$idx] ?? null;
        if ($table === null) {
            return;
        }
        if (isset($this->state->ddNewChecked[$table])) {
            unset($this->state->ddNewChecked[$table]);
        } else {
            $this->state->ddNewChecked[$table] = true;
        }
    }

    /** Ctrl+A in the add-tables popup: tick all, or untick all if all are ticked. */
    private function ddNewToggleAll(): void
    {
        if (count($this->state->ddNewChecked) === count($this->state->ddNewTables)) {
            $this->state->ddNewChecked = [];
            return;
        }
        $this->state->ddNewChecked = [];
        foreach ($this->state->ddNewTables as $table) {
            $this->state->ddNewChecked[$table] = true;
        }
    }

    /**
     * What Enter acts on in the add-tables popup: every ticked table, or — when
     * nothing is ticked — just the highlighted one, so the common "add this one"
     * case needs no Space first.
     *
     * @return list<string>
     */
    private function ddNewSelection(): array
    {
        $checked = [];
        foreach ($this->state->ddNewTables as $table) {
            if (isset($this->state->ddNewChecked[$table])) {
                $checked[] = $table;
            }
        }
        if ($checked !== []) {
            return $checked;
        }
        $one = $this->state->ddNewTables[$this->state->ddNewSel] ?? null;
        return $one === null ? [] : [$one];
    }

    /**
     * Create DD entries for the given undocumented live tables (§11): fetch each
     * one's columns, write them all in a single archived, atomic CSV update, and
     * refresh the viewer so they appear as ordinary DD tables with empty comments.
     *
     * @param list<string> $tables
     */
    private function createDdTables(array $tables): void
    {
        if ($this->dictionary === null || $tables === []) {
            return;
        }
        // Don't attempt a write we can't complete: the file is held open elsewhere.
        if ($this->state->ddLocked || $this->dictionary->isLocked()) {
            $this->state->ddLocked = true;
            $this->state->statusLine = 'DD file is open in another program — adding tables is disabled until it is closed.';
            $this->state->statusIsError = true;
            return;
        }

        $msg = count($tables) === 1
            ? 'Adding ' . $tables[0] . ' to the data dictionary …'
            : 'Adding ' . count($tables) . ' tables to the data dictionary …';
        $this->withBusy($msg, function () use ($tables): void {
            $spec = [];
            $unreadable = [];
            foreach ($tables as $table) {
                $live = $this->schema->describeRows($table);
                if ($live === []) {
                    $unreadable[] = $table;
                    continue;
                }
                $spec[$table] = $live;
            }
            if ($spec === []) {
                $this->state->statusLine = 'Could not read live columns for '
                    . implode(', ', $unreadable) . ' — nothing added.';
                $this->state->statusIsError = true;
                return;
            }
            $this->archiveDdBeforeEdit();
            $result = $this->dictionary->createTables($spec);
            $this->applyCreateResult($result, $unreadable);
        });
    }

    /**
     * Turn a {@see DataDictionary::createTables()} result into a status line, close
     * the popup on success, and drop cached diffs so the new tables pick up badges.
     *
     * @param array{status:string,created:list<string>,fields:int,skipped:list<string>} $result
     * @param list<string> $unreadable tables whose live columns couldn't be read
     */
    private function applyCreateResult(array $result, array $unreadable = []): void
    {
        if ($result['status'] === DataDictionary::SAVE_LOCKED) {
            $this->state->ddLocked = true;
            $this->state->statusLine = 'DD file is open in another program — try Ctrl+N again once it is closed.';
            $this->state->statusIsError = true;
            return;
        }
        if ($result['status'] === DataDictionary::SAVE_FAILED) {
            $this->state->statusLine = 'Adding tables to the DD failed — check file permissions';
            $this->state->statusIsError = true;
            return;
        }
        $created = $result['created'];
        if ($created === []) {
            // Everything requested was already documented — the likeliest cause is a
            // co-worker's entry merging in from disk on the way to writing.
            $this->state->statusLine = 'Already in the data dictionary — nothing added.';
            $this->state->statusIsError = false;
            $this->state->ddNewView = false;
            return;
        }

        // The DD gained tables → the derived lists and every cached diff are stale.
        $this->ddDiffCache = [];
        $this->state->ddNewView = false;
        $this->state->ddNewChecked = [];
        $this->state->ddNewSel = 0;
        $this->state->ddNewOffset = 0;

        $parts = [count($created) . ' table(s) added (' . implode(', ', $created) . ')'];
        $parts[] = $result['fields'] . ' field(s)';
        if ($unreadable !== []) {
            $parts[] = 'skipped ' . implode(', ', $unreadable) . ' (could not read columns)';
        }
        if ($result['status'] === DataDictionary::SAVE_MERGED) {
            $parts[] = 'merged external changes';
        }
        $this->state->statusLine = 'DD updated · ' . implode(' · ', $parts)
            . ' — comments are empty, fill them in with Enter.';
        $this->state->statusIsError = false;

        // Land the table panel on the first table just created so the user is
        // looking straight at what they need to document.
        $this->state->ddTableSel = $this->ddIndexOfTable($created[0]);
        $this->state->ddFieldSel = 0;
        $this->state->ddPanel = 0;
    }

    /**
     * Turn a {@see DataDictionary::sync()} result into a status line and refresh
     * the viewer's diff cache so badges reflect the new structure.
     *
     * @param array{status:string,updated:int,added:list<string>,removed:list<string>} $result
     */
    private function applySyncResult(string $table, array $result): void
    {
        if ($result['status'] === DataDictionary::SAVE_LOCKED) {
            $this->state->ddLocked = true;
            $this->state->statusLine = 'DD file is open in another program — try Ctrl+U again once it is closed.';
            $this->state->statusIsError = true;
            return;
        }
        if ($result['status'] === DataDictionary::SAVE_FAILED) {
            $this->state->statusLine = 'DD sync failed — check file permissions';
            $this->state->statusIsError = true;
            return;
        }
        // Structure changed → drop cached diffs so badges re-compute on next draw.
        $this->ddDiffCache = [];
        $added = $result['added'];
        $removed = $result['removed'] ?? [];
        $parts = [$result['updated'] . ' updated'];
        if ($added !== []) {
            $parts[] = count($added) . ' added (' . implode(', ', $added) . ')';
        }
        if ($removed !== []) {
            $parts[] = count($removed) . ' removed (' . implode(', ', $removed) . ')';
        }
        $merged = $result['status'] === DataDictionary::SAVE_MERGED
            ? ' (merged external changes)' : '';
        $this->state->statusLine = 'DD synced · ' . $table . ' · '
            . implode(', ', $parts) . $merged;
        $this->state->statusIsError = false;
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

    /**
     * Open a read-only detail popup for the selected DD field — the DD viewer's
     * answer to the data view's `i` (§11). Every column of the field is stacked as
     * a label:value line so long comments and narrow columns are readable in full.
     * Once open, Up/Down/Home/End walk the table's fields and Esc closes it. The
     * foreign-keys pseudo-row opens its own dedicated FK view instead.
     */
    private function openDdRowView(): void
    {
        $field = $this->ddFilteredFields()[$this->state->ddFieldSel] ?? null;
        if ($field === null) {
            return;
        }
        // The synthetic "-- Foreign Keys --" row has its own dedicated view.
        if ($field['fk'] ?? false) {
            $this->openDdFkView((string) $field['comment']);
            return;
        }
        $this->buildDdRowView();
        $this->state->ddRowView = true;
    }

    /** Number of real (non-FK-summary) fields in the current filtered field list. */
    private function ddRealFieldCount(): int
    {
        $fields = $this->ddFilteredFields();
        $n = count($fields);
        if ($n > 0 && ($fields[$n - 1]['fk'] ?? false)) {
            $n--; // the trailing "-- Foreign Keys --" pseudo-row isn't a field
        }
        return $n;
    }

    /**
     * Move the open field-detail popup to another field of the same table (Up/Down/
     * Home/End), clamped to the real fields (never the FK pseudo-row), and rebuild
     * the box for the newly selected field (§11).
     */
    private function ddRowViewSelect(int $idx): void
    {
        $n = $this->ddRealFieldCount();
        if ($n <= 0) {
            return;
        }
        $this->state->ddFieldSel = max(0, min($n - 1, $idx));
        $this->buildDdRowView();
    }

    /** Populate the field-detail popup's title + body for the selected field (§11). */
    private function buildDdRowView(): void
    {
        $field = $this->ddFilteredFields()[$this->state->ddFieldSel] ?? null;
        if ($field === null || ($field['fk'] ?? false)) {
            return;
        }
        $table = $this->ddCurrentTable();
        $dash = static fn (string $v): string => $v !== '' ? $v : '—';
        $lines = [
            'Field:    ' . $field['name'],
            'Type:     ' . $dash($field['type']),
            'Null:     ' . $dash($field['nullable']),
            'Key:      ' . $dash($field['key']),
            'Default:  ' . $dash($field['default']),
        ];
        $format = $this->dictionary?->format($table, $field['name']) ?? '';
        if ($format !== '') {
            $lines[] = 'Format:   ' . $format;
        }
        $lines[] = '';
        $lines[] = 'Comment:';
        $lines[] = $field['comment'] !== '' ? $field['comment'] : '(none)';
        $lines[] = '';
        $lines[] = '↑/↓ fields · Home/End · Esc close';

        $this->state->ddRowTitle = ($table !== '' ? $table . '.' : '') . $field['name'];
        $this->state->ddRowText = implode("\n", $lines);
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
        // Field specials first (Esc cancel, Enter=newline, Ctrl+S save — the
        // editor-window convention, §11), then shared clipboard + base editing so
        // the comment box matches the CLI: a multi-line field.
        switch ($e->key) {
            case Key::Escape:
                $this->closeDdCommentEdit();
                return;
            case Key::Enter:
                $editor->insertNewline();
                return;
            case Key::Char:
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    $this->saveDdComment();
                    return;
                }
                break;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: true);
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
                    $this->archiveDdBeforeEdit();
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

    /**
     * Before the **first** edit of a given DD file this session, drop a numbered
     * snapshot of it into a sibling `Archives/` subdir if one exists (§11). Once
     * per file per session (tracked by path, so it survives DD reloads); a no-op
     * when there is no Archives dir. Never blocks or fails the edit itself.
     */
    private function archiveDdBeforeEdit(): void
    {
        if ($this->dictionary === null) {
            return;
        }
        $path = $this->dictionary->path();
        if ($path === '' || in_array($path, $this->ddArchivedPaths, true)) {
            return;
        }
        $archived = $this->dictionary->archive();
        if ($archived !== null) {
            // Mark done only on a real snapshot, so a mid-session `mkdir Archives`
            // (or a transient copy failure) still gets picked up on the next edit.
            $this->ddArchivedPaths[] = $path;
            my::lineout('FILE:FILE', 'DD archived', $archived);
        }
    }

    // --- Assign Data Dictionary picker (§11) -------------------------------

    /**
     * Open the two-stage Assign-DD picker. Stage 0 takes a directory path
     * (seeded from the current db's saved DD directory, else the working dir);
     * stage 1 lists the `.csv` files there to pick from.
     */
    private function openDdPicker(): void
    {
        $scope = $this->database->stateKey();
        $saved = $scope !== '' ? $this->settings->getDictionary($scope) : null;
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
        // Field specials first, then the shared clipboard + base editing so the
        // path box behaves exactly like the CLI (§4): a single-line field.
        switch ($e->key) {
            case Key::Escape:
                $this->closeDdPicker();
                return;
            case Key::Enter:
                $this->ddPickerScanDir($editor->text());
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
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
        // The path of the dictionary file itself is a fair thing to paste: list
        // its directory and land on that file.
        $preselect = '';
        if (is_file($dir) && strtolower((string) pathinfo($dir, PATHINFO_EXTENSION)) === 'csv') {
            $preselect = basename($dir);
            $dir = dirname($dir);
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
        $this->state->ddPickerSel = max(0, (int) array_search($preselect, $files, true));
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

        // Whatever is left may still be spelled for the other runtime or carry
        // backslashes / shell escapes / a dropped leading slash — take the first
        // spelling that exists here.
        return Path::fromTyped($path);
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
        $this->loadDictionary($this->database->stateKey(), $path);
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
        $editor = $this->input;
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
            $table = $this->extractTableFromSql($this->cliText());
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
        $this->state->ddHintStripsMark = true;
        $this->state->ddHint = true;
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
                $this->activateLayoutItem($items);
                break;
            case Key::Char:
                $idx = $this->quickKeyIndex($items, $e);
                if ($idx !== null) {
                    $this->state->layoutItem = $idx;
                    $this->activateLayoutItem($items);
                }
                break;
            default:
                break;
        }
    }

    /**
     * Run the selected Data View Configuration item, if it is enabled; a
     * disabled one only keeps the cursor.
     *
     * @param list<array{label:?string,action:?string,enabled:bool}> $items
     */
    private function activateLayoutItem(array $items): void
    {
        $sel = $items[$this->state->layoutItem] ?? null;
        if ($sel === null || !$sel['enabled'] || $sel['action'] === null) {
            return;
        }
        $action = $sel['action'];
        // Unhiding a single column keeps the popup open so several can be
        // unhidden in a row; everything else closes it.
        $keepOpen = str_starts_with($action, 'unhide:');
        // The per-column half and the table-wide half share one menu, so
        // the dispatch asks which half the chosen action belongs to. The
        // column actions need $popupCol, which openLayoutPopup() set.
        if (in_array($action, self::COLUMN_ACTIONS, true)) {
            $this->applyColumnAction($action);
        } else {
            $this->applyLayoutActionStr($action);
        }
        if ($keepOpen) {
            $this->state->layoutItem = $this->clampMenuIndex(
                $this->layoutPopupItems(),
                $this->state->layoutItem,
            );
        } else {
            $this->state->layoutPopup = false;
        }
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
            case 'format_epoch':
                // Toggle the epoch → local-time display format on this column (§6).
                $this->toggleColumnFormat($name, ColumnFormats::EPOCH);
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
     * Display formats get the same treatment (§6): a formatted column can be
     * cleared from here, and a format the **DD suggests but which is not applied**
     * can be applied from here. Without that, formats were the one Layout Config
     * item reachable only through the popup of the specific column carrying it —
     * you had to already know which column that was.
     *
     * @return list<array{label:?string,action:?string,enabled:bool}>
     */
    public function layoutPopupItems(): array
    {
        // The per-column half first, then the table-wide half: "do something to
        // this column" is the common errand, "put the whole table back" the rare
        // one. Clear sort lives in the column block above, next to Sort ASC/DESC.
        $items = $this->columnBlockItems();
        $items[] = ['label' => null, 'action' => null, 'enabled' => false];
        foreach ([
            ['label' => 'Reset column widths', 'action' => 'reset_widths', 'enabled' => true, 'key' => 'v'],
            ['label' => 'Reset column order',  'action' => 'reset_order',  'enabled' => true, 'key' => 'w'],
            ['label' => 'Reset all',           'action' => 'reset_all',    'enabled' => true, 'key' => 'z'],
        ] as $item) {
            $items[] = $item;
        }
        // The per-column entries below (Unhide: …, Clear format: …) come and
        // go with the table, so they are numbered instead.
        if ($this->state->hiddenCols !== []) {
            $items[] = ['label' => null, 'action' => null, 'enabled' => false];
            $items[] = ['label' => 'Show all hidden columns', 'action' => 'unhide_all', 'enabled' => true, 'key' => 't'];
            foreach ($this->state->hiddenCols as $name) {
                $items[] = ['label' => 'Unhide: ' . $name, 'action' => 'unhide:' . $name, 'enabled' => true];
            }
        }
        foreach ($this->formatPopupItems() as $item) {
            $items[] = $item;
        }
        return $items;
    }

    /**
     * The per-column half of Data View Configuration (§6): everything that acts
     * on {@see State::$popupCol} alone. Formerly the F2 popup, now the top block
     * of the one menu.
     *
     * The format entry toggles, so its label reflects the current state: when the
     * column already shows a datetime, offer to revert to the raw epoch, and
     * vice-versa. The DD may *suggest* a format (§11) without applying it — a raw
     * column the DD marks epoch gets the action flagged `(DD)`, so the suggestion
     * is visible at the place you would act on it.
     *
     * Built here rather than in the renderer because the renderer is a pure
     * reader of State: the labels depend on the format registry and the DD, which
     * are App's to know about.
     *
     * @return list<array{label:?string,action:?string,enabled:bool}>
     */
    private function columnBlockItems(): array
    {
        $name = $this->state->visibleColumnNames()[$this->state->popupCol] ?? null;
        if ($name === null) {
            return [];
        }
        $isEpoch = $this->state->formatOf($name) === ColumnFormats::EPOCH;
        $suggestsEpoch = $this->state->ddSuggestedFormat($name) === ColumnFormats::EPOCH;
        $formatLabel = $isEpoch
            ? 'Show as epoch'
            : 'Show as date/time' . ($suggestsEpoch ? ' (DD)' : '');

        // One "Clear sort", here beside Sort ASC/DESC rather than down in the
        // table-wide block, and labelled with what it will actually clear — which
        // may be a different column than the one this block is scoped to.
        $sortLabel = 'Clear sort';
        if ($this->state->sortCol !== null) {
            $arrow = $this->state->sortDir === 'desc' ? '↓' : '↑';
            $sortLabel = 'Clear sort (' . $this->state->sortCol . ' ' . $arrow . ')';
        }
        // Each fixed item answers to a letter (2.42.1), so the key is the same
        // whatever else the menu is showing. The ones that do something take
        // a letter out of their label — `c` copies, so Change width is `n`.
        // The ones that undo — every reset and clear — sit above `u`, away
        // from them (2.42.2): `v` `w` `x` `y` `z`.
        return [
            ['label' => 'Sort ASC',           'action' => 'sort_asc',     'enabled' => true, 'key' => 'a'],
            ['label' => 'Sort DESC',          'action' => 'sort_desc',    'enabled' => true, 'key' => 'd'],
            ['label' => $sortLabel,           'action' => 'clear_sort',   'enabled' => $this->state->sortCol !== null, 'key' => 'y'],
            ['label' => null,                 'action' => null,           'enabled' => false],
            ['label' => 'Move column',        'action' => 'move',         'enabled' => true, 'key' => 'm'],
            ['label' => 'Change width',       'action' => 'width',        'enabled' => true, 'key' => 'n'],
            ['label' => 'Fit to content',     'action' => 'fit',          'enabled' => true, 'key' => 'f'],
            ['label' => 'Fit all to content', 'action' => 'fit_all',      'enabled' => true, 'key' => 'l'],
            ['label' => $formatLabel,         'action' => 'format_epoch', 'enabled' => true, 'key' => 'e'],
            ['label' => 'Copy column',        'action' => 'copy_col',     'enabled' => true, 'key' => 'c'],
            ['label' => 'Hide column',        'action' => 'hide',         'enabled' => true, 'key' => 'h'],
        ];
    }

    /**
     * The display-format block of the Layout popup: what is formatted now, and
     * what the DD offers that isn't applied yet (§6/§11). Empty — no separator,
     * no heading — when there is nothing to say, so a table with no formats and
     * no DD sees exactly the popup it saw before.
     *
     * @return list<array{label:?string,action:?string,enabled:bool}>
     */
    private function formatPopupItems(): array
    {
        $applied = $this->state->formattedColumns();

        // A suggestion is only worth offering when it isn't already in effect.
        // Comparing against the applied id (not merely "is this column
        // formatted") means a column the user has put on a *different* format
        // still shows the DD's alternative rather than silently hiding it.
        $suggested = [];
        foreach ($this->state->visibleColumnNames() as $name) {
            $id = $this->state->ddSuggestedFormat($name);
            if ($id !== null && ColumnFormats::has($id) && ($applied[$name] ?? null) !== $id) {
                $suggested[$name] = $id;
            }
        }
        if ($applied === [] && $suggested === []) {
            return [];
        }

        $items = [['label' => null, 'action' => null, 'enabled' => false]];
        if ($applied !== []) {
            $items[] = ['label' => 'Clear all formats', 'action' => 'clear_formats', 'enabled' => true, 'key' => 'x'];
            foreach ($applied as $name => $id) {
                $items[] = [
                    'label' => 'Clear format: ' . $name . ' (' . self::formatLabel($id) . ')',
                    'action' => 'clear_format:' . $name,
                    'enabled' => true,
                ];
            }
        }
        foreach ($suggested as $name => $id) {
            $items[] = [
                'label' => 'Apply DD format: ' . $name . ' (' . self::formatLabel($id) . ')',
                // The id leads so the column name — which may contain anything,
                // including a colon — can be taken as the unsplit remainder.
                'action' => 'apply_format:' . $id . ':' . $name,
                'enabled' => true,
            ];
        }
        return $items;
    }

    /** Human-readable name for a format id, falling back to the id itself. */
    private static function formatLabel(string $id): string
    {
        return ColumnFormats::get($id)?->label ?? $id;
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
        if (str_starts_with($action, 'clear_format:')) {
            unset($this->state->colFormats[substr($action, strlen('clear_format:'))]);
            $this->persistColFormats();
            return;
        }
        if (str_starts_with($action, 'apply_format:')) {
            // 'apply_format:<id>:<column>' — the id is a known-safe token, so the
            // column name is whatever is left, colons included.
            [, $id, $name] = explode(':', $action, 3) + [2 => ''];
            if ($name !== '' && ColumnFormats::has($id)) {
                $this->state->colFormats[$name] = $id;
                $this->persistColFormats();
            }
            return;
        }
        switch ($action) {
            case 'unhide_all':
                $this->state->hiddenCols = [];
                $this->persistHidden();
                break;
            case 'clear_formats':
                $this->state->colFormats = [];
                $this->persistColFormats();
                break;
            case 'reset_widths':
                $this->state->colWidthOverride = [];
                $this->persistWidths();
                break;
            case 'reset_order':
                $this->state->colOrder = [];
                $this->state->colAlpha = false;
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
                $this->state->colAlpha = false;
                $this->state->colFormats = [];
                $this->state->sortCol = null;
                $this->state->applySort();
                $this->persistHidden();
                $this->persistWidths();
                $this->persistColOrder();
                $this->persistColFormats();
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
        // Start from the width actually on screen, not naturalWidth(): the grid
        // caps an un-overridden column (MAX_COL_WIDTH), so a long text column
        // drawn at 40 would otherwise jump to its full data width on the first
        // press. naturalWidth() is only the fallback before a frame exists.
        $current = $this->state->colWidthOverride[$name] ?? $this->shownWidth() ?? $this->naturalWidth($name);
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

        // What is saved is a prefix of the display: every column up to the last
        // one the user has placed — this one, or one placed earlier that now
        // sits further right. Everything after it stays "unmoved" and keeps
        // following result order (or A–Z, which is never saved on its own). So
        // moving a column while the view is A–Z saves the A–Z columns before it,
        // and that is what brings its position back next time.
        // In move mode "placed" is judged against the order on entry, not the
        // one the previous step left: each Left/Right saves, and a column merely
        // stepped over would otherwise join the prefix and stop being unmoved.
        $base = $this->state->moveMode ? ($this->moveSavedOrder ?? []) : $this->state->colOrder;
        $placed = array_flip(array_merge($base, [$name]));
        $last = $newI;
        foreach ($vis as $pos => $col) {
            if (isset($placed[$col])) {
                $last = max($last, $pos);
            }
        }
        $prefix = array_slice($vis, 0, $last + 1);
        // Kept even when it matches the result's own order. Moving `name`,
        // `description` and `id` back to `id, name, description` is still a
        // placement — those columns must hold under A–Z. It used to be saved as
        // [] ("nothing to say"), and Ctrl+Alt+S then sorted them with the rest.
        // Reset column order is what clears it.
        $this->state->colOrder = $prefix;
        $this->state->selectedCol = $newI;
        $this->persistColOrder();
    }

    /**
     * Ctrl+Alt+S (§6): the unmoved columns — those after the saved prefix — A–Z,
     * or back to result order. Nothing is persisted; moving a column is what
     * saves an order. The selection stays on the same column.
     */
    private function toggleColAlpha(): void
    {
        if (!$this->state->hasResult() || $this->state->vertical) {
            return;
        }
        $name = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        $this->state->colAlpha = !$this->state->colAlpha;
        if ($name !== null) {
            $idx = array_search($name, $this->state->visibleColumnNames(), true);
            $this->state->selectedCol = $idx === false ? 0 : $idx;
        }
        $placed = count($this->state->colOrder);
        $this->state->statusLine = $this->state->colAlpha
            ? ($placed > 0 ? 'Unmoved columns A–Z' : 'Columns A–Z') . ' (not saved — move a column to keep an order)'
            : ($placed > 0
                ? 'Columns back to your layout (' . $placed . ' placed) + result order'
                : 'Columns back in result order');
        $this->state->statusIsError = false;
    }

    private function hideColumn(string $name): void
    {
        if (!in_array($name, $this->state->hiddenCols, true)) {
            $this->state->hiddenCols[] = $name;
            $this->persistHidden();
        }
        $this->clampSelectedCol();
    }

    /** The selected column's width as last drawn, or null when it wasn't on screen. */
    private function shownWidth(): ?int
    {
        foreach ($this->state->gridLayout?->columns ?? [] as $c) {
            if ($c['idx'] === $this->state->selectedCol) {
                return $c['w'];
            }
        }
        return null;
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
            // Measure the *displayed* width so a formatted column (e.g. epoch →
            // datetime, wider than the raw int) isn't clipped (§6).
            $len = $v === null ? 4 : mb_strlen($this->formatDisplay($name, (string) $v));
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

    /**
     * The display name → stored name map for the current result's columns.
     *
     * Saved column state — widths, hidden columns, order, formats — is keyed by
     * column name, and on a joined result the grid's name is `1.id`. That is the
     * wrong key to write to disk twice over: it means nothing without the header
     * chip beside it, and it is **positional**, so swapping the two tables in the
     * FROM clause silently repoints it at the other column and a saved width
     * lands on the wrong one. The file gets `templates.id` instead — the same
     * spelling an export uses, for the same reason (§6).
     *
     * @return array<string,string> Empty when nothing needs translating, which
     *         is every single-table result.
     */
    private function storageNames(): array
    {
        $result = $this->state->result;
        if ($result === null || $result->sources === []) {
            return [];
        }
        $stored = ResultColumns::qualified($result->columns, $result->sources);
        $map = [];
        foreach ($result->columns as $i => $col) {
            if (($stored[$i] ?? $col) !== $col) {
                $map[$col] = $stored[$i];
            }
        }
        return $map;
    }

    /**
     * Rewrite a name-keyed map for storage, or back again with $map flipped.
     *
     * @param  array<string,mixed>  $byName
     * @param  array<string,string> $map
     * @return array<string,mixed>
     */
    private static function rekey(array $byName, array $map): array
    {
        if ($map === []) {
            return $byName;
        }
        $out = [];
        foreach ($byName as $name => $value) {
            $out[$map[$name] ?? $name] = $value;
        }
        return $out;
    }

    /**
     * @param  list<string>         $names
     * @param  array<string,string> $map
     * @return list<string>
     */
    private static function rename(array $names, array $map): array
    {
        if ($map === []) {
            return $names;
        }
        return array_values(array_map(static fn (string $n): string => $map[$n] ?? $n, $names));
    }

    private function persistWidths(): void
    {
        $scope = $this->database->stateKey();
        if ($scope === '' || $this->state->currentTable === '' || $this->state->layoutUnsaved) {
            return;
        }
        $this->settings->setColWidths($scope, $this->layoutTable(),
            self::rekey($this->state->colWidthOverride, $this->storageNames()));
    }

    private function persistHidden(): void
    {
        $scope = $this->database->stateKey();
        if ($scope === '' || $this->state->currentTable === '' || $this->state->layoutUnsaved) {
            return;
        }
        $this->settings->setHiddenCols($scope, $this->layoutTable(),
            self::rename(array_values($this->state->hiddenCols), $this->storageNames()),
            $this->state->layoutFields);
    }

    private function persistColOrder(): void
    {
        $scope = $this->database->stateKey();
        if ($scope === '' || $this->state->currentTable === '' || $this->state->layoutUnsaved) {
            return;
        }
        $this->settings->setColOrder($scope, $this->layoutTable(),
            self::rename(array_values($this->state->colOrder), $this->storageNames()),
            $this->state->layoutFields);
    }

    private function persistColFormats(): void
    {
        $scope = $this->database->stateKey();
        if ($scope === '' || $this->state->currentTable === '' || $this->state->layoutUnsaved) {
            return;
        }
        $this->settings->setColFormats($scope, $this->layoutTable(),
            self::rekey($this->state->colFormats, $this->storageNames()));
    }

    /**
     * Toggle a display format on the popup's target column (§6, backlog S).
     * Setting the format it already has clears it; the raw value is untouched —
     * only how it renders/edits changes. Persisted as a Layout Config item.
     *
     * The DD's {@see State::$ddColFormats} is only a suggestion (§11), so this is
     * a plain on/off toggle over the manual {@see State::$colFormats}.
     */
    private function toggleColumnFormat(string $col, string $formatId): void
    {
        if ($this->state->formatOf($col) === $formatId) {
            unset($this->state->colFormats[$col]);
        } else {
            $this->state->colFormats[$col] = $formatId;
        }
        $this->persistColFormats();
    }

    /**
     * Populate DD-suggested column formats for the current table (§6/§11).
     * The DD's optional `Format` column marks a field's suggested display format
     * (e.g. `epoch`); these are *not* applied automatically — they surface in the
     * column popup for the user to accept. Only consults a DD that is associated
     * with this db (loading it lazily, once); a no-DD db costs nothing.
     */
    private function applyDdFormats(): void
    {
        $this->state->ddColFormats = [];
        $table = $this->state->currentTable;
        if ($table === '' || !$this->dictionaryAvailable()) {
            return;
        }
        $this->ensureDictionaryLoaded();
        if ($this->dictionary === null || !$this->dictionary->hasTable($table)) {
            return;
        }
        foreach ($this->dictionary->columns($table) as $field) {
            $fid = $this->dictionary->format($table, $field);
            if ($fid !== '' && ColumnFormats::has($fid)) {
                $this->state->ddColFormats[$field] = $fid;
            }
        }
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
     * Restore the saved per-table layout — widths, hidden columns, column order,
     * display formats — for the statement that just ran (§6), and set
     * {@see State::$currentTable} from it.
     *
     * Order and hidden columns are saved per field list ({@see layoutFieldsKey()}):
     * an arrangement made over `SELECT *` comes back for `SELECT *`, one made
     * over `SELECT name, id` comes back only for a query naming exactly `name,
     * id` in that order, and neither leaks into the other. Before 2.39.12 a
     * named list got no saved order at all, because the only one on file was
     * the `SELECT *` one, and restoring it put a hand-written list back in some
     * other query's arrangement.
     *
     * Widths and formats are keyed by column name and neither reorder nor remove
     * anything, so they apply to every query on the table: a column shown as
     * date/time last time is still date/time here.
     *
     * $unsaved marks a view whose layout is never written back
     * ({@see State::$layoutUnsaved}): it still takes the table's widths and
     * formats, but starts with no order and nothing hidden.
     */
    private function applySavedLayout(string $sql, bool $unsaved = false): void
    {
        $this->state->layoutUnsaved = $unsaved;
        $previous = $this->state->currentTable;
        $this->state->currentTable = $this->extractTableFromSql($sql);
        // A–Z (Ctrl+Alt+S) is a look at this table's columns, not a setting:
        // it lasts while the view keeps showing the same table (F5, a new WHERE)
        // and goes when it shows another.
        if ($this->state->currentTable !== $previous) {
            $this->state->colAlpha = false;
        }
        $scope = $this->database->stateKey();
        $table = $this->layoutTable();
        $this->state->layoutFields = $this->layoutFieldsKey($sql);
        if ($table === '' || $scope === '') {
            $this->state->colWidthOverride = [];
            $this->state->hiddenCols = [];
            $this->state->colOrder = [];
            $this->state->colFormats = [];
            return;
        }
        $fields = $this->state->layoutFields;
        // What is on disk is spelled `templates.id`; what the grid works in is
        // `1.id`. Flipping the map turns the stored names back into this
        // result's, and leaves alone anything it does not recognise — a saved
        // entry for a column this query did not return simply never matches.
        $back = array_flip($this->storageNames());
        $this->state->colWidthOverride = self::rekey($this->settings->getColWidths($scope, $table), $back);
        $this->state->hiddenCols = $unsaved ? [] : self::rename($this->settings->getHiddenCols($scope, $table, $fields), $back);
        $this->state->colOrder   = $unsaved ? [] : self::rename($this->settings->getColOrder($scope, $table, $fields), $back);
        $this->state->colFormats = self::rekey($this->settings->getColFormats($scope, $table), $back);
    }

    /**
     * The table a result's saved layout is filed under (§6): the table itself,
     * or for a join every table its columns came from — `template_items,
     * templates`.
     *
     * A join is not its first table. Filed under `templates`, the order made
     * over `templates, template_items` and the one made over `templates` alone
     * were one entry, each overwriting the other. The WHERE, ORDER BY and LIMIT
     * never come into it: the layout belongs to the table(s), not the query.
     *
     * Sorted, so `FROM a, b` and `FROM b, a` are the same pair — the columns
     * inside are stored by real name ({@see storageNames()}) and land right
     * either way. A join that returns one table's columns (`SELECT ti.* FROM
     * templates t JOIN template_items ti`) is that table, whichever came first
     * in the FROM; PDO knows only the alias there, so the FROM table stands.
     */
    private function layoutTable(): string
    {
        $table = $this->state->currentTable;
        $sources = $this->state->result?->sources ?? [];
        if ($table === '' || $sources === []) {
            return $table;
        }
        if (count($sources) === 1) {
            return $sources[0]->table !== '' ? $sources[0]->table : $table;
        }
        $names = array_map(static fn (ResultSource $s): string => $s->name(), $sources);
        sort($names, SORT_STRING);
        return implode(', ', $names);
    }

    /**
     * The key a query's saved order and hidden set are filed under (§6): `*`
     * when it names no columns, otherwise the columns it returned, in the
     * order it returned them, in their stored spelling (`templates.id` on a
     * join, {@see storageNames()}).
     *
     * The result's own names rather than the SQL text, so spacing, backticks
     * and `t.` prefixes don't make two identical lists look different, and an
     * alias is the name the user sees. `name, id` and `id, name` are different
     * lists: the order was part of what the query asked for.
     */
    private function layoutFieldsKey(string $sql): string
    {
        $result = $this->state->result;
        if (!Normalizer::namesColumns($sql) || $result === null) {
            return '*';
        }
        return implode(', ', self::rename($result->columns, $this->storageNames()));
    }

    /**
     * Best-effort table name from a SQL statement (FROM / INTO / UPDATE).
     * Shared by per-table layout persistence, the DD hint, and the guards below.
     *
     * Comments come out first: a commented-out `FROM` names a table the statement
     * never touches, and this answer decides which table's layout is restored and
     * which table a dangerous-query prompt reports.
     */
    private function extractTableFromSql(string $sql): string
    {
        if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i', Splitter::stripComments($sql), $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Decide whether a data-modifying statement needs the dangerous-query confirm
     * (§5). Returns the popup message when confirmation is required, or null when
     * the statement is safe to run immediately. DELETE/UPDATE are nuanced (only a
     * broad WHERE prompts); every other modifying command always prompts.
     *
     * $strict withdraws the DELETE/UPDATE nuance. It is set for statements inside
     * multi-statement input, where the trust a PK-scoped WHERE earns on its own
     * does not carry over: the user is running something they did not compose one
     * statement at a time, most likely pasted (beta-readiness item 9). It is also
     * set for a DELETE built by the data view (§22), which is PK-scoped by
     * construction and so would otherwise never report its row count at all.
     * $strictReason is what the message gives as the reason in that case.
     *
     * The comments are stripped before anything below reads the text. Naming the
     * command can be done past a comment ({@see Splitter::commandKeyword()}), but
     * judging one cannot: every check from here down is a regex over the
     * statement, and a commented-out `WHERE id = 1` under a bare
     * `DELETE FROM users` satisfied both the WHERE test and the primary-key test
     * and took the whole table with no prompt at all.
     */
    private function dangerCheck(
        string $sql,
        string $type,
        bool $strict = false,
        string $strictReason = 'PK-scoped, but one of several statements',
    ): ?string {
        $sql = Splitter::stripComments($sql);

        if ($type === 'DELETE' || $type === 'UPDATE') {
            return $this->dangerCheckDml($sql, $type, $strict, $strictReason);
        }
        return $this->dangerCheckDdl($sql, $type);
    }

    /**
     * DELETE/UPDATE: confirm only when the statement is broad — no WHERE clause,
     * or a WHERE that doesn't reference a primary-key column. A PK-scoped
     * statement runs immediately (returns null) unless $strict. The message
     * reports the query type, table, why it is being asked about, and an
     * estimated affected count.
     */
    private function dangerCheckDml(
        string $sql,
        string $type,
        bool $strict = false,
        string $strictReason = 'PK-scoped, but one of several statements',
    ): ?string {
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

        if ($hasWhere && $refsPk && !$strict) {
            return null; // scoped to specific rows by PK — safe to run
        }

        $count = $this->estimateAffected($table, $hasWhere ? $whereClause : null);
        $tbl = $table !== '' ? '`' . $table . '`' : '(unknown table)';
        if ($hasWhere && $refsPk) {
            // Strict mode, and the statement *is* PK-scoped: whoever set $strict
            // says why it is being asked about anyway - one of several typed
            // statements, or a delete started from the grid (§22).
            $reason = $strictReason;
        } else {
            $reason = $hasWhere ? 'WHERE present but no PK column' : 'no WHERE clause (all rows)';
        }
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

    /**
     * Record a data-modifying statement that ran in `sqli_db_writes.log` (the
     * `log-writes` option, §25). Called from every place a statement's result
     * comes back — the CLI, the grid's own UPDATE and DELETE, and scripting
     * mode — and decides for itself whether there is anything to write: a
     * statement that failed changed nothing, and a SELECT is not a write.
     */
    private function logWrite(string $sql, QueryResult $result, string $first = ''): void
    {
        if (!$this->config->isFlag('log-writes') || $result->hasError()) {
            return;
        }
        $first = $first !== '' ? $first : Splitter::commandKeyword($sql);
        if (!in_array($first, self::MODIFYING, true)) {
            return;
        }
        $this->writeLog ??= new WriteLog($this->config->writeLogPath());
        $written = $this->writeLog->record(
            $this->database->user(),
            $this->database->server(),
            $this->database->currentDatabase(),
            $this->affectedTable(Splitter::stripComments($sql), $first),
            $sql,
        );
        if (!$written) {
            my::lineout('FILE:FILE', 'logWrite: could not write', $this->writeLog->path());
        }
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

    /**
     * Ctrl+N: set the selected cell to SQL NULL (§9). It is an edit like any
     * other — the one-cell UPDATE goes to the CLI to run or cancel — only the
     * value is a real NULL rather than text, which typing cannot say reliably
     * (`NULL` typed into the editor also means NULL, so the literal string
     * cannot be stored that way, and an empty edit is the empty string).
     *
     * Refused where the database would refuse it or the edit makes no sense: a
     * NOT NULL column, the primary key, a joined grid, read-only mode, and a
     * cell that is already NULL.
     */
    private function setCellNull(): void
    {
        if ($this->state->readOnly) {
            $this->state->statusLine = '[RO] Read-only mode — editing is blocked' . $this->readOnlyWhy();
            $this->state->statusIsError = true;
            return;
        }
        if (!$this->state->hasResult() || $this->state->selectedRow < 0 || $this->state->markMode) {
            return;
        }
        $col = $this->state->visibleColumnNames()[$this->state->selectedCol] ?? null;
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($col === null || $rawIdx === null) {
            return;
        }
        if ($this->blockIfMultiSource('edit') || $this->blockIfPrimaryKey($col)) {
            return;
        }
        if ($this->cellIsNull($rawIdx, $col)) {
            $this->state->statusLine = '`' . $col . '` is already NULL';
            $this->state->statusIsError = false;
            return;
        }
        $table = $this->state->currentTable;
        $describe = $table === '' ? null : $this->schema->describeColumn($table, $col);
        if ($describe !== null && strtoupper((string) ($describe['Null'] ?? '')) === 'NO') {
            $this->state->statusLine = '`' . $col . '` is NOT NULL — it cannot be set to NULL';
            $this->state->statusIsError = true;
            return;
        }
        $this->stageEdit($rawIdx, $col, null);
        if ($this->autoUpdate !== null) {
            $this->warnIfForeignKey($col);
        }
    }

    private function openCellEdit(): void
    {
        if ($this->state->readOnly) {
            $this->state->statusLine = '[RO] Read-only mode — editing is blocked' . $this->readOnlyWhy();
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
        if ($this->blockIfMultiSource('edit') || $this->blockIfPrimaryKey($col)) {
            return;
        }
        $this->warnIfForeignKey($col);
        $current = $this->cellIsNull($rawIdx, $col) ? null : $this->cellValueAt($rawIdx, $col);
        // A formatted column edits in its displayed form (e.g. a datetime); the
        // edit is converted back to the raw value on commit (§6).
        $value = $this->formatDisplay($col, $current === null ? '' : (string) $current);
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
        if ($this->blockIfReadOnly() || $this->blockIfMultiSource('edit') || $this->blockIfPrimaryKey($col)) {
            return;
        }
        $this->warnIfForeignKey($col);
        $current = $this->cellIsNull($rawIdx, $col) ? null : $this->cellValueAt($rawIdx, $col);
        $editor = new LineEditor();
        // Edit in the column's displayed form; converted back on commit (§6).
        $editor->setText($this->formatDisplay($col, $current === null ? '' : (string) $current));
        $this->state->cellEditor = $editor;
        $this->state->cellEditRow = $rawIdx;
        $this->state->cellEditCol = $col;
        $this->state->cellEditFromView = true;
        $this->state->cellMoveMode = false;
        $this->state->cellEdit = true;
    }

    /**
     * Refuse to start an edit in read-only mode, saying why. stageEdit() blocks
     * the UPDATE itself; this stops an editor opening that could never save.
     */
    private function blockIfReadOnly(): bool
    {
        if (!$this->state->readOnly) {
            return false;
        }
        $this->state->statusLine = '[RO] Read-only mode — editing is blocked' . $this->readOnlyWhy();
        $this->state->statusIsError = true;
        return true;
    }

    /** JSON viewer form of {@see blockIfReadOnly()}: the refusal goes on its notice row. */
    private function blockJsonIfReadOnly(): bool
    {
        if (!$this->state->readOnly) {
            return false;
        }
        $this->state->jsonNotice = '[RO] Read-only mode — editing is blocked';
        return true;
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
        // Field specials first (Ctrl+S save, Enter=newline, Esc cancel — §9), then
        // shared clipboard + base editing so the cell box matches the CLI: a
        // multi-line field.
        switch ($e->key) {
            case Key::Char:
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    $this->confirmCellEdit();
                    return;
                }
                break;
            case Key::Enter:
                $editor->insertNewline();
                return;
            case Key::Escape:
                $this->closeCellEdit();
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: true);
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
        // A formatted column is edited in its display form (e.g. a datetime);
        // convert it back to the raw stored value before diffing/staging (§6). An
        // unparseable entry keeps the editor open with an error, like a type miss.
        $fmt = ColumnFormats::get($this->state->formatOf($col));
        if ($fmt !== null) {
            $parsed = $fmt->parse($new);
            if ($parsed === null) {
                $this->state->statusLine = 'Invalid ' . $fmt->label . ' value — expected e.g. 2026-07-08 14:30:00';
                $this->state->statusIsError = true;
                return;
            }
            $new = $parsed;
        }
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
        // Drop any half-finished mouse grab; the box the user was dragging is gone.
        // The chosen position/size deliberately survive — see State::$cellEditX.
        $this->editorDrag = null;
        $this->editorResizing = false;
        $this->modalSelecting = false;
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

    /**
     * True (and sets a status error) when the result was joined from more than
     * one table, which makes it unsafe to write to.
     *
     * Every generated statement takes its table from {@see State::$currentTable},
     * which is the *first* table the SQL mentions — so on a join of `templates`
     * and `template_items` a cell edit would build `UPDATE templates`, and a row
     * delete `DELETE FROM templates WHERE id IN (…)`, whichever table the column
     * or the key actually came from. Both are valid SQL aimed at the wrong rows.
     *
     * There is no safe guess to make here — the fix is a per-column table map
     * and a WHERE built from the right one, which is a feature, not a patch. In
     * the meantime the grid refuses and says why, because a wrong `DELETE` is
     * the worst outcome in the program.
     */
    private function blockIfMultiSource(string $verb): bool
    {
        $result = $this->state->result;
        if ($result === null || !$result->isMultiSource()) {
            return false;
        }
        $names = [];
        foreach ($result->sources as $source) {
            $names[] = $source->name();
        }
        $this->state->statusLine = 'Cannot ' . $verb . ': this result joins '
            . implode(' + ', $names) . ' — query one table to edit it';
        $this->state->statusIsError = true;
        return true;
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
    private function stageEdit(int $rawIdx, string $col, ?string $newValue): void
    {
        // The in-flight UPDATE runs directly (bypassing execute's guards), so the
        // read-only block has to be enforced here (§17).
        if ($this->state->readOnly) {
            $this->state->statusLine = '[RO] Read-only mode — editing is blocked' . $this->readOnlyWhy();
            $this->state->statusIsError = true;
            return;
        }
        $this->revertInFlight();
        // An un-run delete and an un-run edit cannot both own the CLI, and the
        // edit is what the user just asked for (§22). Mirrors stageDelete().
        $this->autoDelete = null;
        $this->state->deleteMarks = [];
        $this->state->pending[$rawIdx][$col] = $newValue;
        $sql = $this->buildUpdate($rawIdx); // one pending col → single-cell UPDATE
        if ($sql === null) {
            // Can't form the UPDATE (missing PK etc.) — undo the staged value.
            $this->revertPendingCell($rawIdx, $col);
            return;
        }
        $this->autoUpdate = ['rawIdx' => $rawIdx, 'col' => $col, 'sql' => $sql];
        $this->input->setText($sql);
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
        $this->input->setText('');
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
        $val = $this->state->pending[$au['rawIdx']][$au['col']] ?? null; // null = a staged NULL
        $res = $this->database->query(rtrim($au['sql'], ';'));
        $this->logWrite($au['sql'], $res);
        if ($res->hasError()) {
            $this->revertPendingCell($au['rawIdx'], $au['col']);
            $this->setError('UPDATE failed: ' . ($res->error ?? 'unknown error'));
        } else {
            // Commit the value into the (readonly) result by rebuilding it, then
            // drop the transient staged value — the grid now shows the saved data.
            $this->foldResultCell($au['rawIdx'], $au['col'], $val);
            $this->revertPendingCell($au['rawIdx'], $au['col']);
            $this->state->statusLine = 'Row updated ('
                . $res->affectedRows . ' row' . ($res->affectedRows === 1 ? '' : 's') . ')';
            $this->state->statusIsError = false;
        }
        $this->input->setText('');
        $this->resetHistoryNav();
        $this->screen->invalidate();
    }

    /** Rebuild the readonly result with one cell replaced (fold a saved edit). */
    private function foldResultCell(int $rawIdx, string $col, ?string $val): void
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
            $r->sources,
            $r->columnSources,
        );
    }

    // --- row deletion (§22) -------------------------------------------------

    /**
     * Ctrl+Del: open or close the multi-row checkbox column (§22).
     *
     * Closing always drops the marks. A mark is a raw row index into the result
     * on screen, and the only thing that made it visible was the mode - leaving
     * marks set behind an invisible checkbox would arm a later Del with rows the
     * user can no longer see.
     */
    private function toggleMarkMode(): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        if ($this->state->readOnly) {
            $this->setError('[RO] Read-only mode — deleting is blocked' . $this->readOnlyWhy());
            return;
        }
        if ($this->state->markMode) {
            $this->exitMarkMode();
            return;
        }
        $this->state->markMode = true;
        $this->state->deleteMarks = [];
        $this->state->statusLine = 'Mark rows: Enter/Space/click to tick · Del deletes the marked rows · Esc cancels';
        $this->state->statusIsError = false;
        // The checkbox column shifts every data column one gutter to the right.
        $this->screen->invalidate();
    }

    /** Leave mark mode, dropping every mark (§22). */
    private function exitMarkMode(): void
    {
        $this->state->markMode = false;
        $this->state->deleteMarks = [];
        $this->state->statusLine = 'Marks cleared';
        $this->state->statusIsError = false;
        $this->screen->invalidate();
    }

    /** Tick or un-tick the row under the cursor (§22). */
    private function toggleRowMark(): void
    {
        $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
        if ($rawIdx === null) {
            return;
        }
        if (isset($this->state->deleteMarks[$rawIdx])) {
            unset($this->state->deleteMarks[$rawIdx]);
        } else {
            $this->state->deleteMarks[$rawIdx] = true;
        }
        $n = $this->state->markedCount();
        $this->state->statusLine = $n === 0
            ? 'No rows marked · Enter/Space/click to tick · Esc cancels'
            : $n . ' row' . ($n === 1 ? '' : 's') . ' marked · Del to delete · Esc cancels';
        $this->state->statusIsError = false;
    }

    /**
     * Del in the data view (§22): work out which rows are being deleted, build the
     * DELETE for them, and put the affected-row count in front of the user.
     *
     * Outside mark mode this is the selected row, and it gets marked on the way
     * through so the record about to go is visible while the confirm is up -
     * "delete this one" should never be answered from memory of where the cursor
     * was. Inside mark mode it is the ticked set, and Del is what commits it.
     */
    private function requestDelete(): void
    {
        if (!$this->state->hasResult()) {
            return;
        }
        if ($this->state->readOnly) {
            $this->setError('[RO] Read-only mode — deleting is blocked' . $this->readOnlyWhy());
            return;
        }
        if ($this->state->markMode) {
            $rawIdxs = $this->state->markedRawIndices();
            if ($rawIdxs === []) {
                $this->setError('No rows marked - Enter, Space or click a checkbox to mark one');
                return;
            }
        } else {
            $rawIdx = $this->state->displayOrder()[$this->state->selectedRow] ?? null;
            if ($rawIdx === null) {
                return; // header row, or nothing selected
            }
            // One row at a time outside mark mode: any older mark belongs to a
            // delete that was never carried out.
            $this->state->deleteMarks = [$rawIdx => true];
            $rawIdxs = [$rawIdx];
        }

        $built = $this->buildDelete($rawIdxs);
        if ($built === null) {
            // buildDelete() has already said why (missing table / PK popup).
            if (!$this->state->markMode) {
                $this->state->deleteMarks = [];
            }
            return;
        }

        // The affected-row count comes from the same COUNT(*) the CLI's own
        // dangerous-query guard uses, over the same WHERE - so a DELETE started
        // from the grid is judged by exactly the rule a typed one is (§5).
        $message = $this->dangerCheck(
            $built['sql'],
            'DELETE',
            strict: true,
            strictReason: 'PK-scoped, ' . count($rawIdxs) . ' row(s) marked in the data view',
        );
        $this->pendingDelete = [
            'sql'     => $built['sql'],
            'rawIdxs' => $rawIdxs,
            'count'   => count($rawIdxs),
        ];
        $this->openConfirm(
            'grid_delete',
            $message ?? 'DELETE ' . count($rawIdxs) . ' row(s). Run anyway?',
            [['Y', 'Delete'], ['N', 'Cancel']],
        );
    }

    /**
     * Build the DELETE for $rawIdxs, plus the bare WHERE clause behind it (§22).
     * Returns null when the statement cannot be formed, having reported why.
     *
     * The primary-key rules are {@see buildUpdate()}'s, for the same reason: a
     * statement that cannot name the exact rows it means must not be written at
     * all. A single-column key becomes `IN (...)` and a composite one a series of
     * OR'd AND-groups - both forms name the key as an operand, so
     * {@see dangerCheckDml()} recognises them as PK-scoped.
     *
     * @param  list<int> $rawIdxs
     * @return array{sql:string,where:string}|null
     */
    private function buildDelete(array $rawIdxs): ?array
    {
        if ($this->blockIfMultiSource('delete')) {
            return null;
        }
        $table = $this->state->currentTable;
        if ($table === '') {
            $this->setError('Cannot build DELETE: table is unknown');
            return null;
        }
        $pk = $this->schema->primaryKey($table);
        if ($pk === []) {
            $this->setError('Cannot build DELETE: no primary key on ' . $table);
            return null;
        }
        $rows = $this->state->result?->rows ?? [];
        $missing = [];
        foreach ($pk as $k) {
            foreach ($rawIdxs as $rawIdx) {
                if (!array_key_exists($k, $rows[$rawIdx] ?? [])) {
                    $missing[] = $k;
                    continue 2;
                }
            }
        }
        if ($missing !== []) {
            // Same offer an edit gets: re-run the query with the key added.
            $this->openPkPopup($missing);
            return null;
        }

        if (count($pk) === 1) {
            $col = $pk[0];
            $vals = [];
            foreach ($rawIdxs as $rawIdx) {
                $vals[] = $this->sqlQuote((string) ($rows[$rawIdx][$col] ?? ''));
            }
            $where = '`' . $col . '` IN (' . implode(', ', $vals) . ')';
        } else {
            $groups = [];
            foreach ($rawIdxs as $rawIdx) {
                $parts = [];
                foreach ($pk as $k) {
                    $parts[] = '`' . $k . '` = ' . $this->sqlQuote((string) ($rows[$rawIdx][$k] ?? ''));
                }
                $groups[] = '(' . implode(' AND ', $parts) . ')';
            }
            $where = implode(' OR ', $groups);
        }

        return [
            'sql'   => 'DELETE FROM `' . $table . '` WHERE ' . $where . ';',
            'where' => $where,
        ];
    }

    /**
     * The confirm was answered Y: put the DELETE on the CLI to run or cancel
     * (§22). The marks stay on so the rows stay visible while the statement is
     * read - the grid is the only place that says *which* records these are.
     */
    private function stageDelete(): void
    {
        $d = $this->pendingDelete;
        $this->pendingDelete = null;
        if ($d === null) {
            return;
        }
        // An un-run cell edit and an un-run delete cannot both own the CLI.
        $this->revertInFlight();
        $this->autoDelete = $d;
        $this->input->setText($d['sql']);
        $this->state->detail = false;
        $this->state->cellView = false;
        $this->state->cellMoveMode = false;
        $this->state->focus = State::FOCUS_CLI;
        $this->resetHistoryNav();
        $n = count($d['rawIdxs']);
        $this->state->statusLine = 'DELETE ready - ' . $n . ' row' . ($n === 1 ? '' : 's')
            . ' - Enter to run, Esc to cancel';
        $this->state->statusIsError = false;
    }

    /**
     * Cancel the in-flight delete: clear the CLI, drop the marks and go back to
     * the grid (§22). Mark mode itself survives, so a mis-ticked set can be
     * corrected rather than rebuilt from nothing.
     */
    private function cancelInFlightDelete(): void
    {
        if ($this->autoDelete === null) {
            return;
        }
        $this->autoDelete = null;
        $this->state->deleteMarks = [];
        $this->input->setText('');
        $this->resetHistoryNav();
        $this->state->focus = State::FOCUS_DATA;
        $this->state->statusLine = 'Delete cancelled';
        $this->state->statusIsError = false;
    }

    /**
     * Run the in-flight DELETE, then re-run the SELECT behind the grid so what is
     * on screen is what is in the table (§22).
     *
     * Re-running rather than dropping the rows locally is a deliberate cost: a
     * DELETE can take more rows than the grid knows about - an ON DELETE CASCADE,
     * a trigger, a row someone else changed between the count and the statement -
     * and a grid quietly out of step with its table is worse than a round trip.
     */
    private function runAutoDelete(): void
    {
        $ad = $this->autoDelete;
        $this->autoDelete = null;
        if ($ad === null) {
            return;
        }
        $res = $this->withBusy(
            'Deleting …',
            fn () => $this->database->query(rtrim($ad['sql'], ';')),
        );
        $this->logWrite($ad['sql'], $res);
        $this->input->setText('');
        $this->resetHistoryNav();
        $this->state->deleteMarks = [];
        $this->state->markMode = false;

        if ($res->hasError()) {
            $this->state->focus = State::FOCUS_DATA;
            $this->setError('DELETE failed: ' . ($res->error ?? 'unknown error'));
            $this->screen->invalidate();
            return;
        }

        $n = $res->affectedRows;
        $note = $n . ' row' . ($n === 1 ? '' : 's') . ' deleted';
        $reload = $this->state->lastSql;
        if ($reload === '' || !$this->looksReloadable($reload)) {
            // Nothing safe to re-run (the grid came from something other than a
            // plain SELECT). Say so rather than leaving deleted rows on screen as
            // though they were still there.
            $this->state->focus = State::FOCUS_DATA;
            $this->state->statusLine = $note . ' - re-run your query to refresh the view';
            $this->state->statusIsError = false;
            $this->screen->invalidate();
            return;
        }
        // Through submit(), not execute(), so the reload gets the same batch
        // bookkeeping any typed statement does. The status line it will write is
        // the reload's own, so the sentence that actually matters is parked where
        // applyResult() can lead with it.
        $this->deleteNote = $note;
        $this->rerunUnsaved = $this->state->layoutUnsaved;
        $this->submit($reload, false);
    }

    /**
     * True when $sql is safe to re-issue on its own after a delete (§22): a single
     * plain SELECT. Anything else - a modifying statement, a batch - must not be
     * replayed as a side effect of deleting a row.
     */
    private function looksReloadable(string $sql): bool
    {
        if (count(Splitter::split($sql)) !== 1) {
            return false;
        }
        return Splitter::commandKeyword($sql) === 'SELECT';
    }

    /** True when $sql is (ignoring a trailing `;`/space) the in-flight DELETE. */
    private function isInFlightDelete(string $sql): bool
    {
        if ($this->autoDelete === null) {
            return false;
        }
        $norm = static fn (string $v): string => rtrim(trim($v), '; ');
        return $norm($sql) === $norm($this->autoDelete['sql']);
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

    // --- table view of a separated-value cell (`t`, §10a) -----------------

    /**
     * `t` in DATA focus: show the selected cell as a table, split by the
     * definition saved for its column. A column with no definition yet opens
     * the definition editor first, and saving it opens the table.
     */
    private function openSplitFromData(): void
    {
        $sel = $this->selectedCell();
        if ($sel === null) {
            return;
        }
        if ($this->state->currentTable === '' || $this->database->stateKey() === '') {
            $this->setError('Table view needs a query on a known table — the definition is saved per table');
            return;
        }
        $this->splitRawIdx = $sel['rawIdx'];
        $this->splitStart = $sel['value'];
        $this->state->splitColumn = $sel['col'];
        $spec = $this->splitSpecFor($sel['col']);
        if ($spec === null) {
            $this->openSplitDefEditor(SplitSpec::template($this->splitTitle(), $sel['value']));
            return;
        }
        $this->openSplitTable($spec, $sel['value']);
    }

    /** `table.column`, for titles. */
    private function splitTitle(): string
    {
        return $this->layoutTable() . '.' . $this->state->splitColumn;
    }

    /** The column's name as it is stored on disk (a join's `1.id` → `t.id`). */
    private function splitStoredName(string $col): string
    {
        return $this->storageNames()[$col] ?? $col;
    }

    /** The saved definition for $col on this table, or null. */
    private function splitSpecFor(string $col): ?SplitSpec
    {
        $all = $this->settings->getColSplits($this->database->stateKey(), $this->layoutTable());
        return SplitSpec::fromArray($all[$this->splitStoredName($col)] ?? null);
    }

    private function saveSplitSpec(string $col, SplitSpec $spec): void
    {
        $scope = $this->database->stateKey();
        $table = $this->layoutTable();
        $all = $this->settings->getColSplits($scope, $table);
        $all[$this->splitStoredName($col)] = $spec->toArray();
        $this->settings->setColSplits($scope, $table, $all);
    }

    private function openSplitTable(SplitSpec $spec, string $value): void
    {
        $this->splitTable = new SplitTable($spec, $value);
        $this->state->splitView = true;
        $this->state->splitSel = 0;
        $this->state->splitCol = 0;
        $this->state->splitOffset = 0;
        $this->state->splitColOffset = 0;
        $this->state->splitNotice = '';
        $this->state->splitEdit = false;
        $this->state->splitEditor = null;
        $this->publishSplit();
    }

    /** Copy what the renderer needs out of the table, and keep the cursor on it. */
    private function publishSplit(): void
    {
        $t = $this->splitTable;
        if ($t === null) {
            return;
        }
        $this->state->splitHeaders = $t->spec()->headers();
        $this->state->splitCells = $t->allCells();
        $changed = [];
        for ($r = 0; $r < $t->rowCount(); $r++) {
            $changed[] = $t->isChanged($r);
        }
        $this->state->splitChanged = $changed;
        $this->state->splitSel = $this->clampIndex($this->state->splitSel, $t->rowCount());
        $this->state->splitCol = $this->clampIndex($this->state->splitCol, count($this->state->splitHeaders));
    }

    /**
     * Esc: close the table. If the text it now joins to differs from the cell,
     * the change goes to the CLI as an UPDATE, like any cell edit (§9).
     */
    private function closeSplit(): void
    {
        $table = $this->splitTable;
        $rawIdx = $this->splitRawIdx;
        $col = $this->state->splitColumn;
        $this->resetSplitState();
        if ($table === null || $rawIdx < 0) {
            return;
        }
        $new = $table->join();
        if ($new !== $this->splitStart) {
            $this->stageEdit($rawIdx, $col, $new);
        }
    }

    private function resetSplitState(): void
    {
        $this->splitTable = null;
        $this->state->splitView = false;
        $this->state->splitHeaders = [];
        $this->state->splitCells = [];
        $this->state->splitChanged = [];
        $this->state->splitNotice = '';
        $this->state->splitEdit = false;
        $this->state->splitEditor = null;
        $this->state->splitDefEdit = false;
        $this->state->splitDefEditor = null;
    }

    private function blockSplitIfReadOnly(): bool
    {
        if (!$this->state->readOnly) {
            return false;
        }
        $this->state->splitNotice = '[RO] Read-only mode — editing is blocked';
        return true;
    }

    private function handleSplitKey(KeyEvent $e): void
    {
        if ($this->state->splitEdit) {
            $this->handleSplitEditKey($e);
            return;
        }
        $this->state->splitNotice = '';
        $rows = count($this->state->splitCells);
        $lastRow = max(0, $rows - 1);
        $lastCol = max(0, count($this->state->splitHeaders) - 1);
        $step = max(1, $this->state->splitPageRows);
        switch ($e->key) {
            case Key::Up:
                $this->state->splitSel = max(0, $this->state->splitSel - 1);
                break;
            case Key::Down:
                $this->state->splitSel = min($lastRow, $this->state->splitSel + 1);
                break;
            case Key::Left:
                $this->state->splitCol = max(0, $this->state->splitCol - 1);
                break;
            case Key::Right:
                $this->state->splitCol = min($lastCol, $this->state->splitCol + 1);
                break;
            case Key::Home:
                // Home → first field; Ctrl+Home → first row.
                if ($e->ctrl) {
                    $this->state->splitSel = 0;
                } else {
                    $this->state->splitCol = 0;
                }
                break;
            case Key::End:
                if ($e->ctrl) {
                    $this->state->splitSel = $lastRow;
                } else {
                    $this->state->splitCol = $lastCol;
                }
                break;
            case Key::PageUp:
                $this->state->splitSel = max(0, $this->state->splitSel - $step);
                break;
            case Key::PageDown:
                $this->state->splitSel = min($lastRow, $this->state->splitSel + $step);
                break;
            case Key::Enter:
                $this->editSplitCell();
                break;
            case Key::Delete:
                // Del blanks the field: with fixed fields, "delete" is blank.
                if ($rows > 0 && !$this->blockSplitIfReadOnly()) {
                    $this->splitTable?->set($this->state->splitSel, $this->state->splitCol, '');
                    $this->publishSplit();
                }
                break;
            case Key::Escape:
                $this->closeSplit();
                break;
            case Key::Char:
                if ($e->ctrl || $e->alt) {
                    break;
                }
                switch ($e->char) {
                    case 'e':
                        $this->editSplitCell();
                        break;
                    case 'a':
                        $this->addSplitRow($rows === 0 ? 0 : $this->state->splitSel + 1);
                        break;
                    case 'A':
                        $this->addSplitRow($this->state->splitSel);
                        break;
                    case 'd':
                        if ($rows > 0 && !$this->blockSplitIfReadOnly()) {
                            $this->splitTable?->deleteRow($this->state->splitSel);
                            $this->publishSplit();
                        }
                        break;
                    case 'c':
                        if ($this->splitTable !== null) {
                            $this->openSplitDefEditor($this->splitTable->spec()->toText($this->splitTitle()));
                        }
                        break;
                    default:
                        break;
                }
                break;
            default:
                break;
        }
    }

    /** Insert a blank row so it becomes row $at, and start editing its first field. */
    private function addSplitRow(int $at): void
    {
        if ($this->splitTable === null || $this->blockSplitIfReadOnly()) {
            return;
        }
        $this->splitTable->insertRow($at);
        $this->state->splitSel = $at;
        $this->state->splitCol = 0;
        $this->publishSplit();
        $this->editSplitCell();
    }

    private function editSplitCell(): void
    {
        if ($this->state->splitCells === []) {
            $this->state->splitNotice = 'No rows yet — a adds one';
            return;
        }
        if ($this->blockSplitIfReadOnly()) {
            return;
        }
        $editor = new LineEditor();
        $editor->setText($this->state->splitCells[$this->state->splitSel][$this->state->splitCol] ?? '');
        $this->state->splitEditor = $editor;
        $this->state->splitEdit = true;
    }

    /** The field editor is one line: Enter keeps the value, Esc drops it. */
    private function handleSplitEditKey(KeyEvent $e): void
    {
        $editor = $this->state->splitEditor;
        if ($editor === null) {
            $this->state->splitEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Enter:
                $this->splitTable?->set($this->state->splitSel, $this->state->splitCol, $editor->text());
                $this->state->splitEdit = false;
                $this->state->splitEditor = null;
                $this->publishSplit();
                return;
            case Key::Escape:
                $this->state->splitEdit = false;
                $this->state->splitEditor = null;
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
    }

    /** Open the framed definition editor on $text (§10a). */
    private function openSplitDefEditor(string $text): void
    {
        $editor = new LineEditor();
        $editor->setText($text);
        $this->state->splitDefEditor = $editor;
        $this->state->splitDefTitle = 'Table view: ' . $this->splitTitle();
        $this->state->splitDefEdit = true;
    }

    /**
     * The definition editor follows the edit-window convention: Enter is a new
     * line, Ctrl+S saves, Esc cancels. A definition that does not read leaves
     * the editor open with the reason on the status line.
     */
    private function handleSplitDefKey(KeyEvent $e): void
    {
        $editor = $this->state->splitDefEditor;
        if ($editor === null) {
            $this->state->splitDefEdit = false;
            return;
        }
        switch ($e->key) {
            case Key::Escape:
                $this->state->splitDefEdit = false;
                $this->state->splitDefEditor = null;
                return;
            case Key::Enter:
                $editor->insertNewline();
                return;
            case Key::Char:
                if ($e->ctrl && !$e->alt && strtolower($e->char) === 's') {
                    $this->saveSplitDef($editor->text());
                    return;
                }
                break;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: true);
    }

    private function saveSplitDef(string $text): void
    {
        $spec = SplitSpec::parse($text);
        if (is_string($spec)) {
            $this->setError('Table view definition: ' . $spec);
            return;
        }
        $this->saveSplitSpec($this->state->splitColumn, $spec);
        $this->state->splitDefEdit = false;
        $this->state->splitDefEditor = null;
        $this->state->statusLine = 'Table view definition saved · ' . $this->splitTitle();
        $this->state->statusIsError = false;
        // Re-split what the table holds now, edits and all, or the cell itself
        // when this is the first definition.
        $value = $this->splitTable?->join() ?? $this->splitStart;
        $sel = $this->state->splitSel;
        $col = $this->state->splitCol;
        $wasOpen = $this->splitTable !== null;
        $this->openSplitTable($spec, $value);
        if ($wasOpen) {
            $this->state->splitSel = $sel;
            $this->state->splitCol = $col;
            $this->publishSplit();
        }
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
        $col = $this->state->detailColumns()[$this->state->detailFieldIdx] ?? null;
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
        // A fresh open is always at the top level, whatever was open before.
        $this->jsonStack = [];
        $this->state->jsonPath = [];
        $this->state->jsonNotice = '';
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
        // Closing from inside an embedded document still means "done with this
        // field", so every open level folds back into the one above it first —
        // otherwise an edit made two levels down would be dropped by the very
        // action the user takes to keep it (§10).
        while ($this->jsonStack !== []) {
            $this->jsonAscend();
        }
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
        $this->jsonStack = [];
        $this->state->jsonPath = [];
        $this->state->jsonNotice = '';
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
        // A notice explains the key that was just pressed, so it lives exactly
        // until the next one.
        $this->state->jsonNotice = '';
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
                // Innermost window first: inside an embedded document, Esc comes
                // back up one level rather than closing the whole viewer (§10).
                if ($this->jsonStack !== []) {
                    $this->jsonAscend();
                } else {
                    $this->closeJson();
                }
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
                    case 'j':
                    case 'J':
                        // Open a JSON document held *inside* a string leaf (§10).
                        $this->jsonDescend();
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

    /**
     * `j`/`J` inside the viewer: a string leaf whose text is itself a JSON
     * document opens as its own tree (§10).
     *
     * A blob inside a blob is stored as an escaped string, so the tree can only
     * show it as one long unreadable line — which is the whole reason this key
     * exists. Only a **string** leaf qualifies: a container is already open in
     * front of the user, and a number or a bool has nothing inside it.
     */
    private function jsonDescend(): void
    {
        $row = $this->jsonRows[$this->state->jsonSel] ?? null;
        if ($row === null || $this->jsonTree === null) {
            return;
        }
        $node = $row['node'];
        if ($node->isContainer()) {
            $this->state->jsonNotice = 'That is already an open ' . $node->type
                . ' — j opens a document held inside a string.';
            return;
        }
        if ($node->type !== JsonNode::STRING || !is_string($node->scalar)) {
            $this->state->jsonNotice = 'Only a string can hold an embedded document.';
            return;
        }
        $child = JsonTree::parse($node->scalar);
        if ($child === null) {
            // Same gate as the cell level: a bare scalar that happens to parse
            // (`42`, `"x"`) is not a document and must not open a viewer (§10).
            $this->state->jsonNotice = trim($node->scalar) === ''
                ? 'Empty string — nothing to open.'
                : 'Not an embedded JSON document — nothing to open here.';
            return;
        }
        $this->jsonStack[] = [
            'tree' => $this->jsonTree,
            'node' => $node,
            'original' => $node->scalar,
            'sel' => $this->state->jsonSel,
            'offset' => $this->state->jsonOffset,
        ];
        $this->state->jsonPath[] = $row['label'] !== '' ? $row['label'] : '(value)';
        $this->jsonTree = $child;
        $this->state->jsonSel = 0;
        $this->state->jsonOffset = 0;
        $this->publishJson();
    }

    /**
     * Come back up one level: the nested document is re-encoded into the string
     * leaf it came from, and the viewer returns to the parent tree at the row the
     * user descended from.
     *
     * **It writes back only when the content actually changed.** `encode()`
     * normalizes — whitespace gone, unescaped slashes — so re-encoding a document
     * nobody edited would rewrite the cell for the crime of having been looked
     * at, and that rewrite would then be offered as an UPDATE by
     * {@see closeJson()}. The comparison is against the *parsed* original for the
     * same reason: a pretty-printed blob and its compact form are the same
     * document.
     */
    private function jsonAscend(): void
    {
        $frame = array_pop($this->jsonStack);
        if ($frame === null) {
            return;
        }
        $child = $this->jsonTree;
        if ($child !== null) {
            $encoded = $child->encode();
            $originalNorm = JsonTree::parse($frame['original'])?->encode() ?? $frame['original'];
            if ($encoded !== $originalNorm) {
                $frame['tree']->setScalar($frame['node'], $encoded);
            }
        }
        $this->jsonTree = $frame['tree'];
        array_pop($this->state->jsonPath);
        $this->publishJson();
        $this->state->jsonSel = $this->clampIndex($frame['sel'], count($this->state->jsonNodes));
        $this->state->jsonOffset = $frame['offset'];
    }

    /** The JsonNode under the current selection, or null. */
    private function selectedJsonNode(): ?\Sqli\Json\JsonNode
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
        if ($row === null || $row['container'] || $this->blockJsonIfReadOnly()) {
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
        if ($this->jsonTree === null || $this->blockJsonIfReadOnly()) {
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
        if ($this->jsonTree === null || $this->blockJsonIfReadOnly()) {
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
        // Field specials first (Enter confirms — the node value is single-line,
        // §10 — and Esc abandons), then shared clipboard + base editing so the
        // JSON node box matches the CLI: a single-line field.
        switch ($e->key) {
            case Key::Enter:
                $this->confirmJsonNodeEdit();
                return;
            case Key::Escape:
                // Cancelling the key prompt for a new member simply abandons the
                // add (no node was created yet); a value edit just closes.
                $this->state->jsonEdit = false;
                $this->state->jsonEditor = null;
                $this->state->jsonEditKind = '';
                $this->jsonAddContainer = null;
                return;
        }
        if ($this->editorClipboardKey($e, $editor)) {
            return;
        }
        $this->applyEditingKey($editor, $e, multiline: false);
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
        if ($this->blockIfMultiSource('build an UPDATE')) {
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
            $set[] = '`' . $col . '` = ' . ($val === null ? 'NULL' : $this->sqlQuote($val));
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
        // Line breaks are escaped rather than embedded literally. A generated
        // statement has to survive a round trip through the CLI (§9: a cell edit
        // puts its UPDATE there to run or cancel), and the CLI is line-based —
        // submitting joins its lines with a space. A value with real newlines in it
        // would therefore reach the database with every break turned into a space.
        // Escaped, the statement stays one line: it displays and edits normally on
        // the CLI, and MySQL turns `\n` back into a newline on the way in.
        //
        // Order matters: backslashes are doubled FIRST, so the `\n`/`\r` we add
        // afterwards stay escape sequences instead of being doubled into literals.
        return "'" . str_replace(
            ['\\', "'", "\n", "\r"],
            ['\\\\', "''", '\\n', '\\r'],
            $v,
        ) . "'";
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
            $this->rerunUnsaved = $this->state->layoutUnsaved;
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
                    // This statement suspended a batch; run it, then pick the
                    // queue back up where it left off (§5).
                    $this->batchStop = '';
                    $this->execute(
                        (string) $q['sql'],
                        (bool) $q['vertical'],
                        confirmedDangerous: true,
                        raw: (bool) ($q['raw'] ?? false),
                    );
                    if ($this->continueBatch()) {
                        $this->runBatch();
                    }
                }
            } elseif ($key === 'n' || $key === 'c' || $key === 'esc') {
                $this->closeConfirm();
                $this->pendingDangerous = null;
                $this->cancelBatch();
            }
            return;
        }

        if ($kind === 'grid_delete') {
            if ($key === 'y') {
                $this->closeConfirm();
                $this->stageDelete();
            } elseif ($key === 'n' || $key === 'c' || $key === 'esc') {
                $this->closeConfirm();
                $this->pendingDelete = null;
                // Outside mark mode the mark was made by Del itself, so cancelling
                // takes it back. In mark mode the ticks are the user's own work and
                // survive, ready to be corrected (§22).
                if (!$this->state->markMode) {
                    $this->state->deleteMarks = [];
                }
                $this->state->statusLine = 'Delete cancelled';
                $this->state->statusIsError = false;
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
        $this->state->lastTyped = '';
        $this->state->currentTable = '';
        $this->state->detail = false;
        $this->state->vertical = false;
        $this->state->selectedRow = 0;
        $this->state->selectedCol = 0;
        $this->state->clearRange();
        $this->state->rowOffset = 0;
        $this->state->colOffset = 0;
        $this->state->sortCol = null;
        $this->state->applySort();
        $this->state->pending = [];
        $this->state->deleteMarks = [];
        $this->state->markMode = false;
        $this->state->focus = State::FOCUS_CLI;
        $this->state->statusLine = 'Data view cleared';
        $this->state->statusIsError = false;
        $this->state->autoLimitedAt = 0;
        // `clear` empties *this* view only, so its tab goes back to reading as
        // unused while the other four keep whatever they are holding (§20).
        $this->refreshTabLabel();
        $this->input->clear();
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
        $v = array_key_exists($col, $this->state->pending[$rawIdx] ?? [])
            ? $this->state->pending[$rawIdx][$col]
            : ($this->state->result->rows[$rawIdx][$col] ?? null);
        return $v === null ? '' : (string) $v;
    }

    /**
     * Whether the cell is SQL NULL, reading an in-flight edit first exactly as
     * {@see cellValueAt()} does — an edit that set a value is not NULL any more.
     */
    private function cellIsNull(int $rawIdx, string $col): bool
    {
        $pending = $this->state->pending[$rawIdx] ?? [];
        if (array_key_exists($col, $pending)) {
            return $pending[$col] === null;
        }
        return ($this->state->result->rows[$rawIdx][$col] ?? null) === null;
    }

    /**
     * Apply column $col's display format to a raw value (§6, backlog S). Returns
     * the value unchanged when the column carries no format or the value is empty
     * — the raw value stays the source of truth; only how it's shown changes.
     */
    private function formatDisplay(string $col, string $raw): string
    {
        $fmt = ColumnFormats::get($this->state->formatOf($col));
        return $fmt === null || $raw === '' ? $raw : $fmt->display($raw);
    }

    /** The displayed (format-applied) value of a cell, for copy/inspection (§6/§19). */
    private function displayValueAt(int $rawIdx, string $col): string
    {
        return $this->formatDisplay($col, $this->cellValueAt($rawIdx, $col));
    }

    /**
     * A prominent, transient notice on the top line (§19) plus the status line
     * for good measure — the confirmation style the copy/export family shares.
     */
    private function notifyTop(string $msg, bool $isError = false): void
    {
        $this->state->flashMessage = $msg;
        $this->state->flashUntil = microtime(true) + self::FLASH_SECONDS;
        $this->state->statusLine = $msg;
        $this->state->statusIsError = $isError;
    }

    /**
     * Put the failed statement and its error on the clipboard, in full.
     *
     * Both parts go, in the order they are shown, because an error without the
     * SQL that provoked it is half a bug report — and the SQL is what makes it
     * searchable. Untruncated: what the screen had to cut is exactly what the
     * clipboard is for.
     */
    private function copyErrorToClipboard(): void
    {
        $result = $this->state->result;
        if ($result === null || !$result->hasError()) {
            return;
        }
        $sql = $this->state->lastSql;
        $text = ($sql !== '' ? 'input: ' . $sql . PHP_EOL : '') . 'ERROR: ' . (string) $result->error;
        $this->copyToClipboard($text, 'error');
    }

    /** Push $data to the system clipboard via OSC 52 and confirm to the user. */
    private function copyToClipboard(string $data, string $what): void
    {
        // OSC 52 paints nothing, so no Screen::invalidate() is needed.
        $this->terminal->write(Ansi::clipboardSet($data));
        $this->notifyTop('Copied ' . $what . ' to clipboard');
    }

    /**
     * Export the arranged data view to a timestamped CSV in the cwd (§19, #4).
     *
     * "As arranged" is the whole point of the feature: the file mirrors the
     * screen — {@see State::visibleColumnNames()} gives column order with hidden
     * columns dropped, {@see State::displayOrder()} gives the active sort, and
     * {@see displayValueAt()} applies each column's display format, so a column
     * shown as date/time exports as date/time rather than as an epoch. It also
     * picks up an in-flight cell edit, because `cellValueAt()` reads `pending`
     * first — you export what you are looking at.
     *
     * Rows are the current result set, so a query that was auto-LIMITed exports
     * the capped set; that is what "the table contents" means on screen.
     *
     * NOTE: {@see handleDataKey()} already returns early when there is no
     * result, so the first guard below cannot fire from Ctrl+Alt+O today. It is
     * kept because it is the correct behaviour if export is ever also offered
     * from the system menu, where no result may be loaded.
     */
    private function exportCsv(): void
    {
        $s = $this->state;
        if (!$s->hasResult()) {
            $this->notifyTop('Nothing to export — run a query first.', true);
            return;
        }
        $cols = $s->visibleColumnNames();
        if ($cols === []) {
            $this->notifyTop('Nothing to export — every column is hidden.', true);
            return;
        }
        $dir = getcwd();
        if ($dir === false) {
            $this->notifyTop('Cannot export: the working directory is unavailable.', true);
            return;
        }

        $rows = [];
        foreach ($s->displayOrder() as $rawIdx) {
            $row = [];
            foreach ($cols as $col) {
                // A real NULL goes to the writer as null, so it lands as a bare
                // NULL rather than as the empty string `cellValueAt()` folds it
                // to — which made it indistinguishable from `''` in the file.
                $row[] = $this->cellIsNull($rawIdx, $col) ? null : $this->displayValueAt($rawIdx, $col);
            }
            $rows[] = $row;
        }

        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR
            . CsvExport::fileName($s->currentDatabase, $s->currentTable);
        // The file gets the real table names — `templates.id`, not `1.id`. The
        // numbers only mean anything beside the header chip that defines them.
        $header = ResultColumns::qualified($cols, $s->result?->sources ?? []);
        // A wide grid can take a moment to render and write; say so first.
        $reason = $this->withBusy('Exporting ' . count($rows) . ' rows to CSV …',
            static fn (): ?string => CsvExport::write($path, $header, $rows));

        if ($reason !== null) {
            $this->notifyTop('Export failed: ' . $reason, true);
            my::lineout('FILE:FILE', 'csv export failed', $path, $reason);
            return;
        }
        $this->notifyTop('Exported ' . count($rows) . ' rows × ' . count($cols) . ' cols → ' . $path);
        my::lineout('FILE:FILE', 'csv export', $path, (string) count($rows), (string) count($cols));
    }

    /** Ctrl+C in the grid: the range when there is one, else the cell (§19). */
    private function copyRangeOrCell(): void
    {
        $b = $this->state->rangeBounds();
        // A range folded back onto one data cell copies like that cell: the
        // bare value, not a CSV-quoted one.
        if ($b === null || ($b[0] === $b[1] && $b[2] === $b[3] && $b[0] >= 0)) {
            $this->copyCell();
            return;
        }
        $this->copyRange($b);
    }

    /**
     * Copy a Shift+arrow range as CSV, one line per row (§19).
     *
     * Values are what the grid shows (display formats applied) and are quoted by
     * {@see CsvExport::line()}, so the clipboard and a Ctrl+Alt+O file agree; a
     * NULL is the bare word NULL. A range that reaches the header row starts with
     * the field names, named the way the export names them.
     *
     * @param array{0:int,1:int,2:int,3:int} $b rangeBounds()
     */
    private function copyRange(array $b): void
    {
        $s = $this->state;
        if (!$s->hasResult()) {
            return;
        }
        $cols = array_slice($s->visibleColumnNames(), $b[2], $b[3] - $b[2] + 1);
        if ($cols === []) {
            return;
        }
        $order = $s->displayOrder();
        $text = '';
        $rows = 0;
        $header = false;
        for ($r = $b[0]; $r <= $b[1]; $r++) {
            if ($r < 0) {
                $text .= CsvExport::line(ResultColumns::qualified($cols, $s->result?->sources ?? []));
                $header = true;
                continue;
            }
            $rawIdx = $order[$r] ?? null;
            if ($rawIdx === null) {
                continue;
            }
            $text .= CsvExport::line(array_map(
                fn (string $c): ?string => $this->cellIsNull($rawIdx, $c) ? null : $this->displayValueAt($rawIdx, $c),
                $cols,
            ));
            $rows++;
        }
        $what = $rows . ' row' . ($rows === 1 ? '' : 's') . ' × ' . count($cols) . ' col' . (count($cols) === 1 ? '' : 's')
            . ($header ? ' + header' : '') . ' as CSV';
        $this->copyToClipboard($text, $what);
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
        $this->copyToClipboard($this->displayValueAt($rawIdx, $col), "cell '" . $this->qualifiedField($col) . "'");
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
        $vals = array_map(fn (string $c): string => $this->displayValueAt($rawIdx, $c), $cols);
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
            $vals[] = $this->displayValueAt($rawIdx, $name);
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
        $this->input->clearSelection(); // a completion insert supersedes any selection (§4)
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
        // A single unambiguous table/field/macro candidate is inserted
        // straight onto the line — no popup to dismiss (§4). Other contexts
        // still open it.
        if (count($res->items) === 1
            && in_array($res->title, ['Tables', 'Columns', 'Databases', 'Procedures', Completion::MACROS], true)
        ) {
            $this->applyCompletion($res); // seeds items/start/idx for insertCompletion()
            $this->insertCompletion();    // inserts the lone item; leaves completion off
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
            $this->state->completionDetails = [];
            $this->state->completionIdx = 0;
            $this->state->completionFilter = $this->currentToken($this->state->completionStart);
            return;
        }
        $this->applyCompletion($res);
    }

    private function analyzeCompletion(): ?CompletionResult
    {
        $editor = $this->input;
        return $this->completion->analyze(
            $editor->text(),
            $editor->cursor(),
            fn (): array => $this->schema->tables(),
            fn (string $t): array => $this->schema->columns($t),
            fn (): array => $this->schema->databases(),
            fn (): array => $this->schema->procedures(),
            fn (): array => $this->macros->all(),
        );
    }

    private function applyCompletion(CompletionResult $res): void
    {
        $this->state->completionItems = $res->items;
        $this->state->completionDetails = $res->details;
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
        $editor = $this->input;
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
            // While the list is up, Home/End jump to its first and last item
            // instead of moving the CLI caret.
            case Key::Home:
                $this->state->completionIdx = 0;
                return true;
            case Key::End:
                $this->state->completionIdx = max(0, $n - 1);
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
                if ($e->ctrl || $e->alt || !$this->extendsFilter($e->char)) {
                    return false;
                }
                $this->input->insert($e->char);
                $this->resetHistoryNav();
                $this->refreshCompletion();
                return true;
            case Key::Backspace:
                // Backspace shortens the filter while there's still a token to
                // trim — even down to an empty box, which refills as soon as a
                // matching char is typed. Once it would reach before the token
                // start, let the popup close and the keystroke fall through.
                if ($e->ctrl || $this->input->cursor() <= $this->state->completionStart) {
                    return false;
                }
                $this->input->backspace();
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

    /**
     * Whether typing $c should narrow the open popup rather than close it.
     *
     * Word characters everywhere, plus `-` and `.` while the list is macro
     * names (§23) — those are legal in a name, and without this `?fl-` would
     * dismiss the popup on the hyphen, one character into the very name it was
     * offering.
     */
    private function extendsFilter(string $c): bool
    {
        if ($this->isWordChar($c)) {
            return true;
        }
        return $this->state->completionTitle === Completion::MACROS
            && ($c === '-' || $c === '.');
    }

    private function insertCompletion(): void
    {
        $item = $this->state->completionItems[$this->state->completionIdx] ?? null;
        $this->state->completion = false;
        if ($item === null) {
            return;
        }
        $editor = $this->input;
        $text = $editor->text();
        $start = $this->state->completionStart;
        $cursor = $editor->cursor();
        if ($start < 0 || $start > $cursor) {
            return; // buffer changed under us; skip
        }
        $tail = mb_substr($text, $cursor);

        // A `?name` reference has no closing delimiter to add — the name ends
        // where the name characters do (§23). What still needs handling is Tab
        // pressed in the *middle* of one, `?na▏me`, to swap the name: the rest
        // of the old name is taken with it, or it would strand itself on the end
        // of the new one as `?newme`.
        $isMacro = $this->state->completionTitle === Completion::MACROS;
        if ($isMacro && preg_match('/^[A-Za-z0-9_.\-]+/u', $tail, $m) === 1) {
            $tail = mb_substr($tail, mb_strlen($m[0]));
        }
        // A completed macro name is followed by a space, cursor after it, so
        // the arguments (or whatever comes next) are typed straight away:
        // `?ga` Tab → `?ganl ▏`. A macro with no arguments is unharmed — the
        // name still ends at the space. When the line already has whitespace
        // there, the cursor steps over it rather than doubling it.
        $gap = '';
        $step = 0;
        if ($isMacro) {
            if (preg_match('/^\s/u', $tail) === 1) {
                $step = 1;
            } else {
                $gap = ' ';
            }
        }
        $new = mb_substr($text, 0, $start) . $item . $gap . $tail;
        $editor->setText($new, $start + mb_strlen($item) + mb_strlen($gap) + $step);
        $this->resetHistoryNav();
    }

    // --- query submission --------------------------------------------------

    private function onEnter(KeyEvent $e): void
    {
        $this->input->clearSelection(); // submitting/continuing supersedes any selection (§4)
        $active = rtrim($this->input->text());

        // Enter on the CLI only ever submits — an empty buffer has nothing to
        // submit, so it does nothing. (The detail view is now toggled from DATA
        // focus with `i`; Enter no longer changes the data view.)
        if ($active === '' && count($this->input->lines()) === 1) {
            return;
        }

        // **Alt+Enter always continues** (§4/§5): another line, whatever the
        // buffer ends with. Now that a submission can hold several statements,
        // a trailing `;` is no longer a reliable signal that the user is
        // finished — it is just as likely to be the end of statement one of
        // four — so there has to be a way to say "not yet" that the terminator
        // cannot override. Quit words are skipped too: the key means "give me
        // another line", with no exceptions to remember.
        //
        // Alt, not Shift, for a reason worth not rediscovering: under WSL in
        // Windows Terminal, Shift+Enter and Ctrl+Enter are both byte-identical
        // to Enter (0x0d). Telling them apart needs xterm `modifyOtherKeys`,
        // which was tried and reverted. `$e->shift` is honoured anyway, at no
        // cost, for terminals that do report it.
        if ($e->alt || $e->shift) {
            $this->cliPushLine();
            $this->resetHistoryNav();
            return;
        }

        // Single-line CLI commands (optionally `;`-terminated): `exit`/`quit`
        // quit the app, `clear`/`cls` clear the data view. The pending-changes
        // guard runs first for both (§17/§18).
        if (count($this->input->lines()) === 1) {
            $cmd = strtolower(rtrim($active, "; \t"));
            if (in_array($cmd, self::QUIT_WORDS, true)) {
                $this->requestQuit();
                return;
            }
            if ($cmd === 'clear' || $cmd === 'cls') {
                $this->requestClear();
                return;
            }
        }

        // A `;`, a `\G` / `\N` suffix in any of its sigils, or a complete macro call
        // (§23) on the active line terminates and submits.
        // `\G` selects vertical output mode; `\N` sends the output to data view N
        // and switches to it (§20). The digits are terminators in their own right
        // for the same reason `\G` is — `select * from t \3` is a complete thought,
        // and having to add a `;` after the routing suffix would be noise. One is
        // accepted anyway, and stripped along with the suffix, because the muscle
        // memory that ends every statement with `;` is not worth fighting.
        //
        // The suffix arm runs **before** the plain `;`, or `… \G;` would strip
        // only the semicolon and send a literal `\G` on to the server.
        $terminator = null;
        $targetTab = null;
        $vertical = false;
        if (preg_match(self::terminatorPattern(), $active, $m)) {
            $terminator = $m[0];
            if ($m[1] === 'G' || $m[1] === 'g') {
                $vertical = true;
            } else {
                $targetTab = (int) $m[1] - 1;
            }
        } elseif (str_ends_with($active, ';')) {
            $terminator = ';';
        } elseif (!self::isRawSubmission($this->cliText()) && $this->macros->endsWithCall($active)) {
            // A macro call is a terminator in its own right (§23). `?who` is a
            // complete thought: the statement it stands for carries its own `;`
            // inside the value, and asking for a second one after the reference
            // is asking the user to punctuate a sentence they cannot see.
            //
            // `$terminator` stays a *stripping* instruction, so this arm sets
            // none — there is nothing on the end to remove. The check itself is
            // narrow enough to be safe (see {@see Macros::endsWithCall()}): the
            // name must be defined, must be the last thing on the line, and must
            // already have its arguments. Alt+Enter still means "another line".
            $sql = $this->cliText();
            if (trim($sql) !== '') {
                $this->submit($sql, false, null);
            }
            return;
        }

        if ($terminator !== null) {
            // Build the SQL without mutating the buffer, so Cancel can keep it.
            $sql = $this->stripTerminator($this->cliText(), $terminator);
            if ($sql !== '') {
                $this->submit($sql, $vertical, $targetTab);
            }
            return;
        }

        // No terminator: push this line up and continue on a fresh line below.
        $this->cliPushLine();
        $this->resetHistoryNav();
    }

    /**
     * A submission whose first non-blank character is `!` is sent as typed
     * (§5). `!` cannot begin a SQL statement, so nothing real is taken over.
     */
    public static function isRawSubmission(string $sql): bool
    {
        return str_starts_with(ltrim($sql), '!');
    }

    /** Typed text with a leading `!` taken off; anything else unchanged. */
    private static function withoutBang(string $sql): string
    {
        return self::isRawSubmission($sql) ? substr(ltrim($sql), 1) : $sql;
    }

    /**
     * The command keyword of text as the user typed it — past a leading `!`,
     * so a `!UPDATE` still counts as a write for history's `{safety}` tag and
     * for F5's refusal to re-fire it.
     */
    private static function typedKeyword(string $sql): string
    {
        return Splitter::commandKeyword(self::withoutBang($sql));
    }

    private function stripTerminator(string $combined, string $terminator): string
    {
        $s = rtrim($combined);
        if ($terminator === ';') {
            return rtrim(substr($s, 0, -1));
        }
        // Every other terminator is a `\G` / `\N` suffix in one of its sigils,
        // with or without its trailing `;`, so the pattern that recognised it
        // strips it too.
        return rtrim((string) preg_replace(self::terminatorPattern(), '', $s));
    }

    /**
     * The `\G` and `\1`…`\9` suffixes (§5, §20), with the sigil `\`, `]` or `[`
     * and an optional trailing `;` — so `\G` `]G` `[G` `\G;` and `\3` `]3` `[3`
     * `]3;` all terminate. Capture group 1 is the letter or the digit.
     *
     * The sigils are the three characters MySQL gives no meaning outside a
     * quoted string, so none of them needs a rule about what may stand before
     * it: `SELECT * FROM t]3` routes as surely as `t\G` does, and no query can
     * end that way by accident. `]` and `[` are the unshifted, menu-free keys
     * (2.39.15). `/` was a sigil until then, but it is also division
     * (`SELECT 100 /2` read as routing) and it opens the system menu, so typing
     * `/3` meant `//3` and a missed keystroke ran menu item 3.
     *
     * This is one of the places `TAB_COUNT` is interpolated into a
     * single-character context; see {@see State::TAB_COUNT} before raising it.
     */
    private static function terminatorPattern(): string
    {
        return '/[\\\\\][]([Gg1-' . State::TAB_COUNT . ']);?$/';
    }

    // --- scripting mode: -e and piped SQL (backlog #6) ------------------------

    /** Exit code for a statement the guard would have asked about (§5). */
    public const EXIT_REFUSED = 2;

    /**
     * Run $sql with no TUI and write every result set to $out as CSV. Returns
     * the process exit code: 0, 1 for an error (server, macro, output), or
     * {@see EXIT_REFUSED} when the guard stopped it.
     *
     * The path is the TUI's, minus the person. Macros expand before the split,
     * the splitter decides the statements, the normalizer rewrites them, and
     * read-only and {@see dangerCheck()} judge each one exactly as they would at
     * the CLI — including strict mode for multi-statement input. What changes is
     * the answer to a confirm: there is nobody to give it, so **anything that
     * would ask is refused**, and no flag overrides that. A PK-scoped
     * DELETE/UPDATE asks nothing in the TUI and so runs here too — `-e` is not
     * read-only.
     *
     * Every statement is judged **before any of them runs**. The TUI can stop a
     * batch half-way because the person is there to see where it stopped; a
     * script finding out from an exit code that statements 1 and 2 committed
     * before 3 was refused is the worst version of this.
     *
     * No auto-LIMIT: the 1000-row cap is there so the grid does not paint a
     * million rows, and a script asking for a million rows means it.
     *
     * @param resource $out where the CSV goes
     * @param resource $err where refusals, errors and --verbose notes go
     */
    /**
     * The env in force when nobody typed `-E` — `devel (default)` for a config
     * file's default env, `devel ($SQLI_ENV)` for the variable — or '' when the
     * env was typed or there is none. The header shows it: an env nobody typed
     * lays its settings under whatever the command line said, and that union was
     * invisible (a `-H prod --comment "PROD r/w"` run came up read-only because
     * the directory's default env was a read-only one).
     */
    private function untypedEnv(): string
    {
        $env = $this->config->envName();
        return match ($this->config->envSource()) {
            'config'    => $env . ' (default)',
            '$SQLI_ENV' => $env . ' ($SQLI_ENV)',
            default     => '',
        };
    }

    /**
     * Where read-only mode came from, as a suffix for the message that refuses
     * something — ` (from env devel, the config's default env)`, ` (from
     * …/sqli_config.json)`, ` (from --read-only)`. Empty when it is not on.
     */
    private function readOnlyWhy(): string
    {
        $origin = $this->config->originOf('read-only');
        if (!$this->state->readOnly || $origin === null) {
            return '';
        }
        if ($origin === 'command line') {
            return ' (from --read-only)';
        }
        if (str_starts_with($origin, 'env ')) {
            return ' (from ' . $origin . match ($this->config->envSource()) {
                'config'    => ", the config's default env",
                '$SQLI_ENV' => ', picked by $SQLI_ENV',
                default     => '',
            } . ')';
        }
        return ' (from ' . $origin . ')';
    }

    public function runScript(string $sql, $out, $err, bool $header = true, bool $verbose = false): int
    {
        $this->headless = true;
        $this->state->readOnly = $this->config->isFlag('read-only');

        // A leading `!` (§5, §24) works as it does on the CLI: the text is sent
        // as written — no macros, no rewrites — and the guards below still judge
        // every statement.
        $raw = self::isRawSubmission($sql);
        if ($raw) {
            $sql = self::withoutBang($sql);
        } else {
            $expansion = $this->macros->expand($sql);
            $problem = $expansion->problem();
            if ($problem !== null) {
                fwrite($err, 'sqli: ' . $problem . PHP_EOL);
                return 1;
            }
            $sql = $expansion->sql;
        }
        $asks = $raw ? [] : Macros::promptsIn($sql);
        if ($asks !== []) {
            fwrite($err, 'sqli: ' . implode(', ', array_map(static fn (string $n): string => '?{' . $n . '}', $asks))
                . ' asks for a value, and there is nobody to answer — give it as a macro argument instead' . PHP_EOL);
            return 1;
        }

        // Judge everything first (see above).
        $statements = [];
        foreach (Splitter::split($sql) as $stmt) {
            // A `\G` / `\N` suffix means something to the grid and nothing to CSV.
            $stmt = rtrim((string) preg_replace(self::terminatorPattern(), '', rtrim($stmt)));
            if (in_array(strtolower(rtrim($stmt, "; \t")), self::QUIT_WORDS, true)) {
                break; // `quit` at the end of a piped file, as mysql allows
            }
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
        }
        $total = count($statements);
        $prepared = [];
        foreach ($statements as $i => $stmt) {
            $where = $total > 1 ? 'statement ' . ($i + 1) . ' of ' . $total . ': ' : '';
            if (!$raw) {
                $stmt = $this->normalizer->normalize($stmt)->sql;
                $stmt = $this->normalizer->expandMixedStar($stmt, fn (string $t): array => $this->resolveColumns($t))->sql;
            }
            $first = Splitter::commandKeyword($stmt);
            if (in_array($first, self::MODIFYING, true)) {
                if ($this->state->readOnly) {
                    fwrite($err, 'sqli: refused: ' . $where . 'read-only mode — ' . $first . ' is blocked' . $this->readOnlyWhy() . PHP_EOL);
                    return self::EXIT_REFUSED;
                }
                $message = $this->dangerCheck($stmt, $first, $total > 1);
                if ($message !== null) {
                    fwrite($err, 'sqli: refused: ' . $where . self::withoutRunAnyway($message)
                        . '. Nothing was run: this needs a confirmation, and only the TUI can give one.' . PHP_EOL);
                    return self::EXIT_REFUSED;
                }
            }
            $prepared[] = $stmt;
        }

        $blocks = 0;
        foreach ($prepared as $i => $stmt) {
            $result = $this->database->query($stmt);
            $this->logWrite($stmt, $result);
            if ($result->hasError()) {
                $left = $total - $i - 1;
                fwrite($err, 'sqli: ' . ($total > 1 ? 'statement ' . ($i + 1) . ' of ' . $total . ': ' : '')
                    . 'ERROR ' . $result->error . ($left > 0 ? ' · ' . $left . ' not run' : '') . PHP_EOL);
                return 1;
            }
            if ($result->isSelect) {
                if (!self::writeResultCsv($out, $result, $header, $blocks === 0)) {
                    fwrite($err, 'sqli: could not write the output' . PHP_EOL);
                    return 1;
                }
                $blocks++;
            }
            if ($verbose) {
                fwrite($err, ($result->isSelect
                    ? $result->rowCount() . ' row(s) in set'
                    : 'Query OK, ' . $result->affectedRows . ' row(s) affected')
                    . sprintf(' (%.3f sec)', $result->elapsed) . PHP_EOL);
            }
        }
        return 0;
    }

    /**
     * One result set as a CSV block, for {@see runScript()}. Raw server values,
     * not display formats — a script wants the epoch, not the grid's rendering
     * of it. A result set after the first is preceded by one blank line.
     * Returns false when the stream refused a write.
     *
     * @param resource $out
     */
    public static function writeResultCsv($out, QueryResult $result, bool $header, bool $first): bool
    {
        $cols = $result->columns;
        $rows = (static function () use ($result, $cols): \Generator {
            foreach ($result->rows as $r) {
                $line = [];
                foreach ($cols as $c) {
                    $line[] = $r[$c] ?? null;
                }
                yield $line;
            }
        })();
        return ($first || @fwrite($out, "\n") !== false)
            && CsvExport::stream(
                $out,
                // Real table names in the file, as for Ctrl+Alt+O: a script
                // reading this CSV has no header chip to decode `1.id` against.
                $header ? ResultColumns::qualified($cols, $result->sources) : null,
                $rows,
            );
    }

    /** A confirm message as a sentence: the question on the end is for a person. */
    private static function withoutRunAnyway(string $message): string
    {
        return (string) preg_replace('/\.?\s*Run anyway\?$/', '', $message);
    }

    // --- data view tabs (§20) ----------------------------------------------

    /**
     * Put data view $tab on screen, parking the one that was there (§20).
     *
     * The whole switch is a property swap: the active view lives in State, so
     * after the restore every grid key, every mouse hit-test and the renderer
     * are already talking about the new view with no further plumbing.
     *
     * Safe to call from anywhere a key is handled, because {@see handleKey()}
     * only reaches the Alt+digit branch after every modal has had its chance to
     * consume the key — so a switch can never strand a half-finished cell edit
     * or an open popup on a tab you just left.
     */
    private function switchTab(int $tab): void
    {
        if ($tab < 0 || $tab >= State::TAB_COUNT || $tab === $this->state->activeTab) {
            return;
        }
        $this->tabViews[$this->state->activeTab] = $this->state->captureView();
        $this->state->restoreView($this->tabViews[$tab] ?? State::blankView());
        $this->state->activeTab = $tab;
        // A different result reshapes the whole grid (row count, gutter width,
        // column set), so nothing from the old view may survive as leftover
        // characters under the differential renderer.
        $this->screen->invalidate();
    }

    /**
     * Step $step views along and **wrap** — view 9 goes on to view 1, view 1
     * back to view 9 (§20).
     *
     * Empty views are stepped through like any other. Skipping them would make
     * the gesture's destination depend on history rather than on position, and
     * an empty view is where a query is *sent* — `\7` on the CLI is how one gets
     * used in the first place, and arriving there is how you decide to.
     */
    private function cycleTab(int $step): void
    {
        $count = State::TAB_COUNT;
        $this->switchTab((($this->state->activeTab + $step) % $count + $count) % $count);
    }

    /**
     * Re-label the active tab from what it is now showing (§20). An empty label
     * means "never used", which the tab strip draws differently — so a view that
     * ran a query and got no table name still reads as used.
     */
    private function refreshTabLabel(): void
    {
        $label = $this->state->currentTable;
        if ($label === '' && $this->state->result !== null) {
            $label = '·';
        }
        $this->state->tabLabels[$this->state->activeTab] = $label;
    }

    /**
     * Submit whatever is on the CLI. If it is the in-flight edit's UPDATE, run it
     * specially so the result grid survives and the value folds in (§9). Anything
     * else means the user moved on, so the un-run edit is reverted first.
     *
     * $targetTab is the `\1`…`\9` suffix (§20): the whole submission — every
     * statement of a batch, and any dangerous-query re-run it suspends into —
     * lands in that view, and the view is switched to before anything runs so
     * the busy notice, the result and any error all appear where you sent them.
     */
    private function submit(string $sql, bool $vertical, ?int $targetTab = null): void
    {
        if ($this->isInFlightSubmit($sql)) {
            $this->runAutoUpdate();
            return;
        }
        if ($this->isInFlightDelete($sql)) {
            $this->runAutoDelete();
            return;
        }
        // Running some other statement abandons any un-submitted cell edit. This
        // has to happen BEFORE the tab switch below: the edit being abandoned
        // belongs to the view we are leaving, not to the one we are going to.
        $this->revertInFlight();
        // Same for an un-run delete: the user typed over it, so the marks it was
        // built from are no longer answering any question (§22).
        $this->autoDelete = null;
        $this->state->deleteMarks = [];
        // The user typing something new outranks a statement waiting to go out
        // again by itself: theirs is the newer request, and two statements racing
        // onto one connection is not a thing anyone asked for. A fresh submission
        // also earns a fresh automatic attempt.
        $this->pendingResend = null;
        $this->resendUsed = false;

        // Macros (§23) are expanded **here**, before the splitter, and that
        // ordering is the feature rather than an implementation detail: a macro
        // may hold a whole statement, or several, so `?cleanup` has to become
        // two statements the splitter can see and hand to the read-only check and
        // the dangerous-query guard one at a time. Expanding any later would send
        // them to the server in a single packet, past both.
        //
        // It is also what makes arguments work: `?ganl vegas` is one line in
        // which the word `vegas` belongs to the reference and not to the SQL, and
        // only a pass that runs before the splitter can take it *out* of the line
        // rather than leave it trailing after the statement it was an argument to.
        //
        // Everything downstream — normalization, the guards, the executed-SQL
        // history — therefore works on the expanded text, which is what actually
        // ran. Only the typed history keeps the `?name` form (see below), so
        // Up-arrow gives back a statement that re-reads the macro rather than
        // yesterday's value baked into it.
        $typed = $sql;
        // For F5: the view this submission paints remembers it as typed.
        $this->submissionTyped = $typed;

        // A leading `!` (§5) sends the text as typed: no macro expansion and
        // no `?{name}` prompts here, no normalizing or auto-LIMIT in execute().
        // Only the `!` itself comes off. The split still happens, because it
        // is what lets read-only and the dangerous-query guard see each
        // statement; the history keeps the `!` so Up-arrow and F5 stay raw.
        if (self::isRawSubmission($sql)) {
            $sql = self::withoutBang($sql);
            $this->submitTyped = $typed;
            $this->macroNote = '';
            $this->runSubmission($sql, $typed, $vertical, $targetTab, raw: true);
            return;
        }

        $expansion = $this->macros->expand($sql);
        $problem = $expansion->problem();
        if ($problem !== null) {
            // Nothing runs and the CLI is left exactly as typed: the fix is an
            // edit to the line that is still sitting there, not a retype.
            $this->state->statusLine = $problem;
            $this->state->statusIsError = true;
            return;
        }
        $sql = $expansion->sql;
        $this->submitTyped = $sql !== $typed ? $typed : null;
        $this->macroNote = $expansion->note();

        // `?{name}` (§23) is asked **after** expansion and **before** the
        // split, which is the same seam macros use and for the same two reasons:
        // a macro may carry the prompt, so it has to be expanded first for the
        // question to exist at all; and an answer may contain a `;`, so it has to
        // be in the text before the splitter runs or the second half would reach
        // the server in one packet with the first, past the guards.
        //
        // Everything from here to `runSubmission()` is therefore suspended, not
        // skipped: the popup takes the keyboard, and Enter on the last question
        // resumes exactly where this left off.
        $asks = Macros::promptsIn($sql);
        if ($asks !== []) {
            $this->pendingPrompt = [
                'sql' => $sql, 'typed' => $typed, 'vertical' => $vertical,
                'tab' => $targetTab, 'names' => $asks, 'answers' => [],
            ];
            $this->openNextPrompt();
            return;
        }

        $this->runSubmission($sql, $typed, $vertical, $targetTab);
    }

    /**
     * Run an expanded submission: fill in any prompt answers, split, and hand
     * the statements to the batch runner (§5).
     *
     * Split out of {@see submit()} so the `?{name}` popup has somewhere to
     * come back to. Everything above this in `submit()` is about deciding *what*
     * runs; everything here is about running it, and a suspended submission
     * needs only the second half.
     *
     * @param array<string,string> $answers what the user typed at each prompt
     */
    private function runSubmission(
        string $sql,
        string $typed,
        bool $vertical,
        ?int $targetTab,
        array $answers = [],
        bool $raw = false,
    ): void {
        // Called with or without answers so there is one path rather than two;
        // with none there is nothing to match and the text comes back as it went
        // in.
        $sql = Macros::fillPrompts($sql, $answers);

        // Split first, then run one statement at a time (§5). A single statement
        // is just a batch of one, so there is one path rather than two.
        $statements = Splitter::split($sql);
        if ($statements === []) {
            return; // nothing but comments
        }
        // Only now that there is something to run does `\N` move the screen —
        // `\3` after nothing but a comment should not silently change tabs (§20).
        if ($targetTab !== null) {
            $this->switchTab($targetTab);
        }
        $this->batchQueue    = $statements;
        $this->batchTotal    = count($statements);
        $this->batchDone     = 0;
        $this->batchVertical = $vertical;
        $this->batchRaw      = $raw;
        $this->batchStop     = '';
        if ($this->batchTotal > 1) {
            // One typed submit, one history entry: pushing each piece separately
            // would leave Up-arrow unable to reassemble what was actually typed.
            $this->pushHistory($typed);
        }
        $this->runBatch();
    }

    /**
     * Run queued statements until one of them stops the batch (§5).
     *
     * Stops are not all the same: a dangerous-query confirm *suspends* the batch
     * with its queue intact — {@see handleConfirmKey()} resumes it — while a
     * read-only refusal or a server error drops what is left. Running on past an
     * error would be the worst of both worlds, since the statements that follow
     * were written expecting the one that failed to have succeeded.
     */
    private function runBatch(): void
    {
        while ($this->batchQueue !== []) {
            $stmt = array_shift($this->batchQueue);
            $this->batchStop = '';
            $this->execute($stmt, $this->batchVertical, raw: $this->batchRaw);
            if (!$this->continueBatch()) {
                return;
            }
        }
        $this->finishBatch(false);
    }

    /**
     * Bookkeeping after one statement of a batch. Returns false when the batch
     * must not continue — already suspended, blocked, or failed.
     */
    private function continueBatch(): bool
    {
        if ($this->batchStop === 'confirm') {
            return false; // suspended with the queue intact; the answer resumes it
        }
        if ($this->batchStop === 'async') {
            return false; // suspended with the queue intact; the result resumes it
        }
        if ($this->batchStop === 'blocked') {
            $this->abandonBatch();
            return false;
        }
        if ($this->pendingResend !== null) {
            // Suspended with the queue intact, exactly as 'async' and 'confirm'
            // are: the statement that failed is going out again, and the ones
            // after it were written expecting it to have run. Counting it as
            // done or as a failure here would drop them.
            return false;
        }
        $this->batchDone++;
        if ($this->state->result !== null && $this->state->result->hasError()) {
            $this->finishBatch(true);
            return false;
        }
        return true;
    }

    /**
     * Close out a batch and summarise it on the status line. Single statements
     * are left to speak for themselves — their own status line is the better
     * message, and "1 statement run" tells nobody anything.
     */
    private function finishBatch(bool $failed): void
    {
        if ($this->batchTotal > 1) {
            if ($failed) {
                $left = count($this->batchQueue);
                $this->state->statusLine = 'Statement ' . $this->batchDone . ' of ' . $this->batchTotal
                    . ' — ' . $this->state->statusLine
                    . ($left > 0 ? ' · ' . $left . ' not run' : '');
                $this->state->statusIsError = true;
            } else {
                $this->state->statusLine = $this->batchTotal . ' statements run';
                $this->state->statusIsError = false;
            }
        }
        $this->resetBatch();
    }

    /**
     * Drop the rest of a batch after a statement was refused before it ran,
     * keeping the refusal's own status line and noting what did not run.
     */
    private function abandonBatch(): void
    {
        $left = count($this->batchQueue);
        if ($this->batchTotal > 1 && $left > 0) {
            $this->state->statusLine .= ' · ' . $left . ' statement(s) not run';
        }
        $this->resetBatch();
    }

    /** Drop the rest of a batch after the dangerous-query confirm was declined. */
    private function cancelBatch(): void
    {
        // The declined statement is already off the queue, so it counts too.
        $left = count($this->batchQueue) + 1;
        if ($this->batchTotal > 1) {
            $this->state->statusLine = 'Cancelled — ' . $left . ' of ' . $this->batchTotal
                . ' statement(s) not run';
            $this->state->statusIsError = false;
        }
        $this->resetBatch();
    }

    // --- a statement the connection died under (§2) --------------------------

    /** What is done with a statement whose connection died under it. */
    public const RESEND_AUTO      = 'auto';
    public const RESEND_UNSAFE    = 'unsafe';
    public const RESEND_EXHAUSTED = 'exhausted';

    /**
     * Which of the three, given what is known about the loss.
     *
     * The rule this encodes: **re-send anything that provably never reached the
     * server, and any read.** A read costs nothing to repeat. A write that never
     * left is the same statement the user would type again, so sending it for
     * them is exactly the work being saved.
     *
     * What is left is the one case that must not be automatic: a write the
     * server may have run before the socket died. It may have committed and been
     * unable to say so, and re-sending it would apply it twice — the one failure
     * mode here that costs more than the inconvenience it saves.
     *
     * `$alreadyTried` outranks both, because a re-send that is itself met by a
     * dead connection means the server is gone rather than the session, and a
     * loop a second wide helps nobody.
     *
     * Pure and static so all three claims are pinned without a server.
     */
    public static function resendDecision(bool $neverRan, bool $modifying, bool $alreadyTried): string
    {
        if ($alreadyTried) {
            return self::RESEND_EXHAUSTED;
        }
        return ($neverRan || !$modifying) ? self::RESEND_AUTO : self::RESEND_UNSAFE;
    }

    /**
     * What the user is told, for each of the three. Said next to the error
     * itself rather than instead of it: the error is what happened, this is what
     * is being done about it.
     */
    public static function lossNotice(string $decision): string
    {
        return match ($decision) {
            self::RESEND_AUTO      => 'connection lost — reconnecting and re-sending it …',
            self::RESEND_UNSAFE    => 'connection lost — not re-sent automatically, '
                                      . 'because the server may have run it already. Check, then run it again.',
            self::RESEND_EXHAUSTED => 'connection lost again — the server is not answering. '
                                      . 'Run it again when it is back.',
            default                => '',
        };
    }

    /**
     * Decide what happens to the statement that just lost the connection, and
     * set it up. Returns the decision, because the caller does two different
     * things with it: RESEND_AUTO means the screen is left alone entirely, and
     * the other two mean the error is shown with {@see lossNotice()} beside it.
     */
    private function scheduleResend(string $original, string $first, bool $vertical, bool $confirmed, bool $raw = false): string
    {
        $decision = self::resendDecision(
            $this->database->lostBeforeItRan(),
            in_array($first, self::MODIFYING, true),
            $this->resendUsed,
        );
        if ($decision === self::RESEND_AUTO) {
            // The typed form, not the normalized one: the re-send goes back
            // through the whole of execute(), so it re-normalizes, re-caps and
            // re-checks exactly as the first attempt did.
            $this->pendingResend = [
                'sql'       => $original,
                'vertical'  => $vertical,
                'confirmed' => $confirmed,
                'raw'       => $raw,
                'at'        => microtime(true) + self::RESEND_DELAY,
            ];
        }
        return $decision;
    }

    /**
     * Send the waiting statement once its moment has come. Called once a frame.
     *
     * The delay is waited out here rather than slept through, which is what
     * keeps the second honest: the frame loop goes on drawing, the header goes
     * on saying what is about to happen, and a keystroke still arrives.
     */
    private function maybeResend(): void
    {
        if ($this->pendingResend === null
            || $this->state->queryRunning
            || microtime(true) < $this->pendingResend['at']) {
            return;
        }
        $resend = $this->pendingResend;
        $this->pendingResend = null;
        // Before the statement goes out, not after: if this one dies too, the
        // decision above has to see that an attempt was already spent.
        $this->resendUsed = true;
        $this->resentStatement = true;
        // execute() reopens the connection itself, with the header saying so,
        // and re-runs every guard the first attempt faced.
        $this->execute($resend['sql'], $resend['vertical'], $resend['confirmed'], (bool) ($resend['raw'] ?? false));
        // The same resumption the async path uses: the rest of the submission
        // carries on from here, or waits for this statement to come back.
        if ($this->continueBatch()) {
            $this->runBatch();
        }
    }

    /**
     * Collect a finished async statement, if there is one. Called once a frame
     * (§21).
     *
     * This is the resumption point for a batch suspended by 'async', and the
     * *only* place an async result is applied — so the ordering here is the
     * ordering the rest of the app sees: the result becomes the screen, and only
     * then does the next statement start.
     */
    private function pumpQuery(): void
    {
        if (!$this->state->queryRunning || !$this->database->asyncReady()) {
            return;
        }

        // null means the answer is still arriving: a result set is taken across
        // frames rather than in one blocking read, so that a query which starts
        // replying at once and then streams keeps the clock counting, the
        // spinner turning and Esc live for the whole transfer.
        $result = $this->database->asyncReap();
        if ($result === null) {
            return;
        }

        $cancelled = $this->state->queryCancelling;
        $this->state->queryRunning    = false;
        $this->state->queryCancelling = false;

        // Split the wall clock the user feels into the two halves that have
        // different causes: `db` is the server plus the transfer, `paint` is this
        // app turning the rows into a frame. "The query got slower" is not
        // actionable until it says which half grew.
        $reaped = microtime(true);
        $this->applyResult($result, $cancelled);
        my::lineout(
            'FILE:FILE',
            'query done rows', (string) $result->rowCount(),
            'db', sprintf('%.3fs', $reaped - $this->state->queryStarted),
            'paint', sprintf('%.3fs', microtime(true) - $reaped),
            'cancelled', $cancelled ? 'yes' : 'no',
        );

        // A cancel stops the whole submission, not just the statement that was
        // running. This holds even when the query beat the kill and came back
        // clean: the user asked for the work to stop, and the statements after
        // it were written expecting a state this one may not have reached.
        if ($cancelled) {
            $this->abandonBatch();
            return;
        }

        $this->batchStop = '';
        if ($this->continueBatch()) {
            $this->runBatch();
        }
    }

    /**
     * Esc while a statement is running: ask the server to stop it (§21).
     *
     * The cancel is a *request*. What actually ends the statement is the server
     * noticing, and what the user sees is whatever {@see pumpQuery()} reaps next
     * — normally 1317, but a query that finished in the gap returns its rows and
     * is not an error. So this sets a flag and returns; it never fabricates an
     * outcome, and it never touches the result.
     *
     * A failed cancel leaves the query running and says so, because the honest
     * alternative — clearing the notice — would claim something that did not
     * happen and leave the loop waiting on a statement nothing is watching.
     */
    private function cancelQuery(): void
    {
        if (!$this->state->queryRunning || $this->state->queryCancelling) {
            return;
        }
        $problem = $this->database->cancelAsync();
        if ($problem !== null) {
            $this->state->statusLine = 'Could not cancel: ' . $problem;
            $this->state->statusIsError = true;
            my::lineout('FILE:FILE', 'cancel failed', $problem);
            return;
        }
        $this->state->queryCancelling = true;
    }

    private function resetBatch(): void
    {
        $this->submissionTyped = null;
        $this->batchQueue = [];
        $this->batchTotal = 0;
        $this->batchDone  = 0;
        $this->batchStop  = '';
    }

    private function execute(
        string $sql,
        bool $vertical = false,
        bool $confirmedDangerous = false,
        bool $raw = false,
    ): void {
        // Taken before anything can return early: a marker left lying around
        // would turn up behind some later, unrelated statement's answer.
        $resent = $this->resentStatement;
        $this->resentStatement = false;
        $rerunUnsaved = $this->rerunUnsaved;
        $this->rerunUnsaved = false;
        if ($sql === '') {
            return;
        }

        // Normalize input conveniences before anything else inspects the SQL
        // (§5): first inverted FROM…SELECT, then mixed-star expansion. The
        // normalized form is what runs; history keeps the *original* typed form
        // so Up-arrow restores what was typed.
        //
        // A `!` submission skips both: what was typed is what is sent.
        $original = $sql;
        $norm = $raw ? new NormalizeResult($sql, false, '') : $this->normalizer->normalize($sql);
        $sql = $norm->sql;
        $star = $raw
            ? new NormalizeResult($sql, false, '')
            : $this->normalizer->expandMixedStar($sql, fn (string $t): array => $this->resolveColumns($t));
        $sql = $star->sql;

        // The macro note is taken (and cleared) rather than read: it belongs
        // to the submission, so a batch that came out of one expansion says it
        // once, on the first statement, not on every one of them (§23).
        $tplNote = $this->macroNote;
        $this->macroNote = '';

        $notes = array_filter([$tplNote, $norm->changed ? $norm->note : '', $star->changed ? $star->note : '']);

        // The command this statement actually performs — read past any leading
        // comment and past a CTE list, because both used to hide the verb from
        // these guards (§5).
        $first = Splitter::commandKeyword($sql);
        if ($this->state->readOnly && in_array($first, self::MODIFYING, true)) {
            $this->state->statusLine = '[RO] Read-only mode — ' . $first . ' is blocked' . $this->readOnlyWhy();
            $this->state->statusIsError = true;
            $this->batchStop = 'blocked';
            return;
        }

        // Dangerous-query guard (§5): every data-modifying command needs explicit
        // confirmation before it touches the database. DELETE/UPDATE are nuanced
        // (only a broad statement prompts); all other modifying commands always
        // prompt. We hold the *original typed* form so the confirmed re-run
        // normalizes identically and history stays faithful.
        if (!$confirmedDangerous && in_array($first, self::MODIFYING, true)) {
            $message = $this->dangerCheck($sql, $first, $this->batchTotal > 1);
            if ($message !== null) {
                $this->pendingDangerous = ['sql' => $original, 'vertical' => $vertical, 'raw' => $raw];
                $this->openConfirm(
                    'dangerous_query',
                    $message,
                    [['Y', 'Run anyway'], ['N', 'Cancel']],
                );
                $this->batchStop = 'confirm';
                return;
            }
        }

        // Auto-cap row-returning queries at AUTO_LIMIT rows (§5). This mutates the
        // SQL actually sent to the DB (and thus the executed-SQL history via
        // Shift+Up/Down), but never the typed-form history — $original is pushed
        // untouched below, so plain Up/Down shows exactly what the user typed.
        $autoLimited = false;
        if (!$raw) {
            $sql = $this->applyAutoLimit($sql, $first, $autoLimited);
        }

        // Everything the second half needs, parked where the frame loop can find
        // it: on the async path the result arrives some number of frames from
        // now, and by then $sql, $notes and the rest are long out of scope.
        $this->queryContext = [
            'sql'      => $sql,
            'first'    => $first,
            'original' => $original,
            'notes'    => $notes,
            'vertical' => $vertical,
            'capped'   => $autoLimited,
            // Kept so a re-send can repeat the answer the user already gave to
            // the dangerous-query confirm: being asked twice about one DELETE
            // because the connection died is the friction this removes.
            'confirmed' => $confirmedDangerous,
            // Carried rather than read off the property at the far end: on the
            // async path applyResult() runs frames later, by which time a second
            // statement may already have taken the property for itself.
            'resent'    => $resent,
            // A `!` statement re-sends as one, too.
            'raw'       => $raw,
            // A mixed star (`SELECT id, *`) is a one-off look, not a column
            // list worth filing a layout under: the expansion spells out every
            // column, so each schema change would mint a fresh saved entry.
            'unsaved'   => $star->changed || $rerunUnsaved,
        ];

        // A connection the server dropped is reopened here, in front of the
        // user: the header has said SERVER DISCONNECTED since the loss, and this
        // is the moment it says RECONNECTING instead, on the same line, before
        // the statement goes anywhere.
        //
        // Nothing is replayed. The statement that met the dead socket was
        // reported as an error when it failed; this is the user asking for
        // something new, and it is the *new* one that goes out on a live
        // session. That is what keeps this compatible with §21's refusal to
        // retry an async statement — there is no retry here to be unsafe.
        if ($this->database->isLost()) {
            $this->state->linkState = State::LINK_RELINKING;
            $this->flushFrame();
            $problem = $this->database->reconnect();
            $this->state->linkState = $this->database->isLost()
                ? State::LINK_LOST
                : State::LINK_OK;
            if ($problem !== null) {
                $this->applyResult(QueryResult::fromError('could not reconnect: ' . $problem));
                return;
            }
        }

        // The cancellable path (§21): send the statement, suspend the batch, and
        // let the loop keep drawing. `startAsync()` only returns a result when
        // the statement could not be *sent*, which has nothing to wait for.
        if ($this->database->supportsCancel()) {
            $sendFailure = $this->database->startAsync($sql);
            if ($sendFailure === null) {
                $this->state->queryRunning    = true;
                $this->state->queryStarted    = microtime(true);
                $this->state->queryCancelling = false;
                $this->batchStop              = 'async';
                return;
            }
            $this->applyResult($sendFailure);
            return;
        }

        // No mysqli on this build, so the query blocks and nothing can be done
        // about it. Cover it with the inline busy notice — painted and flushed
        // before query() runs, then cleared after — exactly as sqli did before
        // the async path existed.
        $result = $this->withBusy(
            'Running query …',
            fn () => $this->database->query($sql),
        );
        $this->applyResult($result);
    }

    /**
     * The second half of {@see execute()}: take a finished statement's result
     * and make it the screen.
     *
     * Split out because on the cancellable path these two halves are separated
     * by an unbounded number of frames. Everything below reads
     * {@see $queryContext} rather than parameters for the same reason — the
     * caller that started the statement returned long ago.
     */
    private function applyResult(QueryResult $result, bool $cancelled = false): void
    {
        $sql      = (string) $this->queryContext['sql'];
        $first    = (string) $this->queryContext['first'];
        $original = (string) $this->queryContext['original'];
        $vertical = (bool) $this->queryContext['vertical'];
        $capped   = (bool) ($this->queryContext['capped'] ?? false);
        $confirmed = (bool) ($this->queryContext['confirmed'] ?? false);
        $resent    = (bool) ($this->queryContext['resent'] ?? false);
        /** @var list<string> $notes */
        $notes = $this->queryContext['notes'];

        // What is to be done about a dead connection is settled *here*, before
        // the result becomes the screen, because for one of the three answers
        // the screen must not change at all (§2).
        //
        // The statement is about to go back out and land in a few hundred
        // milliseconds. Applying this result first would blank the grid, drop
        // the sort, the hidden columns and the column layout, clear the marks
        // and reset the scroll — and then do the whole lot again when the rows
        // arrive. A loss handled for the user that costs them their place in the
        // data is not a loss handled for the user.
        $loss = (!$cancelled && $result->hasError() && $this->database->isLost())
            ? $this->scheduleResend($original, $first, $vertical, $confirmed, (bool) ($this->queryContext['raw'] ?? false))
            : '';
        if ($loss === self::RESEND_AUTO) {
            // Everything on screen stays as it was: the previous rows, the
            // previous status line, the previous everything. The red `!db` on
            // the header — armed by the frame loop the moment the link went bad
            // — is the only thing that changes, and the answer that arrives in a
            // moment carries RESENT_NOTE behind its row count.
            //
            // The CLI is still cleared and the text still pushed to history, on
            // the same terms as any other statement: the user pressed Enter and
            // their statement is on its way, which is exactly what the input
            // clearing means. The *executed*-SQL history is deliberately not
            // written here — one Enter, one entry, and the re-send writes it.
            if ($this->batchTotal <= 1) {
                $this->pushHistory($this->submitTyped ?? $original);
            }
            $this->input->clear();
            $this->resetHistoryNav();
            return;
        }

        $this->logWrite($sql, $result, $first);
        $this->state->result = $result;
        // A new result reshapes the whole grid (row count, gutter width, column
        // set). Force a full repaint so nothing from the previous result can
        // survive as leftover characters under the differential renderer.
        $this->screen->invalidate();
        $this->state->lastSql = $sql;
        // The refresh after a grid delete (§22) re-submits lastSql, the executed
        // form; the view keeps what the user typed rather than taking that.
        if ($this->submissionTyped !== null && $this->deleteNote === '') {
            $this->state->lastTyped = $this->submissionTyped;
        }
        $this->state->selectedRow = 0;
        $this->state->selectedCol = 0;
        $this->state->clearRange();
        $this->state->rowOffset = 0;
        $this->state->colOffset = 0;

        // A fresh result clears transient overlays and sort, and sets the
        // output mode (\G → vertical, §5).
        $this->state->detail = false;
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
        // Marks address raw row indices *into the result being replaced*, so they
        // cannot survive it - including the reload a delete itself triggers (§22).
        $this->state->deleteMarks = [];
        $this->state->markMode = false;
        $this->state->colFilter = null;
        $this->state->colFilterMiss = '';

        // Restore the saved per-table layout for this query (§6).
        $this->applySavedLayout($sql, (bool) ($this->queryContext['unsaved'] ?? false));
        $this->applyDdFormats();
        $this->state->applySort();
        // This view now has something in it, so its tab stops reading as empty (§20).
        $this->refreshTabLabel();

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
            $this->ddLoadAttempted = false; // let the new db attempt its own lazy load
            $this->ddDiffCache = [];
        }

        // The cap only counts as applied if the result actually came back holding
        // it (§5). Appending `LIMIT 1000` to a query that returns four rows
        // changed nothing worth telling the user, and announcing it there cost
        // them the one number they asked for — the row count. Zero means the
        // status line shows the plain row/col/time stats, which already say how
        // many rows came back; non-zero adds "auto-limited" as a qualifier on
        // that count rather than in place of it.
        $this->state->autoLimitedAt =
            ($capped && !$result->hasError() && $result->rowCount() >= self::AUTO_LIMIT)
                ? self::AUTO_LIMIT
                : 0;
        // A rewrite note owns the status line, and the count is exactly what it
        // used to displace (§5). So the note is a *prefix* to the stats rather
        // than a replacement for them — the line still says what the rewrite did,
        // and still ends with how many rows came back. Same string the renderer
        // draws when nothing claims the line, cap marker included.
        if (($notes !== [] || $resent) && !$result->hasError() && $result->isSelect) {
            $notes[] = Renderer::resultStats($result, $this->state->autoLimitedAt);
        }
        // Behind the count, never in front of it. `$resent` is enough on its own
        // to claim the line above, because a marker that displaced the row count
        // would be the second time this project traded the one number the user
        // asked for against a note about how it got here (§5).
        if ($resent && !$result->hasError()) {
            $notes[] = self::RESENT_NOTE;
        }

        if ($cancelled && str_starts_with((string) $result->error, '[' . self::INTERRUPTED . ']')) {
            // The user asked for this, so it is not an error and is not coloured
            // like one. Matched on the code rather than the text because the
            // message is the server's and is localised on some builds; anything
            // *else* that failed while a cancel was in flight is a real error and
            // falls through to say so.
            $this->state->statusLine = 'Cancelled after ' . number_format($result->elapsed, 1) . 's';
            $this->state->statusIsError = false;
        } elseif ($result->hasError()) {
            $this->state->statusLine = 'Error: ' . (string) $result->error;
            $this->state->statusIsError = true;
            // The two losses that reach this line are the two that need a
            // decision from the user: a write the server may already have run,
            // and a server that is not answering at all. Neither can be reduced
            // to a marker on the header — an UPDATE that may or may not have
            // committed is not something to find out about later — so these keep
            // every word they had. The silent third answer returned above (§2).
            if ($loss !== '') {
                $this->state->statusLine .= ' · ' . self::lossNotice($loss);
            }
        } else {
            // A result that came back is a connection that works: the next loss
            // gets its own automatic attempt.
            $this->resendUsed = false;
            // Surface the normalization note(s) when a rewrite happened (§5);
            // otherwise leave it blank so the row/col/time stats show.
            $this->state->statusLine = implode(' · ', $notes);
            $this->state->statusIsError = false;
        }

        // A delete re-runs the SELECT behind the grid, and the reload's own status
        // line would otherwise be the last word on screen - so the count the user
        // actually asked about leads, and is consumed on the way out (§22).
        if ($this->deleteNote !== '') {
            $this->state->statusLine = $this->deleteNote
                . ($this->state->statusIsError ? ' · ' . $this->state->statusLine : ' — view refreshed');
            $this->deleteNote = '';
        }

        // A multi-statement submit pushed the whole typed text once, up front;
        // pushing each piece here as well would bury it. The executed-SQL history
        // still records every statement — that is the one that should be granular.
        if ($this->batchTotal <= 1) {
            $this->pushHistory($this->submitTyped ?? $original);
        }
        $this->pushExecHistory($sql);
        $this->input->clear();
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

    /**
     * F5: take what was typed to paint the visible data view, put it back on the
     * CLI, and run it — exactly as though the user had typed it and pressed
     * Enter.
     *
     * Re-running is the point: what paints a data view is a query, so F5 is
     * "refresh this". It goes through {@see submit()} rather than
     * {@see execute()} so it is a typed statement in every way that matters —
     * macros expand, `?{name}` still asks, the read-only and dangerous-query
     * guards still apply, and the text lands in the typed history. The CLI
     * therefore clears when the result arrives, and Up-arrow gets it back.
     *
     * Focus is left where it was: F5 from the grid refreshes the grid and stays
     * there, F5 from the CLI stays on the CLI.
     *
     * Two shapes are **not** fired, and fall back to the old behaviour — text on
     * the CLI, focus there, nothing run:
     *
     *  - a statement that modifies data. It can only reach `lastTyped` by being
     *    typed at the CLI (a cell edit's UPDATE never goes through
     *    {@see applyResult()}), but re-firing an UPDATE on a keypress is not
     *    what F5 is for. It comes back with the `{safety}` prefix, as it does
     *    through Up-arrow history.
     *  - a multi-statement submission, which may hold a modifying statement
     *    among the SELECTs. Same rule as {@see looksReloadable()} after a grid
     *    delete: one statement, or nothing is replayed.
     *
     * Whatever is on the CLI is replaced either way. That includes an un-run
     * cell edit's UPDATE or a grid DELETE, which are abandoned exactly as typing
     * a new statement over them would abandon them ({@see submit()}).
     */
    private function recallViewSql(): void
    {
        $sql = $this->state->lastTyped;
        if ($sql === '') {
            $this->state->statusLine = 'F5: nothing to recall — this data view has not run anything yet';
            $this->state->statusIsError = false;
            return;
        }
        $this->revertInFlight();
        $this->autoDelete = null;
        $this->state->deleteMarks = [];

        $first = self::typedKeyword($sql);
        $modifying = in_array($first, self::MODIFYING, true);
        if ($modifying || count(Splitter::split(self::withoutBang($sql))) !== 1) {
            $this->input->setText($modifying ? '{safety} ' . $sql : $sql);
            $this->resetHistoryNav();
            $this->state->focus = State::FOCUS_CLI;
            $this->state->statusLine = 'Recalled this view\'s query — end it with ; (or ]G, ]1…]9) to run it';
            $this->state->statusIsError = false;
            return;
        }

        // Typed, then Enter. The text sits on the CLI while the statement is in
        // flight — which on the cancellable path (§21) is every frame until the
        // rows land — and applyResult() clears it, as it does for any submission.
        // `vertical` comes from the view, so a `\G` result refreshes as a `\G`.
        $this->input->setText($sql);
        $this->resetHistoryNav();
        $this->submit($sql, $this->state->vertical, null);
    }

    // --- history navigation ------------------------------------------------

    private function pushHistory(string $sql): void
    {
        $first = self::typedKeyword($sql);
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
            $this->historySaved = $this->cliText();
        }
        if ($this->historyIdx > 0) {
            $this->historyIdx--;
            $this->input->setText($this->state->history[$this->historyIdx]);
        }
    }

    private function historyNext(): void
    {
        if ($this->historyIdx >= count($this->state->history)) {
            return;
        }
        $this->historyIdx++;
        if ($this->historyIdx === count($this->state->history)) {
            $this->input->setText($this->historySaved);
        } else {
            $this->input->setText($this->state->history[$this->historyIdx]);
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
            $this->execHistorySaved = $this->cliText();
        }
        if ($this->execHistoryIdx > 0) {
            $this->execHistoryIdx--;
            $this->input->setText($this->state->execHistory[$this->execHistoryIdx]);
        }
    }

    private function execHistoryNext(): void
    {
        if ($this->execHistoryIdx >= count($this->state->execHistory)) {
            return;
        }
        $this->execHistoryIdx++;
        if ($this->execHistoryIdx === count($this->state->execHistory)) {
            $this->input->setText($this->execHistorySaved);
        } else {
            $this->input->setText($this->state->execHistory[$this->execHistoryIdx]);
        }
    }
}
