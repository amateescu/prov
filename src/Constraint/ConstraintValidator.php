<?php

declare(strict_types=1);

namespace Prov\Constraint;

use Prov\Activity;
use Prov\Agent;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Invalidation;
use Prov\Relation\Start;
use Prov\Relation\Usage;

/**
 * Checks a Document against W3C PROV-CONSTRAINTS rules.
 *
 * Coverage is partial by design: 21 of 35 rules are implemented. Of the 14
 * unimplemented rules, 22-23 are key/merging rules that require unifying records
 * sharing an identifier across a document, and 31-32, 35, 41-49 require transitive
 * graph reasoning over derivation chains; both kinds of reasoning are deliberately
 * out of scope for this validator. Use `self::unsupportedConstraints()` to discover
 * what's missing; a document that violates only unsupported rules will report
 * isValid() == true.
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
        $all = array_map(static fn(ConstraintId $id): int => $id->value, ConstraintId::cases());
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
            if ($spec->specificEntity->getUri() === $spec->generalEntity->getUri()) {
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
                $names = array_map(static fn(string $c): string => basename(str_replace('\\', '/', $c)), $types);
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::ImpossiblePropertyOverlap,
                        "Identifier '{$uri}' is used for multiple event types: " . implode(', ', $names),
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
            if ($record->activity === null) {
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
            if ($record->activity === null) {
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
            // The `??=` keeps the first time seen for a given id; reconciling two
            // conflicting times under one id is constraint 23 (key properties),
            // which this validator does not implement.
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
            } elseif ($record instanceof Activity && $record->identifier !== null) {
                $roles[$record->identifier->getUri()]['activity'] = true;
            } elseif ($record instanceof Agent && $record->identifier !== null) {
                $roles[$record->identifier->getUri()]['agent'] = true;
            } elseif ($record instanceof ProvRelation) {
                // Every typed reference position contributes its element role,
                // driven by the metadata table so each relation is covered.
                $rolesByProp = RelationMetadata::TYPING_ROLES[$record::class] ?? [];
                /** @var array<string, \Prov\Identifier\QualifiedName|\DateTimeImmutable|list<mixed>|null> $formals */
                $formals = RelationMetadata::extractFormals($record);
                foreach ($rolesByProp as $prop => $role) {
                    $reference = $formals[$prop] ?? null;
                    if ($reference instanceof QualifiedName) {
                        $roles[$reference->getUri()][$role] = true;
                    }
                }
                // Dictionary key-entity pairs reference entities through an
                // array-typed formal, which the per-property role table cannot
                // express; the member entities are entity-role references too.
                foreach ($formals as $prop => $value) {
                    if ($prop !== 'keyEntityPairs' || !is_array($value)) {
                        continue;
                    }
                    /** @var list<\Prov\Relation\Dictionary\DictionaryEntry> $pairs */
                    $pairs = $value;
                    foreach ($pairs as $entry) {
                        if ($entry->entity !== null) {
                            $roles[$entry->entity->getUri()]['entity'] = true;
                        }
                    }
                }
            }
        }

        // Entity and activity are the only disjoint roles; agent overlaps both
        // legitimately. An identifier declared as both is skipped here (that
        // clash is constraint 55's); the conflict is flagged when at least one
        // of the two roles comes from a relation reference.
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
        // An anonymous activity is its own statement: only its own startTime
        // and endTime can order it.
        foreach ($index->getActivities() as $record) {
            if (
                $record->identifier === null
                && $record->startTime !== null
                && $record->endTime !== null
                && $record->startTime > $record->endTime
            ) {
                $violations->add(new ConstraintViolation(
                    ConstraintId::StartPrecedesEnd,
                    "Activity '' start time is after its end time.",
                    null,
                ));
            }
        }

        // A named activity is checked (and reported) once per URI, with every
        // declaration's times and every start/end event folded in. Every start
        // must precede every end, so the latest start may not follow the
        // earliest end, independent of record order.
        foreach ($index->getActivityTimeBounds() as $uri => ['start' => $start, 'end' => $end]) {
            if ($start !== null && $end !== null && $start > $end) {
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

    /**
     * Constraint 33: Usage must occur within activity timespan.
     *
     * The bounds come from Start/End events as well as inline activity times:
     * an activity statement may leave its times out while identified
     * wasStartedBy / wasEndedBy records carry them.
     */
    private function checkConstraint33(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $bounds = $index->getActivityTimeBounds();
        foreach ($index->getUsages() as $record) {
            if ($record->activity === null || $record->time === null) {
                continue;
            }
            $uri = $record->activity->getUri();
            $bound = $bounds[$uri] ?? null;
            if ($bound === null) {
                continue;
            }
            if ($bound['start'] !== null && $record->time < $bound['start']) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::UsageWithinActivity,
                        "Usage time precedes activity '{$uri}' start time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
            if ($bound['end'] !== null && $record->time > $bound['end']) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::UsageWithinActivity,
                        "Usage time exceeds activity '{$uri}' end time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
        }
    }

    /**
     * Constraint 34: Generation must occur within activity timespan.
     *
     * Bounded the same way as constraint 33.
     */
    private function checkConstraint34(RecordIndex $index, ConstraintViolationList $violations): void
    {
        $bounds = $index->getActivityTimeBounds();
        foreach ($index->getGenerations() as $record) {
            if ($record->activity === null || $record->time === null) {
                continue;
            }
            $uri = $record->activity->getUri();
            $bound = $bounds[$uri] ?? null;
            if ($bound === null) {
                continue;
            }
            if ($bound['start'] !== null && $record->time < $bound['start']) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::GenerationWithinActivity,
                        "Generation time precedes activity '{$uri}' start time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
            if ($bound['end'] !== null && $record->time > $bound['end']) {
                $violations->add(
                    new ConstraintViolation(
                        ConstraintId::GenerationWithinActivity,
                        "Generation time exceeds activity '{$uri}' end time.",
                        $record->identifier?->getUri(),
                    ),
                );
            }
        }
    }

    /** Constraint 36: Generation must precede invalidation for the same entity. */
    private function checkConstraint36(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntityUrisWithEvents() as $uri) {
            $gens = $this->eventTimes($index->getGenerationsForEntity($uri));
            $invs = $this->eventTimes($index->getInvalidationsForEntity($uri));
            if ($gens === [] || $invs === []) {
                continue;
            }
            if (max($gens) > min($invs)) {
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

    /** Constraint 37: Generation must precede usage for the same entity. */
    private function checkConstraint37(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntityUrisWithEvents() as $uri) {
            $gens = $this->eventTimes($index->getGenerationsForEntity($uri));
            $usages = $this->eventTimes($index->getUsagesForEntity($uri));
            if ($gens === [] || $usages === []) {
                continue;
            }
            if (max($gens) > min($usages)) {
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

    /** Constraint 38: Usage must precede invalidation for the same entity. */
    private function checkConstraint38(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntityUrisWithEvents() as $uri) {
            $usages = $this->eventTimes($index->getUsagesForEntity($uri));
            $invs = $this->eventTimes($index->getInvalidationsForEntity($uri));
            if ($usages === [] || $invs === []) {
                continue;
            }
            if (max($usages) > min($invs)) {
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

    /**
     * Collects the non-null event times from a list of generation, usage, or
     * invalidation records, for the max-vs-min ordering comparisons.
     *
     * @param list<\Prov\Relation\Generation|\Prov\Relation\Usage|\Prov\Relation\Invalidation> $events
     *
     * @return list<\DateTimeImmutable>
     */
    private function eventTimes(array $events): array
    {
        $times = [];
        foreach ($events as $event) {
            if ($event->time !== null) {
                $times[] = $event->time;
            }
        }
        return $times;
    }

    /**
     * Constraint 39: Multiple generations for same entity must be simultaneous.
     * Scruffy duplicates (same generation ID) are excluded.
     */
    private function checkConstraint39(RecordIndex $index, ConstraintViolationList $violations): void
    {
        foreach ($index->getEntityUrisWithEvents() as $uri) {
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
                // Last write wins for a restated id: reconciling conflicting
                // times under one id is constraint 23 (key properties), which
                // this validator does not implement.
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
        foreach ($index->getEntityUrisWithEvents() as $uri) {
            $timesByInvId = [];
            foreach ($index->getInvalidationsForEntity($uri) as $inv) {
                if ($inv->time === null) {
                    continue;
                }
                $invId = $inv->identifier?->getUri() ?? '_anon_' . spl_object_id($inv);
                // Last write wins for a restated id: reconciling conflicting
                // times under one id is constraint 23 (key properties), which
                // this validator does not implement.
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
