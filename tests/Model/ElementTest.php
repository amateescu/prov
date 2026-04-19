<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Model\ProvElement;
use Prov\Model\ProvElementInterface;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRecordInterface;

final class ElementTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testEntityConstruction(): void
    {
        $id = $this->ex->qualifiedName('e1');
        $entity = new Entity($id);

        $this->assertSame($id, $entity->identifier);
        $this->assertSame($id, $entity->identifier);
        $this->assertTrue($entity->attributes->isEmpty());
    }

    public function testEntityWithNullIdentifier(): void
    {
        $entity = new Entity(null);
        $this->assertNull($entity->identifier);
    }

    public function testEntityWithAttributes(): void
    {
        $id = $this->ex->qualifiedName('e1');
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), Literal::string('Document'));
        $entity = new Entity($id, $attrs);

        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testEntityImplementsInterfaces(): void
    {
        $entity = new Entity($this->ex->qualifiedName('e1'));

        $this->assertInstanceOf(ProvRecordInterface::class, $entity);
        $this->assertInstanceOf(ProvElementInterface::class, $entity);
        $this->assertInstanceOf(ProvRecord::class, $entity);
        $this->assertInstanceOf(ProvElement::class, $entity);
    }

    public function testAgentConstruction(): void
    {
        $id = $this->ex->qualifiedName('ag1');
        $agent = new Agent($id);

        $this->assertSame($id, $agent->identifier);
        $this->assertTrue($agent->attributes->isEmpty());
    }

    public function testAgentImplementsInterfaces(): void
    {
        $agent = new Agent($this->ex->qualifiedName('ag1'));
        $this->assertInstanceOf(ProvElementInterface::class, $agent);
    }

    public function testActivityConstruction(): void
    {
        $id = $this->ex->qualifiedName('a1');
        $start = new \DateTimeImmutable('2023-01-01T00:00:00+00:00');
        $end = new \DateTimeImmutable('2023-01-02T00:00:00+00:00');

        $activity = new Activity($id, $start, $end);

        $this->assertSame($id, $activity->identifier);
        $this->assertSame($start, $activity->startTime);
        $this->assertSame($end, $activity->endTime);
    }

    public function testActivityWithNullTimes(): void
    {
        $activity = new Activity($this->ex->qualifiedName('a1'));

        $this->assertNull($activity->startTime);
        $this->assertNull($activity->endTime);
    }

    public function testActivityWithOnlyStartTime(): void
    {
        $start = new \DateTimeImmutable('2023-06-15T12:00:00+00:00');
        $activity = new Activity($this->ex->qualifiedName('a1'), startTime: $start);

        $this->assertSame($start, $activity->startTime);
        $this->assertNull($activity->endTime);
    }

    public function testActivityImplementsInterfaces(): void
    {
        $activity = new Activity($this->ex->qualifiedName('a1'));
        $this->assertInstanceOf(ProvElementInterface::class, $activity);
    }
}
