<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Exception\DeserializationException;
use Prov\Serializer\JsonSerializer;

/**
 * Coverage for the opt-in lenient PROV-JSON parse: readable records survive, a
 * record-level defect becomes a warning instead of aborting the parse, and
 * document-level damage still throws in both modes.
 */
final class JsonLenientDeserializationTest extends TestCase
{
    private const string ONE_BAD_ENTITY = '{"prefix":{"ex":"http://e/"},"entity":{"ex:good1":{},"ex:bad":"notamap","ex:good2":{}}}';

    public function testLenientParseKeepsGoodRecordsAndWarnsOnBadRecord(): void
    {
        $result = new JsonSerializer()->deserializeLenient(self::ONE_BAD_ENTITY);

        $localParts = array_map(
            static fn($entity): string => $entity->identifier->localPart,
            $result->document->entities,
        );
        $this->assertSame(['good1', 'good2'], $localParts);

        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('entity', $result->warnings[0]);
        $this->assertStringContainsString('ex:bad', $result->warnings[0]);
    }

    public function testStrictDeserializeStillThrowsOnSameFixture(): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize(self::ONE_BAD_ENTITY);
    }

    public function testLenientParseReturnsEmptyWarningsForCleanDocument(): void
    {
        $result = new JsonSerializer()->deserializeLenient(
            '{"prefix":{"ex":"http://e/"},"entity":{"ex:e1":{},"ex:e2":{}}}',
        );

        $this->assertSame([], $result->warnings);
        $this->assertCount(2, $result->document->entities);
    }

    public function testLenientParseSkipsMalformedRelationRecord(): void
    {
        // wasGeneratedBy without the mandatory prov:entity is a record-level
        // defect: the relation is dropped, the good entity is kept.
        $result = new JsonSerializer()->deserializeLenient(
            '{"prefix":{"ex":"http://e/"},"entity":{"ex:e1":{}},"wasGeneratedBy":{"_:g1":{"prov:activity":"ex:a1"}}}',
        );

        $this->assertCount(1, $result->document->entities);
        $this->assertSame([], $result->document->relations);

        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('wasGeneratedBy', $result->warnings[0]);
        $this->assertStringContainsString('_:g1', $result->warnings[0]);
    }

    public function testLenientParseSkipsRecordWithUndeclaredPrefix(): void
    {
        // An undeclared prefix in a record id surfaces as a NamespaceException in
        // strict mode; lenient mode records it as a warning and moves on.
        $result = new JsonSerializer()->deserializeLenient(
            '{"prefix":{"ex":"http://e/"},"entity":{"ex:e1":{},"nope:e2":{}}}',
        );

        $this->assertCount(1, $result->document->entities);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('nope:e2', $result->warnings[0]);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function documentLevelDamageProvider(): array
    {
        return [
            'not json' => ['this is not json'],
            'root is a scalar' => ['42'],
            'prefix section is a scalar' => ['{"prefix": "notamap"}'],
            'entity section is a scalar' => ['{"prefix":{"ex":"http://e/"},"entity": "notamap"}'],
            'non-string prefix uri' => ['{"prefix": {"ex": 123}}'],
        ];
    }

    #[DataProvider('documentLevelDamageProvider')]
    public function testLenientModeStillThrowsOnDocumentLevelDamage(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserializeLenient($input);
    }

    #[DataProvider('documentLevelDamageProvider')]
    public function testStrictModeThrowsOnDocumentLevelDamage(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($input);
    }
}
