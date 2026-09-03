<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Exception\DeserializationException;
use Prov\Serializer\JsonSerializer;

/**
 * Negative coverage for the PROV-JSON deserializer: malformed input must fail
 * with DeserializationException, never with a leaked \DateMalformedStringException,
 * \TypeError, or other PHP engine exception. Locks in the deserializer exception
 * contract (review item 1.5).
 */
final class JsonMalformedInputTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    public static function malformedSnippetProvider(): array
    {
        return [
            'not json' => ['this is not json'],
            'json scalar' => ['42'],
            'non-string prefix uri' => ['{"prefix": {"ex": 123}}'],
            'prefix uri is object' => ['{"prefix": {"ex": {"nested": true}}}'],
            'garbage start time' => [
                '{"prefix":{"ex":"http://e/"},"activity":{"ex:a1":{"prov:startTime":"not-a-date"}}}',
            ],
            'numeric start time' => [
                '{"prefix":{"ex":"http://e/"},"activity":{"ex:a1":{"prov:startTime":12345}}}',
            ],
            'typed-object start time with numeric value' => [
                '{"prefix":{"ex":"http://e/"},"activity":{"ex:a1":{"prov:startTime":{"$":123,"type":"xsd:dateTime"}}}}',
            ],
            'numeric typed-literal value' => [
                '{"prefix":{"ex":"http://e/"},"entity":{"ex:e1":{"ex:a":{"$":123,"type":"xsd:int"}}}}',
            ],
            'undeclared prefix' => ['{"entity":{"nope:e1":{}}}'],
            'bundle prefix uri not string' => [
                '{"bundle":{"ex:b1":{"prefix":{"bx":false}}}}',
            ],
            'prefix section is a scalar' => ['{"prefix": "notamap"}'],
            'entity section is a scalar' => ['{"entity": "notamap"}'],
            'activity section is a scalar' => ['{"activity": "notamap"}'],
            'agent section is a scalar' => ['{"agent": "notamap"}'],
            'relation section is a scalar' => ['{"wasGeneratedBy": "notamap"}'],
            'bundle section is a scalar' => ['{"bundle": "notamap"}'],
            'bundle body is a scalar' => ['{"bundle": {"ex:b": "notamap"}}'],
            'bundle prefix section is a scalar' => ['{"bundle": {"ex:b": {"prefix": "notamap"}}}'],
            'entity record body is a scalar' => [
                '{"prefix":{"ex":"http://e/"},"entity":{"ex:e1":"notamap"}}',
            ],
            'activity record body is a scalar' => [
                '{"prefix":{"ex":"http://e/"},"activity":{"ex:a1":"notamap"}}',
            ],
            'agent record body is a scalar' => [
                '{"prefix":{"ex":"http://e/"},"agent":{"ex:ag1":"notamap"}}',
            ],
            'relation record body is a scalar' => [
                '{"prefix":{"ex":"http://e/"},"wasGeneratedBy":{"_:g1":"notamap"}}',
            ],
            'scruffy relation instance is a scalar' => [
                '{"prefix":{"ex":"http://e/"},"wasGeneratedBy":{"_:g1":[{"prov:entity":"ex:e1","prov:activity":"ex:a1"},"notamap"]}}',
            ],
            'wasGeneratedBy missing entity' => [
                '{"prefix":{"ex":"http://e/"},"wasGeneratedBy":{"_:g1":{"prov:activity":"ex:a1"}}}',
            ],
            'wasInvalidatedBy missing entity' => [
                '{"prefix":{"ex":"http://e/"},"wasInvalidatedBy":{"_:i1":{"prov:activity":"ex:a1"}}}',
            ],
            'specializationOf missing specificEntity' => [
                '{"prefix":{"ex":"http://e/"},"specializationOf":{"_:s1":{"prov:generalEntity":"ex:e2"}}}',
            ],
            'alternateOf missing alternate1' => [
                '{"prefix":{"ex":"http://e/"},"alternateOf":{"_:a1":{"prov:alternate2":"ex:e2"}}}',
            ],
            'mentionOf missing specificEntity' => [
                '{"prefix":{"ex":"http://e/"},"mentionOf":{"_:m1":{"prov:generalEntity":"ex:e2","prov:bundle":"ex:b1"}}}',
            ],
            'hadDictionaryMember missing dictionary' => [
                '{"prefix":{"ex":"http://e/"},"hadDictionaryMember":{"_:d1":{"prov:key-entity-set":[]}}}',
            ],
            'derivedByInsertionFrom missing after' => [
                '{"prefix":{"ex":"http://e/"},"derivedByInsertionFrom":{"_:di1":{"prov:before":"ex:d1"}}}',
            ],
            'derivedByRemovalFrom missing before' => [
                '{"prefix":{"ex":"http://e/"},"derivedByRemovalFrom":{"_:dr1":{"prov:after":"ex:d2"}}}',
            ],
        ];
    }

    #[DataProvider('malformedSnippetProvider')]
    public function testMalformedSnippetThrowsDeserializationException(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($input);
    }

    /**
     * A PROV-JSON document is a JSON object. A JSON list decodes to a PHP array
     * just like an object does, so the root shape has to be checked explicitly
     * or a list is read as an empty document.
     *
     * @return array<string, list<string>>
     */
    public static function nonObjectRootProvider(): array
    {
        return [
            'root scalar' => ['42'],
            'root string' => ['"hello"'],
            'root null' => ['null'],
            'non-empty list' => ['[{"entity":{}}]'],
            'list of scalars' => ['[1,2,3]'],
        ];
    }

    #[DataProvider('nonObjectRootProvider')]
    public function testNonObjectRootIsRejected(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($input);
    }

    #[DataProvider('nonObjectRootProvider')]
    public function testNonObjectRootIsRejectedLeniently(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserializeLenient($input);
    }

    public function testEmptyObjectAndEmptyListAreEmptyDocuments(): void
    {
        // json_decode(..., true) cannot tell `{}` from `[]`, and an empty array
        // carries no records either way, so both stay an empty document.
        foreach (['{}', '[]'] as $input) {
            $this->assertSame([], new JsonSerializer()->deserialize($input)->records);
            $this->assertSame([], new JsonSerializer()->deserializeLenient($input)->document->records);
        }
    }

    public function testTruncationSweepNeverRaisesUntypedErrors(): void
    {
        $input = $this->validDocument();
        $length = strlen($input);
        for ($cut = 0; $cut < $length; $cut++) {
            $this->assertParsesOrThrowsCleanly(substr($input, 0, $cut), "truncated at byte {$cut}");
        }
    }

    public function testDeletionSweepNeverRaisesUntypedErrors(): void
    {
        $input = $this->validDocument();
        $length = strlen($input);
        for ($at = 0; $at < $length; $at++) {
            $this->assertParsesOrThrowsCleanly(substr($input, 0, $at) . substr($input, $at + 1), "byte {$at} deleted");
        }
    }

    private function validDocument(): string
    {
        $doc = new DocumentBuilder()
            ->namespace('ex', 'http://example.org/')
            ->entity('ex:e1', [
                'ex:tag' => 'value',
                'ex:n' => 42,
                'ex:l' => new \Prov\Attribute\Literal('hi', null, 'en'),
            ])
            ->activity('ex:a1', new \DateTimeImmutable('2024-01-15T10:00:00Z'))
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2024-01-15T10:00:00Z'))
            ->withBundle('ex:b1', static fn($bb): mixed => $bb->entity('ex:e9'))
            ->build();
        return new JsonSerializer()->serialize($doc);
    }

    private function assertParsesOrThrowsCleanly(string $input, string $what): void
    {
        try {
            $document = new JsonSerializer()->deserialize($input);
            $this->assertInstanceOf(Document::class, $document);
        } catch (DeserializationException) {
            $this->addToAssertionCount(1);
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                'Input with %s raised %s instead of DeserializationException: %s',
                $what,
                $e::class,
                $e->getMessage(),
            ));
        }
    }
}
