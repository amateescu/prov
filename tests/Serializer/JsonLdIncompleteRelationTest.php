<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\ProvException;
use Prov\Identifier\ProvNamespace;
use Prov\Model\ProvRelation;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Influence;
use Prov\Relation\Membership;
use Prov\Serializer\JsonLdSerializer;

/**
 * The model allows a relation with missing formals, JSON-LD attaches a relation
 * to its subject node, and PROV-O gives five relations no qualified form. Where
 * those meet, the serializer either writes a shape that keeps everything or
 * fails; it never drops content silently.
 */
final class JsonLdIncompleteRelationTest extends TestCase
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
    private function serializeToArray(array $records): array
    {
        $document = new Document($records, [], [$this->ex]);
        /** @var array<string, mixed> */
        return json_decode($this->serializer->serialize($document), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function getNode(array $data, string $id): ?array
    {
        /** @var list<array<string, mixed>> $graph */
        $graph = $data['@graph'] ?? [$data];
        foreach ($graph as $node) {
            if (($node['@id'] ?? null) === $id) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @return iterable<string, array{\Prov\Model\ProvRelation}>
     */
    public static function subjectLessRelationsWithContent(): iterable
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');

        yield 'influence with identifier, influencer and attributes' => [
            new Influence(
                identifier: $ex->qualifiedName('inf1'),
                influencee: null,
                influencer: $ex->qualifiedName('cause'),
                attributes: Attributes::single(ProvNamespace::prov()->qualifiedName('label'), 'caused'),
            ),
        ];
        yield 'derivation with a used entity' => [
            new Derivation(identifier: null, generatedEntity: null, usedEntity: $ex->qualifiedName('e1')),
        ];
        yield 'derivation with attributes only' => [
            new Derivation(
                identifier: null,
                generatedEntity: null,
                usedEntity: null,
                attributes: Attributes::single(ProvNamespace::prov()->qualifiedName('label'), 'orphan'),
            ),
        ];
        yield 'membership with a member entity' => [
            new Membership(identifier: null, collection: null, entity: $ex->qualifiedName('e1')),
        ];
    }

    #[DataProvider('subjectLessRelationsWithContent')]
    public function testSubjectLessRelationWithContentThrows(ProvRelation $relation): void
    {
        $this->assertSerializationFails($relation, 'has no subject');
    }

    public function testSubjectPresentDerivationWithoutUsedEntityKeepsAQualifiedNode(): void
    {
        $data = $this->serializeToArray([
            new Entity($this->ex->qualifiedName('e2')),
            new Derivation(identifier: null, generatedEntity: $this->ex->qualifiedName('e2'), usedEntity: null),
        ]);

        $node = $this->getNode($data, 'ex:e2');
        $this->assertNotNull($node);
        $this->assertSame(['@type' => 'prov:Derivation'], $node['prov:qualifiedDerivation'] ?? null);
        $this->assertArrayNotHasKey('prov:wasDerivedFrom', $node);
    }

    public function testSubjectPresentInfluenceWithoutInfluencerKeepsAQualifiedNode(): void
    {
        $data = $this->serializeToArray([
            new Entity($this->ex->qualifiedName('e1')),
            new Influence(identifier: $this->ex->qualifiedName('inf1'), influencee: $this->ex->qualifiedName('e1')),
        ]);

        $node = $this->getNode($data, 'ex:e1');
        $this->assertNotNull($node);
        $this->assertSame(['@type' => 'prov:Influence', '@id' => 'ex:inf1'], $node['prov:qualifiedInfluence'] ?? null);
    }

    /**
     * @return iterable<string, array{\Prov\Model\ProvRelation}>
     */
    public static function objectLessRelationsWithoutQualifiedForm(): iterable
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');

        yield 'membership without a member entity' => [
            new Membership(identifier: null, collection: $ex->qualifiedName('c1'), entity: null),
        ];
        yield 'dictionary membership without key-entity pairs' => [
            new DictionaryMembership(identifier: null, dictionary: $ex->qualifiedName('d1'), keyEntityPairs: []),
        ];
    }

    #[DataProvider('objectLessRelationsWithoutQualifiedForm')]
    public function testObjectLessRelationWithoutQualifiedFormThrows(ProvRelation $relation): void
    {
        $this->assertSerializationFails($relation, 'no qualified form');
    }

    /**
     * Serializes a one-relation document and requires a ProvException whose
     * message contains `$expectedMessage`.
     */
    private function assertSerializationFails(ProvRelation $relation, string $expectedMessage): void
    {
        try {
            $json = $this->serializer->serialize(new Document([$relation], [], [$this->ex]));
        } catch (ProvException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            return;
        }
        $this->fail('Expected a ProvException; the serializer produced: ' . $json);
    }

    public function testFullyEmptyRelationCarriesNothingAndIsDropped(): void
    {
        $data = $this->serializeToArray([new Derivation()]);

        $this->assertSame([], $data['@graph'] ?? null);
    }
}
