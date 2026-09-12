<?php

declare(strict_types=1);

namespace Sqli\Sql;

/**
 * Splits input into individual SQL statements, and reports what command each
 * one actually is (§5; beta-readiness items 1, 6 and 9).
 *
 * This exists because guarding a query on `preg_split('/\s+/')`'s first token is
 * not a guard at all. `CLIENT_MULTI_STATEMENTS` is on, so `SELECT 1; DROP TABLE t`
 * used to read as a SELECT and sail past both read-only mode and the
 * dangerous-query confirm. A block comment glued to the front of a `DELETE`, and
 * a `WITH … DELETE`, bypassed them the same way. Splitting the text properly —
 * and then naming each piece's real command — is what lets every statement be
 * judged on its own terms.
 *
 * The scanner is byte-wise, which is safe for utf8mb4: every byte of a
 * multi-byte character is >= 0x80, so none can be mistaken for a quote, a dash
 * or a semicolon.
 *
 * What it understands:
 *
 * - `'…'` and `"…"` strings, with both `\'` escapes and `''` doubling.
 * - `` `…` `` quoted identifiers, with `` `` `` doubling.
 * - `-- ` and `#` line comments, and `/*` block comments. MySQL's rule that `--`
 *   needs whitespace after it is honoured, so `a--b` stays arithmetic.
 * - Executable comments (`/*!` and the version-gated `/*!50100`) are scanned
 *   **as SQL**, not skipped. MySQL runs what is inside them, so a guard that
 *   skipped them would be looking away at exactly the wrong moment.
 * - `DELIMITER //` at the start of a statement, which is what makes a
 *   `CREATE PROCEDURE … BEGIN … END` body survive splitting. The command is
 *   consumed, never emitted, and never sent to the server.
 *
 * Statements that hold nothing but comments are dropped: the server would only
 * answer them with a syntax error, and a `SOURCE`d file is full of them.
 *
 * Known limit, accepted deliberately: a `;` **inside** an executable comment
 * splits the input, which can cut such a comment in half. Erring toward
 * splitting risks a syntax error; erring the other way risks running an
 * unguarded statement, so the scanner errs toward the noisy failure.
 */
final class Splitter
{
    public const DEFAULT_DELIMITER = ';';

    /**
     * Split input into executable statements, in order.
     *
     * Terminators are stripped and each statement is trimmed. Comment-only and
     * empty pieces are dropped, so the result is exactly the list of things
     * worth sending to the server — which may be empty.
     *
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $len   = strlen($sql);
        $out   = [];
        $delim = self::DEFAULT_DELIMITER;
        $i     = 0;
        $start = 0;
        // True while nothing but whitespace and comments has been seen since the
        // last terminator — the only place a DELIMITER command is recognised.
        $atStart = true;

        while ($i < $len) {
            $c = $sql[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if ($atStart && self::isDelimiterCommand($sql, $i)) {
                [$delim, $i] = self::readDelimiterCommand($sql, $i, $delim);
                $start = $i;
                continue;
            }

            if ($c === "'" || $c === '"') {
                $i = self::skipString($sql, $i);
                $atStart = false;
                continue;
            }

            if ($c === '`') {
                $i = self::skipIdentifier($sql, $i);
                $atStart = false;
                continue;
            }

            if (self::isLineComment($sql, $i)) {
                $i = self::skipLineComment($sql, $i);
                continue;
            }

            if (self::isBlockComment($sql, $i)) {
                // An executable comment is code: step over the opener only, and
                // keep scanning its contents as SQL.
                $i = self::isExecutableComment($sql, $i)
                    ? self::enterExecutableComment($sql, $i)
                    : self::skipBlockComment($sql, $i);
                continue;
            }

            if (self::matchesAt($sql, $i, $delim)) {
                self::emit($out, substr($sql, $start, $i - $start));
                $i += strlen($delim);
                $start = $i;
                $atStart = true;
                continue;
            }

            $atStart = false;
            $i++;
        }

        // Whatever trails the last terminator is a statement too — the ordinary
        // case, since the CLI strips the `;` that submitted the line.
        self::emit($out, substr($sql, $start));

        return $out;
    }

    /**
     * The command a statement actually performs, upper-cased — the name the
     * safety guards must judge it by.
     *
     * Leading whitespace, comments and open parens are stepped over, so a
     * comment-prefixed `DELETE` reports DELETE and `(SELECT 1) UNION …` reports
     * SELECT. A `WITH` is resolved past its CTE list to what it introduces, so
     * `WITH c AS (…) DELETE FROM t` reports DELETE rather than WITH.
     *
     * Returns '' when there is no command at all (empty or comment-only input).
     */
    public static function commandKeyword(string $sql): string
    {
        $first = self::firstKeyword($sql);
        if ($first !== 'WITH') {
            return $first;
        }
        return self::keywordAfterCteList($sql) ?: $first;
    }

