<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;

/**
 * Tests the PROV-N parser against openprov/testcases .provn fixtures.
 * For each fixture, we parse the PROV-N, then compare the result against
 * the corresponding PROV-JSON fixture (deserialized independently).
 */
final class OpenProvProvNFixtureTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../vendor/openprov/testcases';

    private ProvNDeserializer $provnDeserializer;
    private JsonSerializer $jsonSerializer;

    protected function setUp(): void
    {
        $this->provnDeserializer = new ProvNDeserializer();
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
        $provnFiles = glob($dir . '/test-*/*.provn');
        if ($provnFiles === false) {
            return [];
        }

        foreach ($provnFiles as $provnFile) {
            $dirName = basename(dirname($provnFile));
            $testName = substr($dirName, 5);

            // Find the corresponding JSON file.
            $jsonFiles = glob(dirname($provnFile) . '/*.json');
            if ($jsonFiles === false || $jsonFiles === []) {
                continue;
            }

            $fixtures[$testName] = [$provnFile, $jsonFiles[0]];
        }

        ksort($fixtures);
        return $fixtures;
    }

    #[DataProvider('fixtureProvider')]
    public function testProvNMatchesJson(string $provnPath, string $jsonPath): void
    {
        $provnDoc = $this->provnDeserializer->deserialize(file_get_contents($provnPath));
        $jsonDoc = $this->jsonSerializer->deserialize(file_get_contents($jsonPath));

        $this->assertTrue(
            DocumentComparator::equals($provnDoc, $jsonDoc),
            'PROV-N fixture is not semantically equal to its PROV-JSON counterpart.',
        );
    }

    #[DataProvider('fixtureProvider')]
    public function testProvNRoundTrip(string $provnPath, string $jsonPath): void
    {
        $doc1 = $this->provnDeserializer->deserialize(file_get_contents($provnPath));
        $provn2 = new ProvNSerializer()->serialize($doc1);
        $doc2 = $this->provnDeserializer->deserialize($provn2);

        $this->assertTrue(
            DocumentComparator::equals($doc1, $doc2),
            'PROV-N document is not semantically equal to itself after a serialize/deserialize round-trip.',
        );
    }
}
