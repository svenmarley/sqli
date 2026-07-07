<?php

declare(strict_types=1);

namespace Sqli2\Config;

/**
 * Command-line + config-file option resolver (spec Section 2).
 *
 * Each option is declared once in self::OPTIONS with its short/long names,
 * value type, and default. Values are resolved by layering four sources, each
 * overriding the one before it (lowest → highest precedence):
 *
 *   1. built-in defaults (self::OPTIONS)
 *   2. the **main** config file   (`-c`/`--config FILE`, or `$SQLI_CONFIG`)
 *   3. the **user** config file   (`--user-config FILE`, or `$SQLI_USER_CONFIG`)
 *   4. explicit command-line flags (most specific intent → always win)
 *
 * Config files are JSON objects keyed by the option's long name, e.g.
 * `{ "host": "db", "user": "dev", "read-only": true }`, and may carry **every**
 * option except `config`/`user-config`/`help`/`version`. The user file is a
 * personal overlay on the (typically shared / per-environment) main file.
 *
 * CLI parsing supports `-pVALUE`, `--key=value`, and space-separated forms, plus
 * boolean flags via `--flag` or `--flag=true/false/1/0/yes/on`.
 */
final class Config
{
    /**
     * key => [short, long, isFlag, default, description]
     * Both short and long may be null.
     */
    private const OPTIONS = [
        'host'               => ['H', 'host', false, 'localhost', 'MySQL server hostname or IP'],
        'user'               => ['u', 'user', false, null, 'Database username'],
        'password'           => ['p', 'password', false, null, 'Password'],
        'port'               => ['P', 'port', false, '3306', 'TCP port'],
        'database'           => ['D', 'database', false, null, 'Initial database/schema'],
        'socket'             => ['S', 'socket', false, null, 'Unix socket path'],
        'theme'              => [null, 'theme', false, 'default', 'Color theme name'],
        'dictionary'         => [null, 'dictionary', false, null, 'Data Dictionary CSV path'],
        'read-only'          => [null, 'read-only', true, false, 'Block data-modifying queries'],
        'screensaver-timeout'=> [null, 'screensaver-timeout', false, 36000, 'Idle seconds before screensaver'],
        'config'             => ['c', 'config', false, null, 'Load options from a JSON config file (or $SQLI_CONFIG)'],
        'user-config'        => [null, 'user-config', false, null, 'User JSON config overriding --config (or $SQLI_USER_CONFIG)'],
        'help'               => ['h', 'help', true, false, 'Print help and exit'],
        'version'            => ['V', 'version', true, false, 'Print version and exit'],
        'debug'              => ['d', 'debug', true, false, 'Verbose debug logging'],
    ];

    /** Options that only make sense on the CLI/env, never inside a config file. */
    private const FILE_EXCLUDED = ['config', 'user-config', 'help', 'version'];

    /** @var array<string,mixed> resolved values */
    private array $values = [];

    /** @var list<string> fatal config-file problems, surfaced by the entry point */
    private array $errors = [];

    /**
     * @param list<string> $argv
     * @param array<string,string|null>|null $env override for env lookups (tests);
     *        defaults to the real `SQLI_CONFIG` / `SQLI_USER_CONFIG` vars.
     */
    public function __construct(array $argv, ?array $env = null)
    {
        $env ??= [
            'SQLI_CONFIG'      => self::envOrNull('SQLI_CONFIG'),
            'SQLI_USER_CONFIG' => self::envOrNull('SQLI_USER_CONFIG'),
        ];

        // 1. defaults
        foreach (self::OPTIONS as $key => [, , , $default]) {
            $this->values[$key] = $default;
        }

        // Parse the CLI first, but into a side buffer so explicit flags can be
        // applied LAST (after the config files), keeping them top precedence.
        [$cli, $provided] = $this->parse($argv);

        // Resolve the config-file paths: an explicit CLI flag beats the env var.
        $mainPath = ($provided['config'] ?? false)
            ? (string) $cli['config']
            : self::nonEmpty($env['SQLI_CONFIG'] ?? null);
        $userPath = ($provided['user-config'] ?? false)
            ? (string) $cli['user-config']
            : self::nonEmpty($env['SQLI_USER_CONFIG'] ?? null);

        // 2. main config file, then 3. user config file (user overrides main).
        if ($mainPath !== null) {
            $this->loadInto($mainPath, 'Config');
        }
        if ($userPath !== null) {
            $this->loadInto($userPath, 'User config');
        }

        // 4. explicit CLI flags win over everything.
        foreach ($provided as $key => $given) {
            if ($given) {
                $this->values[$key] = $cli[$key];
            }
        }

        // Record the paths actually used so callers can report/log them.
        $this->values['config'] = $mainPath;
        $this->values['user-config'] = $userPath;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->values[$key] ?? $fallback;
    }

    public function getString(string $key, string $fallback = ''): string
    {
        $v = $this->values[$key] ?? null;
        return $v === null || $v === true ? $fallback : (string) $v;
    }

