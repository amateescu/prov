<?php

declare(strict_types=1);

namespace Prov\Tests\Constraint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Constraint\ConstraintId;

final class ConstraintIdTest extends TestCase
{
    /**
     * @return array<string, array{ConstraintId, string}>
     */
    public static function constraintNameProvider(): array
    {
        return [
            'KeyObject' => [ConstraintId::KeyObject, 'key-object'],
            'KeyProperties' => [ConstraintId::KeyProperties, 'key-properties'],
            'UniqueGeneration' => [ConstraintId::UniqueGeneration, 'unique-generation'],
            'UniqueInvalidation' => [ConstraintId::UniqueInvalidation, 'unique-invalidation'],
            'UniqueWasStartedBy' => [ConstraintId::UniqueWasStartedBy, 'unique-wasStartedBy'],
            'UniqueWasEndedBy' => [ConstraintId::UniqueWasEndedBy, 'unique-wasEndedBy'],
            'UniqueStartTime' => [ConstraintId::UniqueStartTime, 'unique-startTime'],
            'UniqueEndTime' => [ConstraintId::UniqueEndTime, 'unique-endTime'],
            'StartPrecedesEnd' => [ConstraintId::StartPrecedesEnd, 'start-precedes-end'],
            'StartStartOrdering' => [ConstraintId::StartStartOrdering, 'start-start-ordering'],
            'EndEndOrdering' => [ConstraintId::EndEndOrdering, 'end-end-ordering'],
            'UsageWithinActivity' => [ConstraintId::UsageWithinActivity, 'usage-within-activity'],
            'GenerationWithinActivity' => [ConstraintId::GenerationWithinActivity, 'generation-within-activity'],
            'WasInformedByOrdering' => [ConstraintId::WasInformedByOrdering, 'wasInformedBy-ordering'],
            'GenerationPrecedesInvalidation' => [
                ConstraintId::GenerationPrecedesInvalidation,
                'generation-precedes-invalidation',
            ],
            'GenerationPrecedesUsage' => [ConstraintId::GenerationPrecedesUsage, 'generation-precedes-usage'],
            'UsagePrecedesInvalidation' => [ConstraintId::UsagePrecedesInvalidation, 'usage-precedes-invalidation'],
            'GenerationGenerationOrdering' => [
                ConstraintId::GenerationGenerationOrdering,
                'generation-generation-ordering',
            ],
            'InvalidationInvalidationOrdering' => [
                ConstraintId::InvalidationInvalidationOrdering,
                'invalidation-invalidation-ordering',
            ],
            'DerivationUsageGenerationOrdering' => [
                ConstraintId::DerivationUsageGenerationOrdering,
                'derivation-usage-generation-ordering',
            ],
            'DerivationGenerationGenerationOrdering' => [
                ConstraintId::DerivationGenerationGenerationOrdering,
                'derivation-generation-generation-ordering',
            ],
            'WasStartedByOrdering' => [ConstraintId::WasStartedByOrdering, 'wasStartedBy-ordering'],
            'WasEndedByOrdering' => [ConstraintId::WasEndedByOrdering, 'wasEndedBy-ordering'],
            'SpecializationGenerationOrdering' => [
                ConstraintId::SpecializationGenerationOrdering,
                'specialization-generation-ordering',
            ],
            'SpecializationInvalidationOrdering' => [
                ConstraintId::SpecializationInvalidationOrdering,
                'specialization-invalidation-ordering',
            ],
            'WasAssociatedWithOrdering' => [ConstraintId::WasAssociatedWithOrdering, 'wasAssociatedWith-ordering'],
            'WasAttributedToOrdering' => [ConstraintId::WasAttributedToOrdering, 'wasAttributedTo-ordering'],
            'ActedOnBehalfOfOrdering' => [ConstraintId::ActedOnBehalfOfOrdering, 'actedOnBehalfOf-ordering'],
            'Typing' => [ConstraintId::Typing, 'typing'],
            'ImpossibleUnspecifiedDerivation' => [
                ConstraintId::ImpossibleUnspecifiedDerivation,
                'impossible-unspecified-derivation-generation-use',
            ],
            'ImpossibleSpecializationReflexive' => [
                ConstraintId::ImpossibleSpecializationReflexive,
                'impossible-specialization-reflexive',
            ],
            'ImpossiblePropertyOverlap' => [ConstraintId::ImpossiblePropertyOverlap, 'impossible-property-overlap'],
            'ImpossibleObjectPropertyOverlap' => [
                ConstraintId::ImpossibleObjectPropertyOverlap,
                'impossible-object-property-overlap',
            ],
            'EntityActivityDisjoint' => [ConstraintId::EntityActivityDisjoint, 'entity-activity-disjoint'],
            'MembershipEmptyCollection' => [ConstraintId::MembershipEmptyCollection, 'membership-empty-collection'],
        ];
    }

    #[DataProvider('constraintNameProvider')]
    public function testConstraintNameMapsToSpecId(ConstraintId $id, string $expected): void
    {
        $this->assertSame($expected, $id->constraintName());
    }

    public function testProviderCoversAllEnumCases(): void
    {
        $covered = array_map(static fn(array $row) => $row[0], self::constraintNameProvider());
        $this->assertSame(ConstraintId::cases(), array_values($covered));
    }
}
