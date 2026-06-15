<?php

declare(strict_types=1);

namespace Prov\Tests\Model;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Model\EntityInvolvement;
use Prov\Relation\Dictionary\DictionaryEntry;

final class EntityInvolvementTest extends TestCase
{
    private ProvNamespace $ex;
    private DocumentBuilder $builder;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->builder = new DocumentBuilder();
        $this->builder->addNamespace($this->ex);
    }

    /**
     * @return list<array{string, string, string}>
     *   [relationType, role, entity local part] tuples, in iteration order.
     */
    private function tuples(DocumentBuilder $builder): array
    {
        $out = [];
        foreach ($builder->build()->entityInvolvements() as $involvement) {
            self::assertInstanceOf(EntityInvolvement::class, $involvement);
            $out[] = [$involvement->relationType, $involvement->role, $involvement->entity->localPart];
        }
        return $out;
    }

    public function testGenerationReportsEntityEndpointOnly(): void
    {
        $this->builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        // The activity endpoint is excluded; only the entity is involved.
        $this->assertSame([['wasGeneratedBy', 'entity', 'e1']], $this->tuples($this->builder));
    }

    public function testSpecializationReportsBothEntitiesWithDistinctRoles(): void
    {
        $this->builder->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e0');

        $this->assertSame(
            [
                ['specializationOf', 'specificEntity', 'e1'],
                ['specializationOf', 'generalEntity',  'e0'],
            ],
            $this->tuples($this->builder),
        );
    }

    public function testDerivationReportsGeneratedAndUsedRoles(): void
    {
        $this->builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');

        $this->assertSame(
            [
                ['wasDerivedFrom', 'generatedEntity', 'e2'],
                ['wasDerivedFrom', 'usedEntity',      'e1'],
            ],
            $this->tuples($this->builder),
        );
    }

    public function testRevisionReportsSubtypeAsRelationType(): void
    {
        $this->builder->wasRevisionOf(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');

        // The injected prov:type promotes the label from wasDerivedFrom to its subtype.
        $this->assertSame(
            [
                ['wasRevisionOf', 'generatedEntity', 'e2'],
                ['wasRevisionOf', 'usedEntity',      'e1'],
            ],
            $this->tuples($this->builder),
        );
    }

    public function testAssociationReportsPlanButNotAgentOrActivity(): void
    {
        $this->builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1', plan: 'ex:plan1');

        // Of the three endpoints only the plan is entity-typed.
        $this->assertSame([['wasAssociatedWith', 'plan', 'plan1']], $this->tuples($this->builder));
    }

    public function testAttributionReportsEntityButNotAgent(): void
    {
        $this->builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');

        $this->assertSame([['wasAttributedTo', 'entity', 'e1']], $this->tuples($this->builder));
    }

    public function testDictionaryMemberEntitiesUseKeyEntityRole(): void
    {
        $this->builder->hadDictionaryMember(dictionary: 'ex:d1', keyEntityPairs: [
            new DictionaryEntry('k1', $this->ex->qualifiedName('e1')),
            new DictionaryEntry('k2', $this->ex->qualifiedName('e2')),
        ]);

        $this->assertSame(
            [
                ['hadDictionaryMember', 'dictionary', 'd1'],
                ['hadDictionaryMember', 'keyEntity',  'e1'],
                ['hadDictionaryMember', 'keyEntity',  'e2'],
            ],
            $this->tuples($this->builder),
        );
    }

    public function testCoversOwnRelationsAcrossSeveralRecords(): void
    {
        $this->builder
            ->entity('ex:e1')
            ->activity('ex:a1')
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1')
            ->used(activity: 'ex:a1', entity: 'ex:e0');

        // Elements contribute nothing; both relations contribute their entity endpoint.
        $this->assertSame(
            [
                ['wasGeneratedBy', 'entity', 'e1'],
                ['used',           'entity', 'e0'],
            ],
            $this->tuples($this->builder),
        );
    }

    public function testDocumentDoesNotDescendIntoBundles(): void
    {
        $this->builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $this->builder->withBundle('ex:b1', static function ($bundle): void {
            $bundle->used(activity: 'ex:a1', entity: 'ex:e2');
        });

        $document = $this->builder->build();

        // The document sees only its own relation.
        $this->assertSame(
            [['wasGeneratedBy', 'entity', 'e1']],
            array_map(static fn(EntityInvolvement $i): array => [
                $i->relationType,
                $i->role,
                $i->entity->localPart,
            ], $document->entityInvolvements()),
        );

        // The bundle reports its own.
        $this->assertSame(
            [['used', 'entity', 'e2']],
            array_map(static fn(EntityInvolvement $i): array => [
                $i->relationType,
                $i->role,
                $i->entity->localPart,
            ], $document->bundles[0]->entityInvolvements()),
        );
    }
}
