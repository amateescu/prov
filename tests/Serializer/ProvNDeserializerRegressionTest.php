<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Exception\DeserializationException;
use Prov\Serializer\ProvNDeserializer;

/**
 * Fixture-driven contract tests for malformed PROV-N inputs. Each fixture is a
 * minimal reproducer for a specific failure mode; the deserializer must throw
 * DeserializationException (or succeed) and never hang or raise an untyped PHP error.
 */
final class ProvNDeserializerRegressionTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../Fixtures/provn_regressions';

    /** @return array<string, list<string>> */
    public static function regressionProvider(): array
    {
        $dir = realpath(self::FIXTURES_DIR);
        if ($dir === false) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.provn') ?: [] as $file) {
            $out[basename($file, '.provn')] = [$file];
        }
        return $out;
    }

    /**
     * Each regression input must either parse cleanly or throw DeserializationException
     * within a reasonable wall-clock budget; no hangs, no untyped exceptions.
     */
    #[DataProvider('regressionProvider')]
    public function testMalformedInputParsesOrThrowsPromptly(string $fixturePath): void
    {
        $input = (string) file_get_contents($fixturePath);

        $start = microtime(true);
        try {
            new ProvNDeserializer()->deserialize($input);
        } catch (DeserializationException) {
            // @mago-expect lint:no-empty-catch-clause
            // Rejecting malformed input with a typed exception is the success case; the assertion
            // below only cares that we didn't hang.
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf(
                'Parsing %s took %.3fs; a hang is the bug this regression protects against.',
                basename($fixturePath),
                $elapsed,
            ),
        );
    }
}
