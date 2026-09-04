<?php

declare(strict_types=1);

namespace Prov\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Serializer\JsonLdSerializer;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNSerializer;
use Prov\Serializer\XmlSerializer;

final class FormatTest extends TestCase
{
    private function buildDoc(): Document
    {
        $b = new DocumentBuilder();
        $b->addNamespace(new ProvNamespace('ex', 'http://example.org/'));
        // Reverse identifier order, so record sorting is observable.
        $b->entity('ex:b');
        $b->entity('ex:a');
        return $b->build();
    }

    /**
     * @return iterable<string, array{Format, class-string}>
     */
    public static function formats(): iterable
    {
        yield 'json' => [Format::Json, JsonSerializer::class];
        yield 'provn' => [Format::ProvN, ProvNSerializer::class];
        yield 'xml' => [Format::Xml, XmlSerializer::class];
        yield 'jsonld' => [Format::JsonLd, JsonLdSerializer::class];
    }

    #[DataProvider('formats')]
    public function testDefaultsMatchTheSerializerConstructorDefaults(Format $format, string $class): void
    {
        $doc = $this->buildDoc();

        $this->assertInstanceOf($class, $format->createSerializer());
        $this->assertSame(new $class()->serialize($doc), $format->createSerializer()->serialize($doc));
    }

    /**
     * @return iterable<string, array{Format}>
     */
    public static function formatCases(): iterable
    {
        foreach (Format::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    #[DataProvider('formatCases')]
    public function testSortRecordsOrdersRecordsByIdentifier(Format $format): void
    {
        $doc = $this->buildDoc();

        $kept = $format->createSerializer()->serialize($doc);
        $sorted = $format->createSerializer(sortRecords: true)->serialize($doc);

        $this->assertLessThan(strpos($kept, 'ex:a'), strpos($kept, 'ex:b'));
        $this->assertLessThan(strpos($sorted, 'ex:b'), strpos($sorted, 'ex:a'));
    }

    #[DataProvider('formatCases')]
    public function testPrettyPrintTogglesIndentation(Format $format): void
    {
        $doc = $this->buildDoc();

        $pretty = $format->createSerializer(prettyPrint: true)->serialize($doc);
        $compact = $format->createSerializer(prettyPrint: false)->serialize($doc);

        $this->assertMatchesRegularExpression('/\n /', $pretty);
        $this->assertDoesNotMatchRegularExpression('/\n /', $compact);
    }
}
