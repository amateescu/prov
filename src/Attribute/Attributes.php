<?php

declare(strict_types=1);

namespace Prov\Attribute;

use Prov\Identifier\QualifiedName;

/**
 * Immutable bag of attributes attached to any PROV record. Values can be
 * typed literals, QualifiedName references, or plain scalars; multiple
 * values per key are allowed.
 *
 * Construct with `Attributes::from(...)`, `Attributes::single(...)`, or
 * share the empty singleton via `Attributes::empty()`. Derive new
 * instances with `with()`.
 */
readonly class Attributes
{
    /**
     * @param array<string, list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>> $data
     */
    public function __construct(
        private array $data = [],
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
        $data = $this->data;
        $data[$key->getUri()][] = $value;
        return new self($data);
    }

    /**
     * Creates an Attributes instance from an array of [QualifiedName, value] pairs.
     *
     * @param list<array{\Prov\Identifier\QualifiedName, \Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool}> $pairs
     */
    public static function from(array $pairs): self
    {
        $data = [];
        foreach ($pairs as [$key, $value]) {
            $data[$key->getUri()][] = $value;
        }
        return new self($data);
    }

    /**
     * Shorthand for a single-entry Attributes bag.
     */
    public static function single(QualifiedName $key, QualifiedName|Literal|string|int|float|bool $value): self
    {
        return new self([$key->getUri() => [$value]]);
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
     * Whether the bag holds no values.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
