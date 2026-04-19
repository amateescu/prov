<?php

declare(strict_types=1);

namespace Prov\Tests\Property;

use PHPUnit\Framework\TestCase;
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
 * Generation is deliberately small: a fixed pool of 10 identifiers, 3 element types,
 * 3 relation types, no extra attributes, no bundles. The goal is to exercise random
 * combinations the fixtures don't cover, not to brute-force edge cases.
 *
 * Seeding: set PROV_PROP_SEED to reproduce a specific iteration sequence, or
 * PROV_PROP_ITERATIONS to change how many runs per property.
 */
final class SerializerRoundTripPropertyTest extends TestCase
{
    private const DEFAULT_ITERATIONS = 100;
    private const IDS = ['e1', 'e2', 'e3', 'a1', 'a2', 'ag1', 'bundle1', 'plan1', 'r1', 'r2'];

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
        $seed = (int) (getenv('PROV_PROP_SEED') ?: random_int(1, PHP_INT_MAX));
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

    /** @return list<array{type: string, args: list<string>}> */
    private function randomSpecs(): array
    {
        $n = mt_rand(0, 6);
        $specs = [];
        for ($i = 0; $i < $n; $i++) {
            $specs[] = match (mt_rand(0, 5)) {
                0 => ['type' => 'entity', 'args' => [$this->randomId()]],
                1 => ['type' => 'activity', 'args' => [$this->randomId()]],
                2 => ['type' => 'agent', 'args' => [$this->randomId()]],
                3 => ['type' => 'wasGeneratedBy', 'args' => [$this->randomId(), $this->randomId()]],
                4 => ['type' => 'used', 'args' => [$this->randomId(), $this->randomId()]],
                5 => ['type' => 'wasAttributedTo', 'args' => [$this->randomId(), $this->randomId()]],
            };
        }
        return $specs;
    }

    private function randomId(): string
    {
        return 'ex:' . self::IDS[mt_rand(0, count(self::IDS) - 1)];
    }

    /** @param list<array{type: string, args: list<string>}> $specs */
    private function buildDoc(array $specs): Document
    {
        $b = new DocumentBuilder()->addNamespace(new ProvNamespace('ex', 'http://example.org/'));
        foreach ($specs as $spec) {
            match ($spec['type']) {
                'entity' => $b->entity($spec['args'][0]),
                'activity' => $b->activity($spec['args'][0]),
                'agent' => $b->agent($spec['args'][0]),
                'wasGeneratedBy' => $b->wasGeneratedBy(entity: $spec['args'][0], activity: $spec['args'][1]),
                'used' => $b->used(activity: $spec['args'][0], entity: $spec['args'][1]),
                'wasAttributedTo' => $b->wasAttributedTo(entity: $spec['args'][0], agent: $spec['args'][1]),
                default => null,
            };
        }
        return $b->build();
    }
}
