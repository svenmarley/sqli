<?php

declare(strict_types=1);

namespace Sqli\Config;

/**
 * Is the home `.sqli/` closed to other accounts (§2)?
 *
 * It holds the credentials, the write log, the `projects` list and the only
 * `init-command` sqli runs unasked, so another local account that can write
 * into it can point sqli anywhere. sqli reports this — once at startup and in
 * `--lint` — with the command that fixes it, and changes nothing itself.
 */
final class HomePermissions
{
    /** Accounts that mean "everybody on this machine" in an ACL. */
    private const BROAD_PRINCIPALS = [
        'everyone',
        'builtin\\users',
        'nt authority\\authenticated users',
    ];

    /** icacls rights that let the holder change what is in the folder. */
    private const WRITE_RIGHTS = ['F', 'M', 'W', 'D', 'WD', 'AD', 'WDAC', 'WO', 'DE', 'DC', 'GA', 'GW'];

    /**
     * Linux / WSL: the folder must give group and others nothing, and so must
     * the credentials file.
     *
     * @return list<string>
     */
    public static function posix(string $dir, ?string $credentials): array
    {
        $out = [];
        $mode = @fileperms($dir);
        if ($mode !== false && ($mode & 0077) !== 0) {
            $out[] = sprintf('%s is open to other accounts (mode %o) — fix: chmod 700 %s',
                $dir, $mode & 0777, escapeshellarg($dir));
        }
        if ($credentials !== null) {
            $mode = @fileperms($credentials);
            if ($mode !== false && ($mode & 0077) !== 0) {
                $out[] = sprintf('%s is readable by other accounts (mode %o) — fix: chmod 600 %s',
                    $credentials, $mode & 0777, escapeshellarg($credentials));
            }
        }
        return $out;
    }

    /**
     * Windows: ask `icacls` for the folder's ACL and name any broad group that
     * can write to it. Silent when icacls cannot be run — a missing diagnostic
     * is not a reason to refuse to start.
     *
     * @return list<string>
     */
    public static function windows(string $dir): array
    {
        $output = self::icacls($dir);
        if ($output === null) {
            return [];
        }
        $broad = self::broadWriters($output);
        if ($broad === []) {
            return [];
        }
        return [
            $dir . ' can be changed by ' . implode(', ', $broad)
            . ' — fix: icacls "' . $dir . '" /inheritance:d && icacls "' . $dir . '" /remove:g '
            . implode(' ', array_map(static fn (string $p): string => '"' . self::shortName($p) . '"', $broad)),
        ];
    }

    /**
     * The broad principals in an icacls listing that hold a write right.
     * Exposed for the tests, which feed it captured listings.
     *
     * @return list<string>
     */
    public static function broadWriters(string $icaclsOutput): array
    {
        $found = [];
        foreach (preg_split('/\R/', $icaclsOutput) ?: [] as $line) {
            // "C:\path NAME:(I)(OI)(CI)(F)" on the first line, "   NAME:(…)" after.
            if (!preg_match('/(?:^|\s)([^:\s][^:]*?):((?:\([A-Z,]+\))+)\s*$/i', $line, $m)) {
                continue;
            }
            $name = trim($m[1]);
            $short = strtolower($name);
            if (!in_array($short, self::BROAD_PRINCIPALS, true)) {
                continue;
            }
            preg_match_all('/\(([A-Z,]+)\)/i', $m[2], $groups);
            foreach ($groups[1] as $group) {
                foreach (explode(',', strtoupper($group)) as $right) {
                    if (in_array($right, self::WRITE_RIGHTS, true)) {
                        $found[$short] = self::canonicalName($short);
                        break 2;
                    }
                }
            }
        }
        return array_values($found);
    }

    private static function canonicalName(string $lower): string
    {
        return match (true) {
            str_ends_with($lower, 'authenticated users') => 'NT AUTHORITY\\Authenticated Users',
            str_ends_with($lower, 'users')               => 'BUILTIN\\Users',
            default                                      => 'Everyone',
        };
    }

    /** The name `icacls /remove:g` takes: the part after the backslash. */
    private static function shortName(string $principal): string
    {
        $pos = strrpos($principal, '\\');
        return $pos === false ? $principal : substr($principal, $pos + 1);
    }

    private static function icacls(string $dir): ?string
    {
        $proc = @proc_open(['icacls', $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0 ? $out : null;
    }
}
