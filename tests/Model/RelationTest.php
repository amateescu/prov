<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;
use Prov\Model\ProvRelationInterface;
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Influence;
use Prov\Relation\Invalidation;
use Prov\Relation\Membership;
use Prov\Relation\Specialization;
use Prov\Relation\Start;
use Prov\Relation\Usage;

final class RelationTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    private function qn(string $local): QualifiedName
    {
        return $this->ex->qualifiedName($local);
    }

    public function testGenerationFormalAttributes(): void
    {
        $time = new \DateTimeImmutable('2023-01-15T10:00:00+00:00');
        $gen = new Generation(
            identifier: $this->qn('g1'),
            entity: $this->qn('e1'),
            activity: $this->qn('a1'),
            time: $time,
        );

        $this->assertSame('http://example.org/g1', $gen->identifier->uri);
        $this->assertSame('http://example.org/e1', $gen->entity->uri);
        $this->assertSame('http://example.org/a1', $gen->activity->uri);
        $this->assertSame($time, $gen->time);
        $this->assertInstanceOf(ProvRelationInterface::class, $gen);
        $this->assertInstanceOf(ProvRelation::class, $gen);
    }

    public function testGenerationOptionalFormalsAreNullable(): void
    {
        $gen = new Generation(identifier: null, entity: $this->qn('e1'));
        $this->assertNull($gen->identifier);
        $this->assertSame('http://example.org/e1', $gen->entity->uri);
        $this->assertNull($gen->activity);
        $this->assertNull($gen->time);
    }

    public function testUsageFormalAttributes(): void
    {
        $usage = new Usage(identifier: null, activity: $this->qn('a1'), entity: $this->qn('e1'));

        $this->assertSame('http://example.org/a1', $usage->activity->uri);
        $this->assertSame('http://example.org/e1', $usage->entity->uri);
        $this->assertNull($usage->time);
    }

    public function testCommunicationFormalAttributes(): void
    {
        $comm = new Communication(informed: $this->qn('a1'), informant: $this->qn('a2'));

        $this->assertSame('http://example.org/a1', $comm->informed->uri);
        $this->assertSame('http://example.org/a2', $comm->informant->uri);
    }

    public function testStartFormalAttributes(): void
    {
        $time = new \DateTimeImmutable('2023-06-01T09:00:00+00:00');
        $start = new Start(activity: $this->qn('a1'), trigger: $this->qn('e1'), starter: $this->qn('a2'), time: $time);

        $this->assertSame('http://example.org/a1', $start->activity->uri);
        $this->assertSame('http://example.org/e1', $start->trigger->uri);
        $this->assertSame('http://example.org/a2', $start->starter->uri);
        $this->assertSame($time, $start->time);
    }

    public function testStartWithNullStarter(): void
    {
        $start = new Start(activity: $this->qn('a1'), trigger: $this->qn('e1'));

        $this->assertNull($start->starter);
    }

    public function testEndFormalAttributes(): void
    {
        $end = new End(activity: $this->qn('a1'), trigger: $this->qn('e1'), ender: $this->qn('a2'));

        $this->assertSame('http://example.org/a1', $end->activity->uri);
        $this->assertSame('http://example.org/e1', $end->trigger->uri);
        $this->assertSame('http://example.org/a2', $end->ender->uri);
    }

    public function testEndWithNullEnder(): void
    {
        $end = new End(activity: $this->qn('a1'), trigger: $this->qn('e1'));

        $this->assertNull($end->ender);
    }

    public function testInvalidationFormalAttributes(): void
    {
        $time = new \DateTimeImmutable('2023-06-01T12:00:00+00:00');
        $inv = new Invalidation(
            identifier: $this->qn('inv1'),
            entity: $this->qn('e1'),
            activity: $this->qn('a1'),
            time: $time,
        );

        $this->assertSame('http://example.org/inv1', $inv->identifier->uri);
        $this->assertSame('http://example.org/e1', $inv->entity->uri);
        $this->assertSame('http://example.org/a1', $inv->activity->uri);
        $this->assertSame($time, $inv->time);
        $this->assertInstanceOf(ProvRelationInterface::class, $inv);
    }

    public function testInvalidationOptionalFormalsAreNullable(): void
    {
        $inv = new Invalidation(identifier: null, entity: $this->qn('e1'));
        $this->assertNull($inv->identifier);
        $this->assertSame('http://example.org/e1', $inv->entity->uri);
        $this->assertNull($inv->activity);
        $this->assertNull($inv->time);
    }

    public function testDerivationFormalAttributes(): void
    {
        $der = new Derivation(
            generatedEntity: $this->qn('e2'),
            usedEntity: $this->qn('e1'),
            activity: $this->qn('a1'),
            generation: $this->qn('g1'),
            usage: $this->qn('u1'),
        );

        $this->assertSame('http://example.org/e2', $der->generatedEntity->uri);
        $this->assertSame('http://example.org/e1', $der->usedEntity->uri);
        $this->assertSame('http://example.org/a1', $der->activity->uri);
        $this->assertSame('http://example.org/g1', $der->generation->uri);
        $this->assertSame('http://example.org/u1', $der->usage->uri);
    }

    public function testAttributionFormalAttributes(): void
    {
        $attr = new Attribution(entity: $this->qn('e1'), agent: $this->qn('ag1'));

        $this->assertSame('http://example.org/e1', $attr->entity->uri);
        $this->assertSame('http://example.org/ag1', $attr->agent->uri);
    }

    public function testAssociationFormalAttributes(): void
    {
        $assoc = new Association(activity: $this->qn('a1'), agent: $this->qn('ag1'), plan: $this->qn('plan1'));

        $this->assertSame('http://example.org/a1', $assoc->activity->uri);
        $this->assertSame('http://example.org/ag1', $assoc->agent->uri);
        $this->assertSame('http://example.org/plan1', $assoc->plan->uri);
    }

    public function testDelegationFormalAttributes(): void
    {
        $del = new Delegation(delegate: $this->qn('ag1'), responsible: $this->qn('ag2'), activity: $this->qn('a1'));

        $this->assertSame('http://example.org/ag1', $del->delegate->uri);
        $this->assertSame('http://example.org/ag2', $del->responsible->uri);
        $this->assertSame('http://example.org/a1', $del->activity->uri);
    }

    public function testInfluenceFormalAttributes(): void
    {
        $inf = new Influence(influencee: $this->qn('e1'), influencer: $this->qn('e2'));

        $this->assertSame('http://example.org/e1', $inf->influencee->uri);
        $this->assertSame('http://example.org/e2', $inf->influencer->uri);
    }

    public function testSpecializationFormalAttributes(): void
    {
        $spec = new Specialization(identifier: null, specificEntity: $this->qn('e1'), generalEntity: $this->qn('e2'));

        $this->assertSame('http://example.org/e1', $spec->specificEntity->uri);
        $this->assertSame('http://example.org/e2', $spec->generalEntity->uri);
    }

    public function testAlternateFormalAttributes(): void
    {
        $alt = new Alternate(identifier: null, alternate1: $this->qn('e1'), alternate2: $this->qn('e2'));

        $this->assertSame('http://example.org/e1', $alt->alternate1->uri);
        $this->assertSame('http://example.org/e2', $alt->alternate2->uri);
    }

    public function testMembershipFormalAttributes(): void
    {
        $mem = new Membership(collection: $this->qn('c1'), entity: $this->qn('e1'));

        $this->assertSame('http://example.org/c1', $mem->collection->uri);
        $this->assertSame('http://example.org/e1', $mem->entity->uri);
    }

    public function testRelationWithExtraAttributes(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), 'Revision');

        $gen = new Generation(identifier: null, entity: $this->qn('e1'), attributes: $attrs);

        $this->assertFalse($gen->attributes->isEmpty());
    }
}
