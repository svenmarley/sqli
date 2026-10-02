<?php

declare(strict_types=1);

namespace Sqli\Config;

/**
 * Command-line + config-file option resolver (spec Section 2).
 *
 * Each option is declared once in self::OPTIONS with its short/long names,
 * value type, and default. Values are resolved by layering sources, each
 * overriding the one before it (lowest → highest precedence):
 *
 *   1. built-in defaults (self::OPTIONS)
 *   2. the **global** config       (`$SQLI_HOME/.sqli/sqli_config.json`)
 *   3. **project** configs         (every `.sqli/` from the cwd up to the root;
 *                                   closest to the cwd wins per key)
 *   4. the **main** config file   (`-c`/`--config FILE`, or `$SQLI_CONFIG`)
 *   5. the **user** config file   (`--user-config FILE`, or `$SQLI_USER_CONFIG`)
 *   6. credentials files           (secrets for the selected env)
 *   7. explicit command-line flags (most specific intent → always win)
 *
 * Config files are JSON objects keyed by the option's long name, e.g.
 * `{ "host": "db", "user": "dev", "read-only": true }`, and may carry **every**
 * option except `config`/`user-config`/`help`/`version`. The user file is a
 * personal overlay on the (typically shared / per-environment) main file.
 *
 * CLI parsing supports `-pVALUE`, `--key=value`, and space-separated forms, plus
 * boolean flags via `--flag` or `--flag=true/false/1/0/yes/on`.
 *
 * Which env is selected is layered the same way: `-E NAME`, else `$SQLI_ENV`,
 * else the `env` key in the base of the closest config layer that sets one
 * (`defaults{}` by preference, or the flat root) — so a project directory can say
 * which server it means and a bare `sqli` typed inside it means that server.
 * `--no-env` cancels the two nobody typed. `env` is the one option a file may set
 * only in its base: inside `envs{}` it would be an environment naming an
 * environment, which is what `extends` is for.
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
        'password'           => ['p', 'password', false, null, 'Password (asked for at startup if missing)'],
        'password-prompt'    => [null, 'password-prompt', true, false, 'Always ask for the password at startup'],
        'no-password'        => [null, 'no-password', true, false, 'Connect with an empty password; never ask'],
        'port'               => ['P', 'port', false, '3306', 'TCP port'],
        'database'           => ['D', 'database', false, null, 'Initial database/schema'],
        'socket'             => ['S', 'socket', false, null, 'Unix socket path'],
        'ssl-mode'           => [null, 'ssl-mode', false, 'preferred', 'TLS: disabled|preferred|required|verify-ca|verify-identity'],
        'ssl-ca'             => [null, 'ssl-ca', false, null, 'CA certificate file used to verify the server'],
        'ssl-capath'         => [null, 'ssl-capath', false, null, 'Directory of CA certificates (alternative to --ssl-ca)'],
        'ssl-cert'           => [null, 'ssl-cert', false, null, 'Client certificate file (needs --ssl-key)'],
        'ssl-key'            => [null, 'ssl-key', false, null, 'Client private key file (needs --ssl-cert)'],
        'ssl-cipher'         => [null, 'ssl-cipher', false, null, 'Permitted cipher list for the TLS handshake (TLS 1.2 and below)'],
        'server-public-key'  => [null, 'server-public-key', false, null, 'RSA public key file for sha2 auth on an unencrypted link'],
        'connect-timeout'    => [null, 'connect-timeout', false, 10, 'Seconds to wait for the connection (0 = OS default)'],
        'read-timeout'       => [null, 'read-timeout', false, 1800, 'Seconds of server silence before the connection is dropped (0 = PHP default)'],
        'init-command'       => [null, 'init-command', false, null, 'A single SET statement run on every connect'],
        'driver'             => [null, 'driver', false, 'auto', 'Connection driver: auto|mysqli|pdo (mysqli is what makes Esc able to cancel a query)'],
        'compress'           => [null, 'compress', true, false, 'Compress the client/server protocol'],
        'found-rows'         => [null, 'found-rows', true, false, 'Report rows matched rather than rows changed'],
        'multi-statements'   => [null, 'multi-statements', true, false, 'Let the server accept several statements in one packet'],
        'ignore-space'       => [null, 'ignore-space', true, false, 'Allow a space between a function name and its ( — makes function names reserved'],
        'local-infile'       => [null, 'local-infile', true, false, 'Allow LOAD DATA LOCAL INFILE — the server can then ask this client for any file it can read (refused with --read-only)'],
        'comment'            => [null, 'comment', false, null, 'Short note shown in the header beside the CLI/DATA tag (e.g. PROD)'],
        'host_label'         => [null, 'host-label', false, null, 'Name shown in the header instead of user@host:port/db — hides the address, must be unique across the config'],
        'theme'              => [null, 'theme', false, 'default', 'Color theme name'],
        'truecolor'          => [null, 'truecolor', false, 'auto', 'Truecolor output: auto|on|off'],
        'dictionary'         => [null, 'dictionary', false, null, 'Data Dictionary CSV path'],
        'read-only'          => [null, 'read-only', true, false, 'Block data-modifying queries'],
        'env'                => ['E', 'env', false, null, 'Named environment from the config `envs` section (also $SQLI_ENV)'],
        'no-env'             => [null, 'no-env', true, false, 'Ignore $SQLI_ENV and the config\'s default `env`; run with no named environment'],
        'lint'               => [null, 'lint', true, false, 'Validate the config/formatting JSON files and exit'],
        'execute'            => ['e', 'execute', false, null, 'Run the SQL, print the result as CSV and exit — no TUI (SQL piped on stdin does the same)'],
        'skip-column-names'  => ['N', 'skip-column-names', true, false, 'With -e / piped SQL: no header row in the CSV'],
        'out'                => [null, 'out', false, null, 'With -e / piped SQL: write the CSV to this file instead of stdout'],
        'verbose'            => ['v', 'verbose', true, false, 'With -e / piped SQL: row counts and timings on stderr'],
        'config'             => ['c', 'config', false, null, 'Load options from a JSON config file (or $SQLI_CONFIG)'],
        'user-config'        => [null, 'user-config', false, null, 'User JSON config overriding --config (or $SQLI_USER_CONFIG)'],
        'help'               => ['h', 'help', true, false, 'Print help and exit'],
        'version'            => ['V', 'version', true, false, 'Print version and exit'],
        'debug'              => ['d', 'debug', true, false, 'Verbose debug logging'],
    ];

    /** Options that only make sense on the CLI/env, never inside a config file. */
    private const FILE_EXCLUDED = ['no-env', 'config', 'user-config', 'help', 'version', 'lint', 'execute', 'out'];

    /**
     * Options a config file may set only in its **base** — its flat root keys or
     * its `defaults{}` block — and never inside an `envs{}` block or a credentials
     * file. `env` names the environment to select when the command line names
     * none, so an env carrying one would be an environment naming an environment;
     * `extends` is that. {@see loadStructured()} reads it by hand from the base,
     * which is why {@see applyMap()} skips it everywhere.
     */
    private const BASE_ONLY = ['env'];

    /**
     * The keys that say *which* server and account an env means. When two config
     * files define the same env name with different values for one of these,
     * {@see envConflicts()} reports it — closest still wins.
     */
    private const IDENTITY_KEYS = ['host', 'port', 'socket', 'user', 'database'];

    /** Filenames sqli looks for inside a `.sqli/` directory. */
    private const CONFIG_FILE = 'sqli_config.json';
    private const CREDENTIALS_FILE = 'sqli_credentials.json';
    private const STATE_FILE = 'sqli_state.json';
    private const FORMATTING_FILE = 'sqli_formatting.json';
    private const MACROS_FILE = Macros::FILE;

    /** Registry of known project `.sqli` dirs (home `.sqli/` only; machine-owned cache). */
    private const REGISTRY_FILE = 'sqli_registry.json';

    /** Pre-2.30.4 names inside a `.sqli/` dir, auto-renamed on discovery. */
    private const LEGACY_RENAMES = [
        'config.json'      => self::CONFIG_FILE,
        'credentials.json' => self::CREDENTIALS_FILE,
    ];

    /** Pre-2.30.4 dot-files beside a `.sqli/` dir, auto-moved inside it. */
    private const LEGACY_MOVES = [
        '.sqli.json'            => self::STATE_FILE,
        '.sqli_formatting.json' => self::FORMATTING_FILE,
    ];

    /** Reserved top-level keys in a config file (not options; other subsystems own them). */
    private const RESERVED_SECTIONS = ['defaults', 'envs'];

    /** Seed contents written by {@see scaffoldAt()} when the files are absent. */
    private const DEFAULT_CONFIG_JSON = <<<'JSON'
        {
          "_comment": "sqli config. Full format: see .sqli/sqli_config.example.json shipped with sqli. Put shared settings in defaults{}, and one block per server/database in envs{} (pick with -E NAME, or put \"env\": \"NAME\" in defaults{} to get one with no -E). Envs may `extends` another. Passwords go in sqli_credentials.json, not here.",
          "defaults": {
          },
          "envs": {
          }
        }

        JSON;

    /** Seed contents for the global credentials file. */
    private const DEFAULT_CREDENTIALS_JSON = <<<'JSON'
        {
          "_comment": "sqli secrets, keyed by env name — e.g. \"prod\": { \"password\": \"...\" }. Keep out of version control. Only the selected env's secrets are read; an explicit -p still wins."
        }

        JSON;

    /** @var array<string,mixed> resolved values */
    private array $values = [];

    /** @var list<string> fatal config-file problems, surfaced by the entry point */
    private array $errors = [];

    /** The user's home directory ($SQLI_HOME's fallback root). */
    private string $home = '.';

    /** Root for the *global* config: $SQLI_HOME, else the home directory. */
    private string $sqliHome = '.';

    /** Discovered global config path ($SQLI_HOME/.sqli/sqli_config.json); may not exist. */
    private string $globalConfig = '';

    /**
     * Project `.sqli/` dirs discovered walking from the cwd up to the filesystem
     * root, **closest first**. The home/global `.sqli` is never listed here — it
     * is the base layer, not a project.
     *
     * @var list<string>
     */
    private array $projectDirs = [];

    /** @var list<string> informational lines: migrations performed, registry updates */
    private array $notices = [];

    /** Selected environment name (from -E/--env, or $SQLI_ENV), or null. */
    private ?string $envName = null;

    /** True once some config file actually defined the selected env. */
    private bool $envMatched = false;

    /** @var list<string> every env name any config layer defines, sorted */
    private array $envNames = [];

    /**
     * @var list<string> the selected env's `extends` chain, least specific
     *      first and the env itself last; empty when none was selected or the
     *      name matched nothing
     */
    private array $envChain = [];

    /**
     * The default `env` from the base of the closest config layer that set one —
     * null when no layer did, or when a closer one cancelled it with an empty
     * value. Separate from `$envName` because it stands whether it was used or
     * not: `-E` and `$SQLI_ENV` override it without erasing what the files said.
     */
    private ?string $defaultEnv = null;

    /** The config file the standing {@see $defaultEnv} came from, for messages. */
    private ?string $defaultEnvFrom = null;

    /** How {@see $envName} was chosen: `-E`, `$SQLI_ENV`, `config`, or null. */
    private ?string $envSource = null;

    /**
     * Which layer last set each option — a config file's path, `env NAME`, or
     * `command line`; absent for a built-in default. Kept so a setting nobody
     * typed (a default env's `read-only`) can say where it came from.
     *
     * @var array<string,string>
     */
    private array $origins = [];

    /** True when the CLI carried a bare `-p` / `--password` (i.e. "ask me"). */
    private bool $passwordPromptCli = false;

    /**
     * Every file's own value for each {@see IDENTITY_KEYS} key of each env, in
     * layer order (lowest first, so the last entry is the one that wins).
     *
     * @var array<string,array<string,list<array{0:string,1:string}>>>
     */
    private array $envDefinitions = [];

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
            'SQLI_ENV'         => self::envOrNull('SQLI_ENV'),
            'SQLI_HOME'        => self::envOrNull('SQLI_HOME'),
            'HOME'             => self::envOrNull('HOME'),
            'USERPROFILE'      => self::envOrNull('USERPROFILE'),
        ];

        // 1. built-in defaults
        foreach (self::OPTIONS as $key => [, , , $default]) {
            $this->values[$key] = $default;
        }

        // Parse the CLI first, but into a side buffer so explicit flags can be
        // applied LAST (after the config files), keeping them top precedence.
        [$cli, $provided] = $this->parse($argv);

        // `-p` / `--password` with no value means "ask me", exactly as the mysql
        // client has always meant it. It has to be caught here, from the raw CLI
        // parse: by the time values are resolved, "given but empty" and "not
        // given" are the same empty string.
        $this->passwordPromptCli = ($provided['password'] ?? false) && ($cli['password'] ?? null) === '';

        // Selected environment (config `envs` section). `-E` beats $SQLI_ENV, and
        // both beat a file's default `env`, which is applied further down once the
        // files have been read. `--no-env` cancels the two nobody typed on this
        // command line — an exported $SQLI_ENV and the project's default — and
        // leaves an explicit `-E` standing, so a named env is never discarded.
        $noEnv   = ($cli['no-env'] ?? false) === true;
        $fromCli = self::nonEmpty(($provided['env'] ?? false) ? (string) $cli['env'] : null);
        $fromVar = $noEnv ? null : self::nonEmpty($env['SQLI_ENV'] ?? null);
        $this->envName   = $fromCli ?? $fromVar;
        $this->envSource = $fromCli !== null ? '-E' : ($fromVar !== null ? '$SQLI_ENV' : null);

        // Root for the global config: $SQLI_HOME if set, else the user's home dir.
        $this->home = self::homeDir($env);
        $this->sqliHome = self::nonEmpty($env['SQLI_HOME'] ?? null) ?? $this->home;

        // Discover config files (all optional). Each lives in a `.sqli/` dir:
        //   global:   $SQLI_HOME/.sqli/sqli_config.json
        //   projects: every `.sqli/` dir walking up from the cwd to the root
        // Legacy layouts (pre-2.30.4 names, dot-files beside `.sqli/`) are
        // migrated in place as they are discovered.
        $this->migrateSqliDir(self::joinPath($this->sqliHome, '.sqli'));
        $this->migrateLegacyMoves($this->sqliHome, self::joinPath($this->sqliHome, '.sqli'));
        $this->globalConfig = self::joinPath($this->sqliHome, '.sqli', self::CONFIG_FILE);
        $this->projectDirs  = $this->discoverProjectDirs(getcwd() ?: '.');
        $this->updateRegistry();

        // Explicit --config / --user-config paths: a CLI flag beats the env var.
        $mainPath = ($provided['config'] ?? false)
            ? (string) $cli['config']
            : self::nonEmpty($env['SQLI_CONFIG'] ?? null);
        $userPath = ($provided['user-config'] ?? false)
            ? (string) $cli['user-config']
            : self::nonEmpty($env['SQLI_USER_CONFIG'] ?? null);

        // Ordered config layers, lowest → highest precedence:
        //   2. global config
        //   3. project configs    — farthest ancestor first, so the one closest
        //                           to the cwd wins per key
        //   4. explicit --config
        //   5. explicit --user-config
        // (6. credentials and 7. explicit CLI flags are applied further below.)
        $layers = [];
        if (is_file($this->globalConfig)) {
            $layers[] = [$this->globalConfig, 'Global config', false];
        }
        foreach (array_reverse($this->projectDirs) as $dir) {
            $cfg = self::joinPath($dir, self::CONFIG_FILE);
            if (is_file($cfg)) {
                $layers[] = [$cfg, 'Project config', false];
            }
        }
        if ($mainPath !== null) {
            $layers[] = [$mainPath, 'Config', true];
        }
        if ($userPath !== null) {
            $layers[] = [$userPath, 'User config', true];
        }

        // Pass 1: apply each layer's flat/defaults base (later layer wins per key)
        // and pool every layer's `envs{}` into one map so a selected env may
        // `extends` a parent defined in a *different* file. A closer layer's env
        // definition overrides a farther one's per key.
        $mergedEnvs = [];
        foreach ($layers as [$path, $label, $required]) {
            $this->loadStructured($path, $label, $mergedEnvs, $required);
        }

        $this->envNames = array_keys($mergedEnvs);
        sort($this->envNames, SORT_NATURAL | SORT_FLAG_CASE);

        // Nothing named an env, so the files get to: the default `env` of the
        // closest layer that set one. It belongs here, after pass 1, because which
        // layer that is only becomes known once every file has been read — and
        // before pass 2, which is what applies the selected env's options.
        if ($this->envName === null && !$noEnv && $this->defaultEnv !== null) {
            $this->envName   = $this->defaultEnv;
            $this->envSource = 'config';
        }

        // Pass 2: apply the selected env (resolving its cross-file `extends`
        // chain) on top of every file's base.
        if ($this->envName !== null && isset($mergedEnvs[$this->envName])) {
            $resolved = $this->resolveEnv($mergedEnvs, $this->envName, []);
            if ($resolved !== null) {
                $this->envMatched = true;
                $this->envChain = self::flattenChain(self::chainOf($mergedEnvs, $this->envName, []));
                $this->applyMap($resolved, origin: 'env ' . $this->envName);
            }
        }

        // A selected env that no config file defined is a fatal typo, not silent.
        // Where the name came from is part of the message: a default `env` typo
        // sits in a file the user is not looking at and did not type anything into.
        if ($this->envName !== null && !$this->envMatched) {
            $this->errors[] = "Unknown env '{$this->envName}' — not defined in any config file"
                . ($this->envSource === 'config' && $this->defaultEnvFrom !== null
                    ? " (set as `env` in {$this->defaultEnvFrom})"
                    : '');
        }

        // Secrets (password, …) for the selected env, kept out of the shareable
        // config. Global first, then project layers; closest overrides.
        $this->loadCredentials(self::joinPath($this->sqliHome, '.sqli', self::CREDENTIALS_FILE));
        foreach (array_reverse($this->projectDirs) as $dir) {
            $this->loadCredentials(self::joinPath($dir, self::CREDENTIALS_FILE));
        }

        // Explicit CLI flags win over everything.
        foreach ($provided as $key => $given) {
            if ($given) {
                $this->values[$key] = $cli[$key];
                $this->origins[$key] = 'command line';
            }
        }

        // Expand ${SQLI_HOME} / leading ~ in resolved string values (e.g. paths).
        $this->expandTokens();

        // Record resolved meta so callers can report/log it.
        $this->values['config'] = $mainPath;
        $this->values['user-config'] = $userPath;
        $this->values['env'] = $this->envName;
    }

    /**
     * One sentence per env and connection key that two or more config files set
     * differently — the global `prod` on one host and a project's `prod` on
     * another. Nothing is refused and the closest file still wins; the point is
     * that nobody ends up on a different server than they thought without being
     * told. Only files that define the env themselves count: a value inherited
     * through `extends` is not a second definition.
     *
     * @param list<string>|null $envs the envs to check (the selected chain, at
     *        startup); null checks every env, which is what `--lint` wants
     * @return list<string>
     */
    public function envConflicts(?array $envs = null): array
    {
        $out = [];
        foreach ($this->envDefinitions as $env => $keys) {
            if ($envs !== null && !in_array($env, $envs, true)) {
                continue;
            }
            foreach ($keys as $key => $defs) {
                $values = array_unique(array_column($defs, 1));
                if (count($values) < 2) {
                    continue;
                }
                [$winPath, $winValue] = $defs[count($defs) - 1];
                $others = [];
                foreach (array_slice($defs, 0, -1) as [$path, $value]) {
                    if ($value !== $winValue) {
                        $others[] = "'$value' in $path";
                    }
                }
                $out[] = "env '$env' sets $key differently in more than one config file — "
                    . "using '$winValue' from $winPath (also " . implode(', ', $others) . ')';
            }
        }
        return $out;
    }

    /** Selected environment name, or null when none was requested. */
    public function envName(): ?string
    {
        return $this->envName;
    }

    /**
     * Every environment name the `envs{}` sections define, across all layers.
     *
     * Pooled the same way {@see envName()}'s target is resolved, so a name here
     * is one `-E` would accept. `--lint` uses it to tell an `[env:staging]`
     * macro section that no config file has a staging (§23) — the one way a
     * scoped macro can be lost without anyone typing anything wrong.
     *
     * @return list<string> sorted
     */
    public function envNames(): array
    {
        return $this->envNames;
    }

    /**
     * The selected env with its `extends` ancestors, least specific first and
     * the env itself last — the order they are applied in, so a later entry
     * overrides an earlier one. Empty when no env was selected, or when the
     * name matched nothing (already an error).
     *
     * Config resolves the chain into one flat option map, so it needs only the
     * result; this is for the subsystems that keep their layers apart and have
     * to walk it themselves — `sqli_macros.ini`'s `[env:NAME]` sections (§23),
     * which inherit from a parent env exactly as `envs{}` does.
     *
     * @return list<string>
     */
    public function envChain(): array
    {
        return $this->envChain;
    }

    /**
     * The default `env` the config layers settled on, whether it was used or not:
     * `-E` and `$SQLI_ENV` override it and `--no-env` refuses it, and anything
     * reporting on the configuration (`--lint`) needs to see it either way.
     */
    public function defaultEnvName(): ?string
    {
        return $this->defaultEnv;
    }

    /** The config file {@see defaultEnvName()} came from, or null. */
    public function defaultEnvFile(): ?string
    {
        return $this->defaultEnvFrom;
    }

    /**
     * Where {@see envName()} came from — `-E`, `$SQLI_ENV`, or `config` for a
     * default `env` — and null when no env was selected. The point of asking is
     * the third answer: an env the user did not type is worth saying out loud.
     */
    public function envSource(): ?string
    {
        return $this->envSource;
    }

    /**
     * Where an option's value came from: a config file's path, `env NAME`, or
     * `command line` — null when it is still the built-in default.
     */
    public function originOf(string $key): ?string
    {
        $o = $this->origins[$key] ?? '';
        return $o === '' ? null : $o;
    }

    /** The resolved global config root ($SQLI_HOME or the home directory). */
    public function sqliHome(): string
    {
        return $this->sqliHome;
    }

    /**
     * Directories to search for Base16 theme/scheme files, in precedence order
     * (first match wins): each project `.sqli/themes/` from closest to farthest,
     * then the global `$SQLI_HOME/.sqli/themes/`. Themes are user assets — there
     * is no built-in themes dir shipped with sqli.
     *
     * @return list<string>
     */
    public function themeDirs(): array
    {
        $dirs = [];
        foreach ($this->projectDirs as $dir) {
            $dirs[] = self::joinPath($dir, 'themes');
        }
        $dirs[] = self::joinPath($this->sqliHome, '.sqli', 'themes');
        return $dirs;
    }

    /**
     * Project `.sqli/` dirs, closest to the cwd first (never the global one).
     *
     * @return list<string>
     */
    public function projectSqliDirs(): array
    {
        return $this->projectDirs;
    }

    /**
     * Should the entry point ask for the password before connecting?
     *
     * Two ways to say yes: a bare `-p` on the command line (the mysql client's
     * long-standing meaning), or `password-prompt: true` in any config layer —
     * which is how an environment declares "this server's password is never
     * written down", so `sqli -E prod` asks every time.
     *
     * A `-p VALUE` **wins over the flag**: the explicit value is the more
     * specific intent, and prompting anyway would be ignoring what was typed.
     */
    public function wantsPasswordPrompt(): bool
    {
        if ($this->passwordPromptCli) {
            return true;
        }
        return $this->isFlag('password-prompt') && $this->getString('password') === '';
    }

    /**
     * No password came from anywhere — CLI, config, credentials file, env.
     *
     * The caller treats this as a reason to ask, because a user who names an
     * account and supplies no password has almost always forgotten it rather
     * than meant "empty". Requiring `-p` to *ask* was making the flag carry
     * information sqli can already see for itself.
     *
     * Two guards keep that from being presumptuous. A `--no-password` says the
     * empty password is deliberate (`mysql --skip-password`'s job), and the
     * missing user case is left alone: with no account named there is nothing
     * to log in as, so a prompt would be asking about a connection that was
     * never described. The tty check is the caller's — see sqli.php — because
     * whether a human is watching is not a fact about configuration.
     */
    public function passwordIsMissing(): bool
    {
        if ($this->isFlag('no-password')) {
            return false;
        }
        return $this->getString('password') === '' && $this->getString('user') !== '';
    }

    /** The global `.sqli` directory ($SQLI_HOME/.sqli); may not exist yet. */
    public function globalSqliDir(): string
    {
        return self::joinPath($this->sqliHome, '.sqli');
    }

    /**
     * Where persisted UI state (sqli_state.json / sqli_formatting.json) lives:
     * the closest project `.sqli/`, else the global `.sqli/` when that exists.
     * Null means nothing exists yet — the app prompts before the first save.
     */
    public function stateDir(): ?string
    {
        if ($this->projectDirs !== []) {
            return $this->projectDirs[0];
        }
        $global = $this->globalSqliDir();
        return is_dir($global) ? $global : null;
    }

    /** Path of the home registry file (may not exist). */
    public function registryPath(): string
    {
        return self::joinPath($this->sqliHome, '.sqli', self::REGISTRY_FILE);
    }

    /**
     * Migration / registry-update reports ("moved X -> Y", "updated <registry>"),
     * so the app can say exactly which file in which dir it touched.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * Config-related JSON files that currently exist, as label => path. Used by
     * `--lint` (the cwd state/formatting files are added by the entry point).
     *
     * @return array<string,string>
     */
    public function knownFiles(): array
    {
        $candidates = [
            'global config'      => $this->globalConfig,
            'global credentials' => self::joinPath($this->sqliHome, '.sqli', self::CREDENTIALS_FILE),
        ];
        $candidates['global macros'] = self::joinPath($this->sqliHome, '.sqli', self::MACROS_FILE);
        foreach ($this->projectDirs as $i => $dir) {
            $suffix = count($this->projectDirs) > 1 ? ' #' . ($i + 1) : '';
            $candidates["project config{$suffix}"]      = self::joinPath($dir, self::CONFIG_FILE);
            $candidates["project credentials{$suffix}"] = self::joinPath($dir, self::CREDENTIALS_FILE);
            $candidates["project macros{$suffix}"]   = self::joinPath($dir, self::MACROS_FILE);
        }
        $candidates['config (--config)'] = is_string($this->values['config'] ?? null) ? $this->values['config'] : null;
        $candidates['user config']       = is_string($this->values['user-config'] ?? null) ? $this->values['user-config'] : null;

        $files = [];
        foreach ($candidates as $label => $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                $files[$label] = $path;
            }
        }
        return $files;
    }

    /**
     * Create a `.sqli/` directory at $sqliDir and seed default `sqli_config.json`
     * / `sqli_credentials.json` / `sqli_macros.ini` (+ a `themes/` folder) when
     * missing.
     *
     * Note what is **not** created: a `dd/` folder. A Data Dictionary is a
     * company document — it lives on the shared drive or in the project repo
     * alongside the schema it describes, is maintained by people who do not run
     * sqli, and is pointed at by the `dictionary` option or the Assign-DD
     * picker. Scaffolding a home for it inside `.sqli/` implied sqli owned it.
     * Never overwrites an existing file. Returns null on success, or a helpful
     * error message (path + OS reason) so the caller can show it to the user.
     */
    public function scaffoldAt(string $sqliDir): ?string
    {
        if (!is_dir($sqliDir) && !@mkdir($sqliDir, 0700, true) && !is_dir($sqliDir)) {
            return "Could not create $sqliDir: " . self::lastOsError();
        }
        @mkdir(self::joinPath($sqliDir, 'themes'), 0700, true);

        $config = self::joinPath($sqliDir, self::CONFIG_FILE);
        if (!is_file($config) && @file_put_contents($config, self::DEFAULT_CONFIG_JSON) === false) {
            return "Could not write $config: " . self::lastOsError();
        }
        $creds = self::joinPath($sqliDir, self::CREDENTIALS_FILE);
        if (!is_file($creds)) {
            if (@file_put_contents($creds, self::DEFAULT_CREDENTIALS_JSON) === false) {
                return "Could not write $creds: " . self::lastOsError();
            }
            @chmod($creds, 0600); // best-effort; no-op on Windows
        }
        // Macros (§23). Seeded rather than left absent, because the feature is
        // only discoverable if the file it reads is sitting there next to the
        // others — and this one is seeded with a commented-out cheat sheet, since
        // its INI format has two rules (quote a value with a `;`, one macro per
        // line) that are cheaper to read here than to find out by losing a macro.
        $macros = self::joinPath($sqliDir, self::MACROS_FILE);
        if (!is_file($macros) && @file_put_contents($macros, Macros::DEFAULT_INI) === false) {
            return "Could not write $macros: " . self::lastOsError();
        }
        return null;
    }

    /** The most recent PHP/OS error message, for user-facing write failures. */
    private static function lastOsError(): string
    {
        $e = error_get_last();
        return $e !== null ? $e['message'] : 'permission denied or path not writable';
    }

    /** Canonical option key for a short/long/key name, or null if unknown. */
    public static function canonicalKey(string $name): ?string
    {
        foreach (self::OPTIONS as $key => [$short, $long]) {
            if ($name === $short || $name === $long || $name === $key) {
                return $key;
            }
        }
        return null;
    }

    /** Whether the given canonical key is a boolean flag option. */
    public static function isFlagKey(string $key): bool
    {
        return isset(self::OPTIONS[$key]) && self::OPTIONS[$key][2] === true;
    }

    /** Option keys that a config file may not set. @return list<string> */
    public static function fileExcludedKeys(): array
    {
        return self::FILE_EXCLUDED;
    }

    /** Reserved top-level section names in a config file. @return list<string> */
    public static function reservedSections(): array
    {
        return self::RESERVED_SECTIONS;
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
                // Options must be dash-prefixed; bare tokens are ignored.
                continue;
            }

            // `-e SQL` / `--execute SQL` takes the next token whatever it looks
            // like, as mysql does: SQL can start with a dash (`-- note` + newline,
            // `-1 AS n`), and reading that as "no value, here is another flag"
            // would run nothing and then choke on the SQL as an option.
            if ($arg === '-e' || $arg === '--execute') {
                $this->assign($cli, $provided, 'execute', $argv !== [] ? array_shift($argv) : '');
                continue;
            }

            // --key=value
            if ($isLong && str_contains($arg, '=')) {
                [$name, $value] = explode('=', substr($arg, 2), 2);
                $this->assign($cli, $provided, $this->keyForName($name), $value);
                continue;
            }

            // Short option with attached value: -pVALUE or -E=VALUE.
            if ($isShort && strlen($arg) > 2) {
                $short = $arg[1];
                $key = $this->keyForName($short);
                if ($key !== null && !self::OPTIONS[$key][2]) {
                    $rest = substr($arg, 2);
                    if ($rest !== '' && $rest[0] === '=') {
                        $rest = substr($rest, 1); // -E=prod → prod
                    }
                    $this->assign($cli, $provided, $key, $rest);
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
            // A missing value resolves to '' (empty), never the boolean flag
            // sentinel — so `-p --host x` yields an empty password, not `true`.
            if ($argv !== [] && !str_starts_with($argv[0], '-')) {
                $this->assign($cli, $provided, $key, array_shift($argv));
            } else {
                $cli[$key] = '';
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

    /**
     * Load a JSON config file and merge it into the resolved set. A file's base
     * is its flat root-level option keys plus an explicit `defaults{}` object;
     * the selected env (`envs[<name>]`, with its `extends` chain) then overrides
     * that base. Other top-level keys are reserved for future subsystems and
     * ignored here. `$required` marks explicit --config/--user-config files whose
     * absence is fatal; auto-discovered files are optional and skipped silently.
     *
     * The file's `envs{}` section is pooled into `$mergedEnvs` (closest layer
     * wins per env) rather than applied here — the caller resolves the selected
     * env once, across all files, so `extends` can reach parents in other files.
     *
     * @param array<string,mixed> $mergedEnvs accumulator, keyed by env name
     */
    private function loadStructured(string $path, string $label, array &$mergedEnvs, bool $required = false): void
    {
        if (!is_file($path) || !is_readable($path)) {
            if ($required) {
                $this->errors[] = "$label file not found or unreadable: $path";
            }
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
        // A config file must be a JSON object. `{}` decodes to an empty array
        // (allowed); a populated list like `[1,2]` is a mistake, not a config.
        if ($data !== [] && array_is_list($data)) {
            $this->errors[] = "$label file must be a JSON object, not an array: $path";
            return;
        }

        // Base: flat root-level options, then an explicit defaults{} section.
        $this->applyMap($data, rootOnly: true, origin: $path);
        if (isset($data['defaults']) && is_array($data['defaults'])) {
            $this->applyMap($data['defaults'], origin: $path);
        }

        // The default `env`: which env to use when neither `-E` nor $SQLI_ENV
        // names one. Read from this file's base the way every other option is —
        // flat root first, `defaults{}` over it — and the closest layer wins, with
        // an empty value meaning "no default here", so a project can cancel the
        // global one rather than be stuck inheriting it.
        foreach ([$data, is_array($data['defaults'] ?? null) ? $data['defaults'] : []] as $base) {
            if (!array_key_exists('env', $base)) {
                continue;
            }
            $want = $base['env'];
            if ($want === null || (is_string($want) && trim($want) === '')) {
                $this->defaultEnv = null;
                $this->defaultEnvFrom = null;
            } elseif (is_string($want)) {
                $this->defaultEnv = trim($want);
                $this->defaultEnvFrom = $path;
            } else {
                $this->errors[] = "$label file: `env` must be an env name: $path";
            }
        }

        // Pool this file's envs; a closer layer (applied later) overrides a
        // farther one per key within the same env name.
        if (isset($data['envs']) && is_array($data['envs'])) {
            foreach ($data['envs'] as $name => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $key = (string) $name;
                $mergedEnvs[$key] = isset($mergedEnvs[$key])
                    ? array_merge($mergedEnvs[$key], $def)
                    : $def;
                foreach (self::IDENTITY_KEYS as $identity) {
                    if (array_key_exists($identity, $def) && is_scalar($def[$identity])) {
                        $this->envDefinitions[$key][$identity][] = [$path, (string) $def[$identity]];
                    }
                }
            }
        }
    }

    /**
     * Merge a map of option-keyed values into the resolved set. `$rootOnly` skips
     * the reserved section keys so a file's flat base doesn't pick them up;
     * unknown, file-excluded and base-only keys are ignored either way
     * (forward-compatible). `env` is base-only and {@see loadStructured()} reads
     * it by hand, so this never assigns it — an `envs{}` block or a credentials
     * file carrying one is ignored, not obeyed.
     *
     * @param array<string,mixed> $map
     */
    private function applyMap(array $map, bool $rootOnly = false, string $origin = ''): void
    {
        foreach ($map as $name => $value) {
            if ($rootOnly && in_array((string) $name, self::RESERVED_SECTIONS, true)) {
                continue;
            }
            $key = $this->keyForName((string) $name);
            if ($key === null
                || in_array($key, self::FILE_EXCLUDED, true)
                || in_array($key, self::BASE_ONLY, true)
            ) {
                continue;
            }
            $this->values[$key] = self::OPTIONS[$key][2] ? self::truthy($value) : $value;
            $this->origins[$key] = $origin;
        }
    }

    /**
     * Flatten an env definition, applying its `extends` parent(s) first so the
     * child overrides them. `extends` may be a string or a list. Returns the
     * merged option map, or null when the name isn't defined. `$seen` guards
     * against extends cycles.
     *
     * @param array<string,mixed> $envs
     * @param list<string> $seen
     * @return array<string,mixed>|null
     */
    private function resolveEnv(array $envs, string $name, array $seen): ?array
    {
        if (!isset($envs[$name]) || !is_array($envs[$name])) {
            return null;
        }
        if (in_array($name, $seen, true)) {
            return []; // extends cycle — stop climbing
        }
        $def = $envs[$name];
        $extends = $def['extends'] ?? null;
        unset($def['extends']);

        $base = [];
        $parents = is_array($extends) ? $extends : ($extends !== null ? [$extends] : []);
        foreach ($parents as $parent) {
            $p = $this->resolveEnv($envs, (string) $parent, [...$seen, $name]);
            if ($p !== null) {
                $base = array_merge($base, $p);
            }
        }
        return array_merge($base, $def);
    }

    /**
     * The env names {@see resolveEnv()} walks, in the order it merges them:
     * each parent's own chain first (left to right, so a later `extends` entry
     * beats an earlier one), then $name itself. Duplicates are left in — the
     * caller flattens — and $seen guards the same cycle resolveEnv() guards.
     *
     * @param array<string,mixed> $envs
     * @param list<string> $seen
     * @return list<string>
     */
    private static function chainOf(array $envs, string $name, array $seen): array
    {
        if (!isset($envs[$name]) || !is_array($envs[$name]) || in_array($name, $seen, true)) {
            return [];
        }
        $extends = $envs[$name]['extends'] ?? null;
        $parents = is_array($extends) ? $extends : ($extends !== null ? [$extends] : []);

        $out = [];
        foreach ($parents as $parent) {
            foreach (self::chainOf($envs, (string) $parent, [...$seen, $name]) as $ancestor) {
                $out[] = $ancestor;
            }
        }
        $out[] = $name;
        return $out;
    }

    /**
     * Drop repeats from a chain, keeping the **last** occurrence of each name.
     * A diamond (`prod extends [base, shared]`, both extending `common`) lists
     * `common` twice, and the position that decides is the later one — the same
     * answer array_merge() gives {@see resolveEnv()} for the values.
     *
     * @param list<string> $chain
     * @return list<string>
     */
    private static function flattenChain(array $chain): array
    {
        $seen = [];
        $out = [];
        foreach (array_reverse($chain) as $name) {
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $out[] = $name;
        }
        return array_reverse($out);
    }

    /**
     * Load an env-keyed secrets file (e.g. `{ "prod": { "password": "…" } }`) and
     * apply the selected env's secrets. Separate from config so it can stay out of
     * version control. Optional and silent — no secrets are ever logged.
     */
    private function loadCredentials(string $path): void
    {
        if ($this->envName === null || !is_file($path) || !is_readable($path)) {
            return;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data[$this->envName]) || !is_array($data[$this->envName])) {
            return;
        }
        $this->applyMap($data[$this->envName], origin: $path);
    }

    /** Expand `${SQLI_HOME}`/`$SQLI_HOME` and a leading `~` in resolved strings. */
    private function expandTokens(): void
    {
        foreach ($this->values as $key => $value) {
            // The password is an opaque secret, never a path — a literal `~` or
            // `$SQLI_HOME` inside it must survive verbatim. The same goes for the
            // SQL given to -e, which is not a path either.
            if ($key === 'password' || $key === 'execute' || !is_string($value) || $value === '') {
                continue;
            }
            $v = str_replace(['${SQLI_HOME}', '$SQLI_HOME'], $this->sqliHome, $value);
            if ($v === '~') {
                $v = $this->home;
            } elseif (str_starts_with($v, '~/') || str_starts_with($v, '~\\')) {
                $v = $this->home . substr($v, 1);
            }
            $this->values[$key] = $v;
        }
    }

    /** The user's home directory: $SQLI_HOME's fallback root. */
    private static function homeDir(array $env): string
    {
        $home = self::nonEmpty($env['HOME'] ?? null)
            ?? self::nonEmpty($env['USERPROFILE'] ?? null);
        return $home ?? '.';
    }

    /**
     * Walk from $start up to the filesystem root collecting every project
     * `.sqli/` dir, closest first. A dir counts as a project when it has a
     * `.sqli/` subdir *or* legacy pre-2.30.4 dot-files (`.sqli.json` /
     * `.sqli_formatting.json`) — those are migrated into a freshly-created
     * `.sqli/` on the spot. The home / $SQLI_HOME dir is skipped: its `.sqli`
     * is the global base layer, never a project.
     *
     * @return list<string>
     */
    private function discoverProjectDirs(string $start): array
    {
        $skip = array_filter([self::canonPath($this->sqliHome), self::canonPath($this->home)]);
        $found = [];
        $dir = @realpath($start) ?: $start;
        $prev = null;
        while ($dir !== '' && $dir !== $prev) {
            if (!in_array(self::canonPath($dir), $skip, true)) {
                $sqliDir = self::joinPath($dir, '.sqli');
                $hasLegacy = false;
                foreach (self::LEGACY_MOVES as $old => $_) {
                    $hasLegacy = $hasLegacy || is_file(self::joinPath($dir, $old));
                }
                if (is_dir($sqliDir) || $hasLegacy) {
                    if (!is_dir($sqliDir) && !@mkdir($sqliDir, 0700, true) && !is_dir($sqliDir)) {
                        $this->notices[] = "could not create $sqliDir to migrate legacy state files: " . self::lastOsError();
                    } else {
                        $this->migrateSqliDir($sqliDir);
                        $this->migrateLegacyMoves($dir, $sqliDir);
                        $found[] = $sqliDir;
                    }
                }
            }
            $prev = $dir;
            $dir = dirname($dir);
        }
        return $found;
    }

    /** Rename pre-2.30.4 filenames inside an existing `.sqli/` dir. */
    private function migrateSqliDir(string $sqliDir): void
    {
        if (!is_dir($sqliDir)) {
            return;
        }
        foreach (self::LEGACY_RENAMES as $old => $new) {
            $from = self::joinPath($sqliDir, $old);
            $to   = self::joinPath($sqliDir, $new);
            if (is_file($from) && !is_file($to)) {
                if (@rename($from, $to)) {
                    $this->notices[] = "migrated $from -> $new";
                } else {
                    $this->notices[] = "could not migrate $from -> $new: " . self::lastOsError();
                }
            }
        }
    }

    /** Move pre-2.30.4 dot-files from $dir into its `.sqli/` dir. */
    private function migrateLegacyMoves(string $dir, string $sqliDir): void
    {
        if (!is_dir($sqliDir)) {
            return;
        }
        foreach (self::LEGACY_MOVES as $old => $new) {
            $from = self::joinPath($dir, $old);
            $to   = self::joinPath($sqliDir, $new);
            if (is_file($from) && !is_file($to)) {
                if (@rename($from, $to)) {
                    $this->notices[] = "migrated $from -> .sqli" . DIRECTORY_SEPARATOR . $new;
                } else {
                    $this->notices[] = "could not migrate $from -> $to: " . self::lastOsError();
                }
            }
        }
    }

    /**
     * Record the discovered project dirs in the home registry — an "ugly cache"
     * of where `.sqli` configs live, so tooling can list known projects without
     * scanning drives. Self-pruning: entries whose path style matches the
     * running platform and no longer exist are dropped; foreign-style entries
     * (e.g. `/mnt/d/...` seen from Windows, `D:\...` seen from WSL) are kept —
     * the other OS owns them. Paths are canonicalised so the same project seen
     * from Windows and WSL collapses to one entry. Skipped quietly when the
     * global `.sqli/` doesn't exist yet — a cache never creates directories.
     */
    private function updateRegistry(): void
    {
        if ($this->projectDirs === []) {
            return;
        }
        $globalDir = $this->globalSqliDir();
        if (!is_dir($globalDir)) {
            return;
        }
        $path = $this->registryPath();
        $data = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
        }
        $existing = is_array($data) && isset($data['projects']) && is_array($data['projects'])
            ? array_values(array_filter($data['projects'], 'is_string'))
            : [];

        // Index by canonical form so Windows + WSL spellings dedupe.
        $byCanon = [];
        foreach ($existing as $p) {
            $canon = self::canonPath($p);
            // Prune: only judge paths this platform can actually check.
            if (self::pathStyleMatchesPlatform($p) && !is_dir($p)) {
                continue;
            }
            $byCanon[$canon] = $p;
        }
        foreach ($this->projectDirs as $sqliDir) {
            $project = dirname($sqliDir); // register the project root, not .sqli itself
            $byCanon[self::canonPath($project)] = $project;
        }

        $projects = array_values($byCanon);
        sort($projects);
        if ($projects === $existing) {
            return; // nothing changed — don't churn the file
        }
        $json = json_encode([
            '_comment' => 'sqli project registry — machine-owned cache of dirs containing a .sqli config. Safe to delete; rebuilt as projects are visited.',
            'projects' => $projects,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && @file_put_contents($path, $json . PHP_EOL) !== false) {
            $this->notices[] = "updated registry $path";
        }
    }

    /**
     * Canonical comparison key for a path: forward slashes, WSL `/mnt/<x>/...`
     * folded to `<x>:/...`, lower-cased, no trailing slash. Lower-casing is safe
     * here — these are Windows-drive or user-owned paths where case-insensitive
     * identity is the pragmatic choice.
     */
    private static function canonPath(string $p): string
    {
        $p = str_replace('\\', '/', $p);
        if (preg_match('#^/mnt/([a-z])(/.*)?$#i', $p, $m)) {
            $p = $m[1] . ':' . ($m[2] ?? '');
        }
        return strtolower(rtrim($p, '/')) ?: '/';
    }

    /** True when this platform can meaningfully existence-check the path. */
    private static function pathStyleMatchesPlatform(string $p): bool
    {
        $isWindowsStyle = (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $p);
        return DIRECTORY_SEPARATOR === '\\' ? $isWindowsStyle : !$isWindowsStyle;
    }

    /** Join path segments with the platform separator, trimming trailing slashes. */
    private static function joinPath(string $first, string ...$rest): string
    {
        $out = $first;
        foreach ($rest as $p) {
            $out = rtrim($out, '/\\') . DIRECTORY_SEPARATOR . $p;
        }
        return $out;
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
            'sqli - Interactive MySQL client',
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
        $lines[] = 'Config files (JSON) layer, lowest -> highest precedence:';
        $lines[] = '  built-in defaults';
        $lines[] = '  < $SQLI_HOME/.sqli/sqli_config.json      (global; $SQLI_HOME defaults to ~)';
        $lines[] = '  < every .sqli/sqli_config.json from the cwd up to the root';
        $lines[] = '    (closest to the cwd wins per key)';
        $lines[] = '  < --config (or $SQLI_CONFIG) < --user-config (or $SQLI_USER_CONFIG)';
        $lines[] = '  < .sqli/sqli_credentials.json            (secrets for the selected env)';
        $lines[] = '  < explicit command-line flags';
        $lines[] = '';
        $lines[] = 'Each file: flat option keys and/or a `defaults{}` base, plus an `envs{}`';
        $lines[] = 'section of named environments (support `extends`). Pick one with';
        $lines[] = '  -E NAME / -E=NAME / --env NAME / $SQLI_ENV';
        $lines[] = 'or let the files pick: `"env": "NAME"` in a file\'s defaults{} is used when';
        $lines[] = 'neither is given (closest layer wins, "" cancels a farther one), and';
        $lines[] = '--no-env ignores $SQLI_ENV and that default (an explicit -E still wins).';
        $lines[] = 'Paths may use ${SQLI_HOME} and a leading ~.  Validate files with --lint.';
        $lines[] = '';
        $lines[] = 'Scripting: -e "SQL" (or SQL piped on stdin) runs without the TUI and prints';
        $lines[] = 'each result set as CSV (NULL as a bare NULL), header row unless -N.';
        $lines[] = 'Anything the TUI would ask you to confirm is refused (exit 2) and nothing';
        $lines[] = 'runs; a PK-scoped UPDATE/DELETE asks nothing, so it runs. Not read-only.';
        $lines[] = 'No 1000-row cap. Exit 0 = OK, 1 = error, 2 = refused.';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
