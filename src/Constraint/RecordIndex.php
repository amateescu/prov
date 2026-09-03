<?php

declare(strict_types=1);

namespace Prov\Constraint;

use Prov\Activity;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvElement;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Relation\Derivation;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Invalidation;
use Prov\Relation\Membership;
use Prov\Relation\Specialization;
use Prov\Relation\Start;
use Prov\Relation\Usage;

/**
 * Builds reverse-lookup indexes from a record list for efficient constraint checking.
 *
 * @internal
 */
class RecordIndex
{
    /** @var array<string, list<string>> URI -> list of element class names */
    private array $elementTypes = [];

    /** @var array<string, list<string>> URI -> list of relation class names where it appears as an event ID */
    private array $eventTypes = [];

    /** @var array<string, list<\Prov\Relation\Generation>> entity URI -> generations */
    private array $generationsByEntity = [];

    /** @var array<string, list<\Prov\Relation\Usage>> entity URI -> usages */
    private array $usagesByEntity = [];

    /** @var array<string, list<\Prov\Relation\Invalidation>> entity URI -> invalidations */
    private array $invalidationsByEntity = [];

    /** @var array<string, list<\Prov\Relation\Start>> activity URI -> starts */
    private array $startsByActivity = [];

    /** @var array<string, list<\Prov\Relation\End>> activity URI -> ends */
    private array $endsByActivity = [];

    /** @var array<string, \Prov\Activity> URI -> \Prov\Activity */
    private array $activities = [];

    /** @var list<\Prov\Activity> */
    private array $activityRecords = [];

    /** @var list<\Prov\Relation\Generation> */
    private array $generations = [];

    /** @var list<\Prov\Relation\Usage> */
    private array $usages = [];

    /** @var list<\Prov\Relation\Invalidation> */
    private array $invalidations = [];

    /** @var list<\Prov\Relation\Start> */
    private array $starts = [];

    /** @var list<\Prov\Relation\End> */
    private array $ends = [];

    /** @var list<\Prov\Relation\Specialization> */
    private array $specializations = [];

    /** @var list<\Prov\Relation\Derivation> */
    private array $derivations = [];

    /** @var list<\Prov\Relation\Membership> */
    private array $memberships = [];

    /** @var array<string, true> Entity URIs with prov:type = prov:EmptyCollection */
    private array $emptyCollections = [];

    /** @var list<\Prov\Model\ProvRecord> */
    private array $records;

    /**
     * Cached getActivityTimeBounds() result.
     *
     * @var ?array<string, array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable}>
     */
    private ?array $activityTimeBounds = null;

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    public function __construct(array $records)
    {
        $this->records = $records;
        $this->buildIndex();
    }

    private function buildIndex(): void
    {
        $provType = ProvNamespace::prov()->qualifiedName('type');
        $emptyCollUri = 'http://www.w3.org/ns/prov#EmptyCollection';

        foreach ($this->records as $record) {
            $id = $record->identifier?->getUri();

            if ($record instanceof ProvElement && $id !== null) {
                $this->elementTypes[$id][] = $record::class;

                if ($record instanceof Activity) {
                    $this->activities[$id] = $record;
                }

                if ($record instanceof Entity) {
                    foreach ($record->attributes->get($provType) as $value) {
                        if ($value instanceof QualifiedName && $value->getUri() === $emptyCollUri) {
                            $this->emptyCollections[$id] = true;
                            break;
                        }
                    }
                }
            }

            if ($record instanceof ProvRelation && $id !== null) {
                $this->eventTypes[$id][] = $record::class;
            }

            if ($record instanceof Activity) {
                $this->activityRecords[] = $record;
            } elseif ($record instanceof Generation) {
                $this->generations[] = $record;
                $this->generationsByEntity[$record->entity->getUri()][] = $record;
            } elseif ($record instanceof Usage) {
                $this->usages[] = $record;
                $eUri = $record->entity?->getUri();
                if ($eUri !== null) {
                    $this->usagesByEntity[$eUri][] = $record;
                }
            } elseif ($record instanceof Invalidation) {
                $this->invalidations[] = $record;
                $this->invalidationsByEntity[$record->entity->getUri()][] = $record;
            } elseif ($record instanceof Start) {
                $this->starts[] = $record;
                $aUri = $record->activity?->getUri();
                if ($aUri !== null) {
                    $this->startsByActivity[$aUri][] = $record;
                }
            } elseif ($record instanceof End) {
                $this->ends[] = $record;
                $aUri = $record->activity?->getUri();
                if ($aUri !== null) {
                    $this->endsByActivity[$aUri][] = $record;
                }
            } elseif ($record instanceof Specialization) {
                $this->specializations[] = $record;
            } elseif ($record instanceof Derivation) {
                $this->derivations[] = $record;
            } elseif ($record instanceof Membership) {
                $this->memberships[] = $record;
            }
        }
    }

    /** @return list<\Prov\Activity> */
    public function getActivities(): array
    {
        return $this->activityRecords;
    }

    /** @return list<\Prov\Relation\Generation> */
    public function getGenerations(): array
    {
        return $this->generations;
    }

    /** @return list<\Prov\Relation\Usage> */
    public function getUsages(): array
    {
        return $this->usages;
    }

