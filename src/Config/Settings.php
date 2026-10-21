<?php

declare(strict_types=1);

namespace Sqli\Config;

use includes\my;

/**
 * Reads and writes sqli's persisted UI state (spec 16) inside a `.sqli/`
 * directory — the closest project one walking up from the cwd, else the global
 * `$SQLI_HOME/.sqli` (the resolution lives in {@see Config::stateDir()}) —
 * across two files:
 *
 *   `sqli_state.json`       — churny state: active theme, query history,
 *                             exec history, and each database's dictionary path.
 *   `sqli_formatting.json`  — per-column display settings (widths, order,
 *                             formats, hidden), grouped database → table so the
 *                             file is easy to hand-edit.
 *
 * Both file their per-database nodes under a **connection scope** — the
 * `host:port/db` string from {@see \Sqli\Db\Database::stateKey()}, not a bare
 * database name. A name alone is not unique: `prod` on staging and `prod` in
 * production would otherwise share one entry and overwrite each other's
 * layout and dictionary association.
 *
 * When no `.sqli/` exists anywhere yet the dir is null: values are held in
 * memory, {@see needsDir()} turns true on the first attempted save, and the app
 * prompts the user for where to create one (or to skip persisting). Mutators
 * persist immediately so a crash never loses the last change; write failures
 * are reported via {@see takeWriteError()}, never silent.
 */
final class Settings
{
    private const STATE_FILE = 'sqli_state.json';
    private const FORMATTING_FILE = 'sqli_formatting.json';
    private const LEGACY_FILE = 'sqli.json';
    private const HISTORY_MAX = 25;

    /** The `.sqli/` dir state lives in; null = nowhere yet (in-memory only). */
    private ?string $dir;

    /** True after a save was attempted with no dir — the app should prompt. */
    private bool $pendingSave = false;

    /** True when the user chose "don't persist this session". */
    private bool $muted = false;

    /** Last file-write failure (path + reason), cleared when read. */
    private ?string $writeError = null;

    /** @var array<string,mixed> theme / history / execHistory / databases[db].dictionary */
    private array $state = [];

    /** @var array<string,mixed> databases[db].tables[table] => {widths,order,formats,hidden} */
    private array $formatting = [];

    public function __construct(?string $sqliDir)
    {
        $this->dir = $sqliDir !== null ? rtrim($sqliDir, '/\\') : null;
        if ($this->dir === null) {
            return;
        }

        $state = self::readJson($this->statePath());
        $formatting = self::readJson($this->formattingPath());

        // First run after the split: migrate the ancient combined `sqli.json`
        // sitting beside the `.sqli/` dir (the newer dot-file layout is migrated
        // into `.sqli/` by Config during discovery).
        if ($state === null && $formatting === null) {
            $legacy = self::readJson(dirname($this->dir) . DIRECTORY_SEPARATOR . self::LEGACY_FILE);
            if ($legacy !== null) {
                [$state, $formatting] = self::splitLegacy($legacy);
                $this->state = $state;
                $this->formatting = $formatting;
                $this->saveState();
                $this->saveFormatting();
                return;
            }
        }

        $this->state = $state ?? [];
        $this->formatting = $formatting ?? [];
    }

