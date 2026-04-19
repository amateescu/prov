<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Activity;
use Prov\Agent;
use Prov\Bundle;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Generation;

final class BundleTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testConstruction(): void
    {
        $id = $this->ex->qualifiedName('bundle1');
        $bundle = new Bundle($id, [], []);

        $this->assertSame($id, $bundle->identifier);
        $this->assertSame([], $bundle->records);
        $this->assertSame([], $bundle->namespaces);
    }

    public function testGetEntities(): void
    {
        $e1 = new Entity($this->ex->qualifiedName('e1'));
        $a1 = new Activity($this->ex->qualifiedName('a1'));

        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$e1, $a1], []);
        $this->assertCount(1, $bundle->entities);
    }

    public function testGetActivities(): void
    {
        $a1 = new Activity($this->ex->qualifiedName('a1'));
        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$a1], []);
        $this->assertCount(1, $bundle->activities);
    }

    public function testGetAgents(): void
    {
        $ag1 = new Agent($this->ex->qualifiedName('ag1'));
        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$ag1], []);
        $this->assertCount(1, $bundle->agents);
    }

    public function testGetRelations(): void
    {
        $gen = new Generation(entity: $this->ex->qualifiedName('e1'));
        $e1 = new Entity($this->ex->qualifiedName('e1'));

        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$e1, $gen], []);
        $this->assertCount(1, $bundle->relations);
    }

    public function testGetRecordsByType(): void
    {
        $e1 = new Entity($this->ex->qualifiedName('e1'));
        $gen = new Generation(entity: $this->ex->qualifiedName('e1'));

        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$e1, $gen], []);

        $this->assertCount(1, $bundle->getRecordsByType(Entity::class));
        $this->assertCount(1, $bundle->getRecordsByType(Generation::class));
    }

    public function testGetRecordByIdentifier(): void
    {
        $id = $this->ex->qualifiedName('e1');
        $e1 = new Entity($id);

        $bundle = new Bundle($this->ex->qualifiedName('b1'), [$e1], []);

        $found = $bundle->getRecordByIdentifier($id);
        $this->assertSame($e1, $found);
    }

    public function testGetRecordByIdentifierNotFound(): void
    {
        $bundle = new Bundle($this->ex->qualifiedName('b1'), [], []);
        $this->assertNull($bundle->getRecordByIdentifier($this->ex->qualifiedName('nope')));
    }
}
