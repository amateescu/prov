<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\ProvException;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Alternate;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Dictionary\DictionaryRemoval;
use Prov\Relation\Membership;
use Prov\Relation\Mention;
use Prov\Relation\Specialization;
use Prov\Serializer\JsonLdSerializer;

/**
 * PROV-JSONLD must encode the RDF graph the W3C ontologies define, and it must
 * fail loudly rather than drop a record or a populated field it cannot encode.
 */
final class JsonLdConformanceTest extends TestCase
{
    private ProvNamespace $ex;
    private JsonLdSerializer $serializer;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->serializer = new JsonLdSerializer();
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return array<string, mixed>
     */
    private function serializeRecords(array $records): array
    {
        $document = new Document($records, [], [$this->ex]);
        $data = json_decode($this->serializer->serialize($document), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function node(array $data, string $id): array
    {
        $graph = isset($data['@graph']) ? $data['@graph'] : [$data];
        foreach ($graph as $node) {
            if (($node['@id'] ?? null) === $id) {
                return $node;
            }
        }
        $this->fail("No node '{$id}' in the JSON-LD graph.");
    }

    // R2: Mention

    public function testMentionAttachesBothPropertiesToTheSpecificEntity(): void
    {
        $data = $this->serializeRecords([
            new Entity($this->ex->qualifiedName('specific')),
            new Mention(
                identifier: null,
                specificEntity: $this->ex->qualifiedName('specific'),
                generalEntity: $this->ex->qualifiedName('general'),
                bundle: $this->ex->qualifiedName('b1'),
            ),
        ]);

        $node = $this->node($data, 'ex:specific');
        $this->assertSame(['@id' => 'ex:general'], $node['prov:mentionOf']);
        $this->assertSame(['@id' => 'ex:b1'], $node['prov:asInBundle']);
    }

    public function testMentionWithoutBundleEmitsOnlyMentionOf(): void
    {
        $data = $this->serializeRecords([
            new Entity($this->ex->qualifiedName('specific')),
            new Mention(
                identifier: null,
                specificEntity: $this->ex->qualifiedName('specific'),
                generalEntity: $this->ex->qualifiedName('general'),
            ),
        ]);

        $node = $this->node($data, 'ex:specific');
        $this->assertSame(['@id' => 'ex:general'], $node['prov:mentionOf']);
        $this->assertArrayNotHasKey('prov:asInBundle', $node);
    }

    public function testTwoMentionsOnOneSubjectKeepBothPairs(): void
    {
        $data = $this->serializeRecords([
            new Entity($this->ex->qualifiedName('specific')),
            new Mention(
                identifier: null,
                specificEntity: $this->ex->qualifiedName('specific'),
                generalEntity: $this->ex->qualifiedName('g1'),
                bundle: $this->ex->qualifiedName('b1'),
            ),
            new Mention(
                identifier: null,
                specificEntity: $this->ex->qualifiedName('specific'),
                generalEntity: $this->ex->qualifiedName('g2'),
                bundle: $this->ex->qualifiedName('b2'),
            ),
        ]);

        $node = $this->node($data, 'ex:specific');
        $this->assertSame([['@id' => 'ex:g1'], ['@id' => 'ex:g2']], $node['prov:mentionOf']);
        $this->assertSame([['@id' => 'ex:b1'], ['@id' => 'ex:b2']], $node['prov:asInBundle']);
    }

    // R3: PROV-Dictionary

    public function testDictionaryMembershipEmitsKeyEntityPairs(): void
    {
        $data = $this->serializeRecords([
            new Entity($this->ex->qualifiedName('d1')),
            new DictionaryMembership(identifier: null, dictionary: $this->ex->qualifiedName('d1'), keyEntityPairs: [
                new DictionaryEntry('k1', $this->ex->qualifiedName('v1')),
                new DictionaryEntry(
                    new Literal('7', ProvNamespace::xsd()->qualifiedName('int')),
                    $this->ex->qualifiedName('v2'),
                ),
            ]),
        ]);

        $node = $this->node($data, 'ex:d1');
        $this->assertSame(
            [
                [
                    '@type' => 'prov:KeyEntityPair',
                    'prov:pairKey' => 'k1',
                    'prov:pairEntity' => ['@id' => 'ex:v1'],
                ],
                [
                    '@type' => 'prov:KeyEntityPair',
                    'prov:pairKey' => ['@value' => '7', '@type' => 'xsd:int'],
                    'prov:pairEntity' => ['@id' => 'ex:v2'],
                ],
            ],
            $node['prov:hadDictionaryMember'],
        );
    }

    public function testDictionaryMembershipAcceptsQualifiedNameKeys(): void
    {
        $undeclared = new ProvNamespace('un', 'http://undeclared.example/');
        $data = $this->serializeRecords([
            new DictionaryMembership(
                identifier: null,
                dictionary: $this->ex->qualifiedName('d1'),
                keyEntityPairs: [new DictionaryEntry($undeclared->qualifiedName('k1'), null)],
            ),
        ]);

        $node = $this->node($data, 'ex:d1');
        $pair = $node['prov:hadDictionaryMember'];
        $this->assertSame('prov:KeyEntityPair', $pair['@type']);
        $this->assertArrayNotHasKey('prov:pairEntity', $pair);
        $prefix = strstr((string) $pair['prov:pairKey']['@id'], ':', true);
        $this->assertIsString($prefix);
        $this->assertSame('http://undeclared.example/', $data['@context'][$prefix]);
    }

    public function testDictionaryInsertionEmitsQualifiedInsertion(): void
    {
        $data = $this->serializeRecords([
            new DictionaryInsertion(
                identifier: $this->ex->qualifiedName('ins1'),
                after: $this->ex->qualifiedName('d2'),
                before: $this->ex->qualifiedName('d1'),
                keyEntityPairs: [
                    new DictionaryEntry('k1', $this->ex->qualifiedName('v1')),
                    new DictionaryEntry('k2', $this->ex->qualifiedName('v2')),
                ],
                attributes: Attributes::single(ProvNamespace::prov()->qualifiedName('label'), 'inserted'),
            ),
        ]);

        $node = $this->node($data, 'ex:d2');
        $insertion = $node['prov:qualifiedInsertion'];
        $this->assertSame('prov:Insertion', $insertion['@type']);
        $this->assertSame('ex:ins1', $insertion['@id']);
        $this->assertSame('inserted', $insertion['prov:label']);
        $this->assertSame(['@id' => 'ex:d1'], $insertion['prov:dictionary']);
        $this->assertCount(2, $insertion['prov:insertedKeyEntityPair']);
        $this->assertSame(
            ['@type' => 'prov:KeyEntityPair', 'prov:pairKey' => 'k1', 'prov:pairEntity' => ['@id' => 'ex:v1']],
            $insertion['prov:insertedKeyEntityPair'][0],
        );
    }

    public function testDictionaryRemovalEmitsQualifiedRemoval(): void
    {
        $data = $this->serializeRecords([
            new DictionaryRemoval(
                identifier: $this->ex->qualifiedName('rem1'),
                after: $this->ex->qualifiedName('d2'),
                before: $this->ex->qualifiedName('d1'),
                removedKeys: ['k1', new Literal('7', ProvNamespace::xsd()->qualifiedName('int'))],
                attributes: Attributes::single(ProvNamespace::prov()->qualifiedName('label'), 'removed'),
            ),
        ]);

        $node = $this->node($data, 'ex:d2');
        $removal = $node['prov:qualifiedRemoval'];
        $this->assertSame('prov:Removal', $removal['@type']);
        $this->assertSame('ex:rem1', $removal['@id']);
        $this->assertSame('removed', $removal['prov:label']);
        $this->assertSame(['@id' => 'ex:d1'], $removal['prov:dictionary']);
        $this->assertSame(['k1', ['@value' => '7', '@type' => 'xsd:int']], $removal['prov:removedKey']);
    }

    public function testDictionaryRelationsAreNotDroppedFromTheGraph(): void
    {
        $json = $this->serializer->serialize(
            new Document(
                [
                    new DictionaryMembership(
                        identifier: null,
                        dictionary: $this->ex->qualifiedName('d1'),
                        keyEntityPairs: [new DictionaryEntry('k1', $this->ex->qualifiedName('v1'))],
                    ),
                ],
                [],
                [$this->ex],
            ),
        );

        $this->assertStringContainsString('prov:hadDictionaryMember', $json);
    }

    // R5: metadata the format cannot preserve

    /**
     * @return iterable<string, list<\Prov\Model\ProvRelation>>
     */
    public static function relationsWithoutQualifiedForm(): iterable
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $id = $ex->qualifiedName('r1');
        $attrs = Attributes::single(ProvNamespace::prov()->qualifiedName('label'), 'x');

        yield 'specialization id' => [new Specialization($id, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'))];
        yield 'specialization attributes' => [
            new Specialization(null, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'), $attrs),
        ];
        yield 'alternate id' => [new Alternate($id, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'))];
        yield 'alternate attributes' => [
            new Alternate(null, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'), $attrs),
        ];
        yield 'membership id' => [new Membership($id, $ex->qualifiedName('c1'), $ex->qualifiedName('e1'))];
        yield 'membership attributes' => [
            new Membership(null, $ex->qualifiedName('c1'), $ex->qualifiedName('e1'), $attrs),
        ];
        yield 'mention id' => [
            new Mention($id, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'), $ex->qualifiedName('b1')),
        ];
        yield 'mention attributes' => [
            new Mention(null, $ex->qualifiedName('e1'), $ex->qualifiedName('e2'), $ex->qualifiedName('b1'), $attrs),
        ];
        yield 'dictionary membership id' => [new DictionaryMembership($id, $ex->qualifiedName('d1'))];
        yield 'dictionary membership attributes' => [
            new DictionaryMembership(null, $ex->qualifiedName('d1'), [], $attrs),
        ];
    }

    /**
     * @param \Prov\Model\ProvRelation $relation
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('relationsWithoutQualifiedForm')]
    public function testUnrepresentableMetadataThrows(\Prov\Model\ProvRelation $relation): void
    {
        try {
            $json = $this->serializer->serialize(new Document([$relation], [], [$this->ex]));
        } catch (ProvException $e) {
            $this->assertStringContainsString('PROV-JSONLD cannot represent', $e->getMessage());
            return;
        }
        $this->fail('Expected a ProvException; the serializer produced: ' . $json);
    }

    public function testRelationWithoutQualifiedFormStillSerializesWhenBare(): void
    {
        $data = $this->serializeRecords([
            new Specialization(null, $this->ex->qualifiedName('e1'), $this->ex->qualifiedName('e2')),
        ]);

        $node = $this->node($data, 'ex:e1');
        $this->assertSame(['@id' => 'ex:e2'], $node['prov:specializationOf']);
    }

    public function testBuilderDocumentWithDictionaryRelationsRoundTripsThroughJsonLd(): void
    {
        $document = new DocumentBuilder()
            ->addNamespace($this->ex)
            ->entity('ex:d1')
            ->entity('ex:d2')
            ->derivedByInsertionFrom(after: 'ex:d2', before: 'ex:d1', keyEntityPairs: [new DictionaryEntry(
                'k',
                new QualifiedName($this->ex, 'v1'),
            )])
            ->build();

        $data = json_decode($this->serializer->serialize($document), true);
        $this->assertIsArray($data);
        $node = $this->node($data, 'ex:d2');
        $this->assertArrayHasKey('prov:qualifiedInsertion', $node);
    }
}
