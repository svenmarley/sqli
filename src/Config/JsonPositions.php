<?php

declare(strict_types=1);

namespace Sqli\Config;

/**
 * Where things are in a JSON text. `json_decode` keeps no positions, so this
 * walks the text once and records, for every object member, the offsets of its
 * key and value. `--lint` uses it to put `file:line` on a message (#27), and
 * `--lint --fix` to cut a member out of the text without touching anything
 * else in the file — layout, comment keys and line endings stay as written (#26).
 *
 * A member is addressed by its key path from the root, e.g.
 * `['envs', 'prod', 'label']`; array elements use their index as a string. A
 * duplicated key keeps the first one's position.
 *
 * Invalid JSON does not throw: `errorOffset()` says where the scan stopped,
 * which is a better pointer than `json_last_error_msg()` gives.
 */
final class JsonPositions
{
    /** @var array<string, array{keyStart:int, valueStart:int, valueEnd:int}> path key => offsets */
    private array $members = [];

    /** @var array<string, list<string>> container path key => its member keys in order */
    private array $children = [];

    /** @var array<string, array{open:int, close:int}> container path key => brace offsets */
    private array $containers = [];

    /** @var list<int> offset of the start of each line */
    private array $lineStarts = [0];

    private ?int $errorOffset = null;

    private int $pos = 0;
    private int $len;

    public function __construct(private readonly string $text)
    {
        $this->len = strlen($text);
        for ($i = 0; $i < $this->len; $i++) {
            if ($text[$i] === "\n") {
                $this->lineStarts[] = $i + 1;
            }
        }
        try {
            // A UTF-8 BOM is skipped so the lines still count right.
            if (str_starts_with($text, "\xEF\xBB\xBF")) {
                $this->pos = 3;
            }
            $this->ws();
            $this->value([]);
            $this->ws();
            if ($this->pos < $this->len) {
                throw new \UnexpectedValueException();
            }
        } catch (\UnexpectedValueException) {
            $this->errorOffset = min($this->pos, $this->len);
        }
    }

    /** Offset where the scan found invalid JSON, or null when the text is valid. */
    public function errorOffset(): ?int
    {
        return $this->errorOffset;
    }

    /** 1-based line of a byte offset. */
    public function lineOf(int $offset): int
    {
        $lo = 0;
        $hi = count($this->lineStarts) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($this->lineStarts[$mid] <= $offset) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        return $lo + 1;
    }

    /**
     * Line of the member at this path — the key's line. When the exact member
     * is not found, the closest parent that is; null when none is.
     *
     * @param list<string|int> $path
     */
    public function line(array $path): ?int
    {
        $path = array_map('strval', $path);
        while ($path !== []) {
            $m = $this->members[self::k($path)] ?? null;
            if ($m !== null) {
                return $this->lineOf($m['keyStart']);
            }
            array_pop($path);
        }
        return null;
    }

    /** @param list<string|int> $path */
    public function has(array $path): bool
    {
        return isset($this->members[self::k(array_map('strval', $path))]);
    }

    /**
     * The text with the member at $path removed, together with the comma that
     * joined it to a neighbour, or null when there is no such member. Removing
     * the only member leaves `{}`.
     *
     * - not the last member: cut from its key up to the next key, so the next
     *   member takes its place and its indentation;
     * - the last of several: cut from the end of the previous value, which
     *   takes the comma before it and leaves the closing brace where it was.
     *
     * @param list<string|int> $path
     */
    public function without(array $path): ?string
    {
        $path = array_map('strval', $path);
        $m = $this->members[self::k($path)] ?? null;
        if ($m === null) {
            return null;
        }
        $key = array_pop($path);
        $parent = self::k($path);
        $siblings = $this->children[$parent] ?? [];
        $i = array_search($key, $siblings, true);
        if ($i === false) {
            return null;
        }
        if (isset($siblings[$i + 1])) {
            $next = $this->members[self::k([...$path, $siblings[$i + 1]])];
            [$from, $to] = [$m['keyStart'], $next['keyStart']];
        } elseif ($i > 0) {
            $prev = $this->members[self::k([...$path, $siblings[$i - 1]])];
            [$from, $to] = [$prev['valueEnd'], $m['valueEnd']];
        } else {
            $c = $this->containers[$parent];
            [$from, $to] = [$c['open'] + 1, $c['close']];
        }
        return substr($this->text, 0, $from) . substr($this->text, $to);
    }

