<?php

declare(strict_types=1);

/**
 * sqli2 entry point.
 *
 * Parses CLI args, handles --help/--version, builds the Database and App, and
 * runs the TUI. Display errors are silenced so a stray warning can never
 * corrupt the alternate-screen rendering; errors go to the log instead.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Without an explicit path, log_errors sends to the SAPI default — stderr in CLI,
// which the alt-screen TUI swallows. Pin PHP engine errors to a real file so they
// can be found (same dir as the app, alongside debug.log). Same on Windows + WSL.
ini_set('error_log', __DIR__ . '/php_errors.log');

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    // Minimal fallback autoloader so the app runs before `composer install`.
    require_once __DIR__ . '/includes/my.php';
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'Sqli2\\')) {
            return;
        }
        $rel = str_replace('\\', '/', substr($class, strlen('Sqli2\\')));
        $path = __DIR__ . '/src/' . $rel . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

use includes\my;
use Sqli2\Config\Config;
use Sqli2\Config\Settings;
use Sqli2\Core\App;
use Sqli2\Db\Database;

// Version scheme: major.<ISO week number>.<counter>. The middle digit tracks the
// week we're working in; the trailing counter just keeps climbing per change.
const SQLI_VERSION = '2.28.44';

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

// A bad --config/--user-config (missing file, invalid JSON) is fatal: fail loudly
// here, on the normal terminal, rather than starting the TUI half-configured.
$configErrors = $config->configErrors();
if ($configErrors !== []) {
    fwrite(STDERR, 'sqli: ' . implode(PHP_EOL . 'sqli: ', $configErrors) . PHP_EOL);
    exit(1);
}

my::lineout('FILE:FILE', 'sqli2 start', SQLI_VERSION);

echo 'Starting sqli ' . SQLI_VERSION . ' …' . PHP_EOL;

$settings = new Settings();

$database = new Database(
    host: $config->getString('host', 'localhost'),
    user: $config->getString('user'),
    password: $config->getString('password'),
    database: $config->getString('database'),
    port: $config->getInt('port', 3306),
    socket: $config->getString('socket'),
    debug: $config->isFlag('debug'),
);

$app = new App($database, $config, $settings, SQLI_VERSION);
exit($app->run());
