<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Dictionary\DictionaryEntry;

/**
 * Rebuilds a record with every qualified name it holds passed through a
 * mapping function.
 *
 * Records are immutable, so renaming a name means building a new record. The
 * walk covers every position a name can sit in: the identifier, a relation's
 * formal endpoints, dictionary entry keys and entities, attribute keys and
 * values, and a literal's datatype. Callers that rename blank labels or move
 * names onto a canonical namespace share this one pass, so none of them can
 * miss a position.
 *
 * @internal
 */
final class RecordRewriter
{
    /**
     * Rebuilds a record with every QualifiedName it references passed through
     * `$mapName`. Reconstructed via the constructor using named arguments
     * (public readonly property names match the constructor parameters), so
     * each record type is handled without a per-type branch.
     *
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    public static function rebuild(ProvRecord $record, callable $mapName): ProvRecord
    {
        /** @var array<string, mixed> $args */
        $args = [];
        // @mago-expect analysis:mixed-assignment
        foreach (get_object_vars($record) as $name => $value) {
            $args[$name] = self::rebuildValue($value, $mapName);
        }
        try {
            $new = new \ReflectionClass($record)->newInstanceArgs($args);
        } catch (\ReflectionException $e) {
            // Every record's public property names match its constructor
            // parameters, so reconstruction by named argument cannot fail here.
            throw new \LogicException('Could not rebuild record.', previous: $e);
        }
        assert($new instanceof ProvRecord);
        return $new;
    }

    /**
     * Rebuilds any QualifiedName reachable from a record property value
     * (identifiers, formal endpoints, attribute bags, dictionary entries).
     *
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    private static function rebuildValue(mixed $value, callable $mapName): mixed
    {
        if ($value instanceof QualifiedName) {
            return $mapName($value);
        }
        if ($value instanceof Attributes) {
            return self::rebuildAttributes($value, $mapName);
        }
        if ($value instanceof DictionaryEntry) {
            return new DictionaryEntry(
                self::rebuildDictKey($value->key, $mapName),
                $value->entity !== null ? $mapName($value->entity) : null,
            );
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::rebuildValue($item, $mapName), $value);
        }
        return $value;
    }

    /**
     * Rebuilds a dictionary entry key, preserving its declared value union.
     *
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    private static function rebuildDictKey(
        QualifiedName|Literal|string|int|float|bool|null $key,
        callable $mapName,
    ): QualifiedName|Literal|string|int|float|bool|null {
        if ($key instanceof QualifiedName) {
            return $mapName($key);
        }
        if ($key instanceof Literal) {
            return self::rebuildLiteral($key, $mapName);
        }
        return $key;
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    private static function rebuildAttributes(Attributes $attributes, callable $mapName): Attributes
    {
        if ($attributes->isEmpty()) {
            return $attributes;
        }
        $pairs = [];
        foreach ($attributes as $key => $value) {
            $pairs[] = [$mapName($key), self::rebuildAttrValue($value, $mapName)];
        }
        return Attributes::from($pairs);
    }

    /**
     * Rebuilds an attribute value, preserving its declared value union.
     *
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    private static function rebuildAttrValue(
        QualifiedName|Literal|string|int|float|bool $value,
        callable $mapName,
    ): QualifiedName|Literal|string|int|float|bool {
        if ($value instanceof QualifiedName) {
            return $mapName($value);
        }
        if ($value instanceof Literal) {
            return self::rebuildLiteral($value, $mapName);
        }
        return $value;
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Identifier\QualifiedName $mapName
     */
    private static function rebuildLiteral(Literal $literal, callable $mapName): Literal
    {
        if ($literal->datatype === null) {
            return $literal;
        }
        return new Literal($literal->value, $mapName($literal->datatype), $literal->languageTag);
    }
}
