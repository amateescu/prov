<?php

declare(strict_types=1);

namespace Prov\Attribute;

use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;

/**
 * Imperative accumulator for building an Attributes bag.
 *
 * Complements the immutable Attributes API: call `add()` repeatedly (also
 * with the same key, since attributes are a multimap) and freeze the result
 * with `build()`. When constructed with a NamespaceManager, string keys are
 * resolved as QualifiedName shorthands (see NamespaceManager::resolve());
 * without one, keys must be QualifiedName objects.
 *
 * String values stay plain string literals, with one exception: a
 * `prov:type` value written as a registered `prefix:local` shorthand (or as
 * a full URI under a registered namespace) resolves to a QualifiedName,
 * because prov:type values name types rather than carry text. References
 * under any other key must be passed as QualifiedName objects.
 */
final class AttributesBuilder
{
    private const string PROV_TYPE_URI = 'http://www.w3.org/ns/prov#type';

    /** @var array<string, list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool>> */
    private array $data = [];

    /** @var array<string, \Prov\Identifier\QualifiedName> */
    private array $keys = [];

    /**
     * @param ?\Prov\Identifier\NamespaceManager $namespaceManager
     *   Used to resolve string keys (and prov:type string values). When null,
     *   keys must be QualifiedName objects and prov:type string values stay
     *   string literals.
     */
    public function __construct(
        private readonly ?NamespaceManager $namespaceManager = null,
    ) {}

    /**
     * Appends one value under the given key. Repeated calls with the same key
     * accumulate multiple values.
     */
    public function add(QualifiedName|string $key, QualifiedName|Literal|string|int|float|bool $value): static
    {
        $key = $this->resolveKey($key);
        $uri = $key->getUri();
        if ($uri === self::PROV_TYPE_URI && is_string($value)) {
            $value = $this->resolveTypeValue($value);
        }
        $this->data[$uri][] = $value;
        $this->keys[$uri] ??= $key;
        return $this;
    }

    /**
     * Appends every value in the list under the same key.
     *
     * @param iterable<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool> $values
     */
    public function addAll(QualifiedName|string $key, iterable $values): static
    {
        foreach ($values as $value) {
            $this->add($key, $value);
        }
        return $this;
    }

    /**
     * Freezes the accumulated entries into an immutable Attributes bag. The
     * builder stays usable; later additions affect only later build() calls.
     */
    public function build(): Attributes
    {
        return new Attributes($this->data, $this->keys);
    }

    /**
     * @throws \Prov\Exception\NamespaceException
     *   When a string key is given and no NamespaceManager is bound.
     */
    private function resolveKey(QualifiedName|string $key): QualifiedName
    {
        if ($key instanceof QualifiedName) {
            return $key;
        }
        if ($this->namespaceManager === null) {
            throw new NamespaceException(
                "Cannot resolve string attribute key '{$key}': no NamespaceManager is bound to this AttributesBuilder.",
            );
        }
        return $this->namespaceManager->resolve($key);
    }

    /**
     * Resolves a prov:type string value to a QualifiedName when it is written
     * as a resolvable shorthand. Values without a ':' and values whose prefix
     * (or URI) matches no registered namespace stay plain string literals.
     */
    private function resolveTypeValue(string $value): QualifiedName|string
    {
        if ($this->namespaceManager === null || !str_contains($value, ':') || str_starts_with($value, '_:')) {
            return $value;
        }
        try {
            return $this->namespaceManager->resolve($value);
        } catch (NamespaceException) {
            return $value;
        }
    }
}