    public function getInt(string $key, int $fallback = 0): int
    {
        $v = $this->values[$key] ?? null;
        return is_numeric($v) ? (int) $v : $fallback;
    }

    public function isFlag(string $key): bool
    {
        return $this->values[$key] === true;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Fatal config-file problems (missing/unreadable file, invalid JSON). Empty
     * on success. The entry point prints these and exits rather than starting the
     * TUI with a half-applied configuration.
     *
     * @return list<string>
     */
    public function configErrors(): array
    {
        return $this->errors;
    }

    /**
     * Parse argv into a value map + a "was this explicitly given?" map, without
     * touching the resolved values (so layering can apply CLI last).
     *
     * @param list<string> $argv
     * @return array{0:array<string,mixed>,1:array<string,bool>}
     */
    private function parse(array $argv): array
    {
        $cli = [];
        $provided = [];
        array_shift($argv); // drop script name

        while ($argv !== []) {
            $arg = array_shift($argv);
            $isLong = str_starts_with($arg, '--');
            $isShort = !$isLong && str_starts_with($arg, '-') && $arg !== '-';
            if (!$isLong && !$isShort) {
                continue;
            }

            // --key=value
            if ($isLong && str_contains($arg, '=')) {
                [$name, $value] = explode('=', substr($arg, 2), 2);
                $this->assign($cli, $provided, $this->keyForName($name), $value);
                continue;
            }

            // -pVALUE (short option with attached value)
            if ($isShort && strlen($arg) > 2) {
                $short = $arg[1];
                $key = $this->keyForName($short);
                if ($key !== null && !self::OPTIONS[$key][2]) {
                    $this->assign($cli, $provided, $key, substr($arg, 2));
                    continue;
                }
            }

            $name = ltrim($arg, '-');
            $key = $this->keyForName($name);
            if ($key === null) {
                continue;
            }

            if (self::OPTIONS[$key][2]) {
                $cli[$key] = true;
                $provided[$key] = true;
                continue;
            }

            // Value option: consume the next token unless it looks like a flag.
            if ($argv !== [] && !str_starts_with($argv[0], '-')) {
                $this->assign($cli, $provided, $key, array_shift($argv));
            } else {
                $cli[$key] = true;
                $provided[$key] = true;
            }
        }

        return [$cli, $provided];
    }

    /**
     * @param array<string,mixed> $cli
     * @param array<string,bool> $provided
     */
    private function assign(array &$cli, array &$provided, ?string $key, string $value): void
    {
        if ($key === null) {
            return;
        }
        $cli[$key] = self::OPTIONS[$key][2] ? self::truthy($value) : $value;
        $provided[$key] = true;
    }

    /** Load a JSON config file and merge its known options into the resolved set. */
    private function loadInto(string $path, string $label): void
    {
        if (!is_file($path) || !is_readable($path)) {
            $this->errors[] = "$label file not found or unreadable: $path";
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->errors[] = "$label file could not be read: $path";
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->errors[] = "$label file is not valid JSON: $path";
            return;
        }
        foreach ($data as $name => $value) {
            $key = $this->keyForName((string) $name);
            // Unknown keys are ignored (forward-compatible); files can't set the
            // file-pointer/help/version options.
            if ($key === null || in_array($key, self::FILE_EXCLUDED, true)) {
                continue;
            }
            $this->values[$key] = self::OPTIONS[$key][2] ? self::truthy($value) : $value;
        }
    }

    private function keyForName(string $name): ?string
    {
        foreach (self::OPTIONS as $key => [$short, $long]) {
            if ($name === $short || $name === $long || $name === $key) {
                return $key;
            }
        }
        // '-?' is an alias for help.
        if ($name === '?') {
            return 'help';
        }
        return null;
    }

    /** Coerce a JSON/CLI scalar to a boolean for flag options. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }
        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }

    private static function envOrNull(string $name): ?string
    {
        $v = getenv($name);
        return $v === false ? null : $v;
    }

    private static function nonEmpty(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : $v;
    }

    public function helpText(): string
    {
        $lines = [
            'sqli - Interactive MySQL client (sqli2)',
            'Usage: php sqli.php [options]',
            '',
            'Options:',
        ];
        foreach (self::OPTIONS as [$short, $long, $isFlag, $default, $desc]) {
            $names = [];
            if ($short !== null) {
                $names[] = '-' . $short;
            }
            if ($long !== null) {
                $names[] = '--' . $long;
            }
            $flagStr = implode(', ', $names);
            $def = is_bool($default) ? ($default ? 'true' : 'false') : ($default ?? '');
            $lines[] = sprintf('  %-26s %s%s', $flagStr, $desc, $def !== '' ? "  (default: {$def})" : '');
        }
        $lines[] = '';
        $lines[] = 'Config files (JSON, keyed by long option name) layer under the CLI:';
        $lines[] = '  defaults < --config (or $SQLI_CONFIG) < --user-config (or';
        $lines[] = '  $SQLI_USER_CONFIG) < explicit command-line flags.';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
