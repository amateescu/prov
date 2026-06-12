<?php

declare(strict_types=1);

namespace Prov\Attribute;

use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

/**
 * Immutable bag of attributes attached to any PROV record. Values can be
 * typed literals, QualifiedName references, or plain scalars; multiple
 * values per key are allowed.
 *
 * Construct with `Attributes::from(...)`, `Attributes::single(...)`, an
 * `AttributesBuilder`, or share the empty singleton via `Attributes::empty()`.
 * Derive new instances with `with()`.
 *
 * The bag is iterable (each key-value pair is yielded separately, with the
 * key as a QualifiedName) and countable (total number of values across all
 * keys). `keys()` lists the distinct keys as QualifiedName objects.
 *
 * @implements \IteratorAggregate<\Prov\Identifier\QualifiedName, \Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>
 */
readonly class Attributes implements \Countable, \IteratorAggregate
{
    /**
     * @param array<string, list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>> $data
     *   Values keyed by the full URI of their attribute key.
     * @param array<string, \Prov\Identifier\QualifiedName> $keys
     *   QualifiedName key objects, keyed by the same URIs as $data. Entries may
     *   be omitted; `keys()` and iteration then derive a QualifiedName from the
     *   URI itself, minting a prefix. All library construction paths populate
     *   this map, so original prefixes are preserved unless an instance is
     *   constructed directly from raw URI-keyed data.
     */
    public function __construct(
        private array $data = [],
        private array $keys = [],
    ) {}

    /**
     * Shared empty instance. Safe to reuse because Attributes is immutable.
     */
    public static function empty(): self
    {
        /** @var \Prov\Attribute\Attributes|null $instance */
        static $instance = null;
        return $instance ??= new self();
    }

    /**
     * Returns a new Attributes instance with the given key-value pair added.
     */
    public function with(QualifiedName $key, QualifiedName|Literal|string|int|float|bool $value): self
    {
        $uri = $key->getUri();
        $data = $this->data;
        $keys = $this->keys;
        $data[$uri][] = $value;
        $keys[$uri] ??= $key;
        return new self($data, $keys);
    }

    /**
     * Creates an Attributes instance from an array of [QualifiedName, value] pairs.
     *
     * @param list<array{\Prov\Identifier\QualifiedName, \Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool}> $pairs
     */
    public static function from(array $pairs): self
    {
        $data = [];
        $keys = [];
        foreach ($pairs as [$key, $value]) {
            $uri = $key->getUri();
            $data[$uri][] = $value;
            $keys[$uri] ??= $key;
        }
        return new self($data, $keys);
    }

    /**
     * Shorthand for a single-entry Attributes bag.
     */
    public static function single(QualifiedName $key, QualifiedName|Literal|string|int|float|bool $value): self
    {
        return new self([$key->getUri() => [$value]], [$key->getUri() => $key]);
    }

    /**
     * @return list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>
     */
    public function get(QualifiedName $key): array
    {
        return $this->data[$key->getUri()] ?? [];
    }

    /**
     * First value for a key, or null if the key is unset or empty.
     */
    public function firstValue(QualifiedName $key): QualifiedName|Literal|string|int|float|bool|null
    {
        return $this->data[$key->getUri()][0] ?? null;
    }

    /**
     * Values for a key filtered to Literal instances.
     *
     * @return list<\Prov\Attribute\Literal>
     */
    public function getLiterals(QualifiedName $key): array
    {
        $out = [];
        foreach ($this->data[$key->getUri()] ?? [] as $value) {
            if ($value instanceof Literal) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Values for a key filtered to QualifiedName instances (typically IRI-valued attributes).
     *
     * @return list<\Prov\Identifier\QualifiedName>
     */
    public function getQualifiedNames(QualifiedName $key): array
    {
        $out = [];
        foreach ($this->data[$key->getUri()] ?? [] as $value) {
            if ($value instanceof QualifiedName) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Values for a key filtered to native PHP scalars.
     *
     * @return list<string|int|float|bool>
     */
    public function getScalars(QualifiedName $key): array
    {
        $out = [];
        foreach ($this->data[$key->getUri()] ?? [] as $value) {
            if (is_scalar($value)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Whether any value is stored for the given key.
     */
    public function has(QualifiedName $key): bool
    {
        return isset($this->data[$key->getUri()]);
    }

    /**
     * @return array<string, list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * The distinct attribute keys, as QualifiedName objects.
     *
     * @return list<\Prov\Identifier\QualifiedName>
     */
    public function keys(): array
    {
        $out = [];
        foreach (array_keys($this->data) as $uri) {
            $out[] = $this->keyFor($uri);
        }
        return $out;
    }

    /**
     * Yields each value separately under its QualifiedName key, so a key with
     * multiple values is visited once per value. Keys are objects; collect
     * pairs with a foreach rather than iterator_to_array(), which requires
     * int|string keys.
     *
     * @return \Generator<\Prov\Identifier\QualifiedName, \Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>
     */
    public function getIterator(): \Generator
    {
        foreach ($this->data as $uri => $values) {
            $key = $this->keyFor($uri);
            foreach ($values as $value) {
                yield $key => $value;
            }
        }
    }

    /**
     * Total number of values across all keys (matches the iteration length).
     */
    public function count(): int
    {
        $count = 0;
        foreach ($this->data as $values) {
            $count += count($values);
        }
        return $count;
    }

    /**
     * Whether the bag holds no values.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Returns the QualifiedName for a key URI. For instances constructed from
     * raw URI-keyed data without key objects, one is derived from the URI: the
     * local part starts after the last '#' or '/', and the prefix is minted
     * deterministically from the namespace URI. Opaque IRIs with neither
     * separator (e.g. `urn:uuid:1234`, `tag:example`) split at the last ':'
     * instead, so the scheme-plus-NSS forms the namespace. A key that yields
     * an empty namespace or local part (no separator at all, or nothing after
     * the last one) cannot become a QualifiedName and is rejected loudly.
     */
    private function keyFor(string $uri): QualifiedName
    {
        if (isset($this->keys[$uri])) {
            return $this->keys[$uri];
        }

        $hashPos = strrpos($uri, '#');
        $slashPos = strrpos($uri, '/');
        $pos = max($hashPos === false ? -1 : $hashPos, $slashPos === false ? -1 : $slashPos);
        if ($pos < 0) {
            $colonPos = strrpos($uri, ':');
            if ($colonPos !== false) {
                $pos = $colonPos;
            }
        }
        $nsUri = substr($uri, 0, $pos + 1);
        $localPart = substr($uri, $pos + 1);
        if ($nsUri === '' || $localPart === '') {
            throw new \InvalidArgumentException(
                "Cannot derive a namespace and local part from attribute key URI '{$uri}'.",
            );
        }

        return new QualifiedName(new ProvNamespace('ns' . crc32($nsUri), $nsUri), $localPart);
    }
}
