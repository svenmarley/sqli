<?php

declare(strict_types=1);

namespace Sqli\Config;

/**
 * What {@see Macros::expand()} made of one CLI submission (§23).
 *
 * A result object rather than a pile of by-reference out-parameters, for the
 * same reason {@see \Sqli\Sql\NormalizeResult} is one: the caller needs to know
 * not just the rewritten SQL but whether to run it at all, and to say why when
 * the answer is no.
 *
 * {@see $problem} is the whole decision. When it is non-null the submission is
 * refused and the message is shown as-is; when it is null the statement runs
 * and {@see $note} — possibly empty — is the footnote about what changed.
 */
final class Expansion
{
    /**
     * @param string       $sql     the text after substitution (unchanged on refusal)
     * @param int          $used    references actually substituted, nested ones included
     * @param list<string> $unknown names with no definition anywhere
     * @param list<list<string>> $loops reference loops, each as the path that
     *        closes it — `['a', 'b', 'a']`
     * @param array<string,array{need:int,got:int}> $short names called with too
     *        few arguments, and how many short they were
     * @param bool         $stray   the line itself contained an argument slot
     *                              (`?1`), which means something only inside a value
     * @param bool         $overflow the substitution budget ran out — a loop-free
     *        but explosively wide set of macros
     * @param array<string,list<string>> $elsewhere names that exist only inside
     *        `[env:…]` sections this session did not select, each with those envs
     * @param ?string      $env     the selected environment, for that message
     * @param bool $badPrompt the text carries a `?{…}` that names no usable
     *        question — `?{1}`, `?{}`, an unclosed one
     */
    public function __construct(
        public readonly string $sql,
        public readonly int $used = 0,
        public readonly array $unknown = [],
        public readonly array $loops = [],
        public readonly array $short = [],
        public readonly bool $stray = false,
        public readonly bool $overflow = false,
        public readonly array $elsewhere = [],
        public readonly ?string $env = null,
        public readonly bool $badPrompt = false,
    ) {
    }

    /**
     * Why this submission must not run, or null when it may.
     *
     * Every refusal here exists to replace a server-side syntax error — the
     * unexpanded `?word` would reach MySQL and come back as a parse failure
     * pointing at a character the user never typed — with a sentence naming the
     * macro.
     *
     * A loop is reported first because it is a fault in the file rather than in
     * the call: everything else on the line is the user's typing, and this one
     * would still be there tomorrow.
     */
    public function problem(): ?string
    {
        if ($this->loops !== []) {
            $many = count($this->loops) > 1;
            $paths = array_map(
                static fn (array $path): string => implode(' → ', array_map(
                    static fn (string $n): string => '?' . $n,
                    $path,
                )),
                $this->loops,
            );
            return 'Macro loop' . ($many ? 's' : '') . ': ' . implode(' · ', $paths)
                . ' — a macro cannot expand itself. Break the circle in the file.';
        }
        // Before the plain unknowns, because it is the more specific reading of
        // the same symptom: the name is not missing, it is scoped to an
        // environment this session did not pick (§23), and "check the spelling"
        // would send the user looking for a typo that is not there.
        if ($this->elsewhere !== []) {
            $parts = [];
            foreach ($this->elsewhere as $name => $envs) {
                $parts[] = '?' . $name . ' is defined under ' . self::envs($envs);
            }
            return implode(' · ', $parts) . ' — '
                . ($this->env === null
                    ? 'this session picked no environment, so no [env:…] section is loaded.'
                        . ' Start sqli with -E NAME, or move the macro out of the section.'
                    : 'this session is env ' . $this->env
                        . '. Define it under [env:' . $this->env . '] too, or move it out of the section.');
        }
        if ($this->unknown !== []) {
            return 'Unknown macro' . (count($this->unknown) > 1 ? 's' : '') . ': '
                . self::refs($this->unknown)
                . ' — check the spelling, or quote it: a ?name inside a string literal is left alone.';
        }
        if ($this->short !== []) {
            // Named one at a time: "needs 2, got 1" is the whole answer, and
            // which of the two was missing is a question the user can only
            // answer by looking at the macro — which the message tells them
            // how to do by showing the call shape.
            $parts = [];
            foreach ($this->short as $name => $n) {
                $parts[] = '?' . $name . ' takes ' . self::args($n['need'])
                    . ' — ' . ($n['got'] === 0 ? 'none given' : 'got ' . $n['got'])
                    . ': ?' . $name . self::shape($n['need']);
            }
            return implode(' · ', $parts);
        }
        if ($this->overflow) {
            return 'That expanded into more than ' . Macros::maxExpansions()
                . ' substitutions — check what these macros pull in.';
        }
        if ($this->stray) {
            return '?1, ?2 … only mean something inside a macro\'s value'
                . ' — on the CLI, write the argument itself after the ?name.';
        }
        // Last, because every refusal above is about something the user can
        // name; this one is about a shape. A question has to be answerable
        // before it can be asked, and an unanswerable one left alone reaches
        // MySQL as a parse error pointing at a brace.
        if ($this->badPrompt) {
            return 'That ?{…} names no question — write ?{name}'
                . ' (letters, digits, _ . and -, starting with a letter or _), and close the brace.';
        }
        return null;
    }

    /** The status-line footnote when the statement did run. */
    public function note(): string
    {
        if ($this->used === 0) {
            return '';
        }
        return $this->used === 1 ? '1 macro expanded' : $this->used . ' macros expanded';
    }

    /**
     * `[env:dev] and [env:prod]` — the sections a name was found in.
     *
     * @param list<string> $envs
     */
    private static function envs(array $envs): string
    {
        return '[env:' . implode('] and [env:', $envs) . ']';
    }

    /** @param list<string> $names */
    private static function refs(array $names): string
    {
        return implode(', ', array_map(static fn (string $n): string => '?' . $n, $names));
    }

    private static function args(int $n): string
    {
        return $n === 1 ? '1 argument' : $n . ' arguments';
    }

    /** `<arg1> <arg2>` — the call shape, so the message shows what to type. */
    private static function shape(int $n): string
    {
        $out = '';
        for ($i = 1; $i <= $n; $i++) {
            $out .= ' <arg' . $i . '>';
        }
        return $out;
    }
}
