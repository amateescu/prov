<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Entity;
use Prov\Exception\DeserializationException;
use Prov\Exception\ProvException;
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
use Prov\Serializer\JsonSerializer;

final class JsonSerializerTest extends TestCase
{
    private ProvNamespace $ex;
    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->serializer = new JsonSerializer(prettyPrint: false);
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    public function testSerializeEmptyDocument(): void
    {
        $doc = new DocumentBuilder()->build();
        $json = $this->serializer->serialize($doc);

        // An empty document is the JSON object `{}`, never an array, and carries
        // no empty 'prefix' key.
        $this->assertSame('{}', $json);
        $this->assertArrayNotHasKey('prefix', (array) json_decode($json, true));
    }

    public function testSerializePrefixes(): void
    {
        $doc = $this->buildDoc()->entity('ex:e1', ['prov:label' => 'labelled'])->build();
        $data = json_decode($this->serializer->serialize($doc), true);

        $this->assertSame('http://example.org/', $data['prefix']['ex']);
        $this->assertSame('http://www.w3.org/ns/prov#', $data['prefix']['prov']);
    }

    public function testSerializeEntities(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2', ['prov:type' => Literal::string('Document')]);

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('entity', $data);
        $this->assertArrayHasKey('ex:e1', $data['entity']);
        $this->assertArrayHasKey('ex:e2', $data['entity']);
    }

    public function testSerializeActivity(): void
    {
        $builder = $this->buildDoc();
        $start = new \DateTimeImmutable('2023-01-15T00:00:00+00:00');
        $builder->activity('ex:a1', startTime: $start);

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('activity', $data);
        $this->assertSame('2023-01-15T00:00:00+00:00', $data['activity']['ex:a1']['prov:startTime']);
    }

    public function testSerializeAgent(): void
    {
        $builder = $this->buildDoc();
        $builder->agent('ex:ag1');

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('agent', $data);
        $this->assertArrayHasKey('ex:ag1', $data['agent']);
    }

    public function testSerializeGeneration(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('wasGeneratedBy', $data);
        $rel = $data['wasGeneratedBy']['ex:g1'];
        $this->assertSame('ex:e1', $rel['prov:entity']);
        $this->assertSame('ex:a1', $rel['prov:activity']);
    }

    public function testSerializeAllRelationTypes(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e1');
        $builder->wasInformedBy(informed: 'ex:a1', informant: 'ex:a2');
        $builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');
        $builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1');
        $builder->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2');
        $builder->wasInfluencedBy(influencee: 'ex:e1', influencer: 'ex:e2');
        $builder->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $builder->alternateOf(alternate1: 'ex:e1', alternate2: 'ex:e2');
        $builder->hadMember(collection: 'ex:c1', entity: 'ex:e1');

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('wasGeneratedBy', $data);
        $this->assertArrayHasKey('used', $data);
        $this->assertArrayHasKey('wasInformedBy', $data);
        $this->assertArrayHasKey('wasStartedBy', $data);
        $this->assertArrayHasKey('wasEndedBy', $data);
        $this->assertArrayHasKey('wasDerivedFrom', $data);
        $this->assertArrayHasKey('wasAttributedTo', $data);
        $this->assertArrayHasKey('wasAssociatedWith', $data);
        $this->assertArrayHasKey('actedOnBehalfOf', $data);
        $this->assertArrayHasKey('wasInfluencedBy', $data);
        $this->assertArrayHasKey('specializationOf', $data);
        $this->assertArrayHasKey('alternateOf', $data);
        $this->assertArrayHasKey('hadMember', $data);
    }

    public function testSerializeBundles(): void
    {
        $builder = $this->buildDoc();
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $this->assertArrayHasKey('bundle', $data);
        $this->assertArrayHasKey('ex:b1', $data['bundle']);
        $this->assertArrayHasKey('entity', $data['bundle']['ex:b1']);
    }

    public function testSerializeLiteralAttribute(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $attrVal = $data['entity']['ex:e1']['prov:type'];
        $this->assertSame('Document', $attrVal['$']);
        $this->assertSame('xsd:string', $attrVal['type']);
    }

