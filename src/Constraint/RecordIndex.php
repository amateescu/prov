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

    /** @var array<string, list<\Prov\Relation\Generation>> "entityUri|activityUri" -> generations */
    private array $generationsByPair = [];

    /** @var array<string, list<\Prov\Relation\Invalidation>> "entityUri|activityUri" -> invalidations */
    private array $invalidationsByPair = [];

    /** @var array<string, list<\Prov\Relation\Start>> "activityUri|starterUri" -> starts */
    private array $startsByPair = [];

    /** @var array<string, list<\Prov\Relation\End>> "activityUri|enderUri" -> ends */
    private array $endsByPair = [];

    /** @var array<string, \Prov\Activity> URI -> \Prov\Activity */
    private array $activities = [];

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

            if ($record instanceof Generation) {
                $eUri = $record->entity?->getUri();
                $aUri = $record->activity?->getUri();
                if ($eUri !== null) {
                    $this->generationsByEntity[$eUri][] = $record;
                }
                if ($eUri !== null && $aUri !== null) {
                    $this->generationsByPair["{$eUri}|{$aUri}"][] = $record;
                }
            } elseif ($record instanceof Usage) {
                $eUri = $record->entity?->getUri();
                if ($eUri !== null) {
                    $this->usagesByEntity[$eUri][] = $record;
                }
            } elseif ($record instanceof Invalidation) {
                $eUri = $record->entity?->getUri();
                $aUri = $record->activity?->getUri();
                if ($eUri !== null) {
                    $this->invalidationsByEntity[$eUri][] = $record;
                }
                if ($eUri !== null && $aUri !== null) {
                    $this->invalidationsByPair["{$eUri}|{$aUri}"][] = $record;
                }
            } elseif ($record instanceof Start) {
                $aUri = $record->activity?->getUri();
                $sUri = $record->starter?->getUri() ?? '_';
                if ($aUri !== null) {
                    $this->startsByActivity[$aUri][] = $record;
                    $this->startsByPair["{$aUri}|{$sUri}"][] = $record;
                }
            } elseif ($record instanceof End) {
                $aUri = $record->activity?->getUri();
                $eUri = $record->ender?->getUri() ?? '_';
                if ($aUri !== null) {
                    $this->endsByActivity[$aUri][] = $record;
                    $this->endsByPair["{$aUri}|{$eUri}"][] = $record;
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

    /** @return list<\Prov\Relation\Generation> */
    public function getGenerationsForPair(string $entityUri, string $activityUri): array
    {
        return $this->generationsByPair["{$entityUri}|{$activityUri}"] ?? [];
    }

    /** @return list<\Prov\Relation\Invalidation> */
    public function getInvalidationsForPair(string $entityUri, string $activityUri): array
    {
        return $this->invalidationsByPair["{$entityUri}|{$activityUri}"] ?? [];
    }

    /** @return list<\Prov\Relation\Start> */
    public function getStartsForPair(string $activityUri, string $starterUri): array
    {
        return $this->startsByPair["{$activityUri}|{$starterUri}"] ?? [];
    }

    /** @return list<\Prov\Relation\End> */
    public function getEndsForPair(string $activityUri, string $enderUri): array
    {
        return $this->endsByPair["{$activityUri}|{$enderUri}"] ?? [];
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
