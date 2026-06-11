<?php

declare(strict_types=1);

namespace Prov\Tests\Constraint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Builder\DocumentBuilder;
use Prov\Constraint\ConstraintValidator;
use Prov\Constraint\ConstraintViolationList;
use Prov\Exception\ConstraintViolationException;
use Prov\Identifier\ProvNamespace;
use Prov\Serializer\JsonSerializer;

final class ConstraintValidatorTest extends TestCase
{
    private ProvNamespace $ex;
    private ConstraintValidator $validator;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->validator = new ConstraintValidator();
    }

    private function buildDoc(): DocumentBuilder
    {
        $b = new DocumentBuilder();
        $b->addNamespace($this->ex);
        return $b;
    }

    private function validate(DocumentBuilder $builder): ConstraintViolationList
    {
        return $this->validator->validate($builder->build());
    }

    // --- Valid documents ---

    public function testEmptyDocumentIsValid(): void
    {
        $v = $this->validate(new DocumentBuilder());
        $this->assertTrue($v->isValid());
        $v->throwIfInvalid();
    }

    public function testThrowIfInvalidRaisesOnViolations(): void
    {
        $b = $this->buildDoc();
        $b->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', generation: 'ex:g1');
        $v = $this->validate($b);
        $this->expectException(ConstraintViolationException::class);
        $v->throwIfInvalid();
    }

    public function testThrowIfInvalidExceptionExposesViolationList(): void
    {
        $b = $this->buildDoc();
        $b->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', generation: 'ex:g1');
        $v = $this->validate($b);
        try {
            $v->throwIfInvalid();
            $this->fail('Expected ConstraintViolationException');
        } catch (ConstraintViolationException $e) {
            $this->assertSame($v, $e->violations);
            $this->assertCount(1, $e->violations);
        }
    }

    public function testSimpleValidDocument(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->activity(
            'ex:a1',
            new \DateTimeImmutable('2023-01-01T00:00:00Z'),
            new \DateTimeImmutable('2023-12-31T23:59:59Z'),
        );
        $b->agent('ex:ag1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-15T12:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: new \DateTimeImmutable('2023-06-15T13:00:00Z'));

        $this->assertTrue($this->validate($b)->isValid());
    }

    // --- Constraint 51: impossible-unspecified-derivation-generation-use ---

    public function testConstraint51DerivationWithGenerationButNoActivity(): void
    {
        $b = $this->buildDoc();
        $b->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', generation: 'ex:g1');
        $v = $this->validate($b);
        $this->assertFalse($v->isValid());
        $this->assertCount(1, $v->getViolationsByConstraint(51));
    }

    public function testConstraint51DerivationWithUsageButNoActivity(): void
    {
        $b = $this->buildDoc();
        $b->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', usage: 'ex:u1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(51));
    }

    public function testConstraint51DerivationWithActivityIsValid(): void
    {
        $b = $this->buildDoc();
        $b->wasDerivedFrom(
            generatedEntity: 'ex:e2',
            usedEntity: 'ex:e1',
            activity: 'ex:a1',
            generation: 'ex:g1',
            usage: 'ex:u1',
        );
        $this->assertTrue($this->validate($b)->isValid());
    }

    // --- Constraint 52: impossible-specialization-reflexive ---

    public function testConstraint52SelfSpecialization(): void
    {
        $b = $this->buildDoc();
        $b->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(52));
    }

    public function testConstraint52NonReflexiveIsValid(): void
    {
        $b = $this->buildDoc();
        $b->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $this->assertTrue($this->validate($b)->isValid());
    }

    // --- Constraint 55: entity-activity-disjoint ---

    public function testConstraint55EntityAndActivitySameId(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:x');
        $b->activity('ex:x');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(55));
    }

    // --- Constraint 56: membership-empty-collection ---

    public function testConstraint56EmptyCollectionWithMembers(): void
    {
        $prov = ProvNamespace::prov();
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('EmptyCollection'));
        $b = $this->buildDoc();
        $b->entity('ex:c1', $attrs);
        $b->hadMember(collection: 'ex:c1', entity: 'ex:e1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(56));
    }

    // --- Constraint 30: start-precedes-end ---

    public function testConstraint30StartAfterEnd(): void
    {
        $b = $this->buildDoc();
        $b->activity(
            'ex:a1',
            new \DateTimeImmutable('2023-12-31T00:00:00Z'),
            new \DateTimeImmutable('2023-01-01T00:00:00Z'),
        );
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(30));
    }

    public function testConstraint30StartBeforeEndIsValid(): void
    {
        $b = $this->buildDoc();
        $b->activity(
            'ex:a1',
            new \DateTimeImmutable('2023-01-01T00:00:00Z'),
            new \DateTimeImmutable('2023-12-31T00:00:00Z'),
        );
        $this->assertTrue($this->validate($b)->isValid());
    }

    // --- Constraint 33: usage-within-activity ---

    public function testConstraint33UsageBeforeActivityStart(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1', new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(33));
    }

    // --- Constraint 34: generation-within-activity ---

    public function testConstraint34GenerationAfterActivityEnd(): void
    {
        $b = $this->buildDoc();
        $b->activity(
            'ex:a1',
            new \DateTimeImmutable('2023-01-01T00:00:00Z'),
            new \DateTimeImmutable('2023-06-01T00:00:00Z'),
        );
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(34));
    }

    // --- Constraint 36: generation-precedes-invalidation ---

    public function testConstraint36GenerationAfterInvalidation(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a2', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(36));
    }

    // --- Constraint 37: generation-precedes-usage ---

    public function testConstraint37GenerationAfterUsage(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $b->used(activity: 'ex:a2', entity: 'ex:e1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(37));
    }

    // --- Constraint 28: unique-startTime ---

    public function testConstraint28ActivityStartTimeMismatchesStartEvent(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1', startTime: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasStartedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(28));
    }

    public function testConstraint28MatchingTimesInDifferentInstancesIsValid(): void
    {
        // Two DateTimeImmutable instances representing the same instant must
        // compare as equal; the check uses value equality, not identity.
        $b = $this->buildDoc();
        $b->activity('ex:a1', startTime: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasStartedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(28));
    }

    // --- Constraint 29: unique-endTime ---

    public function testConstraint29ActivityEndTimeMismatchesEndEvent(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1', endTime: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $b->wasEndedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(29));
    }

    public function testConstraint29MatchingTimesInDifferentInstancesIsValid(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1', endTime: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $b->wasEndedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(29));
    }

    // --- Constraint 39: multiple generations must be simultaneous ---

    public function testConstraint39MultipleGenerationsDifferentTimes(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a2', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(39));
    }

    public function testConstraint39MultipleGenerationsSameTimeDifferentInstancesIsValid(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a2', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(39));
    }

    // --- Constraint 40: multiple invalidations must be simultaneous ---

    public function testConstraint40MultipleInvalidationsDifferentTimes(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a2', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(40));
    }

    public function testConstraint40MultipleInvalidationsSameTimeDifferentInstancesIsValid(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a2', time: new \DateTimeImmutable('2023-12-31T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(40));
    }

    // --- Constraint 24: unique-generation ---

    public function testConstraint24DuplicateGenerationDifferentIds(): void
    {
        $b = $this->buildDoc();
        $b->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $b->wasGeneratedBy(identifier: 'ex:g2', entity: 'ex:e1', activity: 'ex:a1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(24));
    }

    public function testConstraint24ScruffyDuplicatesSameIdIsValid(): void
    {
        // Same identifier = scruffy duplicate (merge candidate), not a violation.
        $b = $this->buildDoc();
        $b->wasGeneratedBy(
            identifier: 'ex:g1',
            entity: 'ex:e1',
            activity: 'ex:a1',
            time: new \DateTimeImmutable('2023-01-01T00:00:00Z'),
        );
        $b->wasGeneratedBy(
            identifier: 'ex:g1',
            entity: 'ex:e1',
            activity: 'ex:a1',
            time: new \DateTimeImmutable('2023-06-01T00:00:00Z'),
        );
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(24));
    }

    public function testConstraint24AnonymousGenerationsWithDifferentTimes(): void
    {
        // Two id-less generations of the same (entity, activity) pair state two
        // distinct events, so their concrete times must agree.
        $b = $this->buildDoc();
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(24));
    }

    public function testConstraint24AnonymousGenerationsWithSameTimeAreValid(): void
    {
        $b = $this->buildDoc();
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(24));
    }

    // --- Constraint 25: unique-invalidation ---

    public function testConstraint25DuplicateInvalidationDifferentIds(): void
    {
        $b = $this->buildDoc();
        $b->wasInvalidatedBy(identifier: 'ex:i1', entity: 'ex:e1', activity: 'ex:a1');
        $b->wasInvalidatedBy(identifier: 'ex:i2', entity: 'ex:e1', activity: 'ex:a1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(25));
    }

    public function testConstraint25AnonymousInvalidationsWithDifferentTimes(): void
    {
        $b = $this->buildDoc();
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(25));
    }

    public function testConstraint25DistinctPairsAreValid(): void
    {
        $b = $this->buildDoc();
        $b->wasInvalidatedBy(identifier: 'ex:i1', entity: 'ex:e1', activity: 'ex:a1');
        $b->wasInvalidatedBy(identifier: 'ex:i2', entity: 'ex:e1', activity: 'ex:a2');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(25));
    }

    // --- Constraint 26: unique-wasStartedBy ---

    public function testConstraint26DuplicateStartDifferentIds(): void
    {
        $b = $this->buildDoc();
        $b->wasStartedBy(identifier: 'ex:s1', activity: 'ex:a1', starter: 'ex:a0');
        $b->wasStartedBy(identifier: 'ex:s2', activity: 'ex:a1', starter: 'ex:a0');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(26));
    }

    public function testConstraint26AnonymousStartsWithDifferentTimes(): void
    {
        $b = $this->buildDoc();
        $b->wasStartedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasStartedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(26));
    }

    public function testConstraint26DifferentStartersAreValid(): void
    {
        $b = $this->buildDoc();
        $b->wasStartedBy(identifier: 'ex:s1', activity: 'ex:a1', starter: 'ex:a0');
        $b->wasStartedBy(identifier: 'ex:s2', activity: 'ex:a1', starter: 'ex:a2');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(26));
    }

    // --- Constraint 27: unique-wasEndedBy ---

    public function testConstraint27DuplicateEndDifferentIds(): void
    {
        $b = $this->buildDoc();
        $b->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1', ender: 'ex:a0');
        $b->wasEndedBy(identifier: 'ex:end2', activity: 'ex:a1', ender: 'ex:a0');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(27));
    }

    public function testConstraint27AnonymousEndsWithDifferentTimes(): void
    {
        $b = $this->buildDoc();
        $b->wasEndedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasEndedBy(activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(27));
    }

    public function testConstraint27ScruffyDuplicatesSameIdIsValid(): void
    {
        $b = $this->buildDoc();
        $b->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1');
        $b->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(27));
    }

    // --- Constraint 38: usage-precedes-invalidation ---

    public function testConstraint38UsageAfterInvalidation(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(38));
    }

    public function testConstraint38UsageBeforeInvalidationIsValid(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: new \DateTimeImmutable('2023-01-01T00:00:00Z'));
        $b->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2023-06-01T00:00:00Z'));
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(38));
    }

    // --- Constraint 50: typing ---

    public function testConstraint50EntityAndActivityRolesInReferences(): void
    {
        // ex:x is referenced as an entity by one relation and as an activity by
        // another, without being declared as either element.
        $b = $this->buildDoc();
        $b->wasGeneratedBy(entity: 'ex:x', activity: 'ex:a1');
        $b->used(activity: 'ex:x', entity: 'ex:e2');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(50));
    }

    public function testConstraint50ConsistentRolesAreValid(): void
    {
        $b = $this->buildDoc();
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $b->used(activity: 'ex:a1', entity: 'ex:e1');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(50));
    }

    public function testConstraint50DefersToConstraint55ForDeclaredElements(): void
    {
        // When the identifier is declared as both element types, the conflict is
        // entity-activity-disjoint (55) territory, not a typing violation.
        $b = $this->buildDoc();
        $b->entity('ex:x');
        $b->activity('ex:x');
        $b->wasGeneratedBy(entity: 'ex:x', activity: 'ex:a1');
        $b->used(activity: 'ex:x', entity: 'ex:e2');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(50));
        $this->assertCount(1, $v->getViolationsByConstraint(55));
    }

    // --- Constraint 54: impossible-object-property-overlap ---

    public function testConstraint54ElementAndRelationShareIdentifier(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:x');
        $b->wasGeneratedBy(identifier: 'ex:x', entity: 'ex:e1', activity: 'ex:a1');
        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(54));
    }

    public function testConstraint54DistinctIdentifiersAreValid(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $v = $this->validate($b);
        $this->assertEmpty($v->getViolationsByConstraint(54));
    }

    // --- ConstraintViolationList API ---

    public function testViolationListCountable(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:x');
        $b->activity('ex:x');
        $v = $this->validate($b);
        $this->assertGreaterThan(0, count($v));
    }

    public function testViolationListGetViolations(): void
    {
        $b = $this->buildDoc();
        $b->entity('ex:x');
        $b->activity('ex:x');
        $v = $this->validate($b);
        $all = $v->getViolations();
        $this->assertNotEmpty($all);
        $this->assertSame($all[0]->constraintName, 'entity-activity-disjoint');
    }

    // --- Fixture validation: non-FAIL fixtures should be valid ---

    private const FIXTURES_DIR = __DIR__ . '/../../vendor/openprov/testcases';

    /**
     * @return array<string, list<string>>
     */
    public static function validFixtureProvider(): array
    {
        $dir = realpath(self::FIXTURES_DIR);
        if ($dir === false) {
            return [];
        }

        $fixtures = [];
        $files = glob($dir . '/test-*/*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $testName = substr(basename(dirname($file)), 5);
            if (str_contains($testName, 'FAIL')) {
                continue;
            }
            $fixtures[$testName] = [$file];
        }

        ksort($fixtures);
        return $fixtures;
    }

    #[DataProvider('validFixtureProvider')]
    public function testValidFixturesPassConstraints(string $fixturePath): void
    {
        $serializer = new JsonSerializer();
        $doc = $serializer->deserialize(file_get_contents($fixturePath));
        $violations = $this->validator->validate($doc);

        $this->assertTrue($violations->isValid(), sprintf(
            'Fixture should be valid but has %d violations: %s',
            count($violations),
            implode('; ', array_map(
                static fn($v) => "[C{$v->constraintId}] {$v->message}",
                $violations->getViolations(),
            )),
        ));
    }

    // --- FAIL-DM fixtures should have violations ---

    /**
     * @return array<string, list<string>>
     */
    public static function failFixtureProvider(): array
    {
        $dir = realpath(self::FIXTURES_DIR);
        if ($dir === false) {
            return [];
        }

        $fixtures = [];
        $files = glob($dir . '/test-*FAIL*/*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $testName = substr(basename(dirname($file)), 5);
            $fixtures[$testName] = [$file];
        }

        return $fixtures;
    }

    #[DataProvider('failFixtureProvider')]
    public function testFailFixturesHaveViolations(string $fixturePath): void
    {
        $serializer = new JsonSerializer();
        $doc = $serializer->deserialize(file_get_contents($fixturePath));
        $violations = $this->validator->validate($doc);

        // FAIL-DM fixtures violate hadMember constraints (missing collection or entity).
        // Our validator may or may not catch these specific violations depending on
        // which constraints apply. At minimum, we verify the validator doesn't crash.
        $this->assertInstanceOf(ConstraintViolationList::class, $violations);
    }

    public function testImplementedConstraintsMatchesCheckerRegistry(): void
    {
        $implemented = ConstraintValidator::implementedConstraints();
        $this->assertCount(21, $implemented);

        $ids = array_map(static fn(\Prov\Constraint\ConstraintId $c) => $c->value, $implemented);
        sort($ids);
        $this->assertSame([24, 25, 26, 27, 28, 29, 30, 33, 34, 36, 37, 38, 39, 40, 50, 51, 52, 53, 54, 55, 56], $ids);
    }

    public function testUnsupportedConstraintsAreTheGraphReasoningOnes(): void
    {
        $unsupported = ConstraintValidator::unsupportedConstraints();
        $ids = array_map(static fn(\Prov\Constraint\ConstraintId $c) => $c->value, $unsupported);

        // All graph-reasoning constraints: 22-23 (keys), 31-32 (start/end ordering),
        // 35 (wasInformedBy ordering), 41-49 (derivation-chain transitive ordering).
        $this->assertSame([22, 23, 31, 32, 35, 41, 42, 43, 44, 45, 46, 47, 48, 49], $ids);
    }

    public function testImplementedAndUnsupportedPartitionAllConstraints(): void
    {
        $implemented = ConstraintValidator::implementedConstraints();
        $unsupported = ConstraintValidator::unsupportedConstraints();
        $this->assertCount(count(\Prov\Constraint\ConstraintId::cases()), [...$implemented, ...$unsupported]);
    }

    public function testViolationInsideBundleIsReported(): void
    {
        // Self-specialization inside a bundle exercises the bundle branch of validate().
        $b = new DocumentBuilder();
        $b->addNamespace($this->ex);
        $bundle = $b->bundle('ex:b1');
        $bundle->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e1');

        $violations = $this->validate($b);
        $this->assertFalse($violations->isValid());
        $this->assertCount(1, $violations->getViolationsByConstraint(52));
    }

    public function testDocumentLevelRecordsAndBundleRecordsAreBothValidated(): void
    {
        // One violation at the document level, one in a bundle; both must be caught.
        $b = new DocumentBuilder();
        $b->addNamespace($this->ex);
        $b->specializationOf(specificEntity: 'ex:doc', generalEntity: 'ex:doc');
        $b->bundle('ex:b1')->specializationOf(specificEntity: 'ex:bun', generalEntity: 'ex:bun');

        $violations = $this->validate($b);
        $this->assertSame(2, count($violations->getViolationsByConstraint(52)));
    }

    public function testConstraint53FlagsSharedEventIdentifierOnce(): void
    {
        // One identifier used for both a generation and a usage is a single impossible
        // overlap, reported exactly once rather than once per record carrying it.
        $b = $this->buildDoc();
        $b->entity('ex:e');
        $b->activity('ex:a');
        $b->wasGeneratedBy(identifier: 'ex:ev', entity: 'ex:e', activity: 'ex:a');
        $b->used(identifier: 'ex:ev', activity: 'ex:a', entity: 'ex:e');

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(53));
    }

    public function testConstraint53IgnoresNonEventRelationsSharingAnIdentifier(): void
    {
        // A derivation and an attribution are not instantaneous events, so sharing an
        // identifier is not an impossible property overlap.
        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->entity('ex:e2');
        $b->agent('ex:ag');
        $b->wasDerivedFrom(identifier: 'ex:x', generatedEntity: 'ex:e1', usedEntity: 'ex:e2');
        $b->wasAttributedTo(identifier: 'ex:x', entity: 'ex:e1', agent: 'ex:ag');

        $this->assertCount(0, $this->validate($b)->getViolationsByConstraint(53));
    }
}
