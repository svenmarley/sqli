<?php

declare(strict_types=1);

namespace Sqli\Terminal;

use FFI;

/**
 * Tells a Git Bash (mintty) terminal from a real pipe on Windows.
 *
 * mintty is not a Windows console. The MSYS runtime gives each program it
 * starts a pair of named pipes to stand in for the terminal, so under Git Bash
 * `stream_isatty()` is false for a person typing just as it is for
 * `echo … | sqli`. What does differ is the pipe's name: the runtime calls its
 * terminal pipes `\msys-<hex>-pty<N>-from-master` (stdin) and
 * `…-to-master` (stdout), `cygwin-` under Cygwin, and nothing else is named
 * like that. Git for Windows' own isatty() checks the same name.
 *
 * {@see check()} answers true (a terminal pipe), false (some other pipe, a
 * file, anything else), or null when it cannot ask — not Windows, no FFI —
 * so the caller keeps whatever it did before.
 */
final class MsysPty
{
    public const STDIN  = 4294967286; // (DWORD)-10
    public const STDOUT = 4294967285; // (DWORD)-11

    private const FILE_TYPE_PIPE = 3;
    private const FILE_NAME_INFO = 2;   // FILE_INFO_BY_HANDLE_CLASS FileNameInfo
    private const NAME_BYTES     = 1024;

    private static ?FFI $ffi = null;

    /** True for the name of an MSYS/Cygwin terminal pipe, as the handle reports it. */
    public static function isPtyName(string $name): bool
    {
        return preg_match('/^\\\\(?:msys|cygwin)-[0-9a-f]+-pty\d+-(?:from|to)-master/i', $name) === 1;
    }

    /**
     * Is this standard handle an MSYS/Cygwin terminal? Null when it can't be told.
     *
     * @param int $which {@see STDIN} or {@see STDOUT}
     */
    public static function check(int $which): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $h = $ffi->GetStdHandle($which);
            if ($h === null || $ffi->GetFileType($h) !== self::FILE_TYPE_PIPE) {
                return false;
            }
            $buf = $ffi->new('unsigned char[' . (4 + self::NAME_BYTES) . ']');
            if (!$ffi->GetFileInformationByHandleEx($h, self::FILE_NAME_INFO, FFI::addr($buf), FFI::sizeof($buf))) {
                return false;
            }
            $len = unpack('V', FFI::string($buf, 4))[1];
            $len = min($len, self::NAME_BYTES);
            $wide = FFI::string(FFI::addr($buf[4]), $len);
            $name = (string) mb_convert_encoding($wide, 'UTF-8', 'UTF-16LE');
            return self::isPtyName($name);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (PHP_OS_FAMILY !== 'Windows' || !extension_loaded('ffi')) {
            return null;
        }
        try {
            self::$ffi = FFI::cdef(<<<'CDEF'
                typedef void* HANDLE;
                HANDLE GetStdHandle(unsigned long nStdHandle);
                unsigned long GetFileType(HANDLE hFile);
                int GetFileInformationByHandleEx(HANDLE hFile, int FileInformationClass, void* lpFileInformation, unsigned long dwBufferSize);
            CDEF, 'kernel32.dll');
        } catch (\Throwable) {
            return null;
        }
        return self::$ffi;
    }
}