    /** Absolute path to the state file (for tooling such as --lint). */
    public function statePath(): string
    {
        return ($this->dir ?? '.') . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    /** Absolute path to the formatting file (for tooling such as --lint). */
    public function formattingPath(): string
    {
        return ($this->dir ?? '.') . DIRECTORY_SEPARATOR . self::FORMATTING_FILE;
    }

    /** True when a save was attempted but there is no `.sqli/` dir yet. */
    public function needsDir(): bool
    {
        return $this->dir === null && $this->pendingSave && !$this->muted;
    }

    /** Stop asking: hold state in memory only for the rest of the session. */
    public function mute(): void
    {
        $this->muted = true;
    }

    /**
     * Point state at (a possibly new) `.sqli/` dir and flush the in-memory
     * values there — used after the user picks where to create the config, and
     * by "Create Local sqli Config" when the new dir is closer than the old one.
     */
    public function relocate(string $sqliDir): void
    {
        $this->dir = rtrim($sqliDir, '/\\');
        $this->muted = false;
        $this->pendingSave = false;
        $this->saveState();
        $this->saveFormatting();
    }

    /** Last write failure (path + OS reason), cleared by reading. */
    public function takeWriteError(): ?string
    {
        $e = $this->writeError;
        $this->writeError = null;
        return $e;
    }

    /**
     * Files `--lint` should validate in the given `.sqli/` dir, as
     * label => {path, kind}. Read-only: does not construct/migrate anything.
     *
     * @return array<string,array{path:string,kind:string}>
     */
    public static function lintTargets(?string $sqliDir): array
    {
        if ($sqliDir === null) {
            return [];
        }
        $dir = rtrim($sqliDir, '/\\');
        return [
            'state file'      => ['path' => $dir . DIRECTORY_SEPARATOR . self::STATE_FILE, 'kind' => 'state'],
            'formatting file' => ['path' => $dir . DIRECTORY_SEPARATOR . self::FORMATTING_FILE, 'kind' => 'formatting'],
        ];
    }

    public function getTheme(): string
    {
        return (string) ($this->state['theme'] ?? 'default');
    }

    public function setTheme(string $name): void
    {
        $this->state['theme'] = $name;
        $this->saveState();
    }

    /** @return list<string> */
    public function getHistory(): array
    {
        $h = $this->state['history'] ?? [];
        return is_array($h) ? array_values(array_map('strval', $h)) : [];
    }

    /** @param list<string> $history */
    public function setHistory(array $history): void
    {
        $this->state['history'] = self::capped($history);
        $this->saveState();
    }

    /**
     * Executed-form history: the normalized SQL actually sent to the server,
     * browsed with Alt+Up/Down (§4). Kept parallel to but separate from the
     * typed-form {@see getHistory()}.
     *
     * @return list<string>
     */
    public function getExecHistory(): array
    {
        $h = $this->state['execHistory'] ?? [];
        return is_array($h) ? array_values(array_map('strval', $h)) : [];
    }

    /** @param list<string> $history */
    public function setExecHistory(array $history): void
    {
        $this->state['execHistory'] = self::capped($history);
        $this->saveState();
    }

    public function getDictionary(string $scope): ?string
    {
        $p = $this->state['databases'][$scope]['dictionary'] ?? null;
        return is_string($p) && $p !== '' ? $p : null;
    }

    public function setDictionary(string $scope, string $path): void
    {
        $this->state['databases'][$scope]['dictionary'] = $path;
        $this->saveState();
    }

    /** @return array<string,int> field => width */
    public function getColWidths(string $scope, string $table): array
    {
        $w = $this->formatting['databases'][$scope]['tables'][$table]['widths'] ?? [];
        return is_array($w) ? array_map('intval', $w) : [];
    }

    /** @param array<string,int> $widths */
    public function setColWidths(string $scope, string $table, array $widths): void
    {
        $this->setFmt($scope, $table, 'widths', $widths === [] ? null : $widths);
    }

    /**
     * Column order and hidden columns belong to one field list, not the whole
     * table (§6): what was arranged over `SELECT *` is stored under `*`, and
     * what was arranged over `SELECT name, id` under `name, id`, and neither
     * is applied to the other. Widths and formats stay per table.
     *
     * @return list<string> column names in saved display order ([] = natural)
     */
    public function getColOrder(string $scope, string $table, string $fields = '*'): array
    {
        return $this->getListAttr($scope, $table, $fields, 'order');
    }

    /** @param list<string> $order */
    public function setColOrder(string $scope, string $table, array $order, string $fields = '*'): void
    {
        $this->setListAttr($scope, $table, $fields, 'order', $order);
    }

    /** @return array<string,string> column name => display format id (§6) */
    public function getColFormats(string $scope, string $table): array
    {
        $f = $this->formatting['databases'][$scope]['tables'][$table]['formats'] ?? [];
        return is_array($f) ? array_map('strval', $f) : [];
    }

    /** @param array<string,string> $formats */
    public function setColFormats(string $scope, string $table, array $formats): void
    {
        $this->setFmt($scope, $table, 'formats', $formats === [] ? null : $formats);
    }

    /**
     * Table-view definitions (§10a): column name => {rows, fields:[{name, sep}]}.
     * Saved per table, like formats, and kept whatever layout the query has.
     *
     * @return array<string,array<string,mixed>>
     */
    public function getColSplits(string $scope, string $table): array
    {
        $s = $this->formatting['databases'][$scope]['tables'][$table]['splits'] ?? [];
        return is_array($s) ? array_filter($s, 'is_array') : [];
    }

    /** @param array<string,array<string,mixed>> $splits */
    public function setColSplits(string $scope, string $table, array $splits): void
    {
        $this->setFmt($scope, $table, 'splits', $splits === [] ? null : $splits);
    }

    /** @return list<string> hidden field names, for one field list ({@see getColOrder()}) */
    public function getHiddenCols(string $scope, string $table, string $fields = '*'): array
    {
        return $this->getListAttr($scope, $table, $fields, 'hidden');
    }

    /** @param list<string> $hidden */
    public function setHiddenCols(string $scope, string $table, array $hidden, string $fields = '*'): void
    {
        $this->setListAttr($scope, $table, $fields, 'hidden', $hidden);
    }

    /** @return list<string> */
    private function getListAttr(string $scope, string $table, string $fields, string $attr): array
    {
        $v = $this->formatting['databases'][$scope]['tables'][$table]['lists'][$fields][$attr] ?? [];
        return is_array($v) ? array_values(array_map('strval', $v)) : [];
    }

    /**
     * Store (or, when empty, clear) `order` or `hidden` for one field list,
     * pruning the list's node and the `lists` node when they empty out.
     *
     * @param list<string> $names
     */
    private function setListAttr(string $scope, string $table, string $fields, string $attr, array $names): void
    {
        $lists = $this->formatting['databases'][$scope]['tables'][$table]['lists'] ?? [];
        if (!is_array($lists)) {
            $lists = [];
        }
        if ($names === []) {
            unset($lists[$fields][$attr]);
            if (($lists[$fields] ?? null) === []) {
                unset($lists[$fields]);
            }
        } else {
            $lists[$fields][$attr] = array_values($names);
        }
        $this->setFmt($scope, $table, 'lists', $lists === [] ? null : $lists);
    }

    /**
     * Set (or clear, when $value is null) one formatting attribute for a table,
     * pruning now-empty table/database nodes so the file stays tidy for editing.
     */
    private function setFmt(string $scope, string $table, string $attr, mixed $value): void
    {
        if ($value === null) {
            unset($this->formatting['databases'][$scope]['tables'][$table][$attr]);
            if (($this->formatting['databases'][$scope]['tables'][$table] ?? null) === []) {
                unset($this->formatting['databases'][$scope]['tables'][$table]);
            }
            if (($this->formatting['databases'][$scope]['tables'] ?? null) === []) {
                unset($this->formatting['databases'][$scope]);
            }
        } else {
            $this->formatting['databases'][$scope]['tables'][$table][$attr] = $value;
        }
        $this->saveFormatting();
    }

    /** @param list<string> $items */
    private static function capped(array $items): array
    {
        if (count($items) > self::HISTORY_MAX) {
            $items = array_slice($items, -self::HISTORY_MAX);
        }
        return array_values($items);
    }

    /**
     * Split the legacy combined `sqli.json` into the new state + formatting
     * shapes. Legacy stored `databases[db][colWidths|colOrder|colFormats|
     * hiddenCols][table]`; the new formatting file groups by table.
     *
     * @param array<string,mixed> $legacy
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private static function splitLegacy(array $legacy): array
    {
        $state = [];
        foreach (['theme', 'history', 'execHistory'] as $k) {
            if (isset($legacy[$k])) {
                $state[$k] = $legacy[$k];
            }
        }

        $attrMap = [
            'colWidths'  => 'widths',
            'colOrder'   => 'order',
            'colFormats' => 'formats',
            'hiddenCols' => 'hidden',
        ];
        $formatting = [];
        $dbs = is_array($legacy['databases'] ?? null) ? $legacy['databases'] : [];
        foreach ($dbs as $db => $dbData) {
            if (!is_array($dbData)) {
                continue;
            }
            if (isset($dbData['dictionary'])) {
                $state['databases'][$db]['dictionary'] = $dbData['dictionary'];
            }
            foreach ($attrMap as $legacyKey => $newKey) {
                $byTable = $dbData[$legacyKey] ?? null;
                if (!is_array($byTable)) {
                    continue;
                }
                foreach ($byTable as $table => $value) {
                    if ($value !== [] && $value !== null) {
                        $formatting['databases'][$db]['tables'][$table][$newKey] = $value;
                    }
                }
            }
        }

        return [$state, $formatting];
    }

    /** @return array<string,mixed>|null null = file absent or unparseable */
    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            my::lineout('FILE:FILE', 'settings parse failed', $path);
            return null;
        }
        return $decoded;
    }

    private function saveState(): void
    {
        $this->write($this->statePath(), $this->state);
    }

    private function saveFormatting(): void
    {
        // A header comment makes the hand-edited formatting file self-explanatory.
        $data = $this->formatting;
        $data = ['_comment' => 'sqli per-column display settings — safe to hand-edit. '
            . 'databases > <host:port/db> > tables > <table> > { hidden:[cols], order:[cols], '
            . 'widths:{col:width}, formats:{col:formatId}, splits:{col:{rows, fields:[{name, sep}]}} }. '
            . 'Delete any key to reset it.']
            + $data;
        $this->write($this->formattingPath(), $data);
    }

    /** @param array<string,mixed> $data */
    private function write(string $path, array $data): void
    {
        if ($this->muted) {
            return; // user chose not to persist this session
        }
        if ($this->dir === null) {
            $this->pendingSave = true; // nowhere to write yet — app will prompt
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->writeError = "Could not create {$this->dir}: " . self::lastOsError();
            my::lineout('FILE:FILE', 'settings mkdir failed', $this->dir);
            return;
        }
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            my::lineout('FILE:FILE', 'settings encode failed', $path);
            return;
        }
        if (@file_put_contents($path, $json . PHP_EOL) === false) {
            $this->writeError = "Could not write $path: " . self::lastOsError();
            my::lineout('FILE:FILE', 'settings write failed', $path);
        }
    }

    /** The most recent PHP/OS error message, for user-facing write failures. */
    private static function lastOsError(): string
    {
        $e = error_get_last();
        return $e !== null ? $e['message'] : 'permission denied or path not writable';
    }
}
