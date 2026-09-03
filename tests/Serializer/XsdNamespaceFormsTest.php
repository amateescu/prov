<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Document;
use Prov\Entity;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Operation\DocumentComparator;
use Prov\Prov;
use Prov\Serializer\JsonLdSerializer;

/**
 * The XSD namespace is written with a trailing `#` in PROV-JSONLD and PROV-N
 * and without one in PROV-XML. The two forms are the same datatype namespace,
 * but they build different URIs for every other name: `xsd:e1` is
 * `http://www.w3.org/2001/XMLSchema#e1` under one and
 * `http://www.w3.org/2001/XMLSchemae1` under the other. A serializer keeps the
 * `xsd` prefix only for the form it binds itself and gives the other form a
 * prefix of its own, so identifiers and QName values keep their exact URI.
 * Literal datatypes still compare across the two forms.
 */
final class XsdNamespaceFormsTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    /**
     * @return iterable<string, list<\Prov\Identifier\ProvNamespace>>
     */
    public static function xsdForms(): iterable
    {
        yield 'hash' => [new ProvNamespace('xsd', 'http://www.w3.org/2001/XMLSchema#')];
        yield 'hashless' => [new ProvNamespace('xsd', 'http://www.w3.org/2001/XMLSchema')];
    }

    /**
     * @param array<string, string> $context
     */
    private function expandCompactIri(string $value, array $context): string
    {
        $colon = strpos($value, ':');
        if ($colon === false) {
            return ($context['@vocab'] ?? '') . $value;
        }
        $prefix = substr($value, 0, $colon);
        $local = substr($value, $colon + 1);
        return isset($context[$prefix]) ? $context[$prefix] . $local : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(Document $document): array
    {
        /** @var array<string, mixed> */
        return json_decode(new JsonLdSerializer()->serialize($document), true, flags: JSON_THROW_ON_ERROR);
    }

    private function documentWithIdentifier(ProvNamespace $xsd): Document
    {
        return new Document([new Entity($xsd->qualifiedName('e1'))], [], [$xsd]);
    }

    private function documentWithQNameValue(ProvNamespace $xsd): Document
    {
        return new Document(
            [
                new Entity(
                    $this->ex->qualifiedName('e1'),
                    Attributes::single(ProvNamespace::prov()->qualifiedName('type'), $xsd->qualifiedName('t')),
                ),
            ],
            [],
            [$this->ex, $xsd],
        );
    }

    /**
     * The `prov:type` value of the first record, as its expanded URI.
     */
    private function typeValueUri(Document $document): ?string
    {
        $values = $document->records[0]->attributes->all()['http://www.w3.org/ns/prov#type'] ?? [];
        $value = $values[0] ?? null;
        $this->assertInstanceOf(QualifiedName::class, $value);
        return $value->getUri();
    }

    // PROV-XML

    #[DataProvider('xsdForms')]
    public function testXmlKeepsXsdIdentifiersExact(ProvNamespace $xsd): void
    {
        $document = $this->documentWithIdentifier($xsd);

        $back = Prov::deserialize(Prov::serialize($document, Format::Xml), Format::Xml);

        $this->assertSame($xsd->uri . 'e1', $back->records[0]->identifier?->getUri());
    }

    #[DataProvider('xsdForms')]
    public function testXmlKeepsXsdQNameValuesExact(ProvNamespace $xsd): void
    {
        $document = $this->documentWithQNameValue($xsd);

        $back = Prov::deserialize(Prov::serialize($document, Format::Xml), Format::Xml);

        $this->assertSame($xsd->uri . 't', $this->typeValueUri($back));
    }

    // PROV-N

    #[DataProvider('xsdForms')]
    public function testProvNKeepsXsdIdentifiersExact(ProvNamespace $xsd): void
    {
        $document = $this->documentWithIdentifier($xsd);

        $back = Prov::deserialize(Prov::serialize($document, Format::ProvN), Format::ProvN);

        $this->assertSame($xsd->uri . 'e1', $back->records[0]->identifier?->getUri());
    }

    #[DataProvider('xsdForms')]
    public function testProvNKeepsXsdQNameValuesExact(ProvNamespace $xsd): void
    {
        $document = $this->documentWithQNameValue($xsd);

        $back = Prov::deserialize(Prov::serialize($document, Format::ProvN), Format::ProvN);

        $this->assertSame($xsd->uri . 't', $this->typeValueUri($back));
    }

    // PROV-JSONLD

    #[DataProvider('xsdForms')]
    public function testJsonLdKeepsXsdIdentifiersExact(ProvNamespace $xsd): void
    {
        $data = $this->jsonLd($this->documentWithIdentifier($xsd));
        /** @var array<string, string> $context */
        $context = $data['@context'];

        $this->assertSame($xsd->uri . 'e1', $this->expandCompactIri((string) $data['@id'], $context));
    }

    #[DataProvider('xsdForms')]
    public function testJsonLdKeepsXsdQNameValuesExact(ProvNamespace $xsd): void
    {
        $data = $this->jsonLd($this->documentWithQNameValue($xsd));
        /** @var array<string, string> $context */
        $context = $data['@context'];
        /** @var array<string, string> $type */
        $type = $data['prov:type'];

        $this->assertSame($xsd->uri . 't', $this->expandCompactIri($type['@id'], $context));
    }

    // Literal datatypes

    /**
     * Datatype identity treats the two XSD forms as one, so a literal typed
     * with either form survives a round trip through a serializer that binds
     * the other.
     */
    #[DataProvider('xsdForms')]
    public function testXsdLiteralDatatypesStillCompareAcrossForms(ProvNamespace $xsd): void
    {
        $document = new Document(
            [
                new Entity(
                    $this->ex->qualifiedName('e1'),
                    Attributes::single($this->ex->qualifiedName('k'), new Literal('42', $xsd->qualifiedName('int'))),
                ),
            ],
            [],
            [$this->ex, $xsd],
        );

        foreach ([Format::Xml, Format::ProvN] as $format) {
            $back = Prov::deserialize(Prov::serialize($document, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($document, $back),
                "The literal datatype did not survive {$format->name}.",
            );
        }
    }
}
