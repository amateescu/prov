<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Bundle;
use Prov\Document;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\Generation;
use Prov\Relation\Usage;

/**
 * Read-side query API over the records of a Document or Bundle: follow
 * relation edges from or to an identifier without hand-rolling instanceof
 * scans over `$relations`.
 *
 * The graph indexes every record and relation endpoint by URI once, at
 * construction, so lookups are O(1) in the number of records. Identifiers
 * can be passed as QualifiedName objects, as `prefix:local` shorthands
 * (resolved against the container's declared namespaces), or as full URIs.
 * An identifier that cannot be resolved against any declared namespace can
 * name nothing in the index, so lookups miss (null or an empty list) rather
 * than throw, regardless of the identifier's spelling.
 *
 * Every relation has a subject (its first endpoint in PROV-N positional
 * order: the entity for `wasGeneratedBy`, the activity for `used`, ...) and
 * an object (its second endpoint). `relationsFrom()` and `relationsTo()`
 * query those two roles; `relationsReferencing()` matches any endpoint,
 * including secondary ones like a Derivation's activity or an Association's
 * plan.
 *
 * The graph covers only the container's own records: bundles inside a
 * Document are not traversed. Use `DocumentOperations::flatten()` first to
 * query across bundle boundaries. A graph over a Bundle resolves shorthands
 * against the bundle's own declared namespaces only (a Bundle does not point
 * back at its document); pass QualifiedName objects or full URIs for
 * identifiers under document-level declarations.
 */
final class ProvGraph
{
    /** @var array<string, \Prov\Model\ProvRecord> First record per identifier URI. */
    private readonly array $recordsByUri;

    /** @var array<string, list<\Prov\Model\ProvRelation>> */
    private readonly array $bySubject;

    /** @var array<string, list<\Prov\Model\ProvRelation>> */
    private readonly array $byObject;

    /** @var array<string, list<\Prov\Model\ProvRelation>> */
    private readonly array $byEndpoint;

    private readonly NamespaceManager $nsManager;

    public function __construct(Document|Bundle $container)
    {
        $nsManager = new NamespaceManager();
        foreach ($container->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $nsManager->setDefault($ns);
            } else {
                // The container is the authority on its own declarations, so a
                // non-canonical prov/xsd URI it carries (preserved verbatim
                // through deserialization) replaces the built-in rather than
                // throwing, matching how the serializers/deserializers read it.
                $nsManager->addOrReplace($ns);
            }
        }
        $this->nsManager = $nsManager;

        $recordsByUri = [];
        foreach ($container->records as $record) {
            if ($record->identifier !== null) {
                $recordsByUri[$record->identifier->getUri()] ??= $record;
            }
        }
        $this->recordsByUri = $recordsByUri;

