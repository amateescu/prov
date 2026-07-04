<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Serializer\JsonLdSerializer;

final class JsonLdSerializerTest extends TestCase
{
    private ProvNamespace $ex;
    private JsonLdSerializer $serializer;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->serializer = new JsonLdSerializer();
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    private function serializeToArray(DocumentBuilder $builder): array
    {
        $json = $this->serializer->serialize($builder->build());
        return json_decode($json, true);
    }

    private function getNode(array $data, string $id): ?array
    {
        $graph = $data['@graph'] ?? [$data];
        foreach ($graph as $node) {
            if (($node['@id'] ?? null) === $id) {
                return $node;
            }
        }
        return null;
    }

    // Context tests

    public function testContextIncludesNamespaces(): void
    {
        $data = $this->serializeToArray($this->buildDoc()->entity('ex:e1'));
        $this->assertSame('http://example.org/', $data['@context']['ex']);
        $this->assertSame('http://www.w3.org/ns/prov#', $data['@context']['prov']);
    }

    public function testContextAlwaysIncludesProvAndXsd(): void
    {
        $data = $this->serializeToArray(new DocumentBuilder());
        $this->assertSame('http://www.w3.org/ns/prov#', $data['@context']['prov']);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#', $data['@context']['xsd']);
    }

    public function testContextUsesVocabForDefault(): void
    {
        $builder = new DocumentBuilder();
        $builder->setDefaultNamespace(new ProvNamespace('default', 'http://default.org/'));
        $builder->entity('e1');
        $data = $this->serializeToArray($builder);
        $this->assertSame('http://default.org/', $data['@context']['@vocab']);
    }

    // Element tests

    public function testEntityNode(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertNotNull($node);
        $this->assertSame('prov:Entity', $node['@type']);
    }

    public function testActivityNode(): void
    {
        $builder = $this->buildDoc();
        $start = new \DateTimeImmutable('2023-01-15T00:00:00+00:00');
        $end = new \DateTimeImmutable('2023-12-31T23:59:59+00:00');
        $builder->activity('ex:a1', $start, $end);
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $this->assertSame('prov:Activity', $node['@type']);
        $this->assertSame('2023-01-15T00:00:00+00:00', $node['prov:startedAtTime']['@value']);
        $this->assertSame('xsd:dateTime', $node['prov:startedAtTime']['@type']);
        $this->assertSame('2023-12-31T23:59:59+00:00', $node['prov:endedAtTime']['@value']);
    }

    public function testAgentNode(): void
    {
        $builder = $this->buildDoc();
        $builder->agent('ex:ag1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:ag1');
        $this->assertSame('prov:Agent', $node['@type']);
    }

    public function testEntityWithAttributes(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('Document', $node['prov:type']['@value']);
    }

    public function testAnonymousEntityGetsBlankIdInsteadOfVanishing(): void
    {
        // An identifier-less entity cannot be referenced by any relation (a
        // formal endpoint needs a QualifiedName to point at), but its own
        // attributes must still reach the output; JSON-LD represents an
        // anonymous node fine.
        $builder = $this->buildDoc();
        $builder->entity(null, ['prov:label' => 'orphan']);
        $data = $this->serializeToArray($builder);

        $this->assertSame('prov:Entity', $data['@type']);
        $this->assertSame('_:b1', $data['@id']);
        $this->assertSame('orphan', $data['prov:label']);
    }

    // Unqualified relation tests (no identifier, no extra attrs, no time)

    public function testUnqualifiedGeneration(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->activity('ex:a1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:a1', $node['prov:wasGeneratedBy']['@id']);
    }

