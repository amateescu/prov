<?php

declare(strict_types=1);

namespace Prov\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Prov\Builder\BundleBuilder;
use Prov\Bundle;
use Prov\Identifier\ProvNamespace;

final class BundleBuilderTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testBuildEmptyBundle(): void
    {
        $id = $this->ex->qualifiedName('b1');
        $bundle = new BundleBuilder($id)->build();

        $this->assertInstanceOf(Bundle::class, $bundle);
        $this->assertSame($id, $bundle->identifier);
        $this->assertSame([], $bundle->records);
    }

    public function testEntityWithStringShorthand(): void
    {
        $builder = new BundleBuilder($this->ex->qualifiedName('b1'));
        $builder->addNamespace($this->ex);
        $builder->entity('ex:e1');

        $bundle = $builder->build();
        $this->assertCount(1, $bundle->entities);
        $this->assertSame('http://example.org/e1', $bundle->entities[0]->identifier->uri);
    }

    public function testFluentInterface(): void
    {
        $bundle = new BundleBuilder($this->ex->qualifiedName('b1'))
            ->addNamespace($this->ex)
            ->entity('ex:e1')
            ->activity('ex:a1')
            ->agent('ex:ag1')
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1')
            ->build();

        $this->assertCount(1, $bundle->entities);
        $this->assertCount(1, $bundle->activities);
        $this->assertCount(1, $bundle->agents);
        $this->assertCount(1, $bundle->relations);
    }

    public function testAllRelationMethods(): void
    {
        $bundle = new BundleBuilder($this->ex->qualifiedName('b1'))
            ->addNamespace($this->ex)
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1')
            ->used(activity: 'ex:a1', entity: 'ex:e1')
            ->wasInformedBy(informed: 'ex:a1', informant: 'ex:a2')
            ->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1')
            ->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1')
            ->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1')
            ->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1')
            ->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1')
            ->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2')
            ->wasInfluencedBy(influencee: 'ex:e1', influencer: 'ex:e2')
            ->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2')
            ->alternateOf(alternate1: 'ex:e1', alternate2: 'ex:e2')
            ->hadMember(collection: 'ex:c1', entity: 'ex:e1')
            ->build();

        $this->assertCount(13, $bundle->relations);
    }

    public function testNamespacesPropagateToBundle(): void
    {
        $builder = new BundleBuilder($this->ex->qualifiedName('b1'));
        $builder->addNamespace($this->ex);

        $bundle = $builder->build();
        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $bundle->namespaces);

        $this->assertContains('ex', $prefixes);
        $this->assertContains('prov', $prefixes);
    }

    public function testSetDefaultNamespace(): void
    {
        $builder = new BundleBuilder($this->ex->qualifiedName('b1'));
        $builder->setDefaultNamespace($this->ex);
        $builder->entity('myEntity');

        $bundle = $builder->build();
        $this->assertSame('http://example.org/myEntity', $bundle->entities[0]->identifier->uri);
    }
}
