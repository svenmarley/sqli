<?php

declare(strict_types=1);

namespace Sqli\Support;

/**
 * Cross-platform filesystem path resolution.
 *
 * sqli is run against the same saved settings from three environments (see the
 * `runs under WSL` note): native Windows, native Linux/Ubuntu, and WSL — where a
 * Windows drive `D:\…` is reached as `/mnt/d/…`. A Data-Dictionary path saved in
 * one form is therefore unreadable in another, which is why a `/mnt/d/…` path
 * saved under WSL silently failed to load under native Windows.
 *
 * {@see resolve()} takes a stored path and returns the first form that actually
 * exists in the current runtime, translating between the Windows-drive and
 * WSL-mount spellings as needed. Native Linux paths (`/home/…`) and native
 * Windows paths pass straight through — there is nothing to translate and they
 * resolve as-is. Purely in-process string work: no shell, no `wslpath`.
 */
final class Path
{
    /**
     * Return the given path if it exists, otherwise the first cross-platform
     * translation of it that exists in the current runtime. Falls back to the
     * original string when nothing resolves (so callers still get a usable value
     * to report in an error). Empty in, empty out.
     */
    public static function resolve(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        foreach (self::candidates($path) as $candidate) {
            if (@file_exists($candidate)) {
                return $candidate;
            }
        }
        return $path;
    }

    /**
     * Resolve a path a person typed or pasted, forgiving the spellings that
     * come out of a Windows/WSL mix: backslash separators under Linux
     * (`\mnt\d\a`, `/mnt/d\a\b`), shell escapes copied off a prompt
     * (`/mnt/d/My\ Docs/\!x`), and a mount path missing its leading slash
     * (`mnt/d/a`). The literal spelling is always tried first, so a real file
     * with a backslash in its name still wins. Returns the first spelling that
     * exists, or the input unchanged when none does.
     */
    public static function fromTyped(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        // Separators first: `\!x` in `\mnt\d\!x` is a separator plus `!`, and
        // reading it as a shell escape would glue two segments together.
        $spellings = [$path, str_replace('\\', '/', $path)];
        if (DIRECTORY_SEPARATOR === '/') {
            $spellings[] = (string) preg_replace('#\\\\(.)#', '$1', $path);
        }
        foreach ($spellings as $spelling) {
            $tries = [$spelling];
            if (preg_match('#^mnt/[A-Za-z](/|$)#', $spelling)) {
                $tries[] = '/' . $spelling;
            }
            foreach ($tries as $try) {
                $resolved = self::resolve($try);
                if (@file_exists($resolved)) {
                    return $resolved;
                }
            }
        }
        return $path;
    }

    /**
     * The path plus its cross-platform spellings, ordered with the current
     * runtime's native form first so an ambiguous path prefers what's local.
     *
     * @return list<string>
     */
    private static function candidates(string $path): array
    {
        $win = self::toWindows($path);
        $mnt = self::toWslMount($path);
        $ordered = PHP_OS_FAMILY === 'Windows'
            ? [$path, $win, $mnt]
            : [$path, $mnt, $win];

        $out = [];
        foreach ($ordered as $c) {
            if ($c !== null && $c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Windows-drive spelling of a path, or null when it isn't drive-anchored.
     * `/mnt/d/a/b` → `D:\a\b`; an already-Windows `d:/a/b` → `D:\a\b` (normalized).
     * A rootless or native-Linux path (`/home/x`) has no drive and returns null.
     */
    private static function toWindows(string $path): ?string
    {
        if (preg_match('#^/mnt/([A-Za-z])(/.*)?$#', $path, $m)) {
            $rest = $m[2] ?? '';
            return strtoupper($m[1]) . ':' . str_replace('/', '\\', $rest);
        }
        if (preg_match('#^([A-Za-z]):[\\\\/](.*)$#', $path, $m)) {
            return strtoupper($m[1]) . ':\\' . str_replace('/', '\\', $m[2]);
        }
        return null;
    }

    /**
     * WSL-mount spelling of a path, or null when it isn't drive-anchored.
     * `D:\a\b` (or `d:/a/b`) → `/mnt/d/a/b`; an already-mount `/mnt/d/a` returns
     * itself. A native-Linux path (`/home/x`) has no drive and returns null.
     */
    private static function toWslMount(string $path): ?string
    {
        if (preg_match('#^([A-Za-z]):[\\\\/](.*)$#', $path, $m)) {
            return '/mnt/' . strtolower($m[1]) . '/' . str_replace('\\', '/', $m[2]);
        }
        if (preg_match('#^/mnt/[A-Za-z](/.*)?$#', $path)) {
            return $path;
        }
        return null;
    }
}
