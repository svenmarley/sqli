<?php

declare(strict_types=1);

namespace Sqli\Config;

/**
 * Macros (spec §23) — named snippets the CLI expands before a submission is
 * split and run, with or without arguments.
 *
 * `select * from agencies where id = ?fl-agency-id;` runs as
 * `select * from agencies where id = 22;` when the macro file says
 * `fl-agency-id = 22`. A value is substituted **as text**, so it can be an
 * id, a fragment of a WHERE clause, a table name, or a whole statement.
 *
 * A value may take **arguments** by naming their positions, `?1` upwards:
 *
 *     ganl = "select * from agencies where name like '%?1%';"
 *     ?ganl vegas   →   select * from agencies where name like '%vegas%';
 *
 * The words after the reference are the arguments, and they are consumed — they
 * leave the line rather than trailing after the statement they were arguments
 * to. How many are taken is decided by the value itself: the highest argument
 * index it names. Quote an argument (`?ganl "las vegas"`) to put whitespace
 * in one; the quotes are stripped and are the macro's business, not the
 * argument's.
 *
 * Decisions worth reading before changing anything here:
 *
 *  1. **Text is left alone.** A `?name` is read as a reference only where the
 *     server would read SQL — never inside a `'…'` or `"…"` literal, a
 *     `` `…` `` identifier, or a `-- ` / `#` / `/* … *\/` comment. Quoting is
 *     therefore the escape, and there is no other delimiter to remember: a
 *     query that genuinely needs the text `?agency` is a query that has it in a
 *     literal. It also keeps sqli's hands off the one `?` ordinary SQL really
 *     does contain, the placeholder in `PREPARE s FROM 'SELECT … id = ?'`, and
 *     off the note somebody left themselves in a comment. Everywhere else,
 *     anything that *looks* like a reference is treated as one, so a typo'd
 *     name is a message naming the macro rather than a server-side parse error.
 *     {@see inertSpans()} is the single reader of which is which — `Completion`
 *     asks it too, so the popup and the expander cannot disagree.
 *  2. **A name may not start with a digit:** `[A-Za-z_][A-Za-z0-9_.-]*`. That
 *     one restriction is what makes `?1` unambiguous — an argument slot and a
 *     reference are different shapes rather than two readings of one — and it
 *     costs nothing, since a macro called `2fa` is not a thing anyone has
 *     wanted. Everything outside the set ends the name, so `?recent and 1=1`
 *     is a reference followed by more SQL — but `.` and `-` are *inside* it,
 *     so `?tbl.id` is the name `tbl.id` and is refused as undefined rather
 *     than read as `tbl` plus a qualifier. A loud failure, and the price of a
 *     charset that spells `fl-agency-id` the way people actually write it.
 *  3. **A value may name other macros, and a loop is refused.** Expansion is
 *     depth-first: a value is scanned after its own arguments are filled in, so
 *     an argument can be handed down (`r7 = "?recent ?1"`) and an argument's
 *     own text is scanned too. What stops it running away is the chain of names
 *     being expanded — a name already on it would expand itself, and the chain
 *     from that name back round to it is what the refusal shows. Arguments are
 *     read from the text being scanned and nowhere else: a value of `?inner`
 *     does not reach out to the CLI line for `inner`'s argument.
 *  4. **An argument is substituted as text, quotes and all.** The macro
 *     supplies the SQL quoting (`'%?1%'`); sqli does not add or escape
 *     any, for the same reason a plain value is not escaped — it may legitimately
 *     be a fragment of SQL. An apostrophe in an argument therefore reaches MySQL
 *     as an apostrophe. Argument slots are filled **inside** quotes as well as
 *     out — `'%?1%'` is the normal shape, and `?1` is not something SQL says by
 *     accident the way a bare word is. What an argument may **not** do is change
 *     how the value around it is read: a value is lexed as its author wrote it,
 *     before any argument went in, so `?agency "O'Brien"` puts an apostrophe in
 *     the SQL without silencing the rest of that macro's own body.
 *  5. **A name with no value is not a macro.** `fl-agency =` is a line someone
 *     started and did not finish, and it is refused where it is written. Loaded,
 *     it would be a whole chain of quiet nothing: the reference vanishes,
 *     {@see endsWithCall()} calls the line complete, and Enter submits an empty
 *     statement.
 *
 * A macro may also **ask for a value when it runs**, with `?{name}` — typed
 * on the CLI or written into a macro:
 *
 *     SELECT * FROM inspection WHERE agency = ?{agency}
 *
 * The CLI stops and prompts `agency:`, and what is typed goes in. The same name
 * twice is asked once. This is the other half of an argument: an argument is
 * decided when the line is written, a prompt is answered when the statement
 * runs.
 *
 * Files are INI, one per `.sqli/` layer, named `sqli_macros.ini`, and are read
 * with PHP's **raw** scanner — the only one that survives SQL, since the others
 * refuse a bare `(` and would turn `true` into `1`. Layers are merged with the
 * **closest to the cwd winning per name**, the same rule `sqli_config.json`
 * follows: a project can redefine a name the global file sets without copying
 * the rest. `[section]` headers are dividers, `;` starts a comment, and a later
 * key wins over an earlier one in the same file.
 *
 * A section head spelled **`[env:NAME]` is the exception**: what is under it
 * loads only when that environment is the selected one (§16, `-E NAME`). The
 * same macro can then mean one thing against dev and another against prod —
 * `agency-id` is a different number on each server, and a name that quietly
 * kept the wrong one is the failure this exists to prevent. Anything after the
 * env name is a divider, so `[env:prod values]` is still prod.
 *
 *     agency        = agencies          ; everywhere
 *     [env:dev]
 *     agency-id     = 22
 *     [env:prod]
 *     agency-id     = 1174
 *
 * Three rules hold it together. Every **other** section head is a plain divider
 * and what is under it applies to every env, so the prefix is what makes a
 * section selective and nothing is scoped by accident. Within one file an
 * `[env:…]` definition **beats** the unscoped one for the same name, whatever
 * order they were written in — it is the more specific answer to the same
 * question. And with **no env selected, no `[env:…]` section loads at all**: a
 * session that never said which server it is on cannot be handed prod's id
 * because prod happened to be last in the file. A reference to a name that only
 * exists under another env is refused by name — {@see hidden()} — rather than
 * reported as a typo, because the fix is `-E`, not the spelling.
 *
 * An env that **`extends` another** in `sqli_config.json` (§16) extends it here
 * too: `-E prod` with `"prod": {"extends": "base"}` loads `[env:base]` and then
 * `[env:prod]` over it, so the two files answer "what is prod" the same way and
 * a macro shared by every server is written once, under the parent. The chain
 * arrives from {@see Config::envChain()} least specific first; {@see scopeOf()}
 * names the section a value actually came from, which may be an ancestor's.
 * `[env:base]` is not *hidden* from prod either — {@see hidden()} is about the
 * envs this session cannot reach, and an inherited one is reached.
 *
 * **A value that contains a `;` must be quoted**, because outside quotes the `;`
 * starts a comment and the rest of the line is lost — which matters here more
 * than anywhere, since a macro ending in `;` is the normal case. The raw scanner
 * does not process escapes inside the quotes, so a value cannot hold both a `;`
 * and a `"`; MySQL quotes with `'` and backticks, so that pairing has not come
 * up, and {@see validate()} says so rather than letting it be found in a broken
 * query.
 *
 * The file is edited outside sqli. {@see reload()} re-reads every layer, which
 * is what the Macros viewer's `r` key calls (§12).
 */
