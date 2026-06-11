<?php

declare(strict_types=1);

namespace Prov\Constraint;

use Prov\Activity;
use Prov\Agent;
use Prov\Document;
use Prov\Entity;
use Prov\Model\ProvRelation;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Invalidation;
use Prov\Relation\Start;
use Prov\Relation\Usage;

/**
 * Checks a Document against W3C PROV-CONSTRAINTS rules.
 *
 * Coverage is partial by design: 21 of 35 rules are implemented. The 14 unimplemented
 * rules (31-32, 35, 41-49) all require transitive graph reasoning over derivation
 * chains, which is deliberately out of scope for this validator. Use
 * `self::unsupportedConstraints()` to discover what's missing; a document that
 * violates only unsupported rules will report isValid() == true.
 */
class ConstraintValidator
{
    /**
     * PROV-CONSTRAINTS rule IDs that this validator implements, in execution order.
     * Adding a new constraint means adding an entry here, a call in runCheckers(),
     * and the corresponding private method.
     *
     * @var list<int>
     */
    private const array IMPLEMENTED = [
        // Impossibility.
        51,
        52,
        53,
        54,
        55,
        56,
        // Uniqueness.
        24,
        25,
        26,
        27,
        // Typing.
        50,
        // Ordering.
        30,
        28,
        29,
        33,
        34,
        36,
        37,
        38,
        39,
        40,
    ];

    /**
     * Runs every implemented PROV-CONSTRAINTS check against the document
     * (and each bundle independently) and returns the collected violations.
     */
    public function validate(Document $document): ConstraintViolationList
    {
        $violations = new ConstraintViolationList();
        $this->runCheckers(new RecordIndex($document->records), $violations);

        // Validate each bundle independently.
        foreach ($document->bundles as $bundle) {
            $this->runCheckers(new RecordIndex($bundle->records), $violations);
        }

        return $violations;
    }

    /**
     * Constraints implemented by this validator.
     *
     * @return list<\Prov\Constraint\ConstraintId>
     */
    public static function implementedConstraints(): array
    {
        return array_map(ConstraintId::from(...), self::IMPLEMENTED);
    }

    /**
     * PROV-CONSTRAINTS rules that this validator does not check. A document that
     * only violates these will still report isValid() == true.
     *
     * @return list<\Prov\Constraint\ConstraintId>
     */
    public static function unsupportedConstraints(): array
    {
        $all = array_column(ConstraintId::cases(), 'value');
        $missing = array_values(array_diff($all, self::IMPLEMENTED));
        sort($missing);
        return array_map(ConstraintId::from(...), $missing);
    }

    private function runCheckers(RecordIndex $index, ConstraintViolationList $violations): void
    {
        // Impossibility.
        $this->checkConstraint51($index, $violations);
        $this->checkConstraint52($index, $violations);
        $this->checkConstraint53($index, $violations);
        $this->checkConstraint54($index, $violations);
        $this->checkConstraint55($index, $violations);
        $this->checkConstraint56($index, $violations);

        // Uniqueness.
        $this->checkConstraint24($index, $violations);
        $this->checkConstraint25($index, $violations);
        $this->checkConstraint26($index, $violations);
        $this->checkConstraint27($index, $violations);

        // Typing.
        $this->checkTypingConstraint($index, $violations);

        // Ordering.
        $this->checkConstraint30($index, $violations);
        $this->checkConstraint28($index, $violations);
        $this->checkConstraint29($index, $violations);
        $this->checkConstraint33($index, $violations);
        $this->checkConstraint34($index, $violations);
        $this->checkConstraint36($index, $violations);
        $this->checkConstraint37($index, $violations);
        $this->checkConstraint38($index, $violations);
        $this->checkConstraint39($index, $violations);
        $this->checkConstraint40($index, $violations);
    }

    // ================================================================
    // Impossibility constraints (51-56)
    // ================================================================