        $bySubject = [];
        $byObject = [];
        $byEndpoint = [];
        foreach ($container->relations as $relation) {
            $seen = [];
            $position = 0;
            foreach (RelationMetadata::FORMALS[$relation::class] ?? [] as $prop => $type) {
                if ($type !== 'ref') {
                    continue;
                }
                $position++;
                $value = self::refProperty($relation, $prop);
                if ($value === null) {
                    continue;
                }
                $uri = $value->getUri();
                if ($position === 1) {
                    $bySubject[$uri][] = $relation;
                } elseif ($position === 2) {
                    $byObject[$uri][] = $relation;
                }
                if (!isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $byEndpoint[$uri][] = $relation;
                }
            }
            foreach (RelationMetadata::dictionaryEntities($relation) as $entity) {
                $uri = $entity->getUri();
                if (!isset($seen[$uri])) {
                    $seen[$uri] = true;
                    $byEndpoint[$uri][] = $relation;
                }
            }
        }
        $this->bySubject = $bySubject;
        $this->byObject = $byObject;
        $this->byEndpoint = $byEndpoint;
    }

    /**
     * The record carrying the given identifier, or null. When several records
     * share an identifier (scruffy provenance), the first one wins.
     */
    public function recordByIdentifier(QualifiedName|string $identifier): ?ProvRecord
    {
        return $this->recordsByUri[$this->toUri($identifier)] ?? null;
    }

    /**
     * Relations whose subject (first endpoint) is the given identifier: for
     * an entity its generations and derivations, for an activity its usages
     * and starts, and so on.
     *
     * @return list<\Prov\Model\ProvRelation>
     */
    public function relationsFrom(QualifiedName|string $identifier): array
    {
        return $this->bySubject[$this->toUri($identifier)] ?? [];
    }

    /**
     * Relations whose object (second endpoint) is the given identifier.
     *
     * @return list<\Prov\Model\ProvRelation>
     */
    public function relationsTo(QualifiedName|string $identifier): array
    {
        return $this->byObject[$this->toUri($identifier)] ?? [];
    }

    /**
     * Relations referencing the given identifier in any endpoint, including
     * secondary ones (a Derivation's activity, an Association's plan, a
     * Mention's bundle, a dictionary entry's entity, ...). Each relation
     * appears once even when it references the identifier in several roles.
     *
     * @return list<\Prov\Model\ProvRelation>
     */
    public function relationsReferencing(QualifiedName|string $identifier): array
    {
        return $this->byEndpoint[$this->toUri($identifier)] ?? [];
    }

    /**
     * The Generation relations of the given entity.
     *
     * @return list<\Prov\Relation\Generation>
     */
    public function generationsOf(QualifiedName|string $entity): array
    {
        $uri = $this->toUri($entity);
        $out = [];
        foreach ($this->byEndpoint[$uri] ?? [] as $relation) {
            if ($relation instanceof Generation && $relation->entity?->getUri() === $uri) {
                $out[] = $relation;
            }
        }
        return $out;
    }

    /**
     * The Usage relations of the given entity.
     *
     * @return list<\Prov\Relation\Usage>
     */
    public function usagesOf(QualifiedName|string $entity): array
    {
        $uri = $this->toUri($entity);
        $out = [];
        foreach ($this->byEndpoint[$uri] ?? [] as $relation) {
            if ($relation instanceof Usage && $relation->entity?->getUri() === $uri) {
                $out[] = $relation;
            }
        }
        return $out;
    }

    /**
     * Every identifier a relation references through its formal endpoints,
     * in PROV-N positional order, including the entities of dictionary
     * entries. Null endpoints are skipped.
     *
     * @return list<\Prov\Identifier\QualifiedName>
     */
    public static function referencedIdentifiers(ProvRelation $relation): array
    {
        return RelationMetadata::refEndpoints($relation);
    }

    private function toUri(QualifiedName|string $identifier): string
    {
        if ($identifier instanceof QualifiedName) {
            return $identifier->getUri();
        }
        // URIs with an authority component and blank-node labels are already in
        // index form.
        if (str_contains($identifier, '://') || str_starts_with($identifier, '_:')) {
            return $identifier;
        }
        // A prefixed or unprefixed shorthand resolves against the container's
        // namespaces. A full URI in a scheme without '//' (urn:, tag:, ...)
        // falls through to resolve(), which matches it against registered
        // namespace URIs, so an in-graph URN still maps to its index key. An
        // unresolvable reference cannot name anything in the index, so it is
        // used as-is and the lookup misses, mirroring how an unknown
        // authority-form URI behaves above.
        try {
            return $this->nsManager->resolve($identifier)->getUri();
        } catch (NamespaceException) {
            return $identifier;
        }
    }

    /**
     * Reads one 'ref'-typed formal property off a relation.
     */
    private static function refProperty(ProvRelation $relation, string $prop): ?QualifiedName
    {
        // @mago-expect analysis:mixed-assignment
        $value = get_object_vars($relation)[$prop] ?? null;
        return $value instanceof QualifiedName ? $value : null;
    }
}
