<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Activity;
use Prov\Agent;
use Prov\Entity;
use Prov\Identifier\QualifiedName;

/**
 * Shared base for the two PROV-DM container concepts, Document and Bundle.
 *
 * Holds the canonical record list plus four typed views (entities, activities,
 * agents, relations) computed at construction time.
 *
 * @api
 */
abstract readonly class RecordContainer
{
    /** @var list<\Prov\Model\ProvRecord> */
    public array $records;

    /** @var list<\Prov\Entity> */
    public array $entities;

    /** @var list<\Prov\Activity> */
    public array $activities;

    /** @var list<\Prov\Agent> */
    public array $agents;

    /** @var list<\Prov\Model\ProvRelation> */
    public array $relations;

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    public function __construct(array $records)
    {
        $this->records = $records;
        $entities = [];
        $activities = [];
        $agents = [];
        $relations = [];
        foreach ($records as $record) {
            if ($record instanceof Entity) {
                $entities[] = $record;
            } elseif ($record instanceof Activity) {
                $activities[] = $record;
            } elseif ($record instanceof Agent) {
                $agents[] = $record;
            } elseif ($record instanceof ProvRelation) {
                $relations[] = $record;
            }
        }
        $this->entities = $entities;
        $this->activities = $activities;
        $this->agents = $agents;
        $this->relations = $relations;
    }

    /**
     * @template T of ProvRecord
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function getRecordsByType(string $type): array
    {
        $matches = [];
        foreach ($this->records as $record) {
            if ($record instanceof $type) {
                $matches[] = $record;
            }
        }
        return $matches;
    }

    /**
     * Linear scan over `$records`; O(n). Fine for one-off lookups. For
     * repeated lookups on large documents, build a URI-keyed index from
     * `$records` once and reuse it.
     */
    public function getRecordByIdentifier(QualifiedName $identifier): ?ProvRecord
    {
        $target = $identifier->getUri();
        foreach ($this->records as $record) {
            if ($record->identifier?->getUri() === $target) {
                return $record;
            }
        }
        return null;
    }

    /**
     * A forward pass over this container's relations, yielding one
     * `\Prov\Model\EntityInvolvement` per entity-typed endpoint (including each
     * dictionary entry's entity). Lets a consumer derive a "which entity took
     * part in what" index from a finished container instead of mirroring each
     * builder relation call.
     *
     * Covers only this container's own relations: a Document does not descend
     * into its bundles (call this on each Bundle, or flatten first).
     *
     * @return list<\Prov\Model\EntityInvolvement>
     */
    public function entityInvolvements(): array
    {
        $out = [];
        foreach ($this->relations as $relation) {
            $relationType = RelationMetadata::relationLabel($relation);
            foreach (RelationMetadata::entityEndpoints($relation) as $endpoint) {
                $out[] = new EntityInvolvement($relationType, $endpoint['role'], $endpoint['entity']);
            }
        }
        return $out;
    }
}
