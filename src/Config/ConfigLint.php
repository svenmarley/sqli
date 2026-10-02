<?php

declare(strict_types=1);

namespace Sqli\Config;

use Sqli\Db\ConnectionOptions;
use Sqli\Db\TlsOptions;
use Sqli\Render\Scheme;
use Sqli\Render\SchemeLoader;

/**
 * Validates sqli's JSON config/state files and reports problems in plain
 * language, so a hand-edit that breaks a file is caught by `--lint` instead of
 * silently mis-loading. Errors are things that would break loading (bad JSON,
 * wrong shape, extends cycles); warnings are things that load but are probably
 * mistakes (unknown keys, odd value types, dangling `extends`).
 */
final class ConfigLint
{
    /** @var list<string> */
    private array $errors = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> informational lines (not problems), e.g. discovered themes */
    private array $notes = [];

    /** The config being linted — macros need its env (§23) to load like the app. */
    private ?Config $config = null;

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function hasProblems(): bool
    {
        return $this->errors !== [] || $this->warnings !== [];
    }

    /**
     * Lint every config/state file sqli would consult for the given config:
     * the global + every project config/credentials layer, the resolved state +
     * formatting files, the home registry, and all discoverable themes.
     */
    public static function run(Config $config): self
    {
        $lint = new self();
        $lint->config = $config;
        foreach ($config->notices() as $n) {
            $lint->notes[] = $n;
        }
        foreach ($config->knownFiles() as $label => $path) {
            $kind = match (true) {
                str_contains($label, 'credential') => 'credentials',
                str_contains($label, 'macro')   => 'macros',
                default                            => 'config',
            };
            $lint->checkFile($label, $path, $kind);
        }
        foreach (Settings::lintTargets($config->stateDir()) as $label => $t) {
            $lint->checkFile($label, $t['path'], $t['kind']);
        }
        $lint->checkFile('registry', $config->registryPath(), 'registry');
        // Base16 theme/scheme files: the project + global .sqli/themes dirs.
        // Themes are user assets — sqli ships none.
        $lint->checkThemes($config->themeDirs(), $config->getString('theme', 'default'));
        $lint->checkTls($config);
        $lint->checkConnection($config);
        // Every env, not just the selected one: lint is where you look at the
        // envs you are not using today.
        foreach ($config->envConflicts() as $conflict) {
            $lint->warnings[] = 'envs: ' . $conflict;
        }
        foreach (RuntimeCheck::missing($config->getString('driver', ConnectionOptions::DRIVER_AUTO)) as $missing) {
            $lint->notes[] = 'php: ' . $missing;
        }
        return $lint;
    }

    /**
     * Resolve the non-TLS connection options the way the entry point will, so a
     * rejected `init-command` or an out-of-range timeout is found by `--lint`
     * rather than at startup. The resolved set is always noted: these settings
     * are fixed when the connection opens and cannot be seen or changed from
     * anywhere in the running app, which makes `--lint` the only place to look.
     */
    public function checkConnection(Config $config): void
    {
        $errors = [];
        $warnings = [];
        $opts = ConnectionOptions::fromValues(
            connectTimeout: $config->getInt('connect-timeout', ConnectionOptions::CONNECT_TIMEOUT_DEFAULT),
            readTimeout: $config->getInt('read-timeout', ConnectionOptions::READ_TIMEOUT_DEFAULT),
            initCommand: $config->getString('init-command'),
            compress: $config->isFlag('compress'),
            foundRows: $config->isFlag('found-rows'),
            multiStatements: $config->isFlag('multi-statements'),
            ignoreSpace: $config->isFlag('ignore-space'),
            errors: $errors,
            warnings: $warnings,
            driver: $config->getString('driver', ConnectionOptions::DRIVER_AUTO),
            localInfile: $config->isFlag('local-infile'),
        );
        // The one rule ConnectionOptions cannot check for itself: `read-only`
        // is not a connection option, so the pair is judged here — and at the
        // entry point, which refuses to start on it.
        $conflict = ConnectionOptions::localInfileConflict(
            $config->isFlag('local-infile'),
            $config->isFlag('read-only'),
        );
        if ($conflict !== null) {
            $this->errors[] = 'connection: ' . $conflict;
        }
        foreach ($errors as $e) {
            $this->errors[] = 'connection: ' . $e;
        }
        foreach ($warnings as $w) {
            $this->warnings[] = 'connection: ' . $w;
        }
        $this->notes[] = 'connection options: ' . $opts->describe();
    }