    /**
     * The literal leading keyword, upper-cased, after stepping over whitespace,
     * comments and open parens. Unlike {@see commandKeyword()} this does not
     * look past a CTE list, so `WITH …` reports WITH.
     */
    public static function firstKeyword(string $sql): string
    {
        [$word] = self::nextWord($sql, 0);
        return $word;
    }

    /**
     * The statement with its comments removed — what a guard must read when it
     * has to look *inside* a statement rather than just name it.
     *
     * {@see commandKeyword()} steps over a leading comment, which is enough to
     * identify a command but not to judge one. The guards in `App::dangerCheck()`
     * match on the text itself, and a comment is text:
     *
     *     DELETE FROM users
     *     -- WHERE id = 1
     *
     * has no WHERE clause at all, yet `/\bWHERE\b(.*)$/is` found one, the PK test
     * accepted `id = 1`, and a delete of every row in the table ran with no
     * confirmation — the exact prompt the guard exists to raise. A comment can
     * equally *hide* the real table from `FROM`, or offer a second one.
     *
     * Each comment is replaced by a single space so the tokens either side stay
     * apart, and a line comment's terminating newline is left in place. Strings
     * and `` ` ``-quoted identifiers are copied through untouched: a `--` inside
     * one is data, not a comment.
     *
     * Executable comments keep their **contents**, losing only the `/*!50100`
     * opener and its closing marker. MySQL runs what is inside them, so removing
     * it would blind the guard to a real statement — the same reasoning that
     * makes {@see split()} scan them as SQL.
     *
     * The result is for reading, never for execution: it is not the SQL the user
     * typed, and an optimizer-hint comment does not survive it.
     */
    public static function stripComments(string $sql): string
    {
        $len  = strlen($sql);
        $out  = '';
        $i    = 0;
        $keep = 0;  // start of the run of bytes still to be copied verbatim
        $exec = 0;  // open executable comments, whose `*/` is an opener's partner

        while ($i < $len) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"') {
                $i = self::skipString($sql, $i);
                continue;
            }

            if ($c === '`') {
                $i = self::skipIdentifier($sql, $i);
                continue;
            }

            if (self::isLineComment($sql, $i)) {
                $out .= substr($sql, $keep, $i - $keep) . ' ';
                $i = self::skipLineComment($sql, $i);   // stops at the newline
                $keep = $i;
                continue;
            }

            if (self::isBlockComment($sql, $i)) {
                $out .= substr($sql, $keep, $i - $keep) . ' ';
                if (self::isExecutableComment($sql, $i)) {
                    $i = self::enterExecutableComment($sql, $i);
                    $exec++;
                } else {
                    $i = self::skipBlockComment($sql, $i);
                }
                $keep = $i;
                continue;
            }

            // The other end of an executable comment we descended into.
            if ($exec > 0 && $c === '*' && ($sql[$i + 1] ?? '') === '/') {
                $out .= substr($sql, $keep, $i - $keep) . ' ';
                $i += 2;
                $keep = $i;
                $exec--;
                continue;
            }

