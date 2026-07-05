<?php

declare(strict_types=1);

namespace Prov\Tests\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Exception\NamespaceException;
use Prov\Identifier\ProvNamespace;
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

final class DocumentBuilderTest extends TestCase
{
    private ProvNamespace $ex;
    private DocumentBuilder $builder;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->builder = new DocumentBuilder();
        $this->builder->addNamespace($this->ex);
    }

    public function testBuildEmptyDocument(): void
    {
        $doc = new DocumentBuilder()->build();
        $this->assertInstanceOf(Document::class, $doc);
        $this->assertSame([], $doc->records);
        $this->assertSame([], $doc->bundles);
    }

    public function testNamespaceWithDefaultPrefixSetsDefaultNamespace(): void
    {
        // namespace('default', ...) must set the default namespace so
        // unprefixed identifiers resolve, matching how the read side treats the
        // 'default' prefix. A plain registration would leave entity('foo')
        // unresolvable.
        $doc = new DocumentBuilder()
            ->namespace('default', 'http://default.example/')
            ->entity('foo')
            ->build();

        $this->assertSame('http://default.example/foo', $doc->entities[0]->identifier->uri);
    }

    public function testAddNamespaceWithDefaultPrefixSetsDefaultNamespace(): void
    {
        $doc = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('default', 'http://default.example/'))
            ->entity('foo')
            ->build();

        $this->assertSame('http://default.example/foo', $doc->entities[0]->identifier->uri);
    }

    public function testEntityWithStringShorthand(): void
    {
        $this->builder->entity('ex:e1');
        $doc = $this->builder->build();

        $entities = $doc->entities;
        $this->assertCount(1, $entities);
        $this->assertSame('http://example.org/e1', $entities[0]->identifier->uri);
    }

    public function testEntityWithQualifiedName(): void
    {
        $qn = $this->ex->qualifiedName('e1');
        $this->builder->entity($qn);
        $doc = $this->builder->build();

        $this->assertCount(1, $doc->entities);
        $this->assertSame($qn, $doc->entities[0]->identifier);
    }

    public function testEntityWithNullIdentifier(): void
    {
        $this->builder->entity(null);
        $doc = $this->builder->build();

        $this->assertCount(1, $doc->entities);
        $this->assertNull($doc->entities[0]->identifier);
    }

    public function testEntityWithArrayAttributes(): void
    {
        $this->builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $doc = $this->builder->build();

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testArrayAttributeListValueCreatesMultipleEntries(): void
    {
        $this->builder->entity('ex:e1', ['prov:atLocation' => ['ex:a', 'ex:b']]);
        $doc = $this->builder->build();

        $key = ProvNamespace::prov()->qualifiedName('atLocation');
        $this->assertSame(['ex:a', 'ex:b'], $doc->entities[0]->attributes->get($key));
    }

    public function testProvTypeStringShorthandResolvesToQualifiedName(): void
    {
        $this->builder->entity('ex:e1', ['prov:type' => 'ex:Document']);
        $doc = $this->builder->build();

        $value = $doc->entities[0]->attributes->firstValue(ProvNamespace::prov()->qualifiedName('type'));
        $this->assertInstanceOf(\Prov\Identifier\QualifiedName::class, $value);
        $this->assertSame('http://example.org/Document', $value->getUri());
    }

    public function testProvTypeUnregisteredShorthandStaysString(): void
    {
        $this->builder->entity('ex:e1', ['prov:type' => 'workspace:stage']);
        $doc = $this->builder->build();

        $value = $doc->entities[0]->attributes->firstValue(ProvNamespace::prov()->qualifiedName('type'));
        $this->assertSame('workspace:stage', $value);
    }

    public function testNonTypeKeyStringValueStaysString(): void
    {
        $this->builder->entity('ex:e1', ['ex:related' => 'ex:other']);
        $doc = $this->builder->build();

        $value = $doc->entities[0]->attributes->firstValue($this->ex->qualifiedName('related'));
        $this->assertSame('ex:other', $value);
    }

    public function testRelationIdentifierIsLastParameter(): void
    {
        // Positional endpoints bind in PROV-N order; the relation id stays null
        // unless passed explicitly (by name, or as the trailing argument).
        $this->builder->wasGeneratedBy('ex:e1', 'ex:a1');
        $doc = $this->builder->build();

        $generation = $doc->getRecordsByType(Generation::class)[0];
        $this->assertNull($generation->identifier);
        $this->assertSame('http://example.org/e1', $generation->entity?->getUri());
        $this->assertSame('http://example.org/a1', $generation->activity?->getUri());
    }

    public function testEntityWithAttributesObject(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), Literal::string('Document'));

        $this->builder->entity('ex:e1', $attrs);
        $doc = $this->builder->build();

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testActivity(): void
    {
        $start = new \DateTimeImmutable('2023-01-15T00:00:00+00:00');
        $end = new \DateTimeImmutable('2023-01-16T00:00:00+00:00');

        $this->builder->activity('ex:a1', $start, $end);
        $doc = $this->builder->build();

        $activities = $doc->activities;
        $this->assertCount(1, $activities);
        $this->assertSame('http://example.org/a1', $activities[0]->identifier->uri);
        $this->assertEquals($start, $activities[0]->startTime);
        $this->assertEquals($end, $activities[0]->endTime);
    }

    public function testAgent(): void
    {
        $this->builder->agent('ex:ag1');
        $doc = $this->builder->build();

        $agents = $doc->agents;
        $this->assertCount(1, $agents);
        $this->assertSame('http://example.org/ag1', $agents[0]->identifier->uri);
    }

    public function testWasGeneratedBy(): void
    {
        $this->builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $doc = $this->builder->build();

        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        $this->assertSame('http://example.org/e1', $gens[0]->entity->uri);
        $this->assertSame('http://example.org/a1', $gens[0]->activity->uri);
    }

    public function testUsed(): void
    {
        $this->builder->used(activity: 'ex:a1', entity: 'ex:e1');
        $doc = $this->builder->build();

        $usages = $doc->getRecordsByType(Usage::class);
        $this->assertCount(1, $usages);
        $this->assertSame('http://example.org/a1', $usages[0]->activity->uri);
        $this->assertSame('http://example.org/e1', $usages[0]->entity->uri);
    }

    public function testWasInformedBy(): void
    {
        $this->builder->wasInformedBy(informed: 'ex:a1', informant: 'ex:a2');
        $doc = $this->builder->build();

        $comms = $doc->getRecordsByType(Communication::class);
        $this->assertCount(1, $comms);
        $this->assertSame('http://example.org/a1', $comms[0]->informed->uri);
    }

    public function testWasStartedBy(): void
    {
        $time = new \DateTimeImmutable('2023-01-15T10:00:00+00:00');
        $this->builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1', time: $time);
        $doc = $this->builder->build();

        $starts = $doc->getRecordsByType(Start::class);
        $this->assertCount(1, $starts);
        $this->assertEquals($time, $starts[0]->time);
    }

    public function testWasEndedBy(): void
    {
        $this->builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $doc = $this->builder->build();

        $ends = $doc->getRecordsByType(End::class);
        $this->assertCount(1, $ends);
    }

    public function testWasDerivedFrom(): void
    {
        $this->builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $doc = $this->builder->build();

        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertSame('http://example.org/e2', $ders[0]->generatedEntity->uri);
        $this->assertSame('http://example.org/e1', $ders[0]->usedEntity->uri);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function derivationSubtypeShortcuts(): iterable
    {
        yield 'wasRevisionOf' => ['wasRevisionOf', 'Revision'];
        yield 'wasQuotedFrom' => ['wasQuotedFrom', 'Quotation'];
        yield 'hadPrimarySource' => ['hadPrimarySource', 'PrimarySource'];
    }

    #[DataProvider('derivationSubtypeShortcuts')]
    public function testDerivationSubtypeShortcut(string $method, string $subtype): void
    {
        $this->builder->{$method}(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $doc = $this->builder->build();

        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertSame('http://example.org/e2', $ders[0]->generatedEntity->uri);
        $this->assertSame('http://example.org/e1', $ders[0]->usedEntity->uri);

        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $typeValues = $ders[0]->attributes->get($prov->qualifiedName('type'));
        $this->assertCount(1, $typeValues);
        $this->assertSame('http://www.w3.org/ns/prov#' . $subtype, $typeValues[0]->uri);
    }

    public function testDerivationSubtypeShortcutPreservesCallerAttributes(): void
    {
        $this->builder->wasRevisionOf(identifier: 'ex:d1', generatedEntity: 'ex:e2', usedEntity: 'ex:e1', attributes: [
            'prov:label' => 'v2',
        ]);
        $doc = $this->builder->build();

        $der = $doc->getRecordsByType(Derivation::class)[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $this->assertCount(1, $der->attributes->get($prov->qualifiedName('type')));
        $this->assertCount(1, $der->attributes->get($prov->qualifiedName('label')));
    }

    public function testWasAttributedTo(): void
    {
        $this->builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');
        $doc = $this->builder->build();

        $attrs = $doc->getRecordsByType(Attribution::class);
        $this->assertCount(1, $attrs);
    }

    public function testWasAssociatedWith(): void
    {
        $this->builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1', plan: 'ex:plan1');
        $doc = $this->builder->build();

        $assocs = $doc->getRecordsByType(Association::class);
        $this->assertCount(1, $assocs);
        $this->assertSame('http://example.org/plan1', $assocs[0]->plan->uri);
    }

    public function testActedOnBehalfOf(): void
    {
        $this->builder->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2');
        $doc = $this->builder->build();

        $dels = $doc->getRecordsByType(Delegation::class);
        $this->assertCount(1, $dels);
    }

    public function testWasInfluencedBy(): void
    {
        $this->builder->wasInfluencedBy(influencee: 'ex:e1', influencer: 'ex:e2');
        $doc = $this->builder->build();

        $infs = $doc->getRecordsByType(Influence::class);
        $this->assertCount(1, $infs);
    }

    public function testSpecializationOf(): void
    {
        $this->builder->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $doc = $this->builder->build();

        $specs = $doc->getRecordsByType(Specialization::class);
        $this->assertCount(1, $specs);
    }

    public function testAlternateOf(): void
    {
        $this->builder->alternateOf(alternate1: 'ex:e1', alternate2: 'ex:e2');
        $doc = $this->builder->build();

        $alts = $doc->getRecordsByType(Alternate::class);
        $this->assertCount(1, $alts);
    }

    public function testHadMember(): void
    {
        $this->builder->hadMember(collection: 'ex:c1', entity: 'ex:e1');
        $doc = $this->builder->build();

        $mems = $doc->getRecordsByType(Membership::class);
        $this->assertCount(1, $mems);
    }

    public function testUnknownPrefixThrows(): void
    {
        $this->expectException(NamespaceException::class);
        $this->builder->entity('unknown:e1');
    }

    public function testFluentInterface(): void
    {
        $doc = $this->builder
            ->entity('ex:e1')
            ->entity('ex:e2')
            ->activity('ex:a1')
            ->agent('ex:ag1')
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1')
            ->build();

        $this->assertCount(2, $doc->entities);
        $this->assertCount(1, $doc->activities);
        $this->assertCount(1, $doc->agents);
        $this->assertCount(1, $doc->relations);
    }

    public function testNamespacesPropagateToDocument(): void
    {
        $this->builder->entity('ex:e1', ['prov:label' => Literal::string('typed')]);
        $doc = $this->builder->build();

        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $doc->namespaces);
        $this->assertContains('ex', $prefixes);
        $this->assertContains('prov', $prefixes);
        $this->assertContains('xsd', $prefixes);
    }

    public function testBuildPrunesUnusedNamespaces(): void
    {
        $this->builder->namespace('unused', 'http://unused.example/');
        $this->builder->entity('ex:e1');
        $doc = $this->builder->build();

        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $doc->namespaces);
        $this->assertSame(['ex'], $prefixes);
    }

    public function testKeepUnusedNamespacesDisablesPruning(): void
    {
        $this->builder->namespace('unused', 'http://unused.example/');
        $this->builder->keepUnusedNamespaces();
        $this->builder->entity('ex:e1');
        $doc = $this->builder->build();

        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $doc->namespaces);
        $this->assertContains('unused', $prefixes);
        $this->assertContains('prov', $prefixes);
        $this->assertContains('xsd', $prefixes);
    }

    public function testBundleRecordsKeepDocumentNamespacesAlive(): void
    {
        $this->builder->namespace('b', 'http://bundle-only.example/');
        $this->builder->withBundle('ex:b1', static fn($bb) => $bb->entity('b:e1'));
        $doc = $this->builder->build();

        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $doc->namespaces);
        $this->assertContains('b', $prefixes);
        $this->assertContains('ex', $prefixes);
    }

    public function testAddNamespacesRegistersAll(): void
    {
        $this->builder->addNamespaces([
            new ProvNamespace('a', 'http://a.example/'),
            new ProvNamespace('b', 'http://b.example/'),
        ]);
        $this->builder->entity('a:e1');
        $this->builder->entity('b:e2');
        $doc = $this->builder->build();

        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $doc->namespaces);
        $this->assertContains('a', $prefixes);
        $this->assertContains('b', $prefixes);
    }

    public function testBundleNestedApi(): void
    {
        $bundleBuilder = $this->builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');
        $bundleBuilder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $this->builder->build();

        $this->assertCount(1, $doc->bundles);
        $this->assertSame('http://example.org/b1', $doc->bundles[0]->identifier->uri);
        $this->assertCount(1, $doc->bundles[0]->entities);
        $this->assertCount(1, $doc->bundles[0]->relations);
    }

    public function testBundleInheritsParentNamespaces(): void
    {
        $bundleBuilder = $this->builder->bundle('ex:b1');
        // Should resolve 'ex:e1' via parent namespace manager
        $bundleBuilder->entity('ex:e1');

        $doc = $this->builder->build();
        $this->assertSame('http://example.org/e1', $doc->bundles[0]->entities[0]->identifier->uri);
    }

    public function testAddBundleStandaloneApi(): void
    {
        $bundle = new \Prov\Builder\BundleBuilder($this->ex->qualifiedName('b1'))
            ->addNamespace($this->ex)
            ->entity('ex:e1')
            ->build();

        $this->builder->addBundle($bundle);
        $doc = $this->builder->build();

        $this->assertCount(1, $doc->bundles);
        $this->assertSame('http://example.org/b1', $doc->bundles[0]->identifier->uri);
    }

    public function testAddBundleAdvancesBlankNodeCounterPastBundleLabels(): void
    {
        // A standalone BundleBuilder mints from its own independent sequence,
        // the way a deserialized bundle's blank labels never went through
        // this document builder's blank() calls either.
        $bundleBuilder = new \Prov\Builder\BundleBuilder($this->ex->qualifiedName('b1'))->addNamespace($this->ex);
        $bundleBlank = $bundleBuilder->blank();
        $bundle = $bundleBuilder->entity($bundleBlank)->build();
        $this->assertSame('_:b1', $bundleBlank->uri);

        $this->builder->addBundle($bundle);
        $nextBlank = $this->builder->blank();

        $this->assertNotSame($bundleBlank->uri, $nextBlank->uri);
    }

    public function testSetDefaultNamespace(): void
    {
        $builder = new DocumentBuilder();
        $builder->setDefaultNamespace($this->ex);
        $builder->entity('myEntity');

        $doc = $builder->build();
        $this->assertSame('http://example.org/myEntity', $doc->entities[0]->identifier->uri);
    }

    public function testGetNamespaceManagerReflectsRegisteredNamespaces(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);

        $manager = $builder->getNamespaceManager();

        $this->assertSame($this->ex, $manager->getNamespace('ex'));
    }

    public function testGetNamespaceManagerSharesLiveStateNotASnapshot(): void
    {
        $builder = new DocumentBuilder();
        $manager = $builder->getNamespaceManager();

        $builder->addNamespace($this->ex);

        $this->assertSame($this->ex, $manager->getNamespace('ex'));
    }

    public function testResolveAttributeArrayWithUnresolvableKeyThrows(): void
    {
        $this->expectException(NamespaceException::class);
        $this->builder->entity('ex:e1', ['unknown:key' => 'value']);
    }

    public function testWasGeneratedByWithTime(): void
    {
        $time = new \DateTimeImmutable('2023-06-15T10:30:00+00:00');
        $this->builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1', time: $time);
        $doc = $this->builder->build();

        $gen = $doc->getRecordsByType(Generation::class)[0];
        $this->assertSame('http://example.org/g1', $gen->identifier->uri);
        $this->assertEquals($time, $gen->time);
    }

    public function testActivityWithOnlyEndTime(): void
    {
        $end = new \DateTimeImmutable('2023-12-31T23:59:59+00:00');
        $this->builder->activity('ex:a1', endTime: $end);
        $doc = $this->builder->build();

        $activity = $doc->activities[0];
        $this->assertNull($activity->startTime);
        $this->assertEquals($end, $activity->endTime);
    }

    public function testWasInvalidatedBy(): void
    {
        $time = new \DateTimeImmutable('2023-06-15T12:00:00+00:00');
        $this->builder->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1', time: $time);
        $doc = $this->builder->build();

        $invs = $doc->getRecordsByType(Invalidation::class);
        $this->assertCount(1, $invs);
        $this->assertSame('http://example.org/e1', $invs[0]->entity->uri);
        $this->assertSame('http://example.org/a1', $invs[0]->activity->uri);
        $this->assertEquals($time, $invs[0]->time);
    }

    public function testWasStartedByWithStarter(): void
    {
        $this->builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1', starter: 'ex:a2');
        $doc = $this->builder->build();

        $starts = $doc->getRecordsByType(Start::class);
        $this->assertCount(1, $starts);
        $this->assertSame('http://example.org/a2', $starts[0]->starter->uri);
    }

    public function testWasEndedByWithEnder(): void
    {
        $this->builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1', ender: 'ex:a2');
        $doc = $this->builder->build();

        $ends = $doc->getRecordsByType(End::class);
        $this->assertCount(1, $ends);
        $this->assertSame('http://example.org/a2', $ends[0]->ender->uri);
    }

    public function testWithBundleCallback(): void
    {
        $doc = $this->builder
            ->entity('ex:e1')
            ->withBundle('ex:b1', static function (\Prov\Builder\BundleBuilder $b) {
                $b->entity('ex:be1');
                $b->wasGeneratedBy(entity: 'ex:be1', activity: 'ex:ba1');
            })
            ->build();

        $this->assertCount(1, $doc->entities);
        $this->assertCount(1, $doc->bundles);
        $this->assertCount(1, $doc->bundles[0]->entities);
        $this->assertCount(1, $doc->bundles[0]->relations);
    }

    public function testWithBundleChainsFluently(): void
    {
        $doc = $this->builder
            ->entity('ex:e1')
            ->withBundle('ex:b1', static fn($b) => $b->entity('ex:be1'))
            ->withBundle('ex:b2', static fn($b) => $b->entity('ex:be2'))
            ->agent('ex:ag1')
            ->build();

        $this->assertCount(1, $doc->entities);
        $this->assertCount(1, $doc->agents);
        $this->assertCount(2, $doc->bundles);
    }

    public function testWithBundleInheritsNamespaces(): void
    {
        $doc = $this->builder->withBundle('ex:b1', static fn($b) => $b->entity('ex:e1'))->build();

        $this->assertSame('http://example.org/e1', $doc->bundles[0]->entities[0]->identifier->uri);
    }

    public function testBuildTwiceThrows(): void
    {
        $this->builder->entity('ex:e1')->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('single-use');
        $this->builder->build();
    }

    public function testBundleBuilderBuildTwiceThrows(): void
    {
        $bb = $this->builder->bundle('ex:b1');
        $bb->entity('ex:e1');
        $bb->build();

        $this->expectException(\LogicException::class);
        $bb->build();
    }

    public function testNextBlankIdentifierIsReusableAcrossCalls(): void
    {
        $e = $this->builder->blank();
        $this->builder->entity($e);
        $this->builder->wasGeneratedBy(entity: $e, activity: 'ex:a1');

        $doc = $this->builder->build();

        $this->assertSame('_:b1', $doc->entities[0]->identifier->uri);
        $this->assertSame('_:b1', $doc->relations[0]->entity->uri);
    }

    public function testNextBlankIdentifierIncrements(): void
    {
        $first = $this->builder->blank();
        $second = $this->builder->blank();

        $this->assertSame('_:b1', $first->uri);
        $this->assertSame('_:b2', $second->uri);
    }

    public function testAutoDeclareEntitiesIsOffByDefault(): void
    {
        $this->builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $this->builder->build();

        // No bare Entity is minted for the referenced endpoint.
        $this->assertSame([], $doc->entities);
    }

    public function testAutoDeclareEntitiesEmitsBareNodesForUndeclaredEndpoints(): void
    {
        $this->builder
            ->autoDeclareEntities()
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1')
            ->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e0');

        $doc = $this->builder->build();

        // e1 and e0 are declared once each; the activity is not an entity.
        $ids = array_map(static fn($e): string => $e->identifier->localPart, $doc->entities);
        $this->assertSame(['e1', 'e0'], $ids);
        foreach ($doc->entities as $entity) {
            $this->assertTrue($entity->attributes->isEmpty());
        }
    }

    public function testAutoDeclareDoesNotDuplicateAlreadyDeclaredEntities(): void
    {
        $this->builder
            ->autoDeclareEntities()
            ->entity('ex:e1', ['prov:type' => 'ex:Thing'])
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $this->builder->build();

        // The explicit, attributed e1 is kept; no bare duplicate is appended.
        $this->assertCount(1, $doc->entities);
        $this->assertFalse($doc->entities[0]->attributes->isEmpty());
    }

    public function testAutoDeclareDoesNotMintForNonEntityEndpoints(): void
    {
        $this->builder->autoDeclareEntities()->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1');

        $doc = $this->builder->build();

        // Neither the activity nor the agent endpoint is entity-typed.
        $this->assertSame([], $doc->entities);
    }

    public function testAutoDeclareMintsPlanEndpointOfAssociation(): void
    {
        $this->builder->autoDeclareEntities()->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1', plan: 'ex:plan1');

        $doc = $this->builder->build();

        // The plan is the one entity-typed endpoint of an association.
        $this->assertCount(1, $doc->entities);
        $this->assertSame('plan1', $doc->entities[0]->identifier->localPart);
    }

    public function testAutoDeclareSkipsBlankNodeEndpoints(): void
    {
        $blank = $this->builder->blank();
        $this->builder->autoDeclareEntities()->wasGeneratedBy(entity: $blank, activity: 'ex:a1');

        $doc = $this->builder->build();

        $this->assertSame([], $doc->entities);
    }

    public function testAutoDeclarePropagatesToBundles(): void
    {
        $this->builder->autoDeclareEntities();
        $this->builder->withBundle('ex:b1', static function ($bundle): void {
            $bundle->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        });

        $doc = $this->builder->build();

        $this->assertSame(
            ['e1'],
            array_map(static fn($e): string => $e->identifier->localPart, $doc->bundles[0]->entities),
        );
    }
}
