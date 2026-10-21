<?php

declare(strict_types=1);

namespace Sqli\Render;

/**
 * Finds and parses Base16 *scheme* files by name across a list of directories.
 *
 * Deliberately format-tolerant and dependency-free: it scrapes the 16
 * `base0X: "hex"` assignments out of the raw file text with one regex, so it
 * reads the modern Base16 YAML (`palette:` block), the older flat YAML, and
 * plain JSON alike — without a YAML library. This is the promised "few-line
 * reader", not a framework.
 *
 * Directories are searched in the order given, first match wins — so a project
 * or user `themes/` dir overrides a built-in scheme of the same name. A theme's
 * name is its whole file name, extension and all: `nord.yaml` is `nord.yaml`,
 * and no extension is ever guessed or added.
 */
final class SchemeLoader
{
    /** Matches `base00: "#181818"` / `base0A = a1b56c` etc., quotes and `#` optional. */
    private const SLOT_RE = '/\bbase0([0-9a-fA-F])\b\s*[:=]\s*["\']?#?([0-9a-fA-F]{6})\b/';

    /** @param list<string> $dirs search dirs, first wins */
    public function __construct(
        private readonly array $dirs,
    ) {
    }

    /**
     * Load the named scheme, or null if no file is found or it isn't a complete
     * 16-slot scheme. `$reason` (by-ref) carries a human-readable explanation on
     * failure, for logging / lint.
     */
    public function load(string $name, ?string &$reason = null): ?Scheme
    {
        $path = $this->find($name);
        if ($path === null) {
            $reason = "no scheme file named '$name' in " . implode(', ', $this->dirs);
            return null;
        }
        return self::parse($path, $name, $reason);
    }

    /** The file backing a theme name: that exact file name, in each dir in order. */
    public function find(string $name): ?string
    {
        // The built-in palette lives in code; no file may claim its name.
        // Windows can't even create `<default>`, but Linux can.
        if ($name === Scheme::BUILT_IN) {
            return null;
        }
        // Guard against path traversal in a theme name from config.
        $base = basename($name);
        foreach ($this->dirs as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $base;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Theme names discoverable across the search dirs, de-duplicated (earlier
     * dirs win) and sorted — for a future picker and for `--lint`.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $seen = [];
        foreach ($this->dirs as $dir) {
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            foreach ((glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*') ?: []) as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $name = basename($file);
                if ($name === Scheme::BUILT_IN) {
                    continue; // reserved for the in-code palette
                }
                $seen[$name] ??= true;
            }
        }
        $names = array_keys($seen);
        sort($names);
        return $names;
    }

    /**
     * Parse a scheme file into a {@see Scheme}. Requires all 16 slots; a partial
     * file is rejected (with a reason) rather than half-applied.
     */
    public static function parse(string $path, ?string $name = null, ?string &$reason = null): ?Scheme
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $reason = "could not read $path";
            return null;
        }
        if (!preg_match_all(self::SLOT_RE, $raw, $m, PREG_SET_ORDER)) {
            $reason = "no base0X colors found in $path";
            return null;
        }

        $colors = [];
        foreach ($m as [, $slot, $hex]) {
            // Normalize to the 'base0A' (uppercase) key shape Scheme/Theme use.
            $colors['base0' . strtoupper($slot)] = '#' . strtolower($hex);
        }

        $missing = array_diff(Scheme::SLOTS, array_keys($colors));
        if ($missing !== []) {
            $reason = "$path is missing slots: " . implode(', ', $missing);
            return null;
        }

        return new Scheme($name ?? basename($path), $colors);
    }
}