    /** @return list<\Prov\Relation\Invalidation> */
    public function getInvalidations(): array
    {
        return $this->invalidations;
    }

    /** @return list<\Prov\Relation\Start> */
    public function getStarts(): array
    {
        return $this->starts;
    }

    /** @return list<\Prov\Relation\End> */
    public function getEnds(): array
    {
        return $this->ends;
    }

    /** @return list<string> Element class names for this URI */
    public function getElementTypes(string $uri): array
    {
        return $this->elementTypes[$uri] ?? [];
    }

    /** @return list<string> Relation class names for this URI (used as event ID) */
    public function getEventTypes(string $uri): array
    {
        return $this->eventTypes[$uri] ?? [];
    }

    public function getActivity(string $uri): ?Activity
    {
        return $this->activities[$uri] ?? null;
    }

    /** @return list<\Prov\Relation\Generation> */
    public function getGenerationsForEntity(string $uri): array
    {
        return $this->generationsByEntity[$uri] ?? [];
    }

    /** @return list<\Prov\Relation\Usage> */
    public function getUsagesForEntity(string $uri): array
    {
        return $this->usagesByEntity[$uri] ?? [];
    }

    /** @return list<\Prov\Relation\Invalidation> */
    public function getInvalidationsForEntity(string $uri): array
    {
        return $this->invalidationsByEntity[$uri] ?? [];
    }

    /**
     * Entity URIs referenced by any generation, usage, or invalidation, whether
     * or not the entity is declared. Drives the generation/usage/invalidation
     * ordering checks (36 to 40) so referenced-but-undeclared entities are
     * covered and each entity is examined exactly once.
     *
     * @return list<string>
     */
    public function getEntityUrisWithEvents(): array
    {
        return array_keys($this->generationsByEntity + $this->usagesByEntity + $this->invalidationsByEntity);
    }

    /**
     * Activity URIs referenced by any start or end event, whether or not the
     * activity is declared. Drives the start-precedes-end check (30) so
     * referenced-but-undeclared activities are covered.
     *
     * @return list<string>
     */
    public function getActivityUrisWithEvents(): array
    {
        return array_keys($this->startsByActivity + $this->endsByActivity);
    }

    /** @return list<\Prov\Relation\Start> */
    public function getStartsForActivity(string $uri): array
    {
        return $this->startsByActivity[$uri] ?? [];
    }

    /** @return list<\Prov\Relation\End> */
    public function getEndsForActivity(string $uri): array
    {
        return $this->endsByActivity[$uri] ?? [];
    }

    /**
     * The time bounds known for each activity URI: the latest start it is said
     * to have and the earliest end, gathered from the activity records' inline
     * times and from every timed start and end event that names it. An
     * activity that is only referenced by its events, never declared, is
     * covered too (scruffy PROV).
     *
     * Every start of an activity precedes every end, usage, and generation
     * (constraints 30, 33, and 34), so the latest start and the earliest end
     * are the binding pair for all three checks. A null bound means no timed
     * source for that side exists.
     *
     * @return array<string, array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable}>
     */
    public function getActivityTimeBounds(): array
    {
        if ($this->activityTimeBounds !== null) {
            return $this->activityTimeBounds;
        }

        /** @var array<string, array{starts: list<\DateTimeImmutable>, ends: list<\DateTimeImmutable>}> $times */
        $times = [];
        foreach ($this->activityRecords as $activity) {
            $uri = $activity->identifier?->getUri();
            if ($uri === null) {
                continue;
            }
            $times[$uri] ??= ['starts' => [], 'ends' => []];
            if ($activity->startTime !== null) {
                $times[$uri]['starts'][] = $activity->startTime;
            }
            if ($activity->endTime !== null) {
                $times[$uri]['ends'][] = $activity->endTime;
            }
        }
        foreach ($this->getActivityUrisWithEvents() as $uri) {
            $times[$uri] ??= ['starts' => [], 'ends' => []];
            foreach ($this->startsByActivity[$uri] ?? [] as $start) {
                if ($start->time !== null) {
                    $times[$uri]['starts'][] = $start->time;
                }
            }
            foreach ($this->endsByActivity[$uri] ?? [] as $end) {
                if ($end->time !== null) {
                    $times[$uri]['ends'][] = $end->time;
                }
            }
        }

        $bounds = [];
        foreach ($times as $uri => ['starts' => $starts, 'ends' => $ends]) {
            $bounds[$uri] = [
                'start' => $starts === [] ? null : max($starts),
                'end' => $ends === [] ? null : min($ends),
            ];
        }
        return $this->activityTimeBounds = $bounds;
    }

    /** @return list<\Prov\Relation\Specialization> */
    public function getSpecializations(): array
    {
        return $this->specializations;
    }

    /** @return list<\Prov\Relation\Derivation> */
    public function getDerivations(): array
    {
        return $this->derivations;
    }

    /** @return list<\Prov\Relation\Membership> */
    public function getMemberships(): array
    {
        return $this->memberships;
    }

    /** @return list<\Prov\Model\ProvRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Check if an entity has prov:type = prov:EmptyCollection.
     */
    public function isEmptyCollection(string $entityUri): bool
    {
        return isset($this->emptyCollections[$entityUri]);
    }
}
