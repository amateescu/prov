<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Document;
use Prov\Exception\DeserializationException;
use Prov\Serializer\ProvNDeserializer;

/**
 * Negative coverage for the hand-rolled PROV-N parser: malformed input must
 * fail with DeserializationException, never with an untyped \Error, a PHP
 * engine exception, or a hang. The deterministic truncation and deletion
 * sweeps act as a small fuzz pass over every byte position of a document
 * exercising most grammar constructs.
 */
final class ProvNMalformedInputTest extends TestCase
{
    private const string VALID_DOCUMENT = <<<'PROVN'
        document
          prefix ex <http://example.org/>
          entity(ex:e1, [ex:tag = "value", prov:type = 'ex:Widget', ex:n = "42" %% xsd:int, ex:l = "hi"@en])
          activity(ex:a1, 2024-01-15T10:00:00Z, -)
          wasGeneratedBy(ex:gen1; ex:e1, ex:a1, 2024-01-15T10:00:00Z)
          wasDerivedFrom(ex:e2, ex:e1, ex:a1, ex:gen1, -)
          bundle ex:b1
            prefix bx <http://bundle.example/>
            entity(bx:e9)
          endBundle
        endDocument
        PROVN;

    /**
     * @return array<string, list<string>>
     */
    public static function malformedSnippetProvider(): array
    {
        return [
            'empty input' => [''],
            'whitespace only' => ["  \n\t "],
            'missing document keyword' => ['entity(ex:e1) endDocument'],
            'missing endDocument' => ['document prefix ex <http://example.org/> entity(ex:e1)'],
            'unterminated string' => [
                'document prefix ex <http://example.org/> entity(ex:e1, [ex:a = "oops]) endDocument',
            ],
            'unterminated comment' => ['document /* comment that never ends entity(ex:e1) endDocument'],
            'unterminated iri' => ['document prefix ex <http://example.org/ entity(ex:e1) endDocument'],
            'unbalanced open paren' => ['document prefix ex <http://example.org/> entity(ex:e1 endDocument'],
            'unbalanced close paren' => ['document prefix ex <http://example.org/> entity ex:e1) endDocument'],
            'unbalanced attr bracket' => [
                'document prefix ex <http://example.org/> entity(ex:e1, [ex:a = "v") endDocument',
            ],
            'bad qname prefix' => ['document entity(nope:e1) endDocument'],
            'stray semicolon' => ['document prefix ex <http://example.org/> entity(; ex:e1) endDocument'],
            'unknown keyword' => ['document banana(ex:e1) endDocument'],
            'truncated prefix declaration' => ['document prefix'],
            'prefix without iri' => ['document prefix ex entity(ex:e1) endDocument'],
            'truncated relation args' => ['document prefix ex <http://example.org/> wasGeneratedBy(ex:e1,'],
            'garbage datetime' => [
                'document prefix ex <http://example.org/> activity(ex:a1, 2024-99-99T99:99:99, -) endDocument',
            ],
            'bare date as relation time' => [
                'document prefix ex <http://example.org/> wasGeneratedBy(ex:e1, ex:a1, 2024-01-01) endDocument',
            ],
            'nested bundle' => [
                'document bundle ex:b1 bundle ex:b2 endBundle endBundle endDocument',
            ],
            'unterminated language tag' => [
                'document prefix ex <http://example.org/> entity(ex:e1, [ex:a = "v"@]) endDocument',
            ],
            'typed literal without datatype' => [
                'document prefix ex <http://example.org/> entity(ex:e1, [ex:a = "v" %%]) endDocument',
            ],
        ];
    }

    #[DataProvider('malformedSnippetProvider')]
    public function testMalformedSnippetThrowsDeserializationException(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new ProvNDeserializer()->deserialize($input);
    }

    public function testBareDateInTimePositionReportsExpectedDateTime(): void
    {
        try {
            new ProvNDeserializer()->deserialize(
                'document prefix ex <http://example.org/> wasGeneratedBy(ex:e1, ex:a1, 2024-01-01) endDocument',
            );
            $this->fail('Expected DeserializationException for a bare date in a time position.');
        } catch (DeserializationException $e) {
            $this->assertStringContainsString('xsd:dateTime', $e->getMessage());
            $this->assertStringContainsString('2024-01-01', $e->getMessage());
        }
    }

    public function testTruncationSweepNeverRaisesUntypedErrors(): void
    {
        $input = self::VALID_DOCUMENT;
        $length = strlen($input);
        for ($cut = 0; $cut < $length; $cut++) {
            $this->assertParsesOrThrowsCleanly(substr($input, 0, $cut), "truncated at byte {$cut}");
        }
    }

    public function testDeletionSweepNeverRaisesUntypedErrors(): void
    {
        $input = self::VALID_DOCUMENT;
        $length = strlen($input);
        for ($at = 0; $at < $length; $at++) {
            $this->assertParsesOrThrowsCleanly(
                substr($input, 0, $at) . substr($input, $at + 1),
                "byte {$at} ('{$input[$at]}') deleted",
            );
        }
    }

    private function assertParsesOrThrowsCleanly(string $input, string $what): void
    {
        try {
            $document = new ProvNDeserializer()->deserialize($input);
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
