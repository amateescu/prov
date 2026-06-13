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
        ];
    }

    #[DataProvider('malformedSnippetProvider')]
    public function testMalformedSnippetThrowsDeserializationException(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($input);
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
