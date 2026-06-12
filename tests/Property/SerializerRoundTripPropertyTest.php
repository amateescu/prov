<?php

declare(strict_types=1);

namespace Prov\Tests\Property;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;
use Prov\Serializer\XmlSerializer;

/**
 * Property-based tests: for any randomly-generated Document, serialize + deserialize
 * should produce a semantically-equal Document (modulo DocumentComparator::equals).
 *
 * Generation is deliberately small: a fixed pool of identifiers (including blank
 * nodes), a few element and relation types, a pool of attribute bags and times, and
 * occasional bundles. The goal is to exercise random combinations the fixtures
 * don't cover, not to brute-force edge cases.
 *
 * Seeding: the default seed is pinned so CI runs are reproducible. Set
 * PROV_PROP_SEED to explore a different sequence, or PROV_PROP_ITERATIONS to
 * change how many runs per property.
 */
final class SerializerRoundTripPropertyTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 100;
    private const int DEFAULT_SEED = 20_260_611;

    private const array IDS = [
        'ex:e1',
        'ex:e2',
        'ex:e3',
        'ex:a1',
        'ex:a2',
        'ex:ag1',
        'ex:plan1',
        '_:b1',
        '_:b2',
        // Unprefixed identifiers resolve against the document default namespace.
        'd1',
        'd2',
    ];

    private const array TIMES = [null, '2024-01-15T10:00:00Z', '2024-06-01T08:30:00.123456+02:00'];

    public function testJsonRoundTripIsIdentity(): void
    {
        $this->forEachRandomDoc(function (Document $doc): void {
            $s = new JsonSerializer();
            $rt = $s->deserialize($s->serialize($doc));
            $this->assertTrue(DocumentComparator::equals($doc, $rt), 'JSON round-trip produced a non-equal document');
        });
    }

    public function testProvNRoundTripIsIdentity(): void
    {
        $this->forEachRandomDoc(function (Document $doc): void {
            $provn = new ProvNSerializer()->serialize($doc);
            $rt = new ProvNDeserializer()->deserialize($provn);
            $this->assertTrue(DocumentComparator::equals($doc, $rt), 'PROV-N round-trip produced a non-equal document');
        });
    }

    public function testXmlRoundTripIsIdentity(): void
    {
        $this->forEachRandomDoc(function (Document $doc): void {
            $s = new XmlSerializer();
            $rt = $s->deserialize($s->serialize($doc));
            $this->assertTrue(DocumentComparator::equals($doc, $rt), 'XML round-trip produced a non-equal document');
        });
    }

    /**
     * Runs a property over randomly-generated Documents. On failure, reports the
     * iteration index, seed, and specs so the case is reproducible.
     *
     * @param callable(Document): void $property
     */
    private function forEachRandomDoc(callable $property): void
    {
        $iterations = (int) (getenv('PROV_PROP_ITERATIONS') ?: self::DEFAULT_ITERATIONS);
        $seed = (int) (getenv('PROV_PROP_SEED') ?: self::DEFAULT_SEED);
        mt_srand($seed);

        for ($i = 0; $i < $iterations; $i++) {
            $specs = $this->randomSpecs();
            try {
                $property($this->buildDoc($specs));
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    sprintf(
                        "Property failed at iteration %d (reproduce with PROV_PROP_SEED=%d):\n  specs: %s\n  error: %s",
                        $i,
                        $seed,
                        json_encode($specs),
                        $e->getMessage(),
                    ),
                    previous: $e,
                );
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function randomSpecs(): array
    {
        $n = mt_rand(0, 7);
        $specs = [];
        for ($i = 0; $i < $n; $i++) {
            $specs[] = match (mt_rand(0, 7)) {
                0 => ['type' => 'entity', 'args' => [$this->randomId()], 'attrs' => $this->randomAttrsIndex()],
                1 => [
                    'type' => 'activity',
                    'args' => [$this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                    'start' => mt_rand(0, count(self::TIMES) - 1),
                    'end' => mt_rand(0, count(self::TIMES) - 1),
                ],
                2 => ['type' => 'agent', 'args' => [$this->randomId()], 'attrs' => $this->randomAttrsIndex()],
                3 => [
                    'type' => 'wasGeneratedBy',
                    'args' => [$this->randomId(), $this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                    'time' => mt_rand(0, count(self::TIMES) - 1),
                ],
                4 => [
                    'type' => 'used',
                    'args' => [$this->randomId(), $this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                    'time' => mt_rand(0, count(self::TIMES) - 1),
                ],
                5 => [
                    'type' => 'wasAttributedTo',
                    'args' => [$this->randomId(), $this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                ],
                6 => [
                    'type' => 'wasDerivedFrom',
                    'args' => [$this->randomId(), $this->randomId(), $this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                ],
                7 => [
                    'type' => 'bundle',
                    // The bundle identifier is minted per slot: duplicate bundle
                    // identifiers in one document do not survive PROV-JSON's
                    // bundle map and are out of scope here.
                    'args' => ['ex:bundle' . $i, $this->randomId(), $this->randomId(), $this->randomId()],
                    'attrs' => $this->randomAttrsIndex(),
                ],
            };
        }
        return $specs;
    }

    private function randomId(): string
    {
        return self::IDS[mt_rand(0, count(self::IDS) - 1)];
    }

    private function randomAttrsIndex(): int
    {
        return mt_rand(0, 6);
    }

    /**
     * Maps a spec's attrs index to a builder attribute array. Indexes 5 and 6
     * exercise the typed corners: language-tagged literals and QualifiedName
     * values (including a blank reference).
     *
     * @return array<string, mixed>|null
     */
    private function attrsFor(int $index): ?array
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        return match ($index) {
            1 => ['ex:tag' => 'plain string'],
            2 => [
                'ex:count' => 42,
                'ex:rate' => 1.5,
                'ex:flag' => true,
                'ex:whole' => 2.0,
                'ex:negative' => -7,
                'ex:negfloat' => -2.5,
            ],
            3 => ['ex:tags' => ['first', 'second']],
            4 => ['prov:type' => 'ex:Widget'],
            5 => ['prov:label' => new Literal('hallo', null, 'de')],
            6 => ['ex:ref' => $ex->qualifiedName('e1'), 'ex:anon' => new ProvNamespace('_', '_:')->qualifiedName('b1')],
            default => null,
        };
    }

    private function timeFor(int $index): ?\DateTimeImmutable
    {
        $value = self::TIMES[$index] ?? null;
        return $value !== null ? new \DateTimeImmutable($value) : null;
    }

    /** @param list<array<string, mixed>> $specs */
    private function buildDoc(array $specs): Document
    {
        $b = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('ex', 'http://example.org/'))
            ->setDefaultNamespace(new ProvNamespace('default', 'http://default.example/'));
        foreach ($specs as $spec) {
            $attrs = $this->attrsFor($spec['attrs'] ?? 0);
            match ($spec['type']) {
                'entity' => $b->entity($spec['args'][0], $attrs),
                'activity' => $b->activity(
                    $spec['args'][0],
                    $this->timeFor($spec['start'] ?? 0),
                    $this->timeFor($spec['end'] ?? 0),
                    $attrs,
                ),
                'agent' => $b->agent($spec['args'][0], $attrs),
                'wasGeneratedBy' => $b->wasGeneratedBy(
                    entity: $spec['args'][0],
                    activity: $spec['args'][1],
                    time: $this->timeFor($spec['time'] ?? 0),
                    attributes: $attrs,
                ),
                'used' => $b->used(
                    activity: $spec['args'][0],
                    entity: $spec['args'][1],
                    time: $this->timeFor($spec['time'] ?? 0),
                    attributes: $attrs,
                ),
                'wasAttributedTo' => $b->wasAttributedTo(
                    entity: $spec['args'][0],
                    agent: $spec['args'][1],
                    attributes: $attrs,
                ),
                'wasDerivedFrom' => $b->wasDerivedFrom(
                    generatedEntity: $spec['args'][0],
                    usedEntity: $spec['args'][1],
                    activity: $spec['args'][2],
                    attributes: $attrs,
                ),
                'bundle' => $b->withBundle($spec['args'][0], static fn($bb) => $bb->entity(
                    $spec['args'][1],
                    $attrs,
                )->wasGeneratedBy(entity: $spec['args'][2], activity: $spec['args'][3])),
                default => null,
            };
        }
        return $b->build();
    }
}