    /** @param list<string> $path */
    private static function k(array $path): string
    {
        return implode("\0", $path);
    }

    private function ws(): void
    {
        while ($this->pos < $this->len && str_contains(" \t\r\n", $this->text[$this->pos])) {
            $this->pos++;
        }
    }

    private function expect(string $ch): void
    {
        if ($this->pos >= $this->len || $this->text[$this->pos] !== $ch) {
            throw new \UnexpectedValueException();
        }
        $this->pos++;
    }

    /** @param list<string> $path */
    private function value(array $path): void
    {
        if ($this->pos >= $this->len) {
            throw new \UnexpectedValueException();
        }
        $c = $this->text[$this->pos];
        match (true) {
            $c === '{' => $this->object($path),
            $c === '[' => $this->array($path),
            $c === '"' => $this->string(),
            default    => $this->scalar(),
        };
    }

    /** @param list<string> $path */
    private function object(array $path): void
    {
        $parent = self::k($path);
        $open = $this->pos;
        $this->children[$parent] = [];
        $this->pos++;
        $this->ws();
        if ($this->pos < $this->len && $this->text[$this->pos] === '}') {
            $this->containers[$parent] = ['open' => $open, 'close' => $this->pos];
            $this->pos++;
            return;
        }
        while (true) {
            $this->ws();
            $keyStart = $this->pos;
            if ($this->pos >= $this->len || $this->text[$this->pos] !== '"') {
                throw new \UnexpectedValueException();
            }
            $key = $this->string();
            $this->ws();
            $this->expect(':');
            $this->ws();
            $valueStart = $this->pos;
            $child = [...$path, $key];
            $this->value($child);
            $ck = self::k($child);
            if (!isset($this->members[$ck])) {
                $this->members[$ck] = ['keyStart' => $keyStart, 'valueStart' => $valueStart, 'valueEnd' => $this->pos];
                $this->children[$parent][] = $key;
            }
            $this->ws();
            if ($this->pos < $this->len && $this->text[$this->pos] === ',') {
                $this->pos++;
                continue;
            }
            $this->ws();
            if ($this->pos < $this->len && $this->text[$this->pos] === '}') {
                $this->containers[$parent] = ['open' => $open, 'close' => $this->pos];
                $this->pos++;
                return;
            }
            throw new \UnexpectedValueException();
        }
    }

    /** @param list<string> $path */
    private function array(array $path): void
    {
        $this->pos++;
        $this->ws();
        if ($this->pos < $this->len && $this->text[$this->pos] === ']') {
            $this->pos++;
            return;
        }
        $i = 0;
        while (true) {
            $this->ws();
            $start = $this->pos;
            $child = [...$path, (string) $i];
            $this->value($child);
            $this->members[self::k($child)] ??= ['keyStart' => $start, 'valueStart' => $start, 'valueEnd' => $this->pos];
            $i++;
            $this->ws();
            if ($this->pos < $this->len && $this->text[$this->pos] === ',') {
                $this->pos++;
                continue;
            }
            $this->expect(']');
            return;
        }
    }

    /** Scan a string token and return its decoded value. */
    private function string(): string
    {
        $start = $this->pos;
        $this->pos++;
        while ($this->pos < $this->len) {
            $c = $this->text[$this->pos];
            if ($c === '\\') {
                $this->pos += 2;
                continue;
            }
            if ($c === '"') {
                $this->pos++;
                $decoded = json_decode(substr($this->text, $start, $this->pos - $start));
                if (!is_string($decoded)) {
                    $this->pos = $start;
                    throw new \UnexpectedValueException();
                }
                return $decoded;
            }
            if (ord($c) < 0x20) {
                throw new \UnexpectedValueException();
            }
            $this->pos++;
        }
        throw new \UnexpectedValueException();
    }

    private function scalar(): void
    {
        if (preg_match('/\G(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $this->text, $m, 0, $this->pos) !== 1) {
            throw new \UnexpectedValueException();
        }
        $this->pos += strlen($m[0]);
    }
}
