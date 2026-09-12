<?php

declare(strict_types=1);

namespace Sqli\Json;

/**
 * One node in a parsed JSON document tree (§10). A node is either a container
 * (object/array) with ordered {@see $children}, or a scalar leaf carrying a
 * PHP value in {@see $scalar}. Object members keep their key; array elements
 * keep their integer index; the root's key is null.
 *
 * Nodes are held by reference by {@see JsonTree}, so toggling {@see $expanded}
 * or editing a scalar mutates the live tree the viewer renders.
 */
final class JsonNode
{
    public const OBJECT = 'object';
    public const ARRAY = 'array';
    public const STRING = 'string';
    public const NUMBER = 'number';
    public const BOOL = 'bool';
    public const NULL = 'null';

    public bool $expanded = true;

    /** @var list<JsonNode> */
    public array $children = [];

    public function __construct(
        public string $type,
        public int|string|null $key,
        public mixed $scalar = null,
    ) {
    }

    public function isContainer(): bool
    {
        return $this->type === self::OBJECT || $this->type === self::ARRAY;
    }
}
