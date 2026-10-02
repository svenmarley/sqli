<?php

declare(strict_types=1);

/**
 * sqli entry point.
 *
 * Parses CLI args, handles --help/--version, builds the Database and App, and
 * runs the TUI — or, with -e or SQL piped on stdin, runs the SQL headless and
 * prints CSV (scripting mode). Display errors are silenced so a stray warning can never
 * corrupt the alternate-screen rendering; errors go to the log instead.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Without an explicit path, log_errors sends to the SAPI default — stderr in CLI,
// which the alt-screen TUI swallows. Pin PHP engine errors to a real file so they
// can be found (same dir as the app, alongside debug.log). Same on Windows + WSL.
ini_set('error_log', __DIR__ . '/php_errors.log');

// No composer — zero external deps. PSR-4 autoload for Sqli\ from src/.
require_once __DIR__ . '/includes/my.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Sqli\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen('Sqli\\')));
    $path = __DIR__ . '/src/' . $rel . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use includes\my;
use Sqli\Config\Config;
use Sqli\Config\ConfigLint;
use Sqli\Config\RuntimeCheck;
use Sqli\Config\Settings;
use Sqli\Core\App;
use Sqli\Db\ConnectionOptions;
use Sqli\Db\Database;
use Sqli\Db\TlsOptions;
use Sqli\Export\CsvExport;
use Sqli\Terminal\MsysPty;
use Sqli\Terminal\PasswordPrompt;

// Version scheme: major.<ISO week number>.<counter>. The middle digit tracks the
// week we're working in; the trailing counter just keeps climbing per change.
const SQLI_VERSION = '2.40.1';

// Route my::lineout('FILE:FILE', …) to a log file that actually exists. The
// library default (/data/tmp/error_logs/debug.log) is never created in FILE
// mode, so without this every debug line was silently dropped.
my::$debugLogPath = __DIR__ . '/debug.log';

$config = new Config($argv);

if ($config->isFlag('help')) {
    echo $config->helpText();
    exit(0);
}
if ($config->isFlag('version')) {
    echo 'sqli ' . SQLI_VERSION . PHP_EOL;
    exit(0);
}

// --lint: validate every config/state file (read-only) and exit. Runs before the
// fatal-error check below so a broken file is *reported*, not just refused.
if ($config->isFlag('lint')) {
    $lint = ConfigLint::run($config);
    foreach ($lint->errors() as $e) {
        fwrite(STDERR, 'ERROR  ' . $e . PHP_EOL);
    }
    foreach ($lint->warnings() as $w) {
        fwrite(STDERR, 'WARN   ' . $w . PHP_EOL);
    }
    foreach ($lint->notes() as $n) {
        echo 'note   ' . $n . PHP_EOL;
    }
    echo $lint->hasProblems()
        ? sprintf('sqli --lint: %d error(s), %d warning(s)' . PHP_EOL, count($lint->errors()), count($lint->warnings()))
        : 'sqli --lint: all config files OK' . PHP_EOL;
    exit($lint->errors() !== [] ? 1 : 0);
}

// A bad --config/--user-config (missing file, invalid JSON) is fatal: fail loudly
// here, on the normal terminal, rather than starting the TUI half-configured.
$configErrors = $config->configErrors();
if ($configErrors !== []) {
    fwrite(STDERR, 'sqli: ' . implode(PHP_EOL . 'sqli: ', $configErrors) . PHP_EOL);
    exit(1);
}

my::lineout('FILE:FILE', 'sqli start', SQLI_VERSION);

// Scripting mode (backlog #6): `-e SQL`, or SQL piped on stdin. No TUI, the
// result goes out as CSV, and the exit code says how it went.
//
// Git Bash (MSYSTEM set) needs one more question. With ConPTY on, mintty gives
// php a real console and isatty answers correctly. With it off (older Git for
// Windows, MSYS=disable_pcon) the terminal is a pair of named pipes, so a
// person typing looks exactly like `echo … | sqli` to isatty — MsysPty tells
// them apart by the pipe's name. When it can't ask (no FFI), Git Bash keeps
// the TUI, as it always did, and `-e` still works.
$execute = $config->get('execute');
$stdinIsTerminal = PasswordPrompt::isInteractive();
$stdoutIsTerminal = function_exists('stream_isatty') && @stream_isatty(STDOUT);
if (!$stdinIsTerminal && getenv('MSYSTEM') !== false) {
    $stdinIsTerminal = MsysPty::check(MsysPty::STDIN) ?? true;
    $stdoutIsTerminal = $stdoutIsTerminal || (MsysPty::check(MsysPty::STDOUT) ?? true);
}
$script = $execute !== null || !$stdinIsTerminal;
$verbose = $config->isFlag('verbose');
if (!$script) {
    if ($config->getString('out') !== '') {
        fwrite(STDERR, 'sqli: --out only applies with -e or SQL piped on stdin' . PHP_EOL);
        exit(1);
    }
    // A person at the keyboard with stdout redirected would get the TUI's
    // escape codes in the file and nothing on the screen.
    if ($stdinIsTerminal && !$stdoutIsTerminal) {
        fwrite(STDERR, 'sqli: stdout is not a terminal — give the SQL with -e, or pipe it on stdin' . PHP_EOL);
        exit(1);
    }
} elseif ($execute === null && $config->wantsPasswordPrompt()) {
    // The prompt would read the password off stdin, which is carrying the SQL.
    fwrite(STDERR, 'sqli: -p would read the password from stdin, which is carrying the SQL — '
        . 'use -e, or keep the password in sqli_credentials.json' . PHP_EOL);
    exit(1);
}
$sqlIn = $execute ?? '';
if ($script && $execute === null) {
    $sqlIn = (string) stream_get_contents(STDIN);
}

// What goes on the plain terminal before the TUI takes over. In scripting mode
// stdout is the CSV, so notes and warnings go to stderr, and the chatter only
// under --verbose.
$chatter = $script ? ($verbose ? STDERR : null) : STDOUT;
$warn = $script ? STDERR : STDOUT;

if ($chatter !== null) {
    fwrite($chatter, 'Starting sqli ' . SQLI_VERSION . ' …' . PHP_EOL);
}

// An env nobody typed gets one line on the plain terminal. A default `env` in a
// config file is the one source of a selected env that leaves no trace on the
// command line, and the header only mentions it if that env also sets a
// `comment` — so this is the one place it is guaranteed to be said.
if ($config->envSource() === 'config' && $chatter !== null) {
    fwrite($chatter, 'Using env ' . $config->envName() . ' (set in '
        . ($config->defaultEnvFile() ?? 'the config') . ')' . PHP_EOL);
}

// The selected env's connection settings disagree between config files — a
// project's `prod` naming a different host than the global `prod`. Closest wins,
// as always; this only makes sure it is not a surprise.
foreach ($config->envConflicts($config->envChain()) as $conflict) {
    fwrite($warn, 'sqli: warning: ' . $conflict . PHP_EOL);
}

// Optional extensions whose absence takes a feature away (Esc-cancel, fast
// resize). sqli works without them; this is only so nobody has to discover it.
// Neither feature exists without the TUI.
if (!$script) {
    foreach (RuntimeCheck::missing($config->getString('driver', ConnectionOptions::DRIVER_AUTO)) as $missing) {
        echo 'sqli: note: ' . $missing . PHP_EOL;
    }
}

// State lives in the closest project `.sqli/`, else the global one; when
// neither exists yet the app prompts before the first save (never at startup).
$settings = new Settings($config->stateDir());

// TLS policy (§2). A bad ssl-* combination is fatal on the plain terminal for
// the same reason a bad --config is: starting the TUI with encryption silently
// off is the one outcome nobody would notice.
$tlsErrors = [];
$tls = TlsOptions::fromValues(
    mode: $config->getString('ssl-mode', TlsOptions::PREFERRED),
    ca: $config->getString('ssl-ca'),
    cert: $config->getString('ssl-cert'),
    key: $config->getString('ssl-key'),
    cipher: $config->getString('ssl-cipher'),
    errors: $tlsErrors,
    caPath: $config->getString('ssl-capath'),
    serverPublicKey: $config->getString('server-public-key'),
);
if ($tlsErrors !== []) {
    fwrite(STDERR, 'sqli: ' . implode(PHP_EOL . 'sqli: ', $tlsErrors) . PHP_EOL);
    exit(1);
}

// The rest of the connection settings (§2). Fatal on error for the same reason
// as the TLS block above — a timeout or an init-command that was rejected is a
// configuration the user believes is in force, and finding out otherwise takes
// hours. Warnings print and continue: they describe values that work.
$connErrors = [];
$connWarnings = [];
$connection = ConnectionOptions::fromValues(
    connectTimeout: $config->getInt('connect-timeout', ConnectionOptions::CONNECT_TIMEOUT_DEFAULT),
    readTimeout: $config->getInt('read-timeout', ConnectionOptions::READ_TIMEOUT_DEFAULT),
    initCommand: $config->getString('init-command'),
    compress: $config->isFlag('compress'),
    foundRows: $config->isFlag('found-rows'),
    multiStatements: $config->isFlag('multi-statements'),
    ignoreSpace: $config->isFlag('ignore-space'),
    errors: $connErrors,
    warnings: $connWarnings,
    driver: $config->getString('driver', ConnectionOptions::DRIVER_AUTO),
    localInfile: $config->isFlag('local-infile'),
);
// `read-only` is not a connection option, so the pair cannot be judged inside
// ConnectionOptions. Fatal here for the same reason a bad ssl-* combination is:
// a configuration whose two halves disagree about how far this session is
// trusted is not one to start the TUI on and sort out later.
$infileConflict = ConnectionOptions::localInfileConflict(
    $config->isFlag('local-infile'),
    $config->isFlag('read-only'),
);
if ($infileConflict !== null) {
    $connErrors[] = $infileConflict;
}
if ($connErrors !== []) {
    fwrite(STDERR, 'sqli: ' . implode(PHP_EOL . 'sqli: ', $connErrors) . PHP_EOL);
    exit(1);
}
// A build that refuses the ini at runtime is a warning, not a failure: the
// connection is fine, it just keeps PHP's own read timeout. Said once here
// rather than on every reconnect.
$readTimeoutProblem = $connection->applyReadTimeout();
if ($readTimeoutProblem !== null) {
    $connWarnings[] = $readTimeoutProblem;
}
foreach ($connWarnings as $w) {
    fwrite(STDERR, 'sqli: warning: ' . $w . PHP_EOL);
}

// Ask for the password before the TUI takes the screen (§2). Doing it here, on
// the plain terminal, keeps the masking simple and means a mistyped password
// fails on a readable terminal instead of inside the alternate screen.
//
// Two reasons to ask. Either the user said so — a bare `-p`, or a config layer
// carrying `password-prompt` — or no password reached us at all, which for a
// named account is a missing password rather than an empty one. The second case
// is gated on a real terminal: with stdin on a pipe there is nobody to ask, and
// the read would eat whatever the pipe was carrying.
$password = $config->getString('password');
$askForPassword = $config->wantsPasswordPrompt()
    || ($config->passwordIsMissing() && PasswordPrompt::isInteractive());
if ($askForPassword) {
    $masked = null;
    $password = PasswordPrompt::read('Password', $masked);
    if ($masked === false && PasswordPrompt::isInteractive()) {
        my::lineout('FILE:FILE', 'password prompt could not disable echo');
    }
    // The password is never printed or logged, not even its length — under
    // --debug or otherwise.
}

// The password is readonly on Database, so a retry builds a fresh one rather
// than mutating a connection object that has already failed.
$makeDatabase = static fn (string $pw): Database => new Database(
    host: $config->getString('host', 'localhost'),
    user: $config->getString('user'),
    password: $pw,
    database: $config->getString('database'),
    port: $config->getInt('port', 3306),
    socket: $config->getString('socket'),
    debug: $config->isFlag('debug'),
    tls: $tls,
    options: $connection,
    label: $config->getString('host_label'),
);

/**
 * Connect here, on the plain terminal, before the TUI exists.
 *
 * It used to happen inside the TUI behind a busy modal, which put the error
 * somewhere nothing could act on it: the status line said "Connection failed:
 * Access denied" and the app then sat there offering no way to change a host,
 * a user or a password. A connection failure is the one error sqli cannot
 * work around, so it belongs on a terminal that can still scroll, copy and
 * show a full message — and it should end the process, not decorate it.
 *
 * The single exception is a wrong password, which the user *can* fix on the
 * spot: three attempts, then give up. Anything else — unknown host, refused
 * connection, missing grant on the database, a TLS policy the server won't
 * meet — is not something retyping a password repairs, so it exits at once
 * rather than pretending otherwise.
 */