final class Macros
{
    /** Filename inside a `.sqli/` dir. */
    public const FILE = 'sqli_macros.ini';

    /**
     * What may follow the `?`. See the class note above: no leading digit, so a
     * name and an argument slot (`?1`) can never be read for one another.
     */
    public const NAME = '[A-Za-z_][A-Za-z0-9_.\-]*';

    /**
     * A reference: `?name`, ending at the first character outside the name set.
     *
     * There is no escape alternative here, because quoting is the escape — see
     * {@see inertSpans()}, which is applied by every caller that walks this
     * pattern over user text.
     */
    private const REF = '/\?(' . self::NAME . ')/';

    /**
     * A reference **or** an argument slot, in one pattern, because {@see scan()}
     * has to meet them in the order they were written: an argument is
     * substituted into the value as the walk reaches its slot, not by a second
     * pass over text the first pass has already rewritten.
     *
     * Group 1 is the slot number, group 2 the name. They can never both match —
     * that is what the no-leading-digit rule on a name buys.
     */
    private const REF_OR_SLOT = '/\?(?:([1-9][0-9]*)|(' . self::NAME . '))/';

    /**
     * An argument slot inside a value: `?1`, 1-based.
     *
     * Filled everywhere in the value, quoted stretches included, because
     * `'%?1%'` is the ordinary shape for a `LIKE` and a rule that skipped it
     * would make the common case the broken one. That is safe in a way a bare
     * `?name` is not: `?1` is not something SQL writes by accident.
     */
    private const PARAM = '/\?([1-9][0-9]*)/';

    /**
     * An argument slot that cannot mean anything — `?0`, `?00` — numbered from
     * zero when the slots start at one. A fault in the file, caught while it is
     * being edited rather than in a query.
     */
    private const PARAM_BAD = '/\?0[0-9]*/';

    /**
     * An argument slot **on the CLI**. Typing one there is a misunderstanding
     * worth a sentence: it means something only inside a value. Asked of the
     * unquoted stretches only — `select '?1'` is a string containing a `?1`.
     */
    private const PARAM_STRAY = '/\?[0-9]/';

    /**
     * A value asked for when the statement runs: `?{agency}`.
     *
     * Braces, because the two are answered at different times and reading which
     * is which at a glance is worth the two characters: `?name` and `?1` are
     * settled while the line is being written, `?{name}` is settled when Enter
     * is pressed. A prompt is filled inside quotes as well as out — `'?{from}'`
     * is the ordinary shape — for the same reason an argument slot is.
     */
    private const PROMPT = '/\?\{\s*(' . self::NAME . ')\s*\}/';

    /**
     * `?{` that is not a well-formed prompt — `?{}`, an unclosed one, a name
     * the charset does not allow.
     */
    private const PROMPT_BAD = '/\?\{(?!\s*' . self::NAME . '\s*\})/';
    /** Characters that end an unquoted argument. See {@see readArgs()}. */
    private const ARG_STOP = [' ', "\t", "\n", "\r", ';'];

    /**
     * A section head that scopes what is under it to one environment (§16):
     * `[env:prod]`, and `[env:prod values]` — anything after the name is a
     * divider, so an env can be split into as many labelled blocks as the file
     * wants without repeating itself.
     *
     * The `env:` prefix, rather than a bare `[prod]`, is what keeps every other
     * head a free divider: `[with arguments]` has always been a comment with a
     * box round it, and a rule that turned it into an environment nobody
     * selects would drop those macros silently — which is the one failure this
     * file format has spent its whole life trying not to have.
     */
    private const ENV_SECTION = '/^env\s*:\s*([A-Za-z0-9_.\-]+)(?:\s+\S.*)?$/i';

    /** `[env:…]` that names nothing usable — reported, not read as a divider. */
    private const ENV_SECTION_BAD = '/^env\s*:/i';

    /**
     * Most substitutions one submission may make. A loop is caught by name (see
     * {@see scan()}), so this is not a second loop check: it is the ceiling on a
     * *loop-free* graph that fans out — ten macros each naming the previous
     * one twice is a thousand substitutions from a single reference, and a
     * terminal that stops answering is worse than a refusal that explains.
     */
    private const MAX_EXPANSIONS = 500;

    /** The budget, for the message that reports running out of it. */
    public static function maxExpansions(): int
    {
        return self::MAX_EXPANSIONS;
    }

    /**
     * The stretches of $text that expansion must not look inside, as
     * `[start, end)` byte offsets with their delimiters included (§23).
     *
     * Four kinds, found in **one left-to-right walk** because they nest by
     * position rather than by rule: a `--` inside a string literal is not a
     * comment, an apostrophe inside a comment does not open a literal, and the
     * only way to know which came first is to read the text once, in order.
     *
     *  - **`'…'` and `"…"` string literals.** MySQL's rules for where one
     *    ends: a backslash escapes the next character, and a doubled quote is
     *    one literal quote rather than a close followed by an open. This is the
     *    escape mechanism — a query that genuinely needs the text `?agency` is
     *    a query that has it in a literal — and it settles the one `?` real SQL
     *    contains, the placeholder in `PREPARE s FROM 'SELECT … id = ?'`.
     *  - **`` `…` `` quoted identifiers.** A doubled backtick is one literal
     *    backtick; a backslash is **not** an escape inside them, which is
     *    MySQL's rule and not an oversight. They were once left out on the
     *    grounds that an identifier is a name — but an apostrophe inside one
     *    (`` `o'brien` ``) then opened a literal that never closed, and every
     *    macro later on that line silently stopped expanding. A macro that
     *    holds a table name is written `select * from ?tbl`, without the
     *    backticks; one written `` `?tbl` `` is now text, deliberately.
     *  - **`-- ` and `#` line comments**, to the end of the line. `--` needs the
     *    whitespace after it, exactly as MySQL requires, so `x--1` stays
     *    arithmetic.
     *  - **`/* … *\/` block comments** — except `/*!…*\/`, which is not a
     *    comment at all but SQL the server executes, and so is scanned like any
     *    other text.
     *
     * An unterminated anything — quote, identifier, block comment — runs to the
     * end of the text. That is the safe reading while a line is still being
     * typed: a half-written literal should not start expanding what its closing
     * quote is about to cover.
     *
     * @return list<array{0:int,1:int}> in ascending, non-overlapping order
     */
    public static function inertSpans(string $text): array
    {
        $spans = [];
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $ch = $text[$i];

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                // Backslash escapes inside `…` are MySQL's one exception: there,
                // the only escape for the delimiter is doubling it.
                $escapes = $ch !== '`';
                $j = $i + 1;
                while ($j < $len) {
                    if ($escapes && $text[$j] === '\\') {
                        $j += 2; // whatever follows is literal, the closing quote included
                        continue;
                    }
                    if ($text[$j] !== $ch) {
                        $j++;
                        continue;
                    }
                    if (($text[$j + 1] ?? '') === $ch) {
                        $j += 2; // `''` is one quote inside the literal, not the end
                        continue;
                    }
                    break;
                }
                $end = min($j + 1, $len);
                $spans[] = [$i, $end];
                $i = $end;
                continue;
            }

