<?php

declare(strict_types=1);

namespace Prov\Constraint;

/**
 * The set of PROV-CONSTRAINTS rule IDs. Each case corresponds to a numbered
 * rule in the W3C PROV-CONSTRAINTS specification; use `constraintName()`
 * to get the kebab-case name the spec uses.
 */
enum ConstraintId: int
{
    // Uniqueness constraints
    case KeyObject = 22;
    case KeyProperties = 23;
    case UniqueGeneration = 24;
    case UniqueInvalidation = 25;
    case UniqueWasStartedBy = 26;
    case UniqueWasEndedBy = 27;

    // Ordering constraints
    case UniqueStartTime = 28;
    case UniqueEndTime = 29;
    case StartPrecedesEnd = 30;
    case StartStartOrdering = 31;
    case EndEndOrdering = 32;
    case UsageWithinActivity = 33;
    case GenerationWithinActivity = 34;
    case WasInformedByOrdering = 35;
    case GenerationPrecedesInvalidation = 36;
    case GenerationPrecedesUsage = 37;
    case UsagePrecedesInvalidation = 38;
    case GenerationGenerationOrdering = 39;
    case InvalidationInvalidationOrdering = 40;
    case DerivationUsageGenerationOrdering = 41;
    case DerivationGenerationGenerationOrdering = 42;
    case WasStartedByOrdering = 43;
    case WasEndedByOrdering = 44;
    case SpecializationGenerationOrdering = 45;
    case SpecializationInvalidationOrdering = 46;
    case WasAssociatedWithOrdering = 47;
    case WasAttributedToOrdering = 48;
    case ActedOnBehalfOfOrdering = 49;

    // Type constraint
    case Typing = 50;

    // Impossibility constraints
    case ImpossibleUnspecifiedDerivation = 51;
    case ImpossibleSpecializationReflexive = 52;
    case ImpossiblePropertyOverlap = 53;
    case ImpossibleObjectPropertyOverlap = 54;
    case EntityActivityDisjoint = 55;
    case MembershipEmptyCollection = 56;

    /**
     * The kebab-case name the PROV-CONSTRAINTS spec uses for this rule
     * (e.g. `unique-generation`, `start-precedes-end`).
     */
    public function constraintName(): string
    {
        return match ($this) {
            self::KeyObject => 'key-object',
            self::KeyProperties => 'key-properties',
            self::UniqueGeneration => 'unique-generation',
            self::UniqueInvalidation => 'unique-invalidation',
            self::UniqueWasStartedBy => 'unique-wasStartedBy',
            self::UniqueWasEndedBy => 'unique-wasEndedBy',
            self::UniqueStartTime => 'unique-startTime',
            self::UniqueEndTime => 'unique-endTime',
            self::StartPrecedesEnd => 'start-precedes-end',
            self::StartStartOrdering => 'start-start-ordering',
            self::EndEndOrdering => 'end-end-ordering',
            self::UsageWithinActivity => 'usage-within-activity',
            self::GenerationWithinActivity => 'generation-within-activity',
            self::WasInformedByOrdering => 'wasInformedBy-ordering',
            self::GenerationPrecedesInvalidation => 'generation-precedes-invalidation',
            self::GenerationPrecedesUsage => 'generation-precedes-usage',
            self::UsagePrecedesInvalidation => 'usage-precedes-invalidation',
            self::GenerationGenerationOrdering => 'generation-generation-ordering',
            self::InvalidationInvalidationOrdering => 'invalidation-invalidation-ordering',
            self::DerivationUsageGenerationOrdering => 'derivation-usage-generation-ordering',
            self::DerivationGenerationGenerationOrdering => 'derivation-generation-generation-ordering',
            self::WasStartedByOrdering => 'wasStartedBy-ordering',
            self::WasEndedByOrdering => 'wasEndedBy-ordering',
            self::SpecializationGenerationOrdering => 'specialization-generation-ordering',
            self::SpecializationInvalidationOrdering => 'specialization-invalidation-ordering',
            self::WasAssociatedWithOrdering => 'wasAssociatedWith-ordering',
            self::WasAttributedToOrdering => 'wasAttributedTo-ordering',
            self::ActedOnBehalfOfOrdering => 'actedOnBehalfOf-ordering',
            self::Typing => 'typing',
            self::ImpossibleUnspecifiedDerivation => 'impossible-unspecified-derivation-generation-use',
            self::ImpossibleSpecializationReflexive => 'impossible-specialization-reflexive',
            self::ImpossiblePropertyOverlap => 'impossible-property-overlap',
            self::ImpossibleObjectPropertyOverlap => 'impossible-object-property-overlap',
            self::EntityActivityDisjoint => 'entity-activity-disjoint',
            self::MembershipEmptyCollection => 'membership-empty-collection',
        };
    }
}
