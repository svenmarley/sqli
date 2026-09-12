<?php

declare(strict_types=1);

namespace Sqli\Json;

/**
 * A parsed, editable JSON document tree (spec §10). Pure — no I/O, no terminal.
 *
 * The App builds one from a cell's value when it is a JSON **document** (an
 * object or array; a bare scalar like `42` is valid JSON but must NOT hijack
 * normal cell editing, so it is deliberately rejected here — see {@see
 * isDocument()}). The tree can be walked into a flat, indentation-aware row
 * list for rendering ({@see rows()}), collapsed/expanded, have a scalar leaf's
 * value edited ({@see setScalar()}), and re-encoded to a compact JSON string
 * ({@see encode()}) that feeds the pending-change / UPDATE machinery.
 */
final class JsonTree
{
    private function __construct(
        private readonly JsonNode $root,
        private readonly string $baseline,
    ) {
    }

    /**
     * True when $s is a JSON **document** (top-level object or array) — the only
     * thing the editor opens for. Bare scalars (`42`, `"x"`, `true`, `null`) are
     * valid JSON but are left to the normal cell editor (§9).
     */
    public static function isDocument(string $s): bool
    {
        $t = trim($s);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) {
            return false;
        }
        json_decode($t);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /** Parse a JSON document into a tree, or null if it is not a valid document. */
    public static function parse(string $s): ?self
    {
        if (!self::isDocument($s)) {
            return null;
        }
        $decoded = json_decode(trim($s), false); // objects → stdClass (keeps key order)
        $root = self::build(null, $decoded);
        $tree = new self($root, '');
        return new self($root, $tree->encode()); // capture the normalized baseline
    }

    /** Recursively build a node for a decoded PHP value. */
    private static function build(int|string|null $key, mixed $v): JsonNode
    {
        if (is_object($v)) {
            $node = new JsonNode(JsonNode::OBJECT, $key);
            foreach (get_object_vars($v) as $k => $child) {
                $node->children[] = self::build($k, $child);
            }
            return $node;
        }
        if (is_array($v)) {
            $node = new JsonNode(JsonNode::ARRAY, $key);
            foreach ($v as $i => $child) {
                $node->children[] = self::build($i, $child);
            }
            return $node;
        }
        return new JsonNode(self::scalarType($v), $key, $v);
    }

    private static function scalarType(mixed $v): string
    {
        return match (true) {
            is_bool($v) => JsonNode::BOOL,
            $v === null => JsonNode::NULL,
            is_string($v) => JsonNode::STRING,
            default => JsonNode::NUMBER, // int|float
        };
    }