    /**
     * Resolve the `ssl-*` options the way the entry point will and report what
     * TlsOptions makes of them, so a missing CA file or a half client
     * certificate is found by `--lint` rather than at connect time. The
     * resulting mode is always noted — "which transport am I actually going to
     * get" is the question this section exists to answer.
     */
    public function checkTls(Config $config): void
    {
        $errors = [];
        $tls = TlsOptions::fromValues(
            mode: $config->getString('ssl-mode', TlsOptions::PREFERRED),
            ca: $config->getString('ssl-ca'),
            cert: $config->getString('ssl-cert'),
            key: $config->getString('ssl-key'),
            cipher: $config->getString('ssl-cipher'),
            errors: $errors,
            caPath: $config->getString('ssl-capath'),
            serverPublicKey: $config->getString('server-public-key'),
        );
        foreach ($errors as $e) {
            $this->errors[] = 'tls: ' . $e;
        }
        $this->notes[] = 'connection encryption: ' . $tls->describe()
            . ($tls->requiresTls() ? ' — a plaintext session will be refused' : '');
    }

    /**
     * Validate every discoverable Base16 scheme file (each must yield all 16
     * slots) and record the available theme names. Warns when the configured
     * theme doesn't resolve, since sqli would silently fall back to default.
     *
     * @param list<string> $dirs
     */
    public function checkThemes(array $dirs, string $configTheme): void
    {
        $loader = new SchemeLoader($dirs);
        $names = $loader->available();
        foreach ($names as $name) {
            $path = $loader->find($name);
            if ($path === null) {
                continue;
            }
            $reason = null;
            if (SchemeLoader::parse($path, $name, $reason) === null) {
                $this->errors[] = "theme '$name' ($path): " . ($reason ?? 'not a valid Base16 scheme');
            }
        }
        // The in-code palette is always selectable, so it heads the list even
        // when no scheme files exist at all.
        $this->notes[] = 'themes available: ' . implode(', ', [Scheme::BUILT_IN, ...$names]);
        if ($configTheme !== '' && $configTheme !== 'default' && $configTheme !== Scheme::BUILT_IN
            && !in_array($configTheme, $names, true)) {
            $this->warnings[] = "configured theme '$configTheme' not found "
                . '(sqli will fall back to the built-in default)';
        }
    }

    /**
     * Check one file. $kind ∈ {config, credentials, formatting, state, registry}.
     * Missing files are skipped silently (nothing to lint).
     */
    public function checkFile(string $label, string $path, string $kind): void
    {
        $where = "$label ($path)";
        if (!is_file($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->errors[] = "$where: cannot read file";
            return;
        }
        if (trim($raw) === '') {
            return; // empty file = defaults; not an error
        }
        // Macros are the one `.sqli/` file that is not JSON (§23): INI, because
        // a macro is one line of SQL and JSON spends most of a line escaping it.
        // So the JSON parse below would fail on every valid macro file, and the
        // check is delegated whole to the loader that owns the format.
        if ($kind === 'macros') {
            $this->checkMacros($where, $path);
            return;
        }
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = "$where: invalid JSON — " . json_last_error_msg()
                . '. Check for trailing commas, missing/extra braces, or unquoted keys.';
            return;
        }
        if (!is_array($data) || !self::isObject($data)) {
            $this->errors[] = "$where: top level must be a JSON object { … }";
            return;
        }

        match ($kind) {
            'config'      => $this->checkConfig($where, $data),
            'credentials' => $this->checkCredentials($where, $data),
            'formatting'  => $this->checkFormatting($where, $data),
            'state'       => $this->checkState($where, $data),
            'registry'    => $this->checkRegistry($where, $data),
            default       => null,
        };
    }