$maxAttempts = 3;
$mayRetry = !$config->isFlag('no-password') && PasswordPrompt::isInteractive();
$database = $makeDatabase($password);

for ($attempt = 1; ; $attempt++) {
    if ($chatter !== null) {
        fwrite($chatter, 'Connecting to ' . $database->target() . ' …' . PHP_EOL);
    }
    try {
        $database->connect();
        break;
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        my::lineout('FILE:FILE', 'connect failed', 'attempt ' . $attempt, $message);
        fwrite(STDERR, 'sqli: connection failed: ' . $message . App::connectHint($message) . PHP_EOL);

        $retryable = Database::isAuthFailure($e) && $mayRetry && $attempt < $maxAttempts;
        if (!$retryable) {
            // Say why this is the end, so "it just quit" is never the takeaway.
            if (Database::isAuthFailure($e) && $attempt >= $maxAttempts) {
                fwrite(STDERR, sprintf('sqli: %d failed attempts — giving up.%s', $maxAttempts, PHP_EOL));
            }
            exit(1);
        }

        $masked = null;
        $password = PasswordPrompt::read(
            sprintf('Password (attempt %d of %d)', $attempt + 1, $maxAttempts),
            $masked,
        );
        $database = $makeDatabase($password);
    }
}

$app = new App($database, $config, $settings, SQLI_VERSION);

if (!$script) {
    exit($app->run());
}

$header = !$config->isFlag('skip-column-names');
$outPath = $config->getString('out');
if ($outPath === '') {
    exit($app->runScript($sqlIn, STDOUT, STDERR, $header, $verbose));
}

// --out: the same temp-and-rename the grid's export uses, so a run that fails
// part-way leaves no half a file behind to be mistaken for the whole one.
$code = 1;
$reason = CsvExport::writeWith($outPath, static function ($h) use ($app, $sqlIn, $header, $verbose, &$code): bool {
    $code = $app->runScript($sqlIn, $h, STDERR, $header, $verbose);
    return $code === 0;
});
if ($code !== 0) {
    fwrite(STDERR, 'sqli: ' . $outPath . ' was not written' . PHP_EOL);
    exit($code);
}
if ($reason !== null) {
    fwrite(STDERR, 'sqli: could not write ' . $outPath . ': ' . $reason . PHP_EOL);
    exit(1);
}
exit(0);