    /**
     * Flatten the tree into visible rows (respecting each container's collapsed
     * state), in display order. Each row carries a reference to its {@see
     * JsonNode} so the caller can toggle/edit the exact node the user selects.
     *
     * @return list<array{
     *   node:JsonNode, depth:int, label:string, value:string,
     *   type:string, container:bool, expanded:bool, childCount:int, editable:bool
     * }>
     */
    public function rows(): array
    {
        $out = [];
        $this->walk($this->root, 0, $out);
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $out
     */
    private function walk(JsonNode $node, int $depth, array &$out): void
    {
        $out[] = [
            'node' => $node,
            'depth' => $depth,
            'label' => $this->labelOf($node),
            'value' => $this->valueOf($node),
            'type' => $node->type,
            'container' => $node->isContainer(),
            'expanded' => $node->expanded,
            'childCount' => count($node->children),
            'editable' => !$node->isContainer(),
        ];
        if ($node->isContainer() && $node->expanded) {
            foreach ($node->children as $child) {
                $this->walk($child, $depth + 1, $out);
            }
        }
    }

    /** The row label: object key, `[index]` for array elements, '' for the root. */
    private function labelOf(JsonNode $node): string
    {
        if ($node->key === null) {
            return '';
        }
        return is_int($node->key) ? '[' . $node->key . ']' : (string) $node->key;
    }

    /** The display value: JSON-encoded scalar, or a brace summary for containers. */
    private function valueOf(JsonNode $node): string
    {
        if ($node->type === JsonNode::OBJECT) {
            $n = count($node->children);
            return $node->expanded ? '{' : ($n === 0 ? '{}' : '{ … } (' . $n . ')');
        }
        if ($node->type === JsonNode::ARRAY) {
            $n = count($node->children);
            return $node->expanded ? '[' : ($n === 0 ? '[]' : '[ … ] (' . $n . ')');
        }
        return self::encodeScalar($node->scalar);
    }

    /** JSON encoding of a scalar (strings quoted), for display + edit prefill. */
    public static function encodeScalar(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // --- mutation ----------------------------------------------------------

    public function toggle(JsonNode $node): void
    {
        if ($node->isContainer()) {
            $node->expanded = !$node->expanded;
        }
    }

    public function setExpandedAll(bool $expanded): void
    {
        $this->applyExpanded($this->root, $expanded);
    }

    private function applyExpanded(JsonNode $node, bool $expanded): void
    {
        if ($node->isContainer()) {
            $node->expanded = $expanded;
            foreach ($node->children as $child) {
                $this->applyExpanded($child, $expanded);
            }
        }
    }

    /**
     * Replace a scalar leaf's value from raw editor input, interpreted as JSON so
     * the type is controllable (`"text"` → string, `42` → number, `true`/`null`).
     * Input that is not valid JSON is stored as a plain string. No-op on a
     * container node. Returns true if the node's value actually changed.
     */
    public function setScalar(JsonNode $node, string $input): bool
    {
        if ($node->isContainer()) {
            return false;
        }
        $decoded = json_decode($input);
        if (json_last_error() === JSON_ERROR_NONE && !is_array($decoded) && !is_object($decoded)) {
            $newType = self::scalarType($decoded);
            $newVal = $decoded;
        } else {
            $newType = JsonNode::STRING;
            $newVal = $input;
        }
        if ($node->type === $newType && $node->scalar === $newVal) {
            return false;
        }
        $node->type = $newType;
        $node->scalar = $newVal;
        return true;
    }

    // --- structural edits (§10) --------------------------------------------

    /** The parent container of $target, or null if it is the root / not found. */
    public function parentOf(JsonNode $target): ?JsonNode
    {
        return $this->searchParent($this->root, $target);
    }

    private function searchParent(JsonNode $node, JsonNode $target): ?JsonNode
    {
        foreach ($node->children as $child) {
            if ($child === $target) {
                return $node;
            }
            $found = $this->searchParent($child, $target);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * Delete a node and its whole subtree. Returns false for the root (which
     * can't be removed) or an unknown node. Array indices are renumbered.
     */
    public function deleteNode(JsonNode $target): bool
    {
        if ($target === $this->root) {
            return false;
        }
        $parent = $this->parentOf($target);
        if ($parent === null) {
            return false;
        }
        $i = array_search($target, $parent->children, true);
        if ($i === false) {
            return false;
        }
        array_splice($parent->children, $i, 1);
        if ($parent->type === JsonNode::ARRAY) {
            $this->reindex($parent);
        }
        return true;
    }

    /**
     * Insert a new null-valued node into $container at $pos (clamped). For an
     * object the caller supplies the member key; for an array the key is ignored
     * and indices are renumbered. The container is expanded so the new node
     * shows. Returns the new node (edit its value next).
     */
    public function addNode(JsonNode $container, int $pos, int|string|null $key): ?JsonNode
    {
        if (!$container->isContainer()) {
            return null;
        }
        $node = new JsonNode(JsonNode::NULL, $key, null);
        $pos = max(0, min($pos, count($container->children)));
        array_splice($container->children, $pos, 0, [$node]);
        if ($container->type === JsonNode::ARRAY) {
            $this->reindex($container);
        }
        $container->expanded = true;
        return $node;
    }

    /** The 0-based position of $child within $parent, or count() if not found. */
    public function indexIn(JsonNode $parent, JsonNode $child): int
    {
        $i = array_search($child, $parent->children, true);
        return $i === false ? count($parent->children) : $i;
    }

    private function reindex(JsonNode $arr): void
    {
        foreach ($arr->children as $i => $child) {
            $child->key = $i;
        }
    }

    // --- serialization -----------------------------------------------------

    /** Compact JSON encoding of the current tree (what gets stored on save). */
    public function encode(): string
    {
        return (string) json_encode($this->toValue($this->root), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** True once any edit has diverged the tree from its parsed baseline. */
    public function isModified(): bool
    {
        return $this->encode() !== $this->baseline;
    }

    private function toValue(JsonNode $node): mixed
    {
        if ($node->type === JsonNode::OBJECT) {
            $o = new \stdClass();
            foreach ($node->children as $child) {
                $o->{(string) $child->key} = $this->toValue($child);
            }
            return $o;
        }
        if ($node->type === JsonNode::ARRAY) {
            $a = [];
            foreach ($node->children as $child) {
                $a[] = $this->toValue($child);
            }
            return $a;
        }
        return $node->scalar;
    }
}
