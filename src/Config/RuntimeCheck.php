<?php

declare(strict_types=1);

namespace Sqli\Config;

use Sqli\Db\ConnectionOptions;
use Sqli\Db\MysqliLink;
use Sqli\Terminal\PosixTty;
use Sqli\Terminal\WindowsConsole;

/**
 * The optional PHP extensions whose absence changes what sqli can do, said in
 * one line each with the fix.
 *
 * Both are optional on purpose, and sqli already behaves correctly without
 * them: no `mysqli` means Esc is simply not offered, and no `ffi` means the
 * window is measured by asking the terminal. What was missing is anyone being
 * told — the headline feature just wasn't there, and finding out why meant
 * reading the Requirements section. So the entry point prints these before the
 * TUI opens and `--lint` lists them as notes.
 *
 * Each check asks the same question the code that uses the extension asks
 * ({@see MysqliLink::available()}, `isSupported()` on the terminal backends),
 * so the notice and the behaviour cannot disagree.
 */
final class RuntimeCheck
{
    /**
     * @param string $driver the resolved `driver` option. `pdo` chose not to
     *        cancel, and `mysqli` already refuses to start without the
     *        extension, so only `auto` is told about it.
     * @return list<string>
     */
    public static function missing(string $driver): array
    {
        $out = [];
        $ini = self::iniHint();

        if ($driver === ConnectionOptions::DRIVER_AUTO && !MysqliLink::available()) {
            $out[] = extension_loaded('mysqli')
                ? 'Esc can\'t cancel a running query: this PHP\'s mysqli is not built on mysqlnd (no mysqli_poll)'
                : "Esc can't cancel a running query: enable extension=mysqli in $ini";
        }

        $ffi = PHP_OS_FAMILY === 'Windows' ? WindowsConsole::isSupported() : PosixTty::isSupported();
        if (!$ffi) {
            $out[] = "window resizing is slower: enable extension=ffi in $ini";
        }

        return $out;
    }

    /** Which php.ini to edit, or how to find out when none is loaded. */
    private static function iniHint(): string
    {
        $loaded = php_ini_loaded_file();
        return is_string($loaded) && $loaded !== ''
            ? $loaded
            : 'php.ini (none is loaded — `php --ini` shows where PHP looks)';
    }
}