    /**
     * Macros (§23). The file's own loader already decides what is and is not
     * usable, so lint reports exactly what the running app would — one
     * implementation of the rules, not two that can drift. The argument arity is
     * printed beside a name that takes any, because a macro called with the
     * wrong number of words is refused at submit time and this is the one place
     * that says how many it wanted before you type it.
     *
     * It is loaded **for the selected env**, so the count is the one this
     * session would have. What the other `[env:…]` sections hold is listed
     * beside it rather than left out: a lint that only ever showed the current
     * env would be no help at all with the file's other half, which is exactly
     * the half nobody has looked at recently.
     */
    private function checkMacros(string $where, string $path): void
    {
        $env = $this->config?->envName();
        $chain = $this->config?->envChain() ?? [];
        $macros = new Macros([dirname($path)], $env, $chain);
        foreach ($macros->problems() as $p) {
            // The loader prefixes its own short path; $where already names the
            // file, so keep the message and drop the duplicate location.
            $this->warnings[] = $where . ': ' . preg_replace('/^[^:]*: /', '', $p);
        }

        // A section naming an env no config file defines is the one way a
        // scoped macro disappears without anyone mistyping a macro: the section
        // head is spelled correctly, it just points at nothing.
        $known = $this->config?->envNames();
        foreach ($macros->envsSeen() as $seen) {
            if ($known !== null && $known !== [] && !in_array($seen, $known, true)) {
                $this->warnings[] = "$where: [env:$seen] names an environment no config file defines"
                    . ' (' . implode(', ', $known) . ') — nothing under it can ever load';
            }
        }

        $n = $macros->count();
        $named = [];
        foreach ($macros->names() as $name) {
            $arity = $macros->arity($name);
            $label = $arity > 0 ? $name . '(' . $arity . ')' : $name;
            $scope = $macros->scopeOf($name);
            $named[] = $scope !== null ? $label . ' [env:' . $scope . ']' : $label;
        }
        // An inherited env is worth naming: `-E prod` loading a `[env:base]`
        // macro is the config's `extends` doing its job, and a lint that only
        // said "for env prod" would leave the reader hunting for a section that
        // is not there.
        $inherited = array_values(array_filter($chain, static fn(string $e): bool => $e !== $env));
        $this->notes[] = "$where: " . ($n === 1 ? '1 macro' : "$n macros")
            . ($env !== null ? ' for env ' . $env : '')
            . ($inherited !== [] ? ' (extends ' . implode(', ', $inherited) . ')' : '')
            . ($n > 0 ? ' — ' . implode(', ', $named) : '');

        $hidden = $macros->hidden();
        if ($hidden !== []) {
            $parts = [];
            foreach ($hidden as $name => $envs) {
                $parts[] = $name . ' [env:' . implode('] [env:', $envs) . ']';
            }
            $this->notes[] = "$where: " . count($hidden)
                . (count($hidden) === 1 ? ' macro' : ' macros')
                . ' scoped to another environment, not loaded'
                . ($env === null ? ' (no -E given)' : '') . ' — ' . implode(', ', $parts);
        }
    }

    /** @param array<string,mixed> $data */
    private function checkRegistry(string $where, array $data): void
    {
        $projects = $data['projects'] ?? null;
        if ($projects === null) {
            return;
        }
        if (!is_array($projects) || !array_is_list($projects)) {
            $this->errors[] = "$where: `projects` must be a list of directory paths";
            return;
        }
        foreach ($projects as $p) {
            if (!is_string($p)) {
                $this->warnings[] = "$where: non-string entry in `projects` (ignored)";
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function checkConfig(string $where, array $data): void
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_') {
                continue; // comment convention
            }
            if (in_array($key, Config::reservedSections(), true)) {
                continue; // handled below
            }
            $this->checkOption($where, "top-level '$key'", $key, $value);
        }

        if (isset($data['defaults'])) {
            if (!self::isObject($data['defaults'])) {
                $this->errors[] = "$where: `defaults` must be an object";
            } else {
                foreach ($data['defaults'] as $key => $value) {
                    if ((string) $key === '' || ((string) $key)[0] === '_') {
                        continue;
                    }
                    $this->checkOption($where, "defaults.$key", (string) $key, $value);
                }
            }
        }

        if (isset($data['envs'])) {
            $this->checkEnvs($where, $data['envs']);
        }

        // The default `env` lives in the file's base, so it is checked from both
        // halves of that base — `defaults{}` last, because it is the one that wins.
        $this->checkDefaultEnv($where, $data, 'top-level `env`', $data);
        if (self::isObject($data['defaults'] ?? null)) {
            /** @var array<string,mixed> $defaults */
            $defaults = $data['defaults'];
            $this->checkDefaultEnv($where, $defaults, '`defaults.env`', $data);
        }
    }

