<?php

declare(strict_types=1);

namespace Prov\Tests\Constraint;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Constraint\ConstraintValidator;
use Prov\Constraint\ConstraintViolationList;
use Prov\Identifier\ProvNamespace;

/**
 * PROV-CONSTRAINTS 33 and 34 order a usage or generation against the activity's
 * start and end events. An activity statement may leave its inline times out
 * while identified `wasStartedBy` / `wasEndedBy` records carry them, so the
 * boundaries have to be gathered from both places.
 */
final class ActivityBoundaryEventTest extends TestCase
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
        return new DocumentBuilder()->addNamespace($this->ex);
    }

    private function validate(DocumentBuilder $builder): ConstraintViolationList
    {
        return $this->validator->validate($builder->build());
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    public function testUsageBeforeStartEventViolates(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(33));
    }

    public function testUsageAfterEndEventViolates(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasEndedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T11:00:00Z'));

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(33));
    }

    public function testGenerationAfterEndEventViolates(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasEndedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: self::at('2024-01-01T11:00:00Z'));

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(34));
    }

    public function testGenerationBeforeStartEventViolates(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: self::at('2024-01-01T09:00:00Z'));

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(34));
    }

    public function testEventsAtTheBoundaryAreValid(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->wasEndedBy(activity: 'ex:a1', time: self::at('2024-01-01T12:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T10:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', time: self::at('2024-01-01T12:00:00Z'));

        $v = $this->validate($b);
        $this->assertCount(0, $v->getViolationsByConstraint(33));
        $this->assertCount(0, $v->getViolationsByConstraint(34));
    }

    public function testRestatedStartEventsBothBound(): void
    {
        // Two start records for one activity: the usage must follow the latest.
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T09:00:00Z'), identifier: 'ex:s1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T11:00:00Z'), identifier: 'ex:s2');
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T10:00:00Z'));

        $this->assertCount(1, $this->validate($b)->getViolationsByConstraint(33));
    }

    public function testTimezoneOffsetsForTheSameInstantAreValid(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T12:00:00+02:00'));

        $this->assertCount(0, $this->validate($b)->getViolationsByConstraint(33));
    }

    public function testUndeclaredActivityStillBoundsItsEvents(): void
    {
        // No activity record at all: the start event alone fixes the boundary.
        $b = $this->buildDoc();
        $b->wasStartedBy(activity: 'ex:a1', time: self::at('2024-01-01T10:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', time: self::at('2024-01-01T08:00:00Z'));

        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(33));
        $this->assertCount(1, $v->getViolationsByConstraint(34));
    }

    public function testInlineActivityTimesStillBound(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1', self::at('2024-01-01T10:00:00Z'), self::at('2024-01-01T12:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', time: self::at('2024-01-01T13:00:00Z'));

        $v = $this->validate($b);
        $this->assertCount(1, $v->getViolationsByConstraint(33));
        $this->assertCount(1, $v->getViolationsByConstraint(34));
    }

    public function testInlineTimesAndEventTimesCombine(): void
    {
        // The activity states its start inline and its end through an event.
        $b = $this->buildDoc();
        $b->activity('ex:a1', self::at('2024-01-01T10:00:00Z'));
        $b->wasEndedBy(activity: 'ex:a1', time: self::at('2024-01-01T12:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e2', time: self::at('2024-01-01T13:00:00Z'));

        $this->assertCount(2, $this->validate($b)->getViolationsByConstraint(33));
    }

    public function testUntimedEventsDoNotBound(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->wasStartedBy(activity: 'ex:a1');
        $b->wasEndedBy(activity: 'ex:a1');
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));
        $b->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', time: self::at('2024-01-01T13:00:00Z'));

        $v = $this->validate($b);
        $this->assertCount(0, $v->getViolationsByConstraint(33));
        $this->assertCount(0, $v->getViolationsByConstraint(34));
    }

    public function testEventsForAnotherActivityDoNotBound(): void
    {
        $b = $this->buildDoc();
        $b->activity('ex:a1');
        $b->activity('ex:a2');
        $b->wasStartedBy(activity: 'ex:a2', time: self::at('2024-01-01T10:00:00Z'));
        $b->used(activity: 'ex:a1', entity: 'ex:e1', time: self::at('2024-01-01T09:00:00Z'));

        $this->assertCount(0, $this->validate($b)->getViolationsByConstraint(33));
    }
}