    public function testSerializeQualifiedNameAttribute(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $this->ex->qualifiedName('MyType'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);

        $data = json_decode($this->serializer->serialize($builder->build()), true);

        $attrVal = $data['entity']['ex:e1']['prov:type'];
        $this->assertSame('ex:MyType', $attrVal['$']);
        $this->assertSame('prov:QUALIFIED_NAME', $attrVal['type']);
    }

    public function testSerializePrettyPrint(): void
    {
        $doc = $this->buildDoc()->entity('ex:e1')->build();
        $pretty = new JsonSerializer(prettyPrint: true);
        $json = $pretty->serialize($doc);

        $this->assertStringContainsString("\n", $json);
    }

    // Deserialization tests

    public function testDeserializeInvalidJsonThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('not json');
    }

    public function testDeserializeEntities(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{},"ex:e2":{}}}';
        $doc = $this->serializer->deserialize($json);

        $this->assertCount(2, $doc->entities);
    }

    public function testDeserializeActivity(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"activity":{"ex:a1":{"prov:startTime":"2023-01-15T00:00:00+00:00"}}}';
        $doc = $this->serializer->deserialize($json);

        $activities = $doc->activities;
        $this->assertCount(1, $activities);
        $this->assertSame('2023-01-15T00:00:00+00:00', $activities[0]->startTime->format(\DateTimeInterface::ATOM));
    }

    public function testDeserializeRelations(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"wasGeneratedBy":{"ex:g1":{"prov:entity":"ex:e1","prov:activity":"ex:a1"}}}';
        $doc = $this->serializer->deserialize($json);

        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        $this->assertSame('http://example.org/e1', $gens[0]->entity->uri);
        $this->assertSame('http://example.org/a1', $gens[0]->activity->uri);
    }

    public function testDeserializeBundles(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"bundle":{"ex:b1":{"entity":{"ex:e1":{}}}}}';
        $doc = $this->serializer->deserialize($json);

        $this->assertCount(1, $doc->bundles);
        $this->assertSame('http://example.org/b1', $doc->bundles[0]->identifier->uri);
        $this->assertCount(1, $doc->bundles[0]->entities);
    }

    public function testDeserializeBlankNodePreservesLabel(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"wasGeneratedBy":{"_:blank1":{"prov:entity":"ex:e1"}}}';
        $doc = $this->serializer->deserialize($json);

        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        // A blank-node identifier round-trips as a "_:" QualifiedName, preserving the
        // label so the node can be referenced, rather than collapsing to an anonymous null.
        $this->assertNotNull($gens[0]->identifier);
        $this->assertSame('_:blank1', $gens[0]->identifier->getUri());
    }

    public function testDeserializeLiteralAttribute(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"prov:type":{"$":"Document","type":"xsd:string"}}}}';
        $doc = $this->serializer->deserialize($json);

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    // Round-trip tests

    public function testRoundTripBasicDocument(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:article', ['prov:type' => Literal::string('Document')]);
        $builder->entity('ex:dataset');
        $builder->activity('ex:composing', startTime: new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $builder->agent('ex:alice');

        $builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:composing');
        $builder->used(activity: 'ex:composing', entity: 'ex:dataset');
        $builder->wasAssociatedWith(activity: 'ex:composing', agent: 'ex:alice');
        $builder->wasAttributedTo(entity: 'ex:article', agent: 'ex:alice');
        $builder->wasDerivedFrom(generatedEntity: 'ex:article', usedEntity: 'ex:dataset');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(2, $doc2->entities);
        $this->assertCount(1, $doc2->activities);
        $this->assertCount(1, $doc2->agents);
        $this->assertCount(5, $doc2->relations);
    }

    public function testRoundTripWithBundles(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');

        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e2');
        $bundleBuilder->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(1, $doc2->entities);
        $this->assertCount(1, $doc2->bundles);
        $this->assertCount(1, $doc2->bundles[0]->entities);
        $this->assertCount(1, $doc2->bundles[0]->relations);
    }

    public function testRoundTripAllRelationTypes(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(identifier: 'ex:u1', activity: 'ex:a1', entity: 'ex:e1');
        $builder->wasInformedBy(identifier: 'ex:comm1', informed: 'ex:a1', informant: 'ex:a2');
        $builder->wasStartedBy(identifier: 'ex:s1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasDerivedFrom(identifier: 'ex:d1', generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $builder->wasAttributedTo(identifier: 'ex:at1', entity: 'ex:e1', agent: 'ex:ag1');
        $builder->wasAssociatedWith(identifier: 'ex:as1', activity: 'ex:a1', agent: 'ex:ag1');
        $builder->actedOnBehalfOf(identifier: 'ex:del1', delegate: 'ex:ag1', responsible: 'ex:ag2');
        $builder->wasInfluencedBy(identifier: 'ex:inf1', influencee: 'ex:e1', influencer: 'ex:e2');
        $builder->specializationOf(identifier: 'ex:sp1', specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $builder->alternateOf(identifier: 'ex:alt1', alternate1: 'ex:e1', alternate2: 'ex:e2');
        $builder->hadMember(identifier: 'ex:mem1', collection: 'ex:c1', entity: 'ex:e1');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(13, $doc2->relations);
        $this->assertCount(1, $doc2->getRecordsByType(Generation::class));
        $this->assertCount(1, $doc2->getRecordsByType(Usage::class));
        $this->assertCount(1, $doc2->getRecordsByType(Communication::class));
        $this->assertCount(1, $doc2->getRecordsByType(Start::class));
        $this->assertCount(1, $doc2->getRecordsByType(End::class));
        $this->assertCount(1, $doc2->getRecordsByType(Derivation::class));
        $this->assertCount(1, $doc2->getRecordsByType(Attribution::class));
        $this->assertCount(1, $doc2->getRecordsByType(Association::class));
        $this->assertCount(1, $doc2->getRecordsByType(Delegation::class));
        $this->assertCount(1, $doc2->getRecordsByType(Influence::class));
        $this->assertCount(1, $doc2->getRecordsByType(Specialization::class));
        $this->assertCount(1, $doc2->getRecordsByType(Alternate::class));
        $this->assertCount(1, $doc2->getRecordsByType(Membership::class));
    }

    public function testRoundTripPreservesFormalAttributes(): void
    {
        $builder = $this->buildDoc();
        $time = new \DateTimeImmutable('2023-06-15T10:30:00+00:00');
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1', time: $time);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $gen = $doc2->getRecordsByType(Generation::class)[0];
        $this->assertSame('http://example.org/g1', $gen->identifier->uri);
        $this->assertSame('http://example.org/e1', $gen->entity->uri);
        $this->assertSame('http://example.org/a1', $gen->activity->uri);
        $this->assertSame('2023-06-15T10:30:00+00:00', $gen->time->format(\DateTimeInterface::ATOM));
    }

    public function testRoundTripPreservesActivityTimes(): void
    {
        $builder = $this->buildDoc();
        $start = new \DateTimeImmutable('2023-01-01T00:00:00+00:00');
        $end = new \DateTimeImmutable('2023-12-31T23:59:59+00:00');
        $builder->activity('ex:a1', $start, $end);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $activity = $doc2->activities[0];
        $this->assertSame('2023-01-01T00:00:00+00:00', $activity->startTime->format(\DateTimeInterface::ATOM));
        $this->assertSame('2023-12-31T23:59:59+00:00', $activity->endTime->format(\DateTimeInterface::ATOM));
    }

    public function testRoundTripActivityOnlyEndTime(): void
    {
        $builder = $this->buildDoc();
        $end = new \DateTimeImmutable('2023-12-31T23:59:59+00:00');
        $builder->activity('ex:a1', endTime: $end);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $activity = $doc2->activities[0];
        $this->assertNull($activity->startTime);
        $this->assertSame('2023-12-31T23:59:59+00:00', $activity->endTime->format(\DateTimeInterface::ATOM));
    }

    public function testRoundTripLiteralWithLanguageTag(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('label'), new Literal('Mon Article', languageTag: 'fr'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $labelVal = $data['entity']['ex:e1']['prov:label'];
        $this->assertSame('Mon Article', $labelVal['$']);
        $this->assertSame('fr', $labelVal['lang']);
        $this->assertArrayNotHasKey('type', $labelVal);

        $doc2 = $this->serializer->deserialize($json);
        $entity = $doc2->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testRoundTripMultiValueAttributes(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $key = $prov->qualifiedName('type');
        $attrs = new Attributes()
            ->with($key, Literal::string('Document'))
            ->with($key, Literal::string('Article'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $typeVal = $data['entity']['ex:e1']['prov:type'];
        $this->assertIsArray($typeVal);
        $this->assertCount(2, $typeVal);

        $doc2 = $this->serializer->deserialize($json);
        $entity = $doc2->entities[0];
        $values = $entity->attributes->get($key);
        $this->assertCount(2, $values);
    }

    public function testSerializeRelationWithAllNullFormals(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy();

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $data = json_decode($json, true);

        // A relation with all-null formals still serializes (with a blank node key).
        $this->assertArrayHasKey('wasGeneratedBy', $data);
    }

    public function testDeserializeNonObjectJsonThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('"just a string"');
    }

    public function testDeserializeMissingPrefixKeyStillWorks(): void
    {
        $json = '{"entity":{"prov:e1":{}}}';
        $doc = $this->serializer->deserialize($json);
        $this->assertCount(1, $doc->entities);
    }

    public function testSerializeDeserializeProduceValidJson(): void
    {
        $pretty = new JsonSerializer(prettyPrint: true);
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $builder->build();
        $json = $pretty->serialize($doc);
        $doc2 = $pretty->deserialize($json);
        $json2 = $pretty->serialize($doc2);

        $this->assertNotNull(json_decode($json2));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    // W3C PROV-DM spec compliance tests

    public function testRoundTripInvalidation(): void
    {
        $builder = $this->buildDoc();
        $time = new \DateTimeImmutable('2023-06-15T12:00:00+00:00');
        $builder->wasInvalidatedBy(identifier: 'ex:inv1', entity: 'ex:e1', activity: 'ex:a1', time: $time);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertArrayHasKey('wasInvalidatedBy', $data);
        $rel = $data['wasInvalidatedBy']['ex:inv1'];
        $this->assertSame('ex:e1', $rel['prov:entity']);
        $this->assertSame('ex:a1', $rel['prov:activity']);

        $doc2 = $this->serializer->deserialize($json);
        $invs = $doc2->getRecordsByType(Invalidation::class);
        $this->assertCount(1, $invs);
        $this->assertSame('http://example.org/inv1', $invs[0]->identifier->uri);
        $this->assertSame('http://example.org/e1', $invs[0]->entity->uri);
        $this->assertSame('http://example.org/a1', $invs[0]->activity->uri);
        $this->assertSame('2023-06-15T12:00:00+00:00', $invs[0]->time->format(\DateTimeInterface::ATOM));
    }

    public function testRoundTripStartWithStarter(): void
    {
        $builder = $this->buildDoc();
        $builder->wasStartedBy(
            identifier: 'ex:s1',
            activity: 'ex:a1',
            trigger: 'ex:e1',
            starter: 'ex:a2',
            time: new \DateTimeImmutable('2023-01-15T10:00:00+00:00'),
        );

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('ex:a2', $data['wasStartedBy']['ex:s1']['prov:starter']);

        $doc2 = $this->serializer->deserialize($json);
        $start = $doc2->getRecordsByType(Start::class)[0];
        $this->assertSame('http://example.org/a2', $start->starter->uri);
    }

    public function testRoundTripEndWithEnder(): void
    {
        $builder = $this->buildDoc();
        $builder->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1', trigger: 'ex:e1', ender: 'ex:a2');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('ex:a2', $data['wasEndedBy']['ex:end1']['prov:ender']);

        $doc2 = $this->serializer->deserialize($json);
        $end = $doc2->getRecordsByType(End::class)[0];
        $this->assertSame('http://example.org/a2', $end->ender->uri);
    }

    public function testDeserializeJsonNativeString(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"prov:label":"My Entity"}}}';
        $doc = $this->serializer->deserialize($json);

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testDeserializeJsonNativeBoolean(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"ex:active":true}}}';
        $doc = $this->serializer->deserialize($json);

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testDeserializeJsonNativeNumber(): void
    {
        $json = '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"ex:count":42}}}';
        $doc = $this->serializer->deserialize($json);

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testRoundTripDesignatedAttributes(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = new Attributes()
            ->with($prov->qualifiedName('type'), Literal::string('Document'))
            ->with($prov->qualifiedName('label'), Literal::string('My Document'))
            ->with($prov->qualifiedName('location'), Literal::string('http://example.org/docs'))
            ->with($prov->qualifiedName('value'), Literal::string('content'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $entityData = $data['entity']['ex:e1'];
        $this->assertArrayHasKey('prov:type', $entityData);
        $this->assertArrayHasKey('prov:label', $entityData);
        $this->assertArrayHasKey('prov:location', $entityData);
        $this->assertArrayHasKey('prov:value', $entityData);

        $doc2 = $this->serializer->deserialize($json);
        $entity = $doc2->entities[0];
        $this->assertTrue($entity->attributes->has($prov->qualifiedName('type')));
        $this->assertTrue($entity->attributes->has($prov->qualifiedName('label')));
        $this->assertTrue($entity->attributes->has($prov->qualifiedName('location')));
        $this->assertTrue($entity->attributes->has($prov->qualifiedName('value')));
    }

    public function testRoundTripDerivationSubtypeRevision(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('Revision'));

        $builder = $this->buildDoc();
        $builder->wasDerivedFrom(
            identifier: 'ex:d1',
            generatedEntity: 'ex:e2',
            usedEntity: 'ex:e1',
            attributes: $attrs,
        );

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $derivation = $data['wasDerivedFrom']['ex:d1'];
        $this->assertSame('prov:Revision', $derivation['prov:type']['$']);
        $this->assertSame('prov:QUALIFIED_NAME', $derivation['prov:type']['type']);

        $doc2 = $this->serializer->deserialize($json);
        $ders = $doc2->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertFalse($ders[0]->attributes->isEmpty());
    }

    public function testRoundTripDerivationSubtypeQuotation(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('Quotation'));

        $builder = $this->buildDoc();
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', attributes: $attrs);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $ders = $doc2->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
    }

    public function testRoundTripDerivationSubtypePrimarySource(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('PrimarySource'));

        $builder = $this->buildDoc();
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', attributes: $attrs);

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $ders = $doc2->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
    }

    public function testRoundTripAssociationWithPlanAndRole(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('role'), Literal::string('author'));

        $builder = $this->buildDoc();
        $builder->wasAssociatedWith(
            identifier: 'ex:assoc1',
            activity: 'ex:a1',
            agent: 'ex:ag1',
            plan: 'ex:plan1',
            attributes: $attrs,
        );

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $assocs = $doc2->getRecordsByType(Association::class);
        $this->assertCount(1, $assocs);
        $this->assertSame('http://example.org/plan1', $assocs[0]->plan->uri);
        $this->assertTrue($assocs[0]->attributes->has($prov->qualifiedName('role')));
    }

    public function testRoundTripAll14RelationTypes(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(identifier: 'ex:u1', activity: 'ex:a1', entity: 'ex:e1');
        $builder->wasInformedBy(identifier: 'ex:comm1', informed: 'ex:a1', informant: 'ex:a2');
        $builder->wasStartedBy(identifier: 'ex:s1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasInvalidatedBy(identifier: 'ex:inv1', entity: 'ex:e1', activity: 'ex:a1');
        $builder->wasDerivedFrom(identifier: 'ex:d1', generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $builder->wasAttributedTo(identifier: 'ex:at1', entity: 'ex:e1', agent: 'ex:ag1');
        $builder->wasAssociatedWith(identifier: 'ex:as1', activity: 'ex:a1', agent: 'ex:ag1');
        $builder->actedOnBehalfOf(identifier: 'ex:del1', delegate: 'ex:ag1', responsible: 'ex:ag2');
        $builder->wasInfluencedBy(identifier: 'ex:inf1', influencee: 'ex:e1', influencer: 'ex:e2');
        $builder->specializationOf(identifier: 'ex:sp1', specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $builder->alternateOf(identifier: 'ex:alt1', alternate1: 'ex:e1', alternate2: 'ex:e2');
        $builder->hadMember(identifier: 'ex:mem1', collection: 'ex:c1', entity: 'ex:e1');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(14, $doc2->relations);
        $this->assertCount(1, $doc2->getRecordsByType(Invalidation::class));
    }

    // Phase 2: XSD datatype round-trip tests

    public function testRoundTripXsdDouble(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => Literal::double(3.14)]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('3.14', $data['entity']['ex:e1']['ex:val']['$']);
        $this->assertSame('xsd:double', $data['entity']['ex:e1']['ex:val']['type']);

        $doc2 = $this->serializer->deserialize($json);
        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    public function testRoundTripXsdLong(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => Literal::long(9_999_999_999)]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('9999999999', $data['entity']['ex:e1']['ex:val']['$']);
        $this->assertSame('xsd:long', $data['entity']['ex:e1']['ex:val']['type']);

        $doc2 = $this->serializer->deserialize($json);
        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    public function testRoundTripXsdDecimal(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => Literal::decimal('10.5')]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('10.5', $data['entity']['ex:e1']['ex:val']['$']);
        $this->assertSame('xsd:decimal', $data['entity']['ex:e1']['ex:val']['type']);
    }

    public function testRoundTripXsdAnyURI(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => Literal::anyURI('http://example.org/foo')]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);

        $data = json_decode($json, true);
        $this->assertSame('http://example.org/foo', $data['entity']['ex:e1']['ex:val']['$']);
        $this->assertSame('xsd:anyURI', $data['entity']['ex:e1']['ex:val']['type']);
    }

    public function testRoundTripXsdShortViaGenericLiteral(): void
    {
        $xsd = \Prov\Identifier\ProvNamespace::xsd();
        $lit = new Literal('42', $xsd->qualifiedName('short'));
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => $lit]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    public function testRoundTripXsdUnsignedIntViaGenericLiteral(): void
    {
        $xsd = \Prov\Identifier\ProvNamespace::xsd();
        $lit = new Literal('255', $xsd->qualifiedName('unsignedInt'));
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => $lit]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    public function testRoundTripXsdNonNegativeIntegerViaGenericLiteral(): void
    {
        $xsd = \Prov\Identifier\ProvNamespace::xsd();
        $lit = new Literal('0', $xsd->qualifiedName('nonNegativeInteger'));
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => $lit]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    public function testRoundTripXsdByteViaGenericLiteral(): void
    {
        $xsd = \Prov\Identifier\ProvNamespace::xsd();
        $lit = new Literal('127', $xsd->qualifiedName('byte'));
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:val' => $lit]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertFalse($doc2->entities[0]->attributes->isEmpty());
    }

    // Phase 3: Mixed multi-value attribute arrays

    public function testRoundTripMultiValueMixedTypes(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $key = $prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key, Literal::string('hello'))
            ->with($key, new Literal('bye', languageTag: 'en'))
            ->with($key, new Literal('bonjour', languageTag: 'fr'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $entity = $doc2->entities[0];
        $values = $entity->attributes->get($key);
        $this->assertCount(3, $values);
    }

    public function testDeserializeMultiValueMixedNativeAndTyped(): void
    {
        $json =
            '{"prefix":{"ex":"http://example.org/","prov":"http://www.w3.org/ns/prov#"},'
            . '"entity":{"ex:e1":{"prov:label":["hello",{"$":"bye","lang":"en"},{"$":"bonjour","lang":"fr"}]}}}';
        $doc = $this->serializer->deserialize($json);

        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('label'));
        $this->assertCount(3, $values);
    }

    // Phase 4: Unicode handling

    public function testRoundTripUnicodeRightQuotationMark(): void
    {
        $this->assertUnicodeValueRoundTrips("it\u{2019}s a test");
    }

    public function testRoundTripUnicodeChinese(): void
    {
        $this->assertUnicodeValueRoundTrips("\u{4e16}\u{754c}");
    }

    public function testRoundTripUnicodeEmoji(): void
    {
        $this->assertUnicodeValueRoundTrips("\u{1F600}");
    }

    public function testRoundTripUnicodeArabic(): void
    {
        $this->assertUnicodeValueRoundTrips("\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}");
    }

    private function assertUnicodeValueRoundTrips(string $value): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string($value)]);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $this->assertStringContainsString($value, $json);

        $doc2 = $this->serializer->deserialize($json);
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $literals = $doc2->entities[0]->attributes->getLiterals($prov->qualifiedName('label'));
        $this->assertSame($value, $literals[0]->value ?? null);
    }

    // Phase 5: Document structure edge cases

    public function testRoundTripDocumentWithOnlyRelations(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e2');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(0, $doc2->entities);
        $this->assertCount(2, $doc2->relations);
    }

    public function testRoundTripDocumentWithOnlyBundles(): void
    {
        $builder = $this->buildDoc();
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(0, $doc2->records);
        $this->assertCount(1, $doc2->bundles);
        $this->assertCount(1, $doc2->bundles[0]->entities);
    }

    public function testRoundTripDocumentWithMultipleBundles(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');

        $b1 = $builder->bundle('ex:b1');
        $b1->entity('ex:be1');
        $b1->wasGeneratedBy(entity: 'ex:be1', activity: 'ex:ba1');

        $b2 = $builder->bundle('ex:b2');
        $b2->agent('ex:bag1');

        $b3 = $builder->bundle('ex:b3');
        $b3->activity('ex:ba2');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(1, $doc2->entities);
        $this->assertCount(3, $doc2->bundles);
    }

    public function testRoundTripEmptyDocument(): void
    {
        $doc = new \Prov\Builder\DocumentBuilder()->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(0, $doc2->records);
        $this->assertCount(0, $doc2->bundles);
    }

    public function testRoundTripMultipleLanguageTagsOnEntity(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $key = $prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key, new Literal('My Document', languageTag: 'en'))
            ->with($key, new Literal('Mon Document', languageTag: 'fr'))
            ->with($key, new Literal('Mein Dokument', languageTag: 'de'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $values = $doc2->entities[0]->attributes->get($key);
        $this->assertCount(3, $values);
    }

    public function testBundleWithOwnDefaultNamespace(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');

        $other = new ProvNamespace('other', 'http://other.org/');
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->addNamespace($other);
        $bundleBuilder->entity('other:be1');

        $doc = $builder->build();
        $json = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($json);

        $this->assertCount(1, $doc2->bundles);
        $this->assertSame('http://other.org/be1', $doc2->bundles[0]->entities[0]->identifier->uri);
    }

    public function testBlankNodeIdsAreDeterministicAcrossCalls(): void
    {
        $build = function (): \Prov\Document {
            $b = new DocumentBuilder();
            $b->addNamespace($this->ex);
            $b->entity();
            $b->entity();
            $b->activity();
            return $b->build();
        };

        $first = $this->serializer->serialize($build());
        $second = $this->serializer->serialize($build());
        $this->assertSame($first, $second);
        $this->assertStringContainsString('_:b1', $first);
        $this->assertStringContainsString('_:b2', $first);
        $this->assertStringContainsString('_:b3', $first);
    }

    public function testBlankNodeIdsAreStableWithinOneCall(): void
    {
        $entity = new Entity(identifier: null);
        $doc = new \Prov\Document(records: [$entity, $entity], bundles: [], namespaces: [$this->ex]);
        $json = json_decode($this->serializer->serialize($doc), true);
        $this->assertArrayHasKey('_:b1', $json['entity']);
        $this->assertCount(1, $json['entity']);
    }

    public function testDuplicateBundleIdentifierThrows(): void
    {
        $doc = $this
            ->buildDoc()
            ->withBundle('ex:bnd', static fn($bb): mixed => $bb->entity('ex:e1'))
            ->withBundle('ex:bnd', static fn($bb): mixed => $bb->entity('ex:e2'))
            ->build();

        $this->expectException(ProvException::class);
        $this->expectExceptionMessage('two bundles sharing the identifier');
        $json = $this->serializer->serialize($doc);
        $this->assertIsString($json); // Unreachable: serialize() throws above.
    }
}
