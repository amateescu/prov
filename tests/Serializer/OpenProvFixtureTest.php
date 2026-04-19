<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Document;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\JsonSerializer;

/**
 * Tests against the Southampton Provenance Suite test case documents
 * from https://github.com/openprov/testcases (MIT license).
 *
 * Each fixture is a PROV-JSON file that we deserialize, then re-serialize
 * and deserialize again, comparing the two Documents structurally.
 */
final class OpenProvFixtureTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../vendor/openprov/testcases';

    private JsonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsonSerializer();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function fixtureProvider(): array
    {
        $dir = realpath(self::FIXTURES_DIR);
        if ($dir === false) {
            return [];
        }

        $fixtures = [];
        $files = glob($dir . '/test-*/*.json');
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $dirName = basename(dirname($file));
            $testName = substr($dirName, 5); // strip "test-"
            $fixtures[$testName] = [$file];
        }

        ksort($fixtures);
        return $fixtures;
    }

    #[DataProvider('fixtureProvider')]
    public function testFixtureDeserializes(string $fixturePath): void
    {
        $json = file_get_contents($fixturePath);
        $this->assertNotFalse($json);

        $doc = $this->serializer->deserialize($json);
        $this->assertInstanceOf(Document::class, $doc);
    }

    #[DataProvider('fixtureProvider')]
    public function testFixtureRoundTrips(string $fixturePath): void
    {
        $json = file_get_contents($fixturePath);
        $this->assertNotFalse($json);

        $doc1 = $this->serializer->deserialize($json);
        $json2 = $this->serializer->serialize($doc1);
        $doc2 = $this->serializer->deserialize($json2);

        $this->assertTrue(
            DocumentComparator::equals($doc1, $doc2),
            'Document is not semantically equal to itself after a serialize/deserialize round-trip.',
        );
    }
}
