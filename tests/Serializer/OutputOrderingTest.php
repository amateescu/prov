<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNSerializer;

final class OutputOrderingTest extends TestCase
{
    /**
     * @return list<string>
     *   The document's top-level keys, minus the prefix block.
     */
    private function sectionKeys(array $decoded): array
    {
        return array_values(array_filter(array_keys($decoded), static fn(string $k): bool => $k !== 'prefix'));
    }

    public function testPrefixesSortBuiltinsFirstThenAlphabetically(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('zeta', 'http://z.example/');
        $builder->namespace('alpha', 'http://a.example/');
        $builder->namespace('mid', 'http://m.example/');
        $builder->keepUnusedNamespaces();

        $data = json_decode(new JsonSerializer()->serialize($builder->build()), true);

        // prov (and xsd, kept by keepUnusedNamespaces) lead; the rest are alphabetical.
        $this->assertSame(['prov', 'xsd', 'alpha', 'mid', 'zeta'], array_keys($data['prefix']));
    }

    public function testProvNPrefixesSortBuiltinsFirstThenAlphabetically(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('zeta', 'http://z.example/');
        $builder->namespace('alpha', 'http://a.example/');
        $builder->keepUnusedNamespaces();

        $output = new ProvNSerializer()->serialize($builder->build());

        $prefixes = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\s+prefix (\S+) /', $line, $m) === 1) {
                $prefixes[] = $m[1];
            }
        }

        // PROV-N binds prov and xsd implicitly and forbids redeclaring them, so
        // only the caller's own declarations appear, still ordered by prefix.
        $this->assertSame(['alpha', 'zeta'], $prefixes);
    }

    public function testRecordsKeepInsertionOrderByDefault(): void
    {
        $data = json_decode(new JsonSerializer()->serialize($this->mixedDocument()), true);

        // Default: sections appear in the order the records were added.
        $this->assertSame(['wasGeneratedBy', 'agent', 'activity', 'entity'], $this->sectionKeys($data));
    }

    public function testSortRecordsOrdersElementsThenRelationsInProvDmOrder(): void
    {
        $data = json_decode(new JsonSerializer(sortRecords: true)->serialize($this->mixedDocument()), true);

        // Elements first (entity, activity, agent), then relations in PROV-DM order.
        $this->assertSame(['entity', 'activity', 'agent', 'wasGeneratedBy'], $this->sectionKeys($data));
    }

    public function testSortRecordsSortsIdentifiersWithinASection(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->entity('ex:e3')->entity('ex:e1')->entity('ex:e2');

        $data = json_decode(new JsonSerializer(sortRecords: true)->serialize($builder->build()), true);

        $this->assertSame(['ex:e1', 'ex:e2', 'ex:e3'], array_keys($data['entity']));
    }

    public function testSortRecordsPutsIdentifiedBeforeAnonymousAndKeepsAnonymousOrder(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder
            ->entity('ex:e2', ['ex:n' => 1])
            ->entity(null, ['ex:n' => 2])
            ->entity('ex:e1', ['ex:n' => 3])
            ->entity(null, ['ex:n' => 4]);

        $data = json_decode(new JsonSerializer(sortRecords: true)->serialize($builder->build()), true);

        $this->assertSame(['ex:e1', 'ex:e2', '_:b1', '_:b2'], array_keys($data['entity']));
        $this->assertSame(2, $data['entity']['_:b1']['ex:n']);
        $this->assertSame(4, $data['entity']['_:b2']['ex:n']);
    }

    public function testSortRecordsGroupsRelationsByTypeInProvDmOrder(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        // Added in a deliberately non-canonical order.
        $builder->specializationOf(specificEntity: 'ex:e2', generalEntity: 'ex:e1', identifier: 'ex:s1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e1', identifier: 'ex:u1');
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', identifier: 'ex:d1');
        $builder->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', identifier: 'ex:g1');

        $data = json_decode(new JsonSerializer(sortRecords: true)->serialize($builder->build()), true);

        // Usage, Generation, Derivation, Specialization → PROV-DM component order.
        $this->assertSame(['wasGeneratedBy', 'used', 'wasDerivedFrom', 'specializationOf'], $this->sectionKeys($data));
    }

    private function mixedDocument(): \Prov\Document
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', identifier: 'ex:g1');
        $builder->agent('ex:ag1');
        $builder->activity('ex:a1');
        $builder->entity('ex:e1');
        return $builder->build();
    }
}
