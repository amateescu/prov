<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Exception\DeserializationException;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\XmlSerializer;

/**
 * Regression coverage for review item 1.5: malformed PROV-JSON / PROV-XML must
 * surface as DeserializationException, never as a leaked
 * \DateMalformedStringException or \TypeError escaping the deserialize() contract.
 */
final class JsonXmlMalformedInputTest extends TestCase
{
    /**
     * @return iterable<string, list<string>>
     */
    public static function malformedJsonProvider(): iterable
    {
        yield 'garbage activity start time' => [
            '{"activity":{"ex:a1":{"prov:startTime":"not-a-date"}}}',
        ];
        yield 'typed-object activity start time' => [
            '{"activity":{"ex:a1":{"prov:startTime":{"$":"not-a-date","type":"xsd:dateTime"}}}}',
        ];
        yield 'garbage relation time' => [
            '{"prefix":{"ex":"http://example.org/"},"wasGeneratedBy":{"ex:g1":{"prov:time":"nope"}}}',
        ];
        yield 'non-string prefix uri' => [
            '{"prefix":{"ex":123},"entity":{"ex:e1":{}}}',
        ];
        yield 'numeric typed-literal value' => [
            '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"ex:n":{"$":42,"type":"xsd:int"}}}}',
        ];
        yield 'non-string typed-literal type' => [
            '{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{"ex:n":{"$":"x","type":[1,2]}}}}',
        ];
    }

    #[DataProvider('malformedJsonProvider')]
    public function testMalformedJsonRaisesDeserializationException(string $json): void
    {
        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($json);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function malformedXmlProvider(): iterable
    {
        $head =
            '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" '
            . 'xmlns:ex="http://example.org/">';

        yield 'garbage activity start time' => [
            $head
                . '<prov:activity prov:id="ex:a1"><prov:startTime>not-a-date</prov:startTime>'
                . '</prov:activity></prov:document>',
        ];
        yield 'garbage relation time' => [
            $head
                . '<prov:wasGeneratedBy prov:id="ex:g1"><prov:time>nope</prov:time>'
                . '</prov:wasGeneratedBy></prov:document>',
        ];
    }

    #[DataProvider('malformedXmlProvider')]
    public function testMalformedXmlRaisesDeserializationException(string $xml): void
    {
        $this->expectException(DeserializationException::class);
        new XmlSerializer()->deserialize($xml);
    }
}
