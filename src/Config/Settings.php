<?php

declare(strict_types=1);

namespace Sqli2\Config;

use includes\my;

/**
 * Reads and writes `sqli.json` in the current working directory (spec 16).
 *
 * Holds persisted UI state: active theme, query history, and per-database
 * dictionary path / column widths / hidden columns. Mutators persist
 * immediately so a crash never loses the last change.
 */
final class Settings
{
    private const FILE = 'sqli.json';
    private const HISTORY_MAX = 25;

    private string $path;
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(?string $workingDir = null)
    {
        $dir = $workingDir ?? getcwd() ?: '.';
        $this->path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
        $this->data = $this->load();
    }

    public function getTheme(): string
    {
        return (string) ($this->data['theme'] ?? 'default');
    }

    public function setTheme(string $name): void
    {
        $this->data['theme'] = $name;
        $this->save();
    }

    /** @return list<string> */
    public function getHistory(): array
    {
        $h = $this->data['history'] ?? [];
        return is_array($h) ? array_values(array_map('strval', $h)) : [];
    }

    /** @param list<string> $history */
    public function setHistory(array $history): void
    {
        if (count($history) > self::HISTORY_MAX) {
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        $this->data['history'] = array_values($history);
        $this->save();
    }

    /**
     * Executed-form history: the normalized SQL actually sent to the server,
     * browsed with Shift+Up/Down (§4). Kept parallel to but separate from the
     * typed-form {@see getHistory()}.
     *
     * @return list<string>
     */
    public function getExecHistory(): array
    {
        $h = $this->data['execHistory'] ?? [];
        return is_array($h) ? array_values(array_map('strval', $h)) : [];
    }

    /** @param list<string> $history */
    public function setExecHistory(array $history): void
    {
        if (count($history) > self::HISTORY_MAX) {
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        $this->data['execHistory'] = array_values($history);
        $this->save();
    }

    public function getDictionary(string $db): ?string
    {
        $p = $this->data['databases'][$db]['dictionary'] ?? null;
        return is_string($p) && $p !== '' ? $p : null;
    }

    public function setDictionary(string $db, string $path): void
    {
        $this->data['databases'][$db]['dictionary'] = $path;
        $this->save();
    }

    /** @return array<string,int> field => width */
    public function getColWidths(string $db, string $table): array
    {
        $w = $this->data['databases'][$db]['colWidths'][$table] ?? [];
        return is_array($w) ? array_map('intval', $w) : [];
    }

    /** @param array<string,int> $widths */
    public function setColWidths(string $db, string $table, array $widths): void
    {
        if ($widths === []) {
            unset($this->data['databases'][$db]['colWidths'][$table]);
        } else {
            $this->data['databases'][$db]['colWidths'][$table] = $widths;
        }
        $this->save();
    }

    /** @return list<string> column names in saved display order ([] = natural) */
    public function getColOrder(string $db, string $table): array
    {
        $o = $this->data['databases'][$db]['colOrder'][$table] ?? [];
        return is_array($o) ? array_values(array_map('strval', $o)) : [];
    }

    /** @param list<string> $order */
    public function setColOrder(string $db, string $table, array $order): void
    {
        if ($order === []) {
            unset($this->data['databases'][$db]['colOrder'][$table]);
        } else {
            $this->data['databases'][$db]['colOrder'][$table] = array_values($order);
        }
        $this->save();
    }

    /** @return list<string> hidden field names */
    public function getHiddenCols(string $db, string $table): array
    {
        $h = $this->data['databases'][$db]['hiddenCols'][$table] ?? [];
        return is_array($h) ? array_values(array_map('strval', $h)) : [];
    }

    /** @param list<string> $fields */
    public function setHiddenCols(string $db, string $table, array $fields): void
    {
        if ($fields === []) {
            unset($this->data['databases'][$db]['hiddenCols'][$table]);
        } else {
            $this->data['databases'][$db]['hiddenCols'][$table] = array_values($fields);
        }
        $this->save();
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            my::lineout('FILE:FILE', 'settings parse failed', $this->path);
            return [];
        }
        return $decoded;
    }

    private function save(): void
    {
        $json = json_encode(
            $this->data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            my::lineout('FILE:FILE', 'settings encode failed', $this->path);
            return;
        }
        @file_put_contents($this->path, $json . PHP_EOL);
    }
}