    /**
     * The default `env` (§2): the env used when neither `-E` nor `$SQLI_ENV` names
     * one. Both ways of getting it wrong are invisible until the day this layer
     * happens to be the closest one — a non-string value, and a name no `envs{}`
     * anywhere defines, which then stops sqli from starting at all. Worth saying
     * before someone cd's into the directory, not after.
     *
     * @param array<string,mixed> $base the half of the base being checked — the
     *        file's flat root, or its `defaults{}`
     * @param array<string,mixed> $fileRoot the file root, which is where `envs{}`
     *        lives however deep the key being checked is
     */
    private function checkDefaultEnv(string $where, array $base, string $label, array $fileRoot): void
    {
        if (!array_key_exists('env', $base)) {
            return;
        }
        $value = $base['env'];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->notes[] = "$where: $label is empty — no default env here,"
                . " and a farther layer's default is cancelled";
            return;
        }
        if (!is_string($value)) {
            $this->errors[] = "$where: $label must be a string env name";
            return;
        }
        $name = trim($value);
        // Judge it against the pooled names — every layer's `envs{}`, the way `-E`
        // resolves — so a default naming a parent defined in another file passes.
        // With no Config to ask (a bare lint of one file), this file's own names
        // are the only ones there are.
        $known = $this->config?->envNames() ?? [];
        if ($known === [] && is_array($fileRoot['envs'] ?? null)) {
            $known = array_map('strval', array_keys($fileRoot['envs']));
        }
        if ($known !== [] && !in_array($name, $known, true)) {
            $this->errors[] = "$where: $label names '$name', which no config file defines"
                . ' (' . implode(', ', $known) . ') — sqli would refuse to start here';
            return;
        }
        // Every layer may declare one and only the closest survives, so the note
        // has to say which of the two this file is — otherwise three files each
        // claim to be the one in force and the reader is no better off.
        $resolved = $this->config?->defaultEnvName();
        $active = $this->config?->envName();
        $this->notes[] = "$where: $label = $name"
            . match (true) {
                $this->config === null => '',
                $resolved === null => " — cancelled by a closer layer's empty `env`",
                $resolved !== $name => " — overridden by a closer layer's default env ($resolved)",
                $this->config->envSource() === 'config' => ' — in use this session',
                $active !== null => " — overridden this session by env $active",
                default => ' — not in use this session',
            };
    }

    /** @param mixed $envs */
    private function checkEnvs(string $where, mixed $envs): void
    {
        if (!self::isObject($envs)) {
            $this->errors[] = "$where: `envs` must be an object of named environments";
            return;
        }
        /** @var array<string,mixed> $envs */
        $labels = [];
        foreach ($envs as $name => $def) {
            $name = (string) $name;
            if ($name === '' || $name[0] === '_') {
                continue;
            }
            if (!self::isObject($def)) {
                $this->errors[] = "$where: env '$name' must be an object";
                continue;
            }
            /** @var array<string,mixed> $def */
            foreach ($def as $key => $value) {
                $key = (string) $key;
                if ($key === 'extends' || $key === '' || $key[0] === '_') {
                    continue;
                }
                $this->checkOption($where, "envs.$name.$key", $key, $value);
            }
            foreach ($def as $key => $value) {
                if (Config::canonicalKey((string) $key) === 'host_label' && is_string($value) && trim($value) !== '') {
                    $labels[trim($value)][] = $name;
                }
            }
            // An env naming an env is the one placement that cannot mean anything:
            // `env` selects an environment, and by the time this block is applied
            // the selection has happened. `extends` is the key that was wanted.
            if (array_key_exists('env', $def)) {
                $this->warnings[] = "$where: env '$name' sets `env` (ignored)"
                    . ' — an environment cannot select another; `extends` inherits one';
            }
            $this->checkExtendsRefs($where, $name, $def['extends'] ?? null, $envs);
        }
        $this->checkLabels($where, $labels);
        $this->checkExtendsCycles($where, $envs);
    }

    /**
     * `host_label` (§2) hides an environment's address behind a name, so two
     * environments sharing one name is the failure the feature exists to
     * prevent: the header would read `acme-prod` on both, and the whole point of
     * the setting is that the header is the only thing saying which server this
     * is. Reported as an error rather than a warning for that reason — nothing
     * else on screen would catch it.
     *
     * Per file, because that is the scope the user asked for and the scope a
     * lint of one file can actually judge; a name reused in a *farther* config
     * layer is a different environment list and only one of them can win.
     *
     * @param array<string,list<string>> $labels
     */
    private function checkLabels(string $where, array $labels): void
    {
        foreach ($labels as $label => $envs) {
            if (count($envs) > 1) {
                $this->errors[] = "$where: host_label '$label' is used by more than one env ("
                    . implode(', ', $envs) . ') — a host_label stands in for the address,'
                    . ' so it has to say which server it means';
            }
        }
    }

    /** @param array<string,mixed> $envs */
    private function checkExtendsRefs(string $where, string $name, mixed $extends, array $envs): void
    {
        if ($extends === null) {
            return;
        }
        $parents = is_array($extends) ? $extends : [$extends];
        foreach ($parents as $p) {
            if (!is_string($p)) {
                $this->errors[] = "$where: env '$name' has a non-string `extends` entry";
                continue;
            }
            if (!isset($envs[$p])) {
                $this->warnings[] = "$where: env '$name' extends '$p', which isn't defined "
                    . 'in this file (ok only if another layer defines it)';
            }
        }
    }

    /** @param array<string,mixed> $envs */
    private function checkExtendsCycles(string $where, array $envs): void
    {
        foreach (array_keys($envs) as $start) {
            $seen = [];
            $node = (string) $start;
            while (true) {
                if (in_array($node, $seen, true)) {
                    $this->errors[] = "$where: `extends` cycle: " . implode(' -> ', [...$seen, $node]);
                    break;
                }
                $seen[] = $node;
                $def = $envs[$node] ?? null;
                $ext = is_array($def) ? ($def['extends'] ?? null) : null;
                // Only single-parent chains are walked here; multi-parent cycles
                // are rare and still terminate via the resolver's own guard.
                if (!is_string($ext) || !isset($envs[$ext])) {
                    break;
                }
                $node = $ext;
            }
        }
    }

    private function checkOption(string $where, string $ctx, string $key, mixed $value): void
    {
        $canon = Config::canonicalKey($key);
        if ($canon === null) {
            $this->warnings[] = "$where: unknown option '$key' at $ctx (ignored)";
            return;
        }
        if (in_array($canon, Config::fileExcludedKeys(), true)) {
            $this->warnings[] = "$where: '$key' at $ctx can't be set in a file (ignored)";
            return;
        }
        if (Config::isFlagKey($canon)) {
            if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                $this->warnings[] = "$where: '$key' at $ctx should be true/false";
            }
            return;
        }
        if ($canon === 'port' && !is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $this->warnings[] = "$where: '$key' at $ctx should be a number";
        }
    }

    /** @param array<string,mixed> $data */
    private function checkCredentials(string $where, array $data): void
    {
        foreach ($data as $env => $secrets) {
            $env = (string) $env;
            if ($env === '' || $env[0] === '_') {
                continue;
            }
            if (!self::isObject($secrets)) {
                $this->errors[] = "$where: credentials for '$env' must be an object "
                    . '(e.g. { "password": "…" })';
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function checkFormatting(string $where, array $data): void
    {
        if (!isset($data['databases'])) {
            return;
        }
        if (!self::isObject($data['databases'])) {
            $this->errors[] = "$where: `databases` must be an object";
            return;
        }
        foreach ($data['databases'] as $db => $dbData) {
            if (!self::isObject($dbData)) {
                $this->errors[] = "$where: databases.$db must be an object";
                continue;
            }
            $tables = $dbData['tables'] ?? null;
            if ($tables === null) {
                continue;
            }
            if (!self::isObject($tables)) {
                $this->errors[] = "$where: databases.$db.tables must be an object";
                continue;
            }
            foreach ($tables as $table => $spec) {
                $this->checkFormattingTable("$where: databases.$db.tables.$table", $spec);
            }
        }
    }

    private function checkFormattingTable(string $ctx, mixed $spec): void
    {
        if (!self::isObject($spec)) {
            $this->errors[] = "$ctx must be an object";
            return;
        }
        $allowed = ['widths' => 'object', 'order' => 'list', 'formats' => 'object', 'hidden' => 'list'];
        /** @var array<string,mixed> $spec */
        foreach ($spec as $key => $value) {
            $key = (string) $key;
            if ($key === '' || $key[0] === '_') {
                continue;
            }
            if (!isset($allowed[$key])) {
                $this->warnings[] = "$ctx.$key: unknown key (expected widths/order/formats/hidden)";
                continue;
            }
            $ok = $allowed[$key] === 'list' ? array_is_list((array) $value) && is_array($value) : self::isObject($value) || $value === [];
            if (!$ok) {
                $this->warnings[] = "$ctx.$key: expected a " . ($allowed[$key] === 'list' ? 'list [ … ]' : 'object { … }');
            }
        }
    }

    /** @param array<string,mixed> $data */
    private function checkState(string $where, array $data): void
    {
        if (isset($data['theme']) && !is_string($data['theme'])) {
            $this->warnings[] = "$where: `theme` should be a string";
        }
        foreach (['history', 'execHistory'] as $k) {
            if (isset($data[$k]) && !(is_array($data[$k]) && array_is_list($data[$k]))) {
                $this->warnings[] = "$where: `$k` should be a list of strings";
            }
        }
    }

    /** True for a JSON object (assoc array or the empty array), not a list. */
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && ($v === [] || !array_is_list($v));
    }
}
