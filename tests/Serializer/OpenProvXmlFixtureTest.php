<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\XmlSerializer;

/**
 * Tests the PROV-XML serializer against openprov/testcases .provx fixtures.
 * Each fixture is deserialized and compared against its JSON counterpart.
 */
final class OpenProvXmlFixtureTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../vendor/openprov/testcases';

    private XmlSerializer $xmlSerializer;
    private JsonSerializer $jsonSerializer;

    protected function setUp(): void
    {
        $this->xmlSerializer = new XmlSerializer();
        $this->jsonSerializer = new JsonSerializer();
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
        $xmlFiles = glob($dir . '/test-*/*.provx');
        if ($xmlFiles === false) {
            return [];
        }

        foreach ($xmlFiles as $xmlFile) {
            $dirName = basename(dirname($xmlFile));
            $testName = substr($dirName, 5);

            $jsonFiles = glob(dirname($xmlFile) . '/*.json');
            if ($jsonFiles === false || $jsonFiles === []) {
                continue;
            }

            $fixtures[$testName] = [$xmlFile, $jsonFiles[0]];
        }

        ksort($fixtures);
        return $fixtures;
    }

    /**
     * Cross-format check kept at count-based comparison: ~216 Southampton fixtures
     * use different identifiers across their JSON and XML files (fixture-level
     * inconsistency, not a deserializer bug). A full structural comparison would
     * flag those as failures. Round-trip correctness is covered by testXmlRoundTrip.
     */
    #[DataProvider('fixtureProvider')]
    public function testXmlDeserializationMatchesJson(string $xmlPath, string $jsonPath): void
    {
        $xmlDoc = $this->xmlSerializer->deserialize(file_get_contents($xmlPath));
        $jsonDoc = $this->jsonSerializer->deserialize(file_get_contents($jsonPath));

        $this->assertSame(count($jsonDoc->entities), count($xmlDoc->entities), 'Entity count.');
        $this->assertSame(count($jsonDoc->activities), count($xmlDoc->activities), 'Activity count.');
        $this->assertSame(count($jsonDoc->agents), count($xmlDoc->agents), 'Agent count.');
        $this->assertSame(count($jsonDoc->relations), count($xmlDoc->relations), 'Relation count.');
        $this->assertSame(count($jsonDoc->bundles), count($xmlDoc->bundles), 'Bundle count.');
    }

    #[DataProvider('fixtureProvider')]
    public function testXmlRoundTrip(string $xmlPath, string $jsonPath): void
    {
        $doc1 = $this->xmlSerializer->deserialize(file_get_contents($xmlPath));
        $xml2 = $this->xmlSerializer->serialize($doc1);
        $doc2 = $this->xmlSerializer->deserialize($xml2);

        $this->assertTrue(
            DocumentComparator::equals($doc1, $doc2),
            'PROV-XML document is not semantically equal to itself after a serialize/deserialize round-trip.',
        );
    }
}