    /** Constraint 51: Derivation can't have generation/usage without activity. */
    private function checkConstraint51(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getDerivations() as $der) {
            if ($der->activity === null && ($der->generation !== null || $der->usage !== null)) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::ImpossibleUnspecifiedDerivation,
                        'Derivation specifies generation or usage without an activity.',
                        $der->identifier?->getUri(),
                    ),
                );
            }
        }
    }

    /** Constraint 52: Entity can't specialize itself. */
    private function checkConstraint52(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getSpecializations() as $spec) {
            if (
                $spec->specificEntity !== null
                && $spec->generalEntity !== null
                && $spec->specificEntity->getUri() === $spec->generalEntity->getUri()
            ) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::ImpossibleSpecializationReflexive,
                        "Entity '{$spec->specificEntity->getUri()}' cannot specialize itself.",
                        $spec->specificEntity->getUri(),
                    ),
                );
            }
        }
    }

    /** Constraint 53: one identifier can't denote two distinct instantaneous events. */
    private function checkConstraint53(RecordIndex $index, ConstraintViolationList $violations): void
    {
        // Only the instantaneous events count: a non-event relation (e.g. a derivation or
        // attribution) is not an event and may legitimately share an identifier with one.
        $eventClasses = [Generation::class, Usage::class, Start::class, End::class, Invalidation::class];
        $seen = [];
        foreach ($index->getRecords() as $record) {
            if (!$record instanceof ProvRelation) {
                continue;
            }
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            // Report each conflicting identifier once, not once per record carrying it.
            if (isset($seen[$uri])) {
                continue;
            }
            $seen[$uri] = true;
            $types = array_values(array_unique(array_intersect($index->getEventTypes($uri), $eventClasses)));
            if (count($types) > 1) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::ImpossiblePropertyOverlap,
                        "Identifier '{$uri}' is used for multiple event types: "
                            . implode(', ', array_map(static fn($c) => basename(str_replace('\\', '/', $c)), $types)),
                        $uri,
                    ),
                );
            }
        }
    }

    /** Constraint 54: Identifier can't be both an object (entity/activity/agent) and an event (relation). */
    private function checkConstraint54(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $checked = [];
        foreach ($index->getRecords() as $record) {
            if ($record->identifier === null) {
                continue;
            }
            $uri = $record->identifier->getUri();
            // Report each conflicting identifier once, not once per record carrying it.
            if (isset($checked[$uri])) {
                continue;
            }
            $checked[$uri] = true;
            $elementTypes = $index->getElementTypes($uri);
            $eventTypes = $index->getEventTypes($uri);
            if ($elementTypes !== [] && $eventTypes !== []) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::ImpossibleObjectPropertyOverlap,
                        "Identifier '{$uri}' is used as both an element and an event/relation.",
                        $uri,
                    ),
                );
            }
        }
    }

    /** Constraint 55: Entity and Activity identifiers are disjoint. */
    private function checkConstraint55(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $checked = [];
        foreach ($index->getRecords() as $record) {
            if ($record->identifier === null) {
                continue;
            }
            $uri = $record->identifier->getUri();
            if (isset($checked[$uri])) {
                continue;
            }
            $checked[$uri] = true;

            $types = $index->getElementTypes($uri);
            $hasEntity = in_array(Entity::class, $types, true);
            $hasActivity = in_array(Activity::class, $types, true);
            if ($hasEntity && $hasActivity) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::EntityActivityDisjoint,
                        "Identifier '{$uri}' is used as both an Entity and an Activity.",
                        $uri,
                    ),
                );
            }
        }
    }

    /** Constraint 56: Empty collection can't have members. */
    private function checkConstraint56(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getMemberships() as $mem) {
            if ($mem->collection === null) {
                continue;
            }
            $collUri = $mem->collection->getUri();
            if ($index->isEmptyCollection($collUri)) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::MembershipEmptyCollection,
                        "Empty collection '{$collUri}' cannot have members.",
                        $collUri,
                    ),
                );
            }
        }
    }

    // ================================================================
    // Uniqueness constraints (22-27)
    // ================================================================

    /**
     * Constraint 24: unique-generation. For a given (entity, activity) pair there is
     * at most one generation event.
     */
    private function checkConstraint24(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $groups = [];
        foreach ($index->getGenerations() as $record) {
            if ($record->entity === null || $record->activity === null) {
                continue;
            }
            $this->collectUniqueEventGroup($groups, $record, $record->entity->getUri(), $record->activity->getUri());
        }
        $this->reportUniqueEventConflicts(
            $groups,
            $violations,
            ConstraintId::UniqueGeneration,
            static fn(string $a, string $b): string => "Multiple generations for entity '{$a}' by activity '{$b}'.",
        );
    }

    /** Constraint 25: unique-invalidation. */
    private function checkConstraint25(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $groups = [];
        foreach ($index->getInvalidations() as $record) {
            if ($record->entity === null || $record->activity === null) {
                continue;
            }
            $this->collectUniqueEventGroup($groups, $record, $record->entity->getUri(), $record->activity->getUri());
        }
        $this->reportUniqueEventConflicts(
            $groups,
            $violations,
            ConstraintId::UniqueInvalidation,
            static fn(string $a, string $b): string => "Multiple invalidations for entity '{$a}' by activity '{$b}'.",
        );
    }

    /** Constraint 26: unique-wasStartedBy. */
    private function checkConstraint26(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $groups = [];
        foreach ($index->getStarts() as $record) {
            if ($record->activity === null) {
                continue;
            }
            $starterUri = $record->starter?->getUri() ?? '_unspecified';
            $this->collectUniqueEventGroup($groups, $record, $record->activity->getUri(), $starterUri);
        }
        $this->reportUniqueEventConflicts(
            $groups,
            $violations,
            ConstraintId::UniqueWasStartedBy,
            static fn(string $a, string $_b): string => "Multiple starts for activity '{$a}'.",
        );
    }

    /** Constraint 27: unique-wasEndedBy. */
    private function checkConstraint27(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $groups = [];
        foreach ($index->getEnds() as $record) {
            if ($record->activity === null) {
                continue;
            }
            $enderUri = $record->ender?->getUri() ?? '_unspecified';
            $this->collectUniqueEventGroup($groups, $record, $record->activity->getUri(), $enderUri);
        }
        $this->reportUniqueEventConflicts(
            $groups,
            $violations,
            ConstraintId::UniqueWasEndedBy,
            static fn(string $a, string $_b): string => "Multiple ends for activity '{$a}'.",
        );
    }

    /**
     * Folds one event record into its uniqueness group, keyed by the pair of
     * subject URIs the constraint quantifies over.
     *
     * @param array<string, array{subjects: array{string, string}, ids: array<string, true>, times: array<string, string>}> $groups
     */
    private function collectUniqueEventGroup(
        array &$groups,
        Generation|Invalidation|Start|End $record,
        string $subjectA,
        string $subjectB,
    ): void {
        $pair = $subjectA . '|' . $subjectB;
        $groups[$pair] ??= ['subjects' => [$subjectA, $subjectB], 'ids' => [], 'times' => []];

        $id = $record->identifier?->getUri();
        if ($id !== null) {
            $groups[$pair]['ids'][$id] = true;
        }
        if ($record->time !== null) {
            // Keyed per statement: restating one identified event is not a second
            // event, while every anonymous record counts as its own statement.
            $statementKey = $id ?? '_anon_' . spl_object_id($record);
            $groups[$pair]['times'][$statementKey] ??= $record->time->format('U.u');
        }
    }

    /**
     * Flags each group that states more than one event: either two distinct
     * non-null identifiers, or (since anonymous records merge into the same
     * event) two distinct concrete event times.
     *
     * @param array<string, array{subjects: array{string, string}, ids: array<string, true>, times: array<string, string>}> $groups
     * @param \Closure(string, string): string $message
     */
    private function reportUniqueEventConflicts(
        array $groups,
        ConstraintViolationList $violations,
        ConstraintId $constraint,
        \Closure $message,
    ): void {
        foreach ($groups as $group) {
            if (count($group['ids']) <= 1 && count(array_unique($group['times'])) <= 1) {
                continue;
            }
            [$subjectA, $subjectB] = $group['subjects'];
            $violations->add(new ConstraintViolation($constraint, $message($subjectA, $subjectB), $subjectA));
        }
    }

    // ================================================================
    // Typing constraint (50)
    // ================================================================

    /** Constraint 50: Identifiers must have consistent types across all usages. */
    private function checkTypingConstraint(RecordIndex $index, ConstraintViolationList $violations): void
    {
        /** @var array<string, array<string, true>> $roles URI to set of roles. */
        $roles = [];

        foreach ($index->getRecords() as $record) {
            if ($record instanceof Entity && $record->identifier !== null) {
                $roles[$record->identifier->getUri()]['entity'] = true;
            }
            if ($record instanceof Activity && $record->identifier !== null) {
                $roles[$record->identifier->getUri()]['activity'] = true;
            }
            if ($record instanceof Agent && $record->identifier !== null) {
                $roles[$record->identifier->getUri()]['agent'] = true;
            }

            // Check references in relations.
            if ($record instanceof Generation) {
                if ($record->entity !== null) {
                    $roles[$record->entity->getUri()]['entity'] = true;
                }
                if ($record->activity !== null) {
                    $roles[$record->activity->getUri()]['activity'] = true;
                }
            } elseif ($record instanceof Usage) {
                if ($record->activity !== null) {
                    $roles[$record->activity->getUri()]['activity'] = true;
                }
                if ($record->entity !== null) {
                    $roles[$record->entity->getUri()]['entity'] = true;
                }
            } elseif ($record instanceof Communication) {
                if ($record->informed !== null) {
                    $roles[$record->informed->getUri()]['activity'] = true;
                }
                if ($record->informant !== null) {
                    $roles[$record->informant->getUri()]['activity'] = true;
                }
            }
            // Agent references from attribution, association, delegation.
            if ($record instanceof Attribution && $record->agent !== null) {
                $roles[$record->agent->getUri()]['agent'] = true;
            }
            if ($record instanceof Association && $record->agent !== null) {
                $roles[$record->agent->getUri()]['agent'] = true;
            }
            if ($record instanceof Delegation) {
                if ($record->delegate !== null) {
                    $roles[$record->delegate->getUri()]['agent'] = true;
                }
                if ($record->responsible !== null) {
                    $roles[$record->responsible->getUri()]['agent'] = true;
                }
            }
        }

        // Check for conflicts: entity vs activity is caught by constraint 55.
        // Here we just check entity-vs-activity in reference contexts.
        foreach ($roles as $uri => $typeSet) {
            if (!isset($typeSet['entity'], $typeSet['activity'])) {
                continue;
            }
            $elementTypes = $index->getElementTypes($uri);
            if (in_array(Entity::class, $elementTypes, true) && in_array(Activity::class, $elementTypes, true)) {
                continue;
            }
            $violations->add(
                new ConstraintViolation(
                    ConstraintId::Typing,
                    "Identifier '{$uri}' is used as both an entity and an activity in relation references.",
                    $uri,
                ),
            );
        }
    }

    // ================================================================
    // Ordering constraints (28-49)
    // ================================================================

    /** Constraint 28: Activity startTime must match its start event time. */
    private function checkConstraint28(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getActivities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null || $record->startTime === null) {
                continue;
            }
            $uri = $identifier->getUri();
            foreach ($index->getStartsForActivity($uri) as $start) {
                if ($start->time !== null && $start->time->format('U.u') !== $record->startTime->format('U.u')) {
                    $violations->add(
                        new ConstraintViolation(
                            ConstraintId::UniqueStartTime,
                            "Activity '{$uri}' startTime doesn't match its start event time.",
                            $uri,
                        ),
                    );
                }
            }
        }
    }

    /** Constraint 29: Activity endTime must match its end event time. */
    private function checkConstraint29(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getActivities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null || $record->endTime === null) {
                continue;
            }
            $uri = $identifier->getUri();
            foreach ($index->getEndsForActivity($uri) as $end) {
                if ($end->time !== null && $end->time->format('U.u') !== $record->endTime->format('U.u')) {
                    $violations->add(
                        new ConstraintViolation(
                            ConstraintId::UniqueEndTime,
                            "Activity '{$uri}' endTime doesn't match its end event time.",
                            $uri,
                        ),
                    );
                }
            }
        }
    }

    /** Constraint 30: Start must precede end for the same activity. */
    private function checkConstraint30(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getActivities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();

            // Use activity's own times if available.
            $startTime = $record->startTime;
            $endTime = $record->endTime;

            // Also check start/end event times.
            if ($startTime === null) {
                foreach ($index->getStartsForActivity($uri) as $start) {
                    if ($start->time !== null) {
                        $startTime = $start->time;
                        break;
                    }
                }
            }
            if ($endTime === null) {
                foreach ($index->getEndsForActivity($uri) as $end) {
                    if ($end->time !== null) {
                        $endTime = $end->time;
                        break;
                    }
                }
            }

            if ($startTime !== null && $endTime !== null && $startTime > $endTime) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::StartPrecedesEnd,
                        "Activity '{$uri}' start time is after its end time.",
                        $uri,
                    ),
                );
            }
        }
    }

    /** Constraint 33: Usage must occur within activity timespan. */
    private function checkConstraint33(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getUsages() as $record) {
            if ($record->activity === null || $record->time === null) {
                continue;
            }
            $activity = $index->getActivity($record->activity->getUri());
            if ($activity === null) {
                continue;
            }
            if ($activity->startTime !== null && $record->time < $activity->startTime) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::UsageWithinActivity,
                        "Usage time precedes activity '{$record->activity->getUri()}' start time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
            if ($activity->endTime !== null && $record->time > $activity->endTime) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::UsageWithinActivity,
                        "Usage time exceeds activity '{$record->activity->getUri()}' end time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
        }
    }

    /** Constraint 34: Generation must occur within activity timespan. */
    private function checkConstraint34(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getGenerations() as $record) {
            if ($record->activity === null || $record->time === null) {
                continue;
            }
            $activity = $index->getActivity($record->activity->getUri());
            if ($activity === null) {
                continue;
            }
            if ($activity->startTime !== null && $record->time < $activity->startTime) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::GenerationWithinActivity,
                        "Generation time precedes activity '{$record->activity->getUri()}' start time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
            if ($activity->endTime !== null && $record->time > $activity->endTime) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::GenerationWithinActivity,
                        "Generation time exceeds activity '{$record->activity->getUri()}' end time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
        }
    }

    /** Constraint 36: Generation must precede invalidation for the same entity. */
    private function checkConstraint36(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            $gens = $index->getGenerationsForEntity($uri);
            $invs = $index->getInvalidationsForEntity($uri);

            foreach ($gens as $gen) {
                if ($gen->time === null) {
                    continue;
                }
                foreach ($invs as $inv) {
                    if ($inv->time !== null && $gen->time > $inv->time) {
                        $violations->add(
                            new ConstraintViolation(
                                ConstraintId::GenerationPrecedesInvalidation,
                                "Entity '{$uri}' generation time is after its invalidation time.",
                                $uri,
                            ),
                        );
                    }
                }
            }
        }
    }

    /** Constraint 37: Generation must precede usage for the same entity. */
    private function checkConstraint37(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            $gens = $index->getGenerationsForEntity($uri);
            $usages = $index->getUsagesForEntity($uri);

            foreach ($gens as $gen) {
                if ($gen->time === null) {
                    continue;
                }
                foreach ($usages as $use) {
                    if ($use->time !== null && $gen->time > $use->time) {
                        $violations->add(
                            new ConstraintViolation(
                                ConstraintId::GenerationPrecedesUsage,
                                "Entity '{$uri}' generation time is after a usage time.",
                                $uri,
                            ),
                        );
                    }
                }
            }
        }
    }

    /** Constraint 38: Usage must precede invalidation for the same entity. */
    private function checkConstraint38(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            $usages = $index->getUsagesForEntity($uri);
            $invs = $index->getInvalidationsForEntity($uri);

            foreach ($usages as $use) {
                if ($use->time === null) {
                    continue;
                }
                foreach ($invs as $inv) {
                    if ($inv->time !== null && $use->time > $inv->time) {
                        $violations->add(
                            new ConstraintViolation(
                                ConstraintId::UsagePrecedesInvalidation,
                                "Entity '{$uri}' usage time is after its invalidation time.",
                                $uri,
                            ),
                        );
                    }
                }
            }
        }
    }

    /**
     * Constraint 39: Multiple generations for same entity must be simultaneous.
     * Scruffy duplicates (same generation ID) are excluded.
     */
    private function checkConstraint39(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $checked = [];
        foreach ($index->getEntities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            if (isset($checked[$uri])) {
                continue;
            }
            $checked[$uri] = true;

            // Deduplicate by generation ID (scruffy duplicates share an ID).
            // Key times by "U.u" (Unix timestamp + microseconds) for timezone-independent
            // value comparison; two DateTimeImmutable instances for the same instant
            // produce the same key.
            $timesByGenId = [];
            foreach ($index->getGenerationsForEntity($uri) as $gen) {
                if ($gen->time === null) {
                    continue;
                }
                $genId = $gen->identifier?->getUri() ?? '_anon_' . spl_object_id($gen);
                $timesByGenId[$genId] = $gen->time->format('U.u');
            }

            if (count(array_unique($timesByGenId)) > 1) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::GenerationGenerationOrdering,
                        "Entity '{$uri}' has multiple generation events with different times.",
                        $uri,
                    ),
                );
            }
        }
    }

    /**
     * Constraint 40: Multiple invalidations for same entity must be simultaneous.
     * Scruffy duplicates (same invalidation ID) are excluded.
     */
    private function checkConstraint40(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $checked = [];
        foreach ($index->getEntities() as $record) {
            $identifier = $record->identifier;
            if ($identifier === null) {
                continue;
            }
            $uri = $identifier->getUri();
            if (isset($checked[$uri])) {
                continue;
            }
            $checked[$uri] = true;

            $timesByInvId = [];
            foreach ($index->getInvalidationsForEntity($uri) as $inv) {
                if ($inv->time === null) {
                    continue;
                }
                $invId = $inv->identifier?->getUri() ?? '_anon_' . spl_object_id($inv);
                $timesByInvId[$invId] = $inv->time->format('U.u');
            }

            if (count(array_unique($timesByInvId)) > 1) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::InvalidationInvalidationOrdering,
                        "Entity '{$uri}' has multiple invalidation events with different times.",
                        $uri,
                    ),
                );
            }
        }
    }
}
