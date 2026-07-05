<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Activity;
use Prov\Agent;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Attribution;
use Prov\Relation\Generation;

final class DocumentTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testConstruction(): void
    {
        $doc = new Document(records: [], bundles: [], namespaces: [$this->ex]);

        $this->assertSame([], $doc->records);
        $this->assertSame([], $doc->bundles);
        $this->assertCount(1, $doc->namespaces);
    }

    public function testGetEntities(): void
    {
        $e1 = new Entity($this->ex->qualifiedName('e1'));
        $e2 = new Entity($this->ex->qualifiedName('e2'));
        $a1 = new Activity($this->ex->qualifiedName('a1'));

        $doc = new Document(records: [$e1, $a1, $e2], bundles: [], namespaces: []);

        $entities = $doc->entities;
        $this->assertCount(2, $entities);
        $this->assertSame($e1, $entities[0]);
        $this->assertSame($e2, $entities[1]);
    }

    public function testGetActivities(): void
    {
        $a1 = new Activity($this->ex->qualifiedName('a1'));
        $e1 = new Entity($this->ex->qualifiedName('e1'));

        $doc = new Document(records: [$a1, $e1], bundles: [], namespaces: []);
        $this->assertCount(1, $doc->activities);
    }

    public function testGetAgents(): void
    {
        $ag1 = new Agent($this->ex->qualifiedName('ag1'));
        $doc = new Document(records: [$ag1], bundles: [], namespaces: []);
        $this->assertCount(1, $doc->agents);
    }

    public function testGetRelations(): void
    {
        $gen = new Generation(identifier: null, entity: $this->ex->qualifiedName('e1'));
        $attr = new Attribution(entity: $this->ex->qualifiedName('e1'), agent: $this->ex->qualifiedName('ag1'));
        $e1 = new Entity($this->ex->qualifiedName('e1'));

        $doc = new Document(records: [$e1, $gen, $attr], bundles: [], namespaces: []);

        $relations = $doc->relations;
        $this->assertCount(2, $relations);
    }

    public function testGetRecordsByType(): void
    {
        $gen1 = new Generation(identifier: null, entity: $this->ex->qualifiedName('e1'));
        $gen2 = new Generation(identifier: null, entity: $this->ex->qualifiedName('e2'));
        $attr = new Attribution(entity: $this->ex->qualifiedName('e1'));

        $doc = new Document(records: [$gen1, $gen2, $attr], bundles: [], namespaces: []);

        $this->assertCount(2, $doc->getRecordsByType(Generation::class));
        $this->assertCount(1, $doc->getRecordsByType(Attribution::class));
        $this->assertCount(0, $doc->getRecordsByType(Entity::class));
    }

    public function testGetRecordByIdentifier(): void
    {
        $id = $this->ex->qualifiedName('e1');
        $e1 = new Entity($id);
        $e2 = new Entity($this->ex->qualifiedName('e2'));

        $doc = new Document(records: [$e1, $e2], bundles: [], namespaces: []);

        $found = $doc->getRecordByIdentifier($id);
        $this->assertSame($e1, $found);
    }

    public function testGetRecordByIdentifierNotFound(): void
    {
        $doc = new Document(records: [], bundles: [], namespaces: []);
        $this->assertNull($doc->getRecordByIdentifier($this->ex->qualifiedName('nope')));
    }

    public function testGetBundleByIdentifier(): void
    {
        $bundleId = $this->ex->qualifiedName('b1');
        $bundle = new Bundle($bundleId, [], []);

        $doc = new Document(records: [], bundles: [$bundle], namespaces: []);

        $found = $doc->getBundleByIdentifier($bundleId);
        $this->assertSame($bundle, $found);
    }

    public function testGetBundleByIdentifierNotFound(): void
    {
        $doc = new Document(records: [], bundles: [], namespaces: []);
        $this->assertNull($doc->getBundleByIdentifier($this->ex->qualifiedName('nope')));
    }
}