            if ($ch === '#'
                || ($ch === '-' && ($text[$i + 1] ?? '') === '-'
                    && in_array($text[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))
            ) {
                $nl = strpos($text, "\n", $i);
                $end = $nl === false ? $len : $nl;
                $spans[] = [$i, $end];
                $i = $end;
                continue;
            }

            if ($ch === '/' && ($text[$i + 1] ?? '') === '*' && ($text[$i + 2] ?? '') !== '!') {
                $close = strpos($text, '*/', $i + 2);
                $end = $close === false ? $len : $close + 2;
                $spans[] = [$i, $end];
                $i = $end;
                continue;
            }

            $i++;
        }

        return $spans;
    }

    /**
     * Whether the byte at $offset falls inside one of $spans.
     *
     * @param list<array{0:int,1:int}> $spans from {@see inertSpans()}
     */
    private static function inSpans(int $offset, array $spans): bool
    {
        foreach ($spans as [$from, $to]) {
            if ($offset < $from) {
                return false; // ascending order: nothing later can contain it
            }
            if ($offset < $to) {
                return true;
            }
        }
        return false;
    }

    /** @var list<string> `.sqli/` dirs, lowest precedence first */
    private array $dirs;

    /** @var array<string,string> name => value, closest layer winning */
    private array $macros = [];

    /** @var array<string,string> name => the file it came from */
    private array $origin = [];

    /** @var list<string> files actually read, in precedence order */
    private array $files = [];

    /** @var list<string> load complaints (bad JSON, unusable names/values) */
    private array $problems = [];

    /** The selected environment (§16), or null when the session named none. */
    private ?string $env;

    /**
     * @var list<string> the envs whose sections load, least specific first: the
     *      selected env's `extends` ancestors and then the env itself. Empty
     *      when none was selected.
     */
    private array $envChain;

    /** @var array<string,?string> name => the env its winning definition came from */
    private array $scope = [];

    /**
     * @var array<string,array<string,true>> name => the envs whose `[env:…]`
     *      sections define it and that this session did not select
     */
    private array $elsewhere = [];

    /** @var array<string,true> every env any layer writes a section for */
    private array $envsSeen = [];

    /**
     * @param list<string> $dirs `.sqli/` directories, lowest precedence first
     * @param ?string $env the selected environment (§16). `[env:NAME]` sections
     *        matching it load; every other one stays out of the set entirely,
     *        and null means none of them load at all — see the class note.
     * @param list<string> $envChain $env's `extends` ancestors and then $env
     *        itself, least specific first — {@see Config::envChain()}. Empty
     *        means "just $env", which is what a caller with no config to ask
     *        (a test, a bare `new Macros([$dir], 'prod')`) gets.
     */
    public function __construct(array $dirs, ?string $env = null, array $envChain = [])
    {
        $this->dirs = $dirs;
        $this->env = $env;
        $this->envChain = $envChain !== [] ? $envChain : ($env !== null ? [$env] : []);
        $this->reload();
    }

    /**
     * The layers sqli would consult, lowest precedence first: the global
     * `.sqli/`, then each project `.sqli/` from the farthest up-tree to the
     * closest — so the closest is applied last and wins.
     */
    public static function fromConfig(Config $config): self
    {
        $dirs = [$config->globalSqliDir()];
        foreach (array_reverse($config->projectSqliDirs()) as $dir) {
            $dirs[] = $dir;
        }
        return new self($dirs, $config->envName(), $config->envChain());
    }

    /** Re-read every layer from disk, replacing what is held. */
    public function reload(): void
    {
        $this->macros = [];
        $this->origin = [];
        $this->files = [];
        $this->problems = [];
        $this->scope = [];
        $this->elsewhere = [];
        $this->envsSeen = [];

        foreach ($this->dirs as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
            if (!is_file($path)) {
                continue;
            }
            $this->files[] = $path;
            $raw = @file_get_contents($path);
            if ($raw === false) {
                $this->problems[] = self::short($path) . ': cannot read the file';
                continue;
            }
            if (trim($raw) === '') {
                continue; // an empty file defines nothing; not a complaint
            }
            $parsed = self::parseIni($raw, $path, $this->problems);
            if ($parsed === null) {
                continue;
            }
            [$plain, $byEnv] = $parsed;

            // Everything is validated, including what this session's env does
            // not load. The fault is in the file being edited, and hearing about
            // a broken `[env:prod]` value only after `-E prod` is hearing about
            // it in front of the prod server.
            foreach ($plain as $name => $value) {
                $problem = self::validate($name, $value);
                if ($problem !== null) {
                    $this->problems[] = self::short($path) . ': ' . $problem;
                    unset($plain[$name]);
                }
            }
            foreach ($byEnv as $env => $entries) {
                $this->envsSeen[$env] = true;
                foreach ($entries as $name => $value) {
                    $problem = self::validate($name, $value);
                    if ($problem !== null) {
                        $this->problems[] = self::short($path) . ' [env:' . $env . ']: ' . $problem;
                        unset($byEnv[$env][$name]);
                        continue;
                    }
                    if (!in_array($env, $this->envChain, true)) {
                        $this->elsewhere[$name][$env] = true;
                    }
                }
            }

            // Within one file the env section wins over the unscoped one for the
            // same name, whichever was written first: it is the more specific
            // answer to the same question, and a file that has to be *ordered*
            // correctly to mean what it says is a file that will be reordered.
            // Between files the older rule still decides — a whole layer at a
            // time, closest to the cwd last and therefore winning.
            //
            // More than one env section can load, because an env may `extends`
            // another (§16) and a macro scoped to the parent is inherited by the
            // child exactly as an option in `envs{}` is. The chain arrives least
            // specific first, so walking it in order leaves the closest env's
            // answer standing — and $from remembers which env that was, so the
            // viewer and `--lint` can say `[env:base]` rather than crediting the
            // selected env with a definition it does not contain.
            $scoped = [];
            $from = [];
            foreach ($this->envChain as $envName) {
                foreach ($byEnv[$envName] ?? [] as $name => $value) {
                    $scoped[$name] = $value;
                    $from[$name] = $envName;
                }
            }
            foreach ([...array_keys($plain), ...array_keys($scoped)] as $name) {
                $this->macros[$name] = $scoped[$name] ?? $plain[$name];
                $this->origin[$name] = $path;
                $this->scope[$name] = $from[$name] ?? null;
            }
        }
        ksort($this->macros, SORT_NATURAL | SORT_FLAG_CASE);

        // Loops are a property of the merged set, not of any one file: a project
        // layer can close a circle the global file left open. So the check runs
        // once, here, after every layer is in — and reports rather than drops,
        // because a name that vanished would be reported as a typo instead.
        foreach ($this->findLoops() as $path) {
            $this->problems[] = 'macro loop '
                . implode(' → ', array_map(static fn (string $n): string => '?' . $n, $path))
                . ' — a macro cannot expand itself';
        }
    }

    /**
     * Every reference loop in the loaded set, each as the path that closes it
     * (`a → b → a`).
     *
     * The same fault {@see scan()} refuses at submit time, found statically so
     * `--lint` and the viewer can say it while the file is being edited rather
     * than when a query is being run. The two cannot disagree about *what* a
     * loop is: both read references out of a value with the same pattern, and
     * both mean "a name that would expand itself".
     *
     * Iterative depth-first with the classic three colours (unvisited / on the
     * stack / done), so a name reachable by many paths is walked once.
     *
     * @return list<list<string>>
     */
    private function findLoops(): array
    {
        $edges = [];
        foreach ($this->macros as $name => $value) {
            $edges[$name] = self::referencesIn($value);
        }

        $loops = [];
        $state = []; // name => 1 on the current path, 2 finished
        foreach (array_keys($edges) as $root) {
            if (($state[$root] ?? 0) !== 0) {
                continue;
            }
            $this->walkForLoops($root, $edges, $state, [], $loops);
        }
        return array_values($loops);
    }

    /**
     * @param array<string,list<string>> $edges
     * @param array<string,int>          $state
     * @param list<string>               $path
     * @param array<string,list<string>> $loops
     */
    private function walkForLoops(string $name, array $edges, array &$state, array $path, array &$loops): void
    {
        $state[$name] = 1;
        $path[] = $name;
        foreach ($edges[$name] ?? [] as $next) {
            if (!isset($edges[$next])) {
                continue; // an undefined name is a typo, reported on its own terms
            }
            if (($state[$next] ?? 0) === 1) {
                $at = array_search($next, $path, true);
                $loop = array_merge(array_slice($path, (int) $at), [$next]);
                $loops[implode("\0", $loop)] = $loop;
                continue;
            }
            if (($state[$next] ?? 0) === 0) {
                $this->walkForLoops($next, $edges, $state, $path, $loops);
            }
        }
        $state[$name] = 2;
    }

    /**
     * The macro names a value refers to, quoted stretches excluded — a `?x`
     * inside `'…'` is literal text and is not an edge in the graph.
     *
     * @return list<string>
     */
    private static function referencesIn(string $value): array
    {
        if (!str_contains($value, '?')
            || preg_match_all(self::REF, $value, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1
        ) {
            return [];
        }
        $spans = self::inertSpans($value);
        $names = [];
        foreach ($m as $hit) {
            if (self::inSpans((int) $hit[0][1], $spans)) {
                continue; // quoted: text, not an edge in the graph
            }
            $names[(string) $hit[1][0]] = true;
        }
        return array_keys($names);
    }

    /**
     * One file's `name = value` pairs, split into the ones that apply to every
     * environment and the ones each `[env:NAME]` section scopes — or null when
     * the file could not be parsed at all (the complaint is appended to
     * $problems either way).
     *
     * **`INI_SCANNER_RAW` is not a preference, it is the only one that works.**
     * The normal and typed scanners refuse a bare `(` — which any value calling
     * a function has — with `syntax error, unexpected '('`, and they also
     * interpret `true`/`false`/`null` and backslash escapes, all of which are
     * ordinary text in a SQL fragment. Raw hands back exactly what was written.
     *
     * Sections are kept (`$process_sections = true`) only to find the
     * `[env:…]` ones: every other head is a divider, and what is under it is
     * merged straight back into the always-on set in file order, exactly as it
     * was when sections were flattened away.
     *
     * Which heads exist is read from the **raw text** rather than from the
     * parsed array, because at the top level a section and a `key[] = …` list
     * arrive as the same thing — an array — and one of them is a file saying
     * something this format has no meaning for.
     *
     * @param list<string> $problems appended to in place
     * @return array{0:array<string,string>,1:array<string,array<string,string>>}|null
     *         the always-on macros, then the scoped ones keyed by env name
     */
    private static function parseIni(string $raw, string $path, array &$problems): ?array
    {
        // parse_ini_string reports a malformed file by emitting a warning and
        // returning false; the message names the line, which is the whole value
        // of catching it rather than saying "invalid".
        $message = '';
        set_error_handler(static function (int $_no, string $msg) use (&$message): bool {
            $message = $msg;
            return true;
        });
        $data = parse_ini_string($raw, true, INI_SCANNER_RAW);
        restore_error_handler();

        if (!is_array($data)) {
            $problems[] = self::short($path) . ': cannot be read as INI'
                . ($message !== '' ? ' — ' . self::tidyIniError($message) : '');
            return null;
        }
        // A line PHP simply ignored is the one fault this format has that JSON
        // did not: a missing `=` is not a syntax error to the parser, it is
        // nothing at all, and "my macro is not there" then has no explanation
        // anywhere. A value cannot span lines — a quote left open is a parse
        // error, caught above — so every line either defines something or is
        // blank, a comment, or a section head.
        // Section heads, in the same pass: head => the env it scopes to, null
        // for a plain divider, false for an `[env:…]` head that named no env.
        $heads = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '[' && ($close = strpos($line, ']')) !== false) {
                $head = trim(substr($line, 1, $close - 1));
                if (preg_match(self::ENV_SECTION, $head, $m) === 1) {
                    $heads[$head] = $m[1];
                    continue;
                }
                // `[env:]`, `[env: ]`, `[env:!]` — a head that was reaching for
                // an environment and missed. Read as a divider it would put one
                // server's macros into every session, which is the outcome this
                // feature exists to prevent, so it loads nothing and says so.
                if (preg_match(self::ENV_SECTION_BAD, $head) === 1) {
                    $heads[$head] = false;
                    $problems[] = self::short($path) . ': line ' . ($i + 1)
                        . " — `[$head]` names no environment, so nothing under it is loaded;"
                        . ' write [env:NAME] (letters, digits, _ . and -), optionally followed by a divider';
                    continue;
                }
                $heads[$head] = null;
                continue;
            }
            if (str_contains($line, '=')) {
                continue;
            }
            $lead = $line[0];
            if ($lead === ';' || $lead === '#') {
                continue;
            }
            $problems[] = self::short($path) . ': line ' . ($i + 1)
                . ' does nothing — a macro is `name = value`';
        }

        $plain = [];
        $byEnv = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (is_array($value) && array_key_exists($key, $heads)) {
                $env = $heads[$key];
                if ($env === false) {
                    continue; // a malformed `[env:…]` head, already reported
                }
                foreach ($value as $k => $v) {
                    if (is_array($v)) {
                        $problems[] = self::short($path)
                            . ": `$k` is a list — a macro is one line of text";
                        continue;
                    }
                    if ($env === null) {
                        $plain[(string) $k] = (string) $v;
                    } else {
                        $byEnv[$env][(string) $k] = (string) $v;
                    }
                }
                continue;
            }
            // A value is always a string from the raw scanner — but a key
            // repeated with `[]` arrives as an array, and that is a file saying
            // something this format has no meaning for.
            if (is_array($value)) {
                $problems[] = self::short($path) . ": `$key` is a list — a macro is one line of text";
                continue;
            }
            $plain[$key] = (string) $value;
        }
        return [$plain, $byEnv];
    }

    /** PHP's INI error text, minus the wrapper nobody needs to read. */
    private static function tidyIniError(string $message): string
    {
        $message = (string) preg_replace('/^\s*parse_ini_string\(\)\s*:\s*/i', '', $message);
        return trim((string) preg_replace('/\s+in Unknown on line \d+/', '', $message));
    }

    /** Why $name => $value cannot be a macro, or null when it can. */
    private static function validate(string $name, string $value): ?string
    {
        if (preg_match('/^' . self::NAME . '$/', $name) !== 1) {
            return "`$name` is not a usable macro name (letters, digits, _ . and -, starting with a letter or _)";
        }
        // A name with nothing after the `=` is a line someone started and did
        // not finish. Loaded, it would be a macro that expands to nothing: the
        // reference disappears, `endsWithCall()` says the line is complete, and
        // Enter submits an empty statement — a whole chain of quiet nothing from
        // one missing value. Whitespace inside quotes is a different thing and
        // stays legal: `blank = "   "` is someone asking for spaces.
        if ($value === '') {
            return "`$name` has no value — a macro must expand to something (delete the line, or give it one)";
        }
        // A malformed placeholder is caught here rather than at expansion time
        // because it is a fault in the *file*, and the file is what the user is
        // editing when they want to hear about it. Left to expansion it would
        // reach MySQL verbatim and come back as a parse error pointing at a
        // character nobody typed.
        if (preg_match(self::PARAM_BAD, $value) === 1) {
            return "`$name` has a malformed argument slot — write ?1, ?2, … (numbered from 1)";
        }
        if (preg_match(self::PROMPT_BAD, $value) === 1) {
            return "`$name` has a malformed prompt — write ?{name} (letters, digits, _ . and -)";
        }
        // A value the raw scanner handed back still wearing its opening quote is
        // one whose closing quote is missing: a matched pair is stripped, so a
        // survivor means the line ran out first. PHP does not call that an
        // error — it keeps the `"` and carries on — which makes it exactly the
        // kind of quiet wrong value this file refuses to pass on.
        if (str_starts_with($value, '"')) {
            return "`$name` has an unclosed \" — quote the whole value or none of it";
        }
        // The one place the INI format shows through. A `"` inside a quoted
        // value survives with its backslash attached, because the raw scanner
        // does not unescape — so a value that needed the quotes (it has a `;`)
        // and also carries a `"` has already been mangled by the time it gets
        // here. Saying so beats letting it be found in a broken query, and the
        // fix is usually MySQL's own quoting: `'` or a backtick.
        if (str_contains($value, '\\"')) {
            return "`$name` contains \\\" — an INI value cannot hold both a `;` and a \", so the backslash is part of the text now; use ' or ` instead";
        }
        return null;
    }

    /** @return array<string,string> every macro, name-sorted */
    public function all(): array
    {
        return $this->macros;
    }

    /** @return list<string> names, name-sorted */
    public function names(): array
    {
        return array_keys($this->macros);
    }

    public function get(string $name): ?string
    {
        return $this->macros[$name] ?? null;
    }

    /**
     * How many arguments $name takes: the highest `PARAM` index its value names,
     * or 0 for a plain macro. Unknown names take none.
     */
    public function arity(string $name): int
    {
        $value = $this->macros[$name] ?? null;
        return $value === null ? 0 : self::arityOf($value);
    }

    /** The highest `?n` index in a value, or 0 when it names none. */
    private static function arityOf(string $value): int
    {
        if (preg_match_all(self::PARAM, $value, $m) < 1) {
            return 0;
        }
        return max(array_map('intval', $m[1]));
    }

    /** Which file a name came from, for the viewer. */
    /** The environment these macros were loaded for (§16), or null for none. */
    public function env(): ?string
    {
        return $this->env;
    }

    /**
     * The envs whose sections loaded, least specific first: {@see env()}'s
     * `extends` ancestors and then it. One entry is the ordinary case; more
     * means the config gave the selected env a parent (§16).
     *
     * @return list<string>
     */
    public function envChain(): array
    {
        return $this->envChain;
    }

    /**
     * The env `$name`'s winning definition was scoped to, or null when it came
     * from outside any `[env:…]` section and so applies everywhere.
     */
    public function scopeOf(string $name): ?string
    {
        return $this->scope[$name] ?? null;
    }

    /** @return list<string> every env any layer writes a section for, sorted */
    public function envsSeen(): array
    {
        $envs = array_keys($this->envsSeen);
        sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
        return $envs;
    }

    /**
     * The names this session cannot reach: each with the envs that define it.
     *
     * A macro defined *only* under other envs is not a macro that is missing —
     * it is one behind a `-E`, and the two mistakes have different fixes. The
     * viewer says how many there are, `--lint` names them, and {@see scan()}
     * refuses a reference to one with a sentence about the env rather than
     * about the spelling. A name also defined outside a section is not here:
     * that one loaded, and what it is worth per env is the file's business.
     *
     * @return array<string,list<string>> name => env names, both sorted
     */
    public function hidden(): array
    {
        $out = [];
        foreach ($this->elsewhere as $name => $envs) {
            if (isset($this->macros[$name])) {
                continue;
            }
            $out[$name] = $this->sortedEnvs($name);
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** @return list<string> the envs defining $name that this session skipped */
    private function sortedEnvs(string $name): array
    {
        $envs = array_keys($this->elsewhere[$name] ?? []);
        sort($envs, SORT_NATURAL | SORT_FLAG_CASE);
        return $envs;
    }

    public function originOf(string $name): ?string
    {
        return $this->origin[$name] ?? null;
    }

    /** @return list<string> the files that exist, lowest precedence first */
    public function files(): array
    {
        return $this->files;
    }

    /** @return list<string> the `.sqli/` dirs searched, lowest precedence first */
    public function dirs(): array
    {
        return $this->dirs;
    }

    /** @return list<string> load complaints; empty when every layer parsed */
    public function problems(): array
    {
        return $this->problems;
    }

    public function count(): int
    {
        return count($this->macros);
    }

    /**
     * Whether $text ends with a complete call to a **defined** macro — the name
     * itself, or the name plus exactly the arguments it takes (§23).
     *
     * This is what lets `?who` submit on Enter with no `;` after it. A macro
     * that holds a whole statement carries its own terminator inside the value,
     * and making the user type a second one after the reference is asking them
     * to punctuate a sentence they cannot see. The terminator rules are
     * unchanged for everything else: this is one extra way for a line to be
     * finished, not a relaxation of the others, and Alt+Enter still overrides it
     * when the line is meant to continue.
     *
     * Deliberately narrow, because Enter is destructive when it guesses wrong:
     *
     *  - the **last** unquoted reference on the line must be the thing at the
     *    end, so `select ?cols from t` is still an unfinished line;
     *  - the name must be defined — an unknown one is a typo the user is still
     *    fixing, not a statement;
     *  - a macro that takes arguments must have been given them, so `?ganl` on
     *    its own waits for the word that is obviously still coming while
     *    `?ganl vegas` submits.
     */
    public function endsWithCall(string $text): bool
    {
        $text = rtrim($text);
        if ($text === '' || !str_contains($text, '?')
            || preg_match_all(self::REF, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) < 1
        ) {
            return false;
        }
        $spans = self::inertSpans($text);
        foreach (array_reverse($m) as $hit) {
            $start = (int) $hit[0][1];
            if (self::inSpans($start, $spans)) {
                continue; // quoted: text, and not the last reference either
            }
            $value = $this->macros[(string) $hit[1][0]] ?? null;
            if ($value === null) {
                return false;
            }
            $after = $start + strlen((string) $hit[0][0]);
            $arity = self::arityOf($value);
            if ($arity > 0) {
                [$args, $after] = self::readArgs($text, $after, $arity);
                if (count($args) < $arity) {
                    return false;
                }
            }
            return trim(substr($text, $after)) === '';
        }
        return false;
    }

    /**
     * Substitute every unquoted `?name` in $text — consuming any arguments that
     * follow a macro which takes them, and expanding whatever the value itself
     * refers to.
     *
     * Undefined names are **not** substituted — they are reported instead, so
     * the caller can refuse the submission with something better to say than
     * whatever the server would make of the leftover `?word`. Too few arguments
     * and a loop are reported the same way.
     *
     * The scan is a hand-rolled walk rather than a `preg_replace_callback`
     * because an argument-taking reference rewrites the text *after* itself: the
     * words it consumed have to disappear from the line, which a callback that
     * only ever returns a replacement for its own match cannot do.
     *
     * Note what is deliberately *not* checked afterwards: the finished text. A
     * value may legitimately end up carrying a `?word` inside a string literal,
     * and a second scan of the result could not tell that apart from a reference
     * that failed to expand.
     */
    public function expand(string $text): Expansion
    {
        if ($text === '' || !str_contains($text, '?')) {
            return new Expansion($text); // the overwhelmingly common case, at no cost
        }

        $st = [
            'used' => 0, 'missing' => [], 'short' => [], 'loops' => [],
            'elsewhere' => [],
            'budget' => self::MAX_EXPANSIONS, 'overflow' => false,
        ];
        $out = $this->scan($text, [], $st);

        // `?1` typed straight onto the CLI is a misunderstanding worth a
        // sentence: it means something only inside a value, and left alone it
        // reaches MySQL as a syntax error naming a character the user did type
        // but not for that reason. Asked of the typed text only — inside a value
        // is exactly where it *does* mean something — and of its unquoted
        // stretches only, since `select '?1'` is a string that contains one.
        $stray = false;
        if (preg_match_all(self::PARAM_STRAY, $text, $sm, PREG_OFFSET_CAPTURE) > 0) {
            $typedSpans = self::inertSpans($text);
            foreach ($sm[0] as $hit) {
                if (!self::inSpans((int) $hit[1], $typedSpans)) {
                    $stray = true;
                    break;
                }
            }
        }

        // `?{1}`, `?{}`, `?{oops` — a question that cannot be asked. A value
        // carrying one is refused when the file loads ({@see validate()}); this
        // is the same fault typed on the line or handed in as an argument, and
        // it has to be caught here or nothing catches it: PROMPT does not match
        // it, so it is never asked, and PARAM_STRAY does not either, so it goes
        // to the server as a syntax error naming a `{` the user did type — but
        // not for that reason. Asked of the finished text, which is where a
        // prompt is looked for anyway ({@see promptsIn()}), and of all of it,
        // since a prompt is filled inside quotes as well as out.
        $badPrompt = preg_match(self::PROMPT_BAD, $out) === 1;

        $result = new Expansion(
            $out,
            $st['used'],
            array_keys($st['missing']),
            array_values($st['loops']),
            $st['short'],
            $stray,
            $st['overflow'],
            $st['elsewhere'],
            $this->env,
            $badPrompt,
        );

        // Nothing runs on a refusal, and what nothing runs on is the line as it
        // was typed. Worth stating in code rather than trusting: the walk is
        // depth-first, so it can be several macros deep before it meets the
        // loop, and $out at that point is a half-expanded statement sitting in a
        // field whose name says it is the text to run.
        if ($result->problem() === null) {
            return $result;
        }
        return new Expansion(
            $text,
            0,
            $result->unknown,
            $result->loops,
            $result->short,
            $result->stray,
            $result->overflow,
            $result->elsewhere,
            $this->env,
            $result->badPrompt,
        );
    }

    /**
     * Expand one piece of text — the typed line, or a value being substituted
     * into it — and return the result.
     *
     * $stack is the chain of macros currently being expanded, outermost
     * first, and it is the whole of the loop check: a name already on it is a
     * macro that would expand itself, so the reference is left alone and the
     * chain from that name back round to it is recorded as the path to show the
     * user. Nothing else can stop the recursion — a graph with no cycle in it
     * terminates on its own — which is why the check is here and not a depth
     * limit dressed up as one.
     *
     * A value is scanned **after** its own arguments are substituted, so an
     * argument can be handed down (`r7 = "?recent ?1"`), and so an argument's
     * text is itself scanned. That is one rule rather than two, and it is what
     * makes quoting the answer for an argument that must stay literal — the
     * same answer it already is everywhere else.
     *
     * Arguments are read from the text being scanned and nowhere else. A value
     * of `?inner` does not reach out to the CLI line for `inner`'s argument:
     * to pass one down, the outer value has to say so.
     *
     * Quoted stretches of $text are copied through untouched. They are found
     * once per call, over the text as it was handed in: a substitution may well
     * introduce a quote of its own, but that quote belongs to the value, not to
     * the document the reference was written in, and re-lexing after every
     * substitution would let one macro's stray apostrophe silently swallow the
     * rest of the line.
     *
     * @param list<string>         $stack names being expanded, outermost first
     * @param array<string,mixed>  $st    accumulator (used/missing/short/loops/budget)
     */
    private function scan(string $text, array $stack, array &$st, ?array $args = null): string
    {
        $out = '';
        $pos = 0;
        $len = strlen($text);
        $spans = self::inertSpans($text);

        while ($pos < $len && preg_match(self::REF_OR_SLOT, $text, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            $start = (int) $m[0][1];
            $end = $start + strlen((string) $m[0][0]);
            $out .= substr($text, $pos, $start - $pos);
            $pos = $end;

            // An argument slot, `?1`. Filled inside quotes as well as out —
            // `'%?1%'` is the ordinary shape for a LIKE — but only where the
            // caller handed arguments down; on the typed line and in a value
            // that takes none it is text, and `expand()` says so separately.
            if (($m[1][1] ?? -1) !== -1) {
                if ($args === null) {
                    $out .= $m[0][0];
                    continue;
                }
                $arg = $args[((int) $m[1][0]) - 1] ?? '';
                // Outside quotes an argument is scanned like any other text, so
                // one macro can be handed to another. Inside them it is what it
                // says it is: the value asked for a string and got one.
                $out .= self::inSpans($start, $spans) ? $arg : $this->scan($arg, $stack, $st);
                continue;
            }

            // Inside a literal, an identifier or a comment: the escape, and the
            // whole of it. The reference is copied through as the text it is.
            if (self::inSpans($start, $spans)) {
                $out .= $m[0][0];
                continue;
            }

            $name = (string) $m[2][0];
            $value = $this->macros[$name] ?? null;
            if ($value === null) {
                // Defined, but under an env this session did not select: a
                // different mistake from a typo, and one whose fix is `-E`
                // rather than the spelling. Kept apart here so the refusal can
                // say which.
                if (isset($this->elsewhere[$name])) {
                    $st['elsewhere'][$name] = $this->sortedEnvs($name);
                } else {
                    $st['missing'][$name] = true;
                }
                $out .= $m[0][0];
                continue;
            }

            $at = array_search($name, $stack, true);
            if ($at !== false) {
                $path = array_merge(array_slice($stack, (int) $at), [$name]);
                $st['loops'][implode("\0", $path)] = $path; // keyed: one loop, said once
                $out .= $m[0][0];
                continue;
            }

            $arity = self::arityOf($value);
            $given = null;
            if ($arity > 0) {
                [$given, $after] = self::readArgs($text, $end, $arity);
                if (count($given) < $arity) {
                    $st['short'][$name] = ['need' => $arity, 'got' => count($given)];
                    $out .= $m[0][0];
                    continue;
                }
                // The arguments were read out of the text as written, which for
                // a value means before its own slots were filled — so a macro
                // handing its argument down (`r7 = "?recent ?1"`) would pass the
                // characters `?1` rather than the word they stand for. Resolved
                // here, one level at a time, which is the only place that knows
                // what this level's `?1` is.
                if ($args !== null) {
                    $given = array_map(
                        static fn (string $a): string => self::fillSlots($a, $args),
                        $given,
                    );
                }
                $pos = $after; // the words became the arguments; they leave the line
            }

            // A loop-free graph still terminates, but it can terminate after an
            // enormous amount of text: ten macros each naming the previous
            // one twice is a thousand substitutions from one reference. The
            // budget is not a second loop check — it is the difference between a
            // slow answer and a hung terminal.
            if (--$st['budget'] < 0) {
                $st['overflow'] = true;
                $out .= $m[0][0];
                continue;
            }

            $st['used']++;
            // The value is walked with **its own** quoting, read before any
            // argument went into it. An argument is data, and data that happens
            // to carry an apostrophe (`?agency "O'Brien"`) must not re-lex the
            // macro it was handed to and silently stop the rest of that macro's
            // own body from expanding.
            $out .= str_contains($value, '?')
                ? $this->scan($value, [...$stack, $name], $st, $given)
                : $value;
        }
        return $out . substr($text, $pos);
    }

    /**
     * The names $text asks the user for, in the order they are first written,
     * each once — the questions to put before this submission runs.
     *
     * Asked of the text **after** macro expansion, so a `?{name}` written into
     * a macro is found exactly like one typed on the line — and of the whole
     * text, quoted stretches included, because `'?{from}'` is the ordinary
     * shape for a value that lands in a string.
     *
     * @return list<string>
     */
    public static function promptsIn(string $text): array
    {
        if (!str_contains($text, '?{') || preg_match_all(self::PROMPT, $text, $m, PREG_SET_ORDER) < 1) {
            return [];
        }
        $names = [];
        foreach ($m as $hit) {
            $names[$hit[1]] = true; // keyed: asked once however often it is written
        }
        return array_keys($names);
    }

    /**
     * Put the answers into $text.
     *
     * Called before a submission runs whether or not there were questions, so
     * there is one path rather than two; with no answers there is nothing to
     * match and the text comes back as it went in.
     *
     * An answer is substituted **as text**, on the same terms as a macro value
     * and an argument: it may be a number, a quoted literal, a fragment of SQL.
     * Nothing is escaped and nothing is quoted for you, so the statement decides
     * its own quoting — `= '?{name}'` if that is what it wants.
     *
     * The result is not rescanned. An answer that happens to contain `?x` or
     * `?{y}` is text: this is a value the user typed a moment ago in reply to
     * a question, not a document they are composing, and re-reading it as syntax
     * would make an ordinary search term into a second round of questions.
     *
     * @param array<string,string> $answers name => what the user typed
     */
    public static function fillPrompts(string $text, array $answers): string
    {
        if ($answers === [] || !str_contains($text, '?{')) {
            return $text;
        }
        return (string) preg_replace_callback(
            self::PROMPT,
            static fn (array $m): string => $answers[$m[1]] ?? $m[0],
            $text,
        );
    }
    /**
     * Read up to $want arguments from $text starting at $from.
     *
     * Bash-shaped, with one borrowing from SQL: an unquoted argument ends at
     * whitespace **or at `;`**, so `?ganl vegas;` passes `vegas` and leaves
     * the statement terminator where it was written. Typing the `;` is a habit
     * nobody should have to break to use a macro, and the alternative — a
     * `LIKE '%vegas;%'` that matches nothing and says nothing — is the kind of
     * quiet wrong answer this whole file exists to avoid. Quote the argument to
     * put a `;` (or a space) inside one.
     *
     * A quote with no closing partner is not an argument at all: it is left in
     * place, and the shortfall is reported. Guessing where the user meant it to
     * end would be the same quiet wrong answer in a different costume.
     *
     * **A quoted argument ends where {@see inertSpans()} would end a literal**,
     * and by the same two rules: a doubled delimiter (`"it""s"`) is one literal
     * character rather than a close followed by an open, and a backslash before
     * the delimiter (`"a\"b"`) keeps it inside. Finding the close with a plain
     * `strpos()` was the last place two lexers in this class read the same two
     * characters differently — `?agency "it""s"` ended the argument at the
     * doubled quote and let `s"` ride along behind the value's own `;`.
     *
     * @return array{0:list<string>,1:int} the arguments read, and the offset just
     *         past the last one consumed (equal to $from when none were)
     */
    private static function readArgs(string $text, int $from, int $want): array
    {
        $args = [];
        $pos = $from;
        $len = strlen($text);

        while (count($args) < $want) {
            $p = $pos;
            while ($p < $len && ($text[$p] === ' ' || $text[$p] === "\t")) {
                $p++;
            }
            if ($p >= $len) {
                break;
            }
            $ch = $text[$p];
            $ch = $text[$p];
            if ($ch === '"' || $ch === "'") {
                [$arg, $after] = self::readQuoted($text, $p, $ch);
                if ($after === null) {
                    break; // no closing partner: not an argument at all
                }
                $args[] = $arg;
                $pos = $after;
                continue;
            }
            if (in_array($ch, self::ARG_STOP, true)) {
                break; // a `;` or a line end: the arguments stop here
            }
            $q = $p;
            while ($q < $len && !in_array($text[$q], self::ARG_STOP, true)) {
                $q++;
            }
            $args[] = substr($text, $p, $q - $p);
            $pos = $q;
        }
        return [$args, $pos];
    }

    /**
     * Read one quoted argument, beginning at the opening delimiter at $from.
     *
     * Where it ends is {@see inertSpans()}' question, answered its way, so the
     * two readers of a quoted span cannot drift apart again. What comes back is
     * the argument's **text**, with the quoting taken off: a doubled delimiter
     * is one character, and a backslash is dropped before the delimiter or
     * before another backslash — and **only** there. `\n`, `\%` and a Windows
     * path keep both of their characters, because an argument is data on its
     * way into SQL rather than a C string, and eating the backslash out of
     * `"C:\dev\sqli"` would be a quiet wrong answer of its own.
     *
     * @return array{0:string,1:int|null} the text, and the offset just past the
     *         closing delimiter — or `null` when the quote never closes
     */
    private static function readQuoted(string $text, int $from, string $quote): array
    {
        $len = strlen($text);
        $out = '';
        $i = $from + 1;

        while ($i < $len) {
            $ch = $text[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $text[$i + 1];
                $out .= ($next === $quote || $next === '\\') ? $next : $ch . $next;
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                if (($text[$i + 1] ?? '') === $quote) {
                    $out .= $quote; // `""` is one quote inside the argument
                    $i += 2;
                    continue;
                }
                return [$out, $i + 1];
            }
            $out .= $ch;
            $i++;
        }
        return ['', null];
    }

    /**
     * Put $args into the `?n` slots of one **argument token** read out of a
     * value, 1-based.
     *
     * This used to fill a whole value in one pass, before {@see scan()} walked
     * it — which is what let an argument's apostrophe re-lex the macro it was
     * handed to. The walk now substitutes each slot as it reaches it, and the
     * only text still filled ahead of time is an argument being passed *down*
     * to another macro (`r7 = "?recent ?1"`), because {@see readArgs()} reads it
     * out of the value as written and cannot know what this level's `?1` is.
     *
     * @param list<string> $args this level's arguments
     */
    private static function fillSlots(string $text, array $args): string
    {
        if (!str_contains($text, '?')) {
            return $text;
        }
        return (string) preg_replace_callback(
            self::PARAM,
            static fn (array $m): string => $args[((int) $m[1]) - 1] ?? '',
            $text,
        );
    }

    /** Last few path segments — enough to tell the layers apart in one line. */
    private static function short(string $path): string
    {
        $parts = preg_split('#[/\\\\]#', $path) ?: [];
        return implode('/', array_slice($parts, -3));
    }

    /** Seed contents written beside the other `.sqli/` files by `scaffoldAt()`. */
    public const DEFAULT_INI = <<<'INI'
        ; sqli macros — see spec §23. Edit this file in your editor; sqli never writes it.
        ; Reload it from the running app with  /  ->  Macros  ->  r
        ;
        ;   name = value          a name typed on the CLI as ?name is replaced by its
        ;                         value before the statement runs. Do not write the ?
        ;                         here. A ?name inside '…', "…", `…` or a comment is
        ;                         left alone — that is the escape, and the only one.
        ;   ?1  ?2  ?3            an argument: the words after ?name on the CLI fill
        ;                         these in, numbered from 1. Quote one to include a
        ;                         space. These ARE filled inside quotes.
        ;   ?{name}               ask when the statement runs: the CLI prompts "name:".
        ;   ?other                a value may use other macros, as deep as it goes.
        ;                         A loop (a uses b uses a) is refused and reported here.
        ;
        ; QUOTE ANY VALUE CONTAINING A SEMICOLON — outside quotes a ; starts a comment
        ; and the rest of the line is lost, and a macro ending in ; is the normal case:
        ;
        ;   who   = select current_user(), database()
        ;   ganl  = "select * from agencies where name like '%?1%';"
        ;   byagy = "select * from inspection where agency = ?{agency};"
        ;
        ; [section] headers are dividers and are yours to use — with one
        ; exception. A head spelled [env:NAME] scopes what is under it to that
        ; environment (-E NAME), so one name can be a different id per server:
        ;
        ;   agency-id = 22          ; no section: every env, and every session
        ;
        ;   [env:dev]
        ;   agency-id = 22
        ;
        ;   [env:prod]
        ;   agency-id = 1174
        ;
        ; With no -E, no [env:…] section loads at all, and ?agency-id then says
        ; which envs define it rather than quietly using the last one written.

        INI;
}
