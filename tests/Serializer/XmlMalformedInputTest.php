<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Exception\DeserializationException;
use Prov\Serializer\XmlSerializer;

/**
 * Negative coverage for the PROV-XML deserializer: malformed input must fail
 * with DeserializationException, never with a leaked \DateMalformedStringException,
 * \TypeError, or other PHP engine exception. Locks in the deserializer exception
 * contract (review item 1.5).
 */
final class XmlMalformedInputTest extends TestCase
{
    private const string PROV_NS = 'http://www.w3.org/ns/prov#';

    /**
     * @return array<string, list<string>>
     */
    public static function malformedSnippetProvider(): array
    {
        $ns = self::PROV_NS;
        return [
            'not xml' => ['this is not xml'],
            'empty string' => [''],
            'unclosed tag' => ['<prov:document xmlns:prov="' . self::PROV_NS . '"><prov:entity'],
            'wrong root element' => ['<root/>'],
            'root not in prov namespace' => ['<document/>'],
            'garbage start time' => [
                '<prov:document xmlns:prov="'
                    . $ns
                    . '" xmlns:ex="http://e/">'
                    . '<prov:activity prov:id="ex:a1"><prov:startTime>not-a-date</prov:startTime></prov:activity>'
                    . '</prov:document>',
            ],
            'undeclared prefix in id' => [
                '<prov:document xmlns:prov="' . $ns . '"><prov:entity prov:id="nope:e1"/></prov:document>',
            ],
            'malformed generation time' => [
                '<prov:document xmlns:prov="'
                    . $ns
                    . '" xmlns:ex="http://e/">'
                    . '<prov:wasGeneratedBy><prov:entity prov:ref="ex:e1"/>'
                    . '<prov:time>garbage</prov:time></prov:wasGeneratedBy></prov:document>',
            ],
            'wasGeneratedBy missing entity' => [
                '<prov:document xmlns:prov="'
                    . $ns
                    . '" xmlns:ex="http://e/">'
                    . '<prov:wasGeneratedBy><prov:activity prov:ref="ex:a1"/></prov:wasGeneratedBy>'
                    . '</prov:document>',
            ],
            'specializationOf missing specificEntity' => [
                '<prov:document xmlns:prov="'
                    . $ns
                    . '" xmlns:ex="http://e/">'
                    . '<prov:specializationOf><prov:generalEntity prov:ref="ex:e2"/></prov:specializationOf>'
                    . '</prov:document>',
            ],
            'hadDictionaryMember missing dictionary' => [
                '<prov:document xmlns:prov="'
                    . $ns
                    . '" xmlns:ex="http://e/">'
                    . '<prov:hadDictionaryMember/>'
                    . '</prov:document>',
            ],
        ];
    }

    #[DataProvider('malformedSnippetProvider')]
    public function testMalformedSnippetThrowsDeserializationException(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new XmlSerializer()->deserialize($input);
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
            ->entity('ex:e1', ['ex:tag' => 'value', 'ex:n' => 42])
            ->activity('ex:a1', new \DateTimeImmutable('2024-01-15T10:00:00Z'))
            ->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable('2024-01-15T10:00:00Z'))
            ->build();
        return new XmlSerializer()->serialize($doc);
    }

    private function assertParsesOrThrowsCleanly(string $input, string $what): void
    {
        try {
            $document = new XmlSerializer()->deserialize($input);
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