    public function testUnqualifiedUsage(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->entity('ex:e1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $this->assertSame('ex:e1', $node['prov:used']['@id']);
    }

    public function testUnqualifiedAttribution(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->agent('ex:ag1');
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:ag1', $node['prov:wasAttributedTo']['@id']);
    }

    public function testUnqualifiedDerivation(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e2');
        $this->assertSame('ex:e1', $node['prov:wasDerivedFrom']['@id']);
    }

    public function testUnqualifiedCommunication(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->activity('ex:a2');
        $builder->wasInformedBy(informed: 'ex:a2', informant: 'ex:a1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a2');
        $this->assertSame('ex:a1', $node['prov:wasInformedBy']['@id']);
    }

    public function testUnqualifiedAssociation(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->agent('ex:ag1');
        $builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $this->assertSame('ex:ag1', $node['prov:wasAssociatedWith']['@id']);
    }

    public function testUnqualifiedDelegation(): void
    {
        $builder = $this->buildDoc();
        $builder->agent('ex:ag1');
        $builder->agent('ex:ag2');
        $builder->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:ag1');
        $this->assertSame('ex:ag2', $node['prov:actedOnBehalfOf']['@id']);
    }

    public function testUnqualifiedInfluence(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->wasInfluencedBy(influencee: 'ex:e1', influencer: 'ex:e2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:e2', $node['prov:wasInfluencedBy']['@id']);
    }

    public function testUnqualifiedStart(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->entity('ex:e1');
        $builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $this->assertSame('ex:e1', $node['prov:wasStartedBy']['@id']);
    }

    public function testUnqualifiedEnd(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->entity('ex:e1');
        $builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $this->assertSame('ex:e1', $node['prov:wasEndedBy']['@id']);
    }

    public function testUnqualifiedInvalidation(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->activity('ex:a1');
        $builder->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:a1', $node['prov:wasInvalidatedBy']['@id']);
    }

    // Non-qualifiable binary relations

    public function testSpecializationOf(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:e2', $node['prov:specializationOf']['@id']);
    }

    public function testAlternateOf(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->alternateOf(alternate1: 'ex:e1', alternate2: 'ex:e2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('ex:e2', $node['prov:alternateOf']['@id']);
    }

    public function testHadMember(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:c1');
        $builder->entity('ex:e1');
        $builder->hadMember(collection: 'ex:c1', entity: 'ex:e1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:c1');
        $this->assertSame('ex:e1', $node['prov:hadMember']['@id']);
    }

    // Qualified relation tests

    public function testQualifiedGenerationWithTime(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasGeneratedBy(
            entity: 'ex:e1',
            activity: 'ex:a1',
            time: new \DateTimeImmutable('2023-06-15T10:00:00+00:00'),
        );
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $qGen = $node['prov:qualifiedGeneration'];
        $this->assertSame('prov:Generation', $qGen['@type']);
        $this->assertSame('ex:a1', $qGen['prov:activity']['@id']);
        $this->assertSame('2023-06-15T10:00:00+00:00', $qGen['prov:atTime']['@value']);
    }

    public function testQualifiedGenerationWithIdentifier(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $qGen = $node['prov:qualifiedGeneration'];
        $this->assertSame('ex:g1', $qGen['@id']);
        $this->assertSame('prov:Generation', $qGen['@type']);
    }

    public function testQualifiedUsageWithTime(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e1', time: new \DateTimeImmutable('2023-06-15T10:00:00+00:00'));
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $qUsage = $node['prov:qualifiedUsage'];
        $this->assertSame('prov:Usage', $qUsage['@type']);
        $this->assertSame('ex:e1', $qUsage['prov:entity']['@id']);
        $this->assertSame('2023-06-15T10:00:00+00:00', $qUsage['prov:atTime']['@value']);
    }

    public function testQualifiedDerivationWithActivity(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e2');
        $builder->wasDerivedFrom(
            generatedEntity: 'ex:e2',
            usedEntity: 'ex:e1',
            activity: 'ex:a1',
            generation: 'ex:g1',
            usage: 'ex:u1',
        );
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e2');
        $qDer = $node['prov:qualifiedDerivation'];
        $this->assertSame('prov:Derivation', $qDer['@type']);
        $this->assertSame('ex:e1', $qDer['prov:entity']['@id']);
        $this->assertSame('ex:a1', $qDer['prov:hadActivity']['@id']);
        $this->assertSame('ex:g1', $qDer['prov:hadGeneration']['@id']);
        $this->assertSame('ex:u1', $qDer['prov:hadUsage']['@id']);
    }

    public function testQualifiedAssociationWithPlan(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1', plan: 'ex:plan1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $qAssoc = $node['prov:qualifiedAssociation'];
        $this->assertSame('prov:Association', $qAssoc['@type']);
        $this->assertSame('ex:ag1', $qAssoc['prov:agent']['@id']);
        $this->assertSame('ex:plan1', $qAssoc['prov:hadPlan']['@id']);
    }

    public function testQualifiedDelegationWithActivity(): void
    {
        $builder = $this->buildDoc();
        $builder->agent('ex:ag1');
        $builder->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2', activity: 'ex:a1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:ag1');
        $qDel = $node['prov:qualifiedDelegation'];
        $this->assertSame('prov:Delegation', $qDel['@type']);
        $this->assertSame('ex:ag2', $qDel['prov:agent']['@id']);
        $this->assertSame('ex:a1', $qDel['prov:hadActivity']['@id']);
    }

    public function testQualifiedStartWithStarter(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->wasStartedBy(
            activity: 'ex:a1',
            trigger: 'ex:e1',
            starter: 'ex:a2',
            time: new \DateTimeImmutable('2023-01-15T10:00:00+00:00'),
        );
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $qStart = $node['prov:qualifiedStart'];
        $this->assertSame('prov:Start', $qStart['@type']);
        $this->assertSame('ex:e1', $qStart['prov:entity']['@id']);
        $this->assertSame('ex:a2', $qStart['prov:hadActivity']['@id']);
        $this->assertSame('2023-01-15T10:00:00+00:00', $qStart['prov:atTime']['@value']);
    }

    public function testQualifiedEndWithEnder(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1');
        $builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1', ender: 'ex:a2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:a1');
        $qEnd = $node['prov:qualifiedEnd'];
        $this->assertSame('prov:End', $qEnd['@type']);
        $this->assertSame('ex:e1', $qEnd['prov:entity']['@id']);
        $this->assertSame('ex:a2', $qEnd['prov:hadActivity']['@id']);
    }

    public function testQualifiedInvalidationWithTime(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasInvalidatedBy(
            entity: 'ex:e1',
            activity: 'ex:a1',
            time: new \DateTimeImmutable('2023-06-15T12:00:00+00:00'),
        );
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $qInv = $node['prov:qualifiedInvalidation'];
        $this->assertSame('prov:Invalidation', $qInv['@type']);
        $this->assertSame('ex:a1', $qInv['prov:activity']['@id']);
        $this->assertSame('2023-06-15T12:00:00+00:00', $qInv['prov:atTime']['@value']);
    }

    public function testQualifiedRelationWithExtraAttributes(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('Revision'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e2');
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', attributes: $attrs);
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e2');
        $qDer = $node['prov:qualifiedDerivation'];
        $this->assertSame('prov:Derivation', $qDer['@type']);
        $this->assertSame('prov:Revision', $qDer['prov:type']['@id']);
    }

    // Mention (cross-bundle reference)

    public function testMentionWithBundleShape(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:specific');
        $builder->mentionOf(specificEntity: 'ex:specific', generalEntity: 'ex:general', bundle: 'ex:b1');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:specific');
        $this->assertSame(
            [
                'prov:asInBundle' => ['@id' => 'ex:b1'],
                'prov:mentionOf' => ['@id' => 'ex:general'],
            ],
            $node['prov:mentionOf'],
        );
    }

    public function testMentionWithoutBundleIsPlainReference(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:specific');
        $builder->mentionOf(specificEntity: 'ex:specific', generalEntity: 'ex:general');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:specific');
        $this->assertSame(['@id' => 'ex:general'], $node['prov:mentionOf']);
    }

    // Multi-value relations

    public function testMultipleRelationsOnSameSubject(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a2');
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertIsArray($node['prov:wasGeneratedBy']);
        $this->assertCount(2, $node['prov:wasGeneratedBy']);
    }

    // Bundle tests

    public function testBundle(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');

        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e2');
        $bundleBuilder->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1');

        $data = $this->serializeToArray($builder);

        $bundleNode = $this->getNode($data, 'ex:b1');
        $this->assertNotNull($bundleNode);
        $this->assertSame('prov:Bundle', $bundleNode['@type']);
        $this->assertArrayHasKey('@graph', $bundleNode);

        $bundleGraph = $bundleNode['@graph'];
        $entityNode = null;
        foreach ($bundleGraph as $node) {
            if (($node['@id'] ?? null) === 'ex:e2') {
                $entityNode = $node;
                break;
            }
        }
        $this->assertNotNull($entityNode);
        $this->assertSame('prov:Entity', $entityNode['@type']);
    }

    // Output format tests

    public function testOutputIsValidJson(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $json = $this->serializer->serialize($builder->build());
        $this->assertNotNull(json_decode($json));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
    }

    public function testPrettyPrint(): void
    {
        $pretty = new JsonLdSerializer(prettyPrint: true);
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');

        $json = $pretty->serialize($builder->build());
        $this->assertStringContainsString("\n", $json);
    }

    // Full document test

    public function testFullProvDocument(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:article', ['prov:type' => Literal::string('Document')]);
        $builder->entity('ex:dataset');
        $builder->activity('ex:composing', startTime: new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $builder->agent('ex:alice', ['prov:type' => Literal::string('Person')]);

        $builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:composing');
        $builder->used(activity: 'ex:composing', entity: 'ex:dataset');
        $builder->wasAssociatedWith(activity: 'ex:composing', agent: 'ex:alice');
        $builder->wasAttributedTo(entity: 'ex:article', agent: 'ex:alice');
        $builder->wasDerivedFrom(generatedEntity: 'ex:article', usedEntity: 'ex:dataset');

        $data = $this->serializeToArray($builder);

        $this->assertArrayHasKey('@context', $data);
        $this->assertArrayHasKey('@graph', $data);

        $article = $this->getNode($data, 'ex:article');
        $this->assertSame('prov:Entity', $article['@type']);
        $this->assertSame('ex:composing', $article['prov:wasGeneratedBy']['@id']);
        $this->assertSame('ex:alice', $article['prov:wasAttributedTo']['@id']);
        $this->assertSame('ex:dataset', $article['prov:wasDerivedFrom']['@id']);

        $composing = $this->getNode($data, 'ex:composing');
        $this->assertSame('prov:Activity', $composing['@type']);
        $this->assertSame('ex:dataset', $composing['prov:used']['@id']);
        $this->assertSame('ex:alice', $composing['prov:wasAssociatedWith']['@id']);
    }

    public function testLiteralWithLanguageTag(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('label'), new Literal('Mon Article', languageTag: 'fr'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $data = $this->serializeToArray($builder);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertSame('Mon Article', $node['prov:label']['@value']);
        $this->assertSame('fr', $node['prov:label']['@language']);
    }
}
