<?php

declare(strict_types=1);

namespace Prov\Tests\Scan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Exception\DeserializationException;
use Prov\Identifier\QualifiedName;
use Prov\Scan\JsonScanner;
use Prov\Serializer\JsonSerializer;

/**
 * `JsonScanner::isQualifiedNameValue()` answers whether a PROV-JSON value names
 * a record, and the deserializer decides the same thing when it builds the
 * model. A consumer that rewrites stored PROV-JSON reads the first and writes
 * against the second, so the two must classify a value the same way, whatever
 * prefix the document bound the datatype to.
 */
final class QualifiedNameDatatypeTest extends TestCase
{
    /**
     * @param array<string, string> $prefixes
     */
    private function document(array $prefixes, string $datatype): string
    {
        return (string) json_encode([
            'prefix' => ['ex' => 'http://example.org/'] + $prefixes,
            'entity' => [
                'ex:e1' => [
                    'ex:ref' => ['$' => 'ex:other', 'type' => $datatype],
                ],
            ],
        ]);
    }

    private function deserializedValue(string $json): mixed
    {
        $document = new JsonSerializer()->deserialize($json);
        $values = $document->records[0]->attributes->all()['http://example.org/ref'] ?? [];
        return $values[0] ?? null;
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function qualifiedNameDatatypes(): iterable
    {
        $prov = 'http://www.w3.org/ns/prov#';
        $xsd = 'http://www.w3.org/2001/XMLSchema#';

        yield 'canonical prov tag' => [[], 'prov:QUALIFIED_NAME'];
        yield 'canonical xsd tag' => [[], 'xsd:QName'];
        yield 'aliased prov prefix' => [['p' => $prov], 'p:QUALIFIED_NAME'];
        yield 'aliased xsd prefix' => [['xs' => $xsd], 'xs:QName'];
        yield 'rebound prov prefix' => [['prov' => $prov, 'pr' => $prov], 'pr:QUALIFIED_NAME'];
    }

    /**
     * @param array<string, string> $prefixes
     */
    #[DataProvider('qualifiedNameDatatypes')]
    public function testScannerAndDeserializerBothReadAQualifiedName(array $prefixes, string $datatype): void
    {
        $json = $this->document($prefixes, $datatype);

        $scanner = new JsonScanner($json);
        $raw = ['$' => 'ex:other', 'type' => $datatype];
        $this->assertTrue($scanner->isQualifiedNameValue($raw), 'The scanner did not see a qualified name.');

        $value = $this->deserializedValue($json);
        $this->assertInstanceOf(QualifiedName::class, $value, 'The deserializer did not build a qualified name.');
        $this->assertSame('http://example.org/other', $value->getUri());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function foreignDatatypes(): iterable
    {
        yield 'foreign QName local part' => [['other' => 'http://other.example/'], 'other:QName'];
        yield 'foreign qualified-name local part' => [
            ['other' => 'http://other.example/'],
            'other:QUALIFIED_NAME',
        ];
        yield 'plain xsd string' => [[], 'xsd:string'];
    }

    /**
     * @param array<string, string> $prefixes
     */
    #[DataProvider('foreignDatatypes')]
    public function testForeignDatatypesStayLiteralsOnBothPaths(array $prefixes, string $datatype): void
    {
        $json = $this->document($prefixes, $datatype);

        $scanner = new JsonScanner($json);
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'ex:other', 'type' => $datatype]));

        $this->assertInstanceOf(Literal::class, $this->deserializedValue($json));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function reboundReservedPrefixes(): iterable
    {
        yield 'prov rebound' => [
            ['prov' => 'http://foreign.example/prov#'],
            'prov:QUALIFIED_NAME',
            'http://foreign.example/prov#QUALIFIED_NAME',
        ];
        yield 'xsd rebound' => [
            ['xsd' => 'http://foreign.example/xsd#'],
            'xsd:QName',
            'http://foreign.example/xsd#QName',
        ];
    }

    /**
     * A document may bind prov or xsd to something else. The datatype spelled
     * with that prefix is then a foreign datatype and the value stays a
     * literal on both paths.
     *
     * @param array<string, string> $prefixes
     */
    #[DataProvider('reboundReservedPrefixes')]
    public function testReboundReservedPrefixKeepsAForeignDatatype(
        array $prefixes,
        string $datatype,
        string $expectedDatatypeUri,
    ): void {
        $json = $this->document($prefixes, $datatype);

        $scanner = new JsonScanner($json);
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'ex:other', 'type' => $datatype]));

        $value = $this->deserializedValue($json);
        $this->assertInstanceOf(Literal::class, $value);
        $this->assertSame('ex:other', $value->value);
        $this->assertInstanceOf(QualifiedName::class, $value->datatype);
        $this->assertSame($expectedDatatypeUri, $value->datatype->getUri());
    }

    public function testUnknownPrefixIsNotAQualifiedNameAndFailsDeserialization(): void
    {
        $json = $this->document([], 'zz:QUALIFIED_NAME');

        $scanner = new JsonScanner($json);
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'ex:other', 'type' => 'zz:QUALIFIED_NAME']));

        $this->expectException(DeserializationException::class);
        new JsonSerializer()->deserialize($json);
    }

    public function testScannedAndDeserializedValuesResolveToTheSameUri(): void
    {
        $json = $this->document(['p' => 'http://www.w3.org/ns/prov#'], 'p:QUALIFIED_NAME');

        $scanner = new JsonScanner($json);
        $value = $scanner->attributesOf('entity', 'ex:e1')['http://example.org/ref'][0] ?? null;
        $this->assertIsArray($value);
        $this->assertTrue($scanner->isQualifiedNameValue($value));
        $scanned = $scanner->tryResolve((string) $value['$']);
        $this->assertInstanceOf(QualifiedName::class, $scanned);

        $deserialized = $this->deserializedValue($json);
        $this->assertInstanceOf(QualifiedName::class, $deserialized);
        $this->assertSame($scanned->getUri(), $deserialized->getUri());
    }
}