            $i++;
        }

        return $out . substr($sql, $keep);
    }

    // --- statement assembly -------------------------------------------------

    /**
     * Add a statement to the list unless it is empty or nothing but comments.
     *
     * @param list<string> $out
     */
    private static function emit(array &$out, string $piece): void
    {
        $piece = trim($piece);
        if ($piece === '' || self::firstKeyword($piece) === '') {
            return;
        }
        $out[] = $piece;
    }

    // --- DELIMITER ----------------------------------------------------------

    /**
     * Is a `DELIMITER <x>` client command starting at $i?
     *
     * A `DELIMITER` with nothing after it counts, and is consumed as a no-op.
     * That is not a hypothetical: pasting a script that ends `DELIMITER ;` loses
     * the final `;` to the CLI's own terminator, so the keyword arrives bare —
     * and passing it through would end an otherwise clean batch with a syntax
     * error from the server.
     */
    private static function isDelimiterCommand(string $sql, int $i): bool
    {
        if (strtoupper(substr($sql, $i, 9)) !== 'DELIMITER') {
            return false;
        }
        $after = $sql[$i + 9] ?? '';
        return $after === '' || ctype_space($after);
    }

    /**
     * Consume a `DELIMITER <x>` command and report the new terminator.
     *
     * Only the keyword and its argument are consumed — **not** the rest of the
     * line, which is what the mysql client does. Stopping at the argument reads
     * the same for a real file, where nothing follows it anyway, and it keeps
     * the command working on input that reaches here with its newlines already
     * lost: `SOURCE`d text pasted as one line, or any caller that flattened it.
     * Eating to the line end would swallow such a paste whole and run nothing at
     * all — silently, which is the worst way to fail. (`App::cliText()` no longer
     * flattens; it did once, and this rule outlived the reason.)
     *
     * The argument is the next whitespace-delimited token with any surrounding
     * quotes removed; an empty one leaves the delimiter as it was.
     *
     * @return array{0:string,1:int} the delimiter in force, and the new offset
     */
    private static function readDelimiterCommand(string $sql, int $i, string $current): array
    {
        $eol = $i + strcspn($sql, "\r\n", $i);
        $p   = min($i + 9 + strspn($sql, " \t", $i + 9), $eol);
        $end = min($p + strcspn($sql, " \t\r\n", $p), $eol);
        $arg = trim(substr($sql, $p, $end - $p), "'\"`");
        return [$arg !== '' ? $arg : $current, $end];
    }

    // --- scanners -----------------------------------------------------------

    /** Does $needle sit at offset $i in $haystack? */
    private static function matchesAt(string $haystack, int $i, string $needle): bool
    {
        return $needle !== '' && substr($haystack, $i, strlen($needle)) === $needle;
    }

    /**
     * Step over a `'…'` or `"…"` string starting at $i, honouring `\'` escapes
     * and `''` doubling. An unterminated string consumes the rest of the input,
     * which leaves it in one piece for the server to reject.
     */
    private static function skipString(string $sql, int $i): int
    {
        $quote = $sql[$i];
        $len   = strlen($sql);
        $i++;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '\\') {
                $i += 2; // the escaped byte cannot close the string
                continue;
            }
            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2; // '' — a literal quote, not the end
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    /** Step over a `` `…` `` quoted identifier, honouring `` `` `` doubling. */
    private static function skipIdentifier(string $sql, int $i): int
    {
        $len = strlen($sql);
        $i++;
        while ($i < $len) {
            if ($sql[$i] === '`') {
                if (($sql[$i + 1] ?? '') === '`') {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    /** `#` anywhere, or `--` followed by whitespace or end of input. */
    private static function isLineComment(string $sql, int $i): bool
    {
        if ($sql[$i] === '#') {
            return true;
        }
        if (substr($sql, $i, 2) !== '--') {
            return false;
        }
        $after = $sql[$i + 2] ?? '';
        return $after === '' || ctype_space($after);
    }

    private static function skipLineComment(string $sql, int $i): int
    {
        return $i + strcspn($sql, "\r\n", $i);
    }

    private static function isBlockComment(string $sql, int $i): bool
    {
        return $sql[$i] === '/' && ($sql[$i + 1] ?? '') === '*';
    }

    /** `/*!…` — MySQL executes the contents, so the scanner must read them. */
    private static function isExecutableComment(string $sql, int $i): bool
    {
        return ($sql[$i + 2] ?? '') === '!';
    }

    /**
     * Step over an executable comment's opener, including the optional version
     * gate: `/*!50100` introduces SQL run only from 5.1.0 on. The digits are not
     * part of the statement, so keyword position sits after them.
     */
    private static function enterExecutableComment(string $sql, int $i): int
    {
        $i += 3;
        return $i + strspn($sql, '0123456789', $i);
    }

    /** Step over a plain block comment; an unterminated one runs to the end. */
    private static function skipBlockComment(string $sql, int $i): int
    {
        $end = strpos($sql, '*/', $i + 2);
        return $end === false ? strlen($sql) : $end + 2;
    }

    /**
     * Step over whitespace, comments and `(` from $i. Executable comments are
     * descended into rather than skipped, for the reason given in the class
     * docblock.
     */
    private static function skipNoise(string $sql, int $i): int
    {
        $len = strlen($sql);
        while ($i < $len) {
            $c = $sql[$i];
            if (ctype_space($c) || $c === '(') {
                $i++;
                continue;
            }
            if (self::isLineComment($sql, $i)) {
                $i = self::skipLineComment($sql, $i);
                continue;
            }
            if (self::isBlockComment($sql, $i)) {
                $i = self::isExecutableComment($sql, $i) ? self::enterExecutableComment($sql, $i) : self::skipBlockComment($sql, $i);
                continue;
            }
            // A version-comment's closing `*/` is noise in keyword position too.
            if ($c === '*' && ($sql[$i + 1] ?? '') === '/') {
                $i += 2;
                continue;
            }
            break;
        }
        return $i;
    }

    /**
     * The next bare word at or after $i, upper-cased, with the offset just past
     * it. Returns ['', …] when the next thing is not a word.
     *
     * @return array{0:string,1:int}
     */
    private static function nextWord(string $sql, int $i): array
    {
        $i = self::skipNoise($sql, $i);
        $j = $i;
        $len = strlen($sql);
        while ($j < $len) {
            $c = $sql[$j];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_') {
                $j++;
                continue;
            }
            break;
        }
        return [strtoupper(substr($sql, $i, $j - $i)), $j];
    }

    // --- WITH … <verb> ------------------------------------------------------

    /**
     * Resolve `WITH [RECURSIVE] a AS (…)[, b AS (…)] <verb>` to <verb>.
     *
     * Each CTE is found by stepping to its opening paren and over the balanced
     * group; a following `,` means another CTE, anything else means the body has
     * started. Returns '' if the shape is not recognised, so the caller can fall
     * back to reporting WITH.
     */
    private static function keywordAfterCteList(string $sql): string
    {
        $len = strlen($sql);
        [, $i] = self::nextWord($sql, 0);   // past WITH

        [$word, $next] = self::nextWord($sql, $i);
        if ($word === 'RECURSIVE') {
            $i = $next;
        }

        // Bound the walk by the number of CTEs the text could possibly hold, so
        // malformed input cannot spin here.
        $guard = substr_count($sql, '(') + 1;
        while ($guard-- > 0) {
            $open = self::findParen($sql, $i);
            if ($open === -1) {
                return '';
            }
            $i = self::skipParens($sql, $open);
            if ($i >= $len) {
                return '';
            }
            $i = self::skipNoiseNoParen($sql, $i);
            if (($sql[$i] ?? '') === ',') {
                $i++;
                continue;   // another CTE definition follows
            }
            [$verb] = self::nextWord($sql, $i);
            return $verb;
        }
        return '';
    }

    /** Offset of the next `(` that is really punctuation, or -1. */
    private static function findParen(string $sql, int $i): int
    {
        $len = strlen($sql);
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '(') {
                return $i;
            }
            if ($c === "'" || $c === '"') {
                $i = self::skipString($sql, $i);
                continue;
            }
            if ($c === '`') {
                $i = self::skipIdentifier($sql, $i);
                continue;
            }
            if (self::isLineComment($sql, $i)) {
                $i = self::skipLineComment($sql, $i);
                continue;
            }
            if (self::isBlockComment($sql, $i) && !self::isExecutableComment($sql, $i)) {
                $i = self::skipBlockComment($sql, $i);
                continue;
            }
            $i++;
        }
        return -1;
    }

    /**
     * Step over the balanced paren group opening at $i, ignoring parens that sit
     * inside strings, identifiers or comments.
     */
    private static function skipParens(string $sql, int $i): int
    {
        $len   = strlen($sql);
        $depth = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $i = self::skipString($sql, $i);
                continue;
            }
            if ($c === '`') {
                $i = self::skipIdentifier($sql, $i);
                continue;
            }
            if (self::isLineComment($sql, $i)) {
                $i = self::skipLineComment($sql, $i);
                continue;
            }
            if (self::isBlockComment($sql, $i) && !self::isExecutableComment($sql, $i)) {
                $i = self::skipBlockComment($sql, $i);
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
            $i++;
        }
        return $len;
    }

    /**
     * {@see skipNoise()} without the paren-skipping — used between CTEs, where a
     * `(` is structure to stop at rather than noise to step over.
     */
    private static function skipNoiseNoParen(string $sql, int $i): int
    {
        $len = strlen($sql);
        while ($i < $len) {
            if (ctype_space($sql[$i])) {
                $i++;
                continue;
            }
            if (self::isLineComment($sql, $i)) {
                $i = self::skipLineComment($sql, $i);
                continue;
            }
            if (self::isBlockComment($sql, $i)) {
                $i = self::isExecutableComment($sql, $i) ? self::enterExecutableComment($sql, $i) : self::skipBlockComment($sql, $i);
                continue;
            }
            break;
        }
        return $i;
    }
}
