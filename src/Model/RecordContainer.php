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
}
