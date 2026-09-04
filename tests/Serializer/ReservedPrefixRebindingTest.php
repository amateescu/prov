<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Prov;
use Prov\Serializer\JsonLdSerializer;

/**
 * A document may bind `prov` or `xsd` to a namespace of its own. PROV-XML and
 * PROV-JSONLD write prov:* and xsd:* terms themselves, so those two prefixes
 * keep their canonical meaning in the output and the caller's names get another
 * prefix. Both sides have to hold: the caller's URIs survive, and the
 * structural terms still name PROV.
 */
final class ReservedPrefixRebindingTest extends TestCase
{
    private const string PROV_URI = 'http://www.w3.org/ns/prov#';

    private ProvNamespace $ex;
    private ProvNamespace $foreignProv;
    private ProvNamespace $foreignXsd;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->foreignProv = new ProvNamespace('prov', 'http://foreign.example/prov#');
        $this->foreignXsd = new ProvNamespace('xsd', 'http://foreign.example/xsd#');
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

    // PROV-XML

    public function testXmlKeepsAForeignDocumentProvBinding(): void
    {
        $document = new Document([new Entity($this->foreignProv->qualifiedName('e1'))], [], [$this->foreignProv]);

        $xml = Prov::serialize($document, Format::Xml);
        $this->assertStringContainsString('xmlns:prov="' . self::PROV_URI . '"', $xml);

        $dom = \Dom\XMLDocument::createFromString($xml);
        $this->assertSame(self::PROV_URI, $dom->documentElement?->namespaceURI);

        $back = Prov::deserialize($xml, Format::Xml);
        $this->assertSame('http://foreign.example/prov#e1', $back->records[0]->identifier?->getUri());
    }

    public function testXmlKeepsAForeignBundleProvBinding(): void
    {
        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: $this->ex->qualifiedName('b1'),
                    records: [new Entity($this->foreignProv->qualifiedName('e1'))],
                    namespaces: [$this->foreignProv],
                ),
            ],
            namespaces: [$this->ex],
        );

        $back = Prov::deserialize(Prov::serialize($document, Format::Xml), Format::Xml);

        $this->assertSame('http://foreign.example/prov#e1', $back->bundles[0]->records[0]->identifier?->getUri());
    }

    public function testXmlKeepsAForeignXsdBindingOnIdentifiers(): void
    {
        $document = new Document([new Entity($this->foreignXsd->qualifiedName('e1'))], [], [$this->foreignXsd]);

        $back = Prov::deserialize(Prov::serialize($document, Format::Xml), Format::Xml);

        $this->assertSame('http://foreign.example/xsd#e1', $back->records[0]->identifier?->getUri());
    }

    public function testXmlKeepsAForeignXsdBindingOnLiteralDatatypes(): void
    {
        $document = new Document(
            [
                new Entity(
                    $this->ex->qualifiedName('e1'),
                    Attributes::single(
                        $this->ex->qualifiedName('k'),
                        new Literal('v', $this->foreignXsd->qualifiedName('myType')),
                    ),
                ),
            ],
            [],
            [$this->ex, $this->foreignXsd],
        );

        $back = Prov::deserialize(Prov::serialize($document, Format::Xml), Format::Xml);

        $value = $back->records[0]->attributes->all()['http://example.org/k'][0] ?? null;
        $this->assertInstanceOf(Literal::class, $value);
        $this->assertSame('http://foreign.example/xsd#myType', $value->datatype?->getUri());
    }

    // PROV-JSONLD

    public function testJsonLdKeepsAForeignDocumentProvBinding(): void
    {
        $document = new Document([new Entity($this->foreignProv->qualifiedName('e1'))], [], [$this->foreignProv]);

        $data = $this->jsonLd($document);
        /** @var array<string, string> $context */
        $context = $data['@context'];

        $this->assertSame(self::PROV_URI, $context['prov']);
        $this->assertSame(self::PROV_URI . 'Entity', $this->expandCompactIri((string) $data['@type'], $context));
        $this->assertSame('http://foreign.example/prov#e1', $this->expandCompactIri((string) $data['@id'], $context));
    }

    public function testJsonLdKeepsAForeignBundleProvBinding(): void
    {
        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: $this->ex->qualifiedName('b1'),
                    records: [new Entity($this->foreignProv->qualifiedName('e1'))],
                    namespaces: [$this->foreignProv],
                ),
            ],
            namespaces: [$this->ex],
        );

        $data = $this->jsonLd($document);
        /** @var array<string, string> $context */
        $context = $data['@context'];
        /** @var list<array<string, mixed>> $graph */
        $graph = $data['@graph'];
        $bundleNode = $graph[0];
        /** @var list<array<string, mixed>> $bundleGraph */
        $bundleGraph = $bundleNode['@graph'];

        $this->assertSame(self::PROV_URI, $context['prov']);
        $this->assertSame(self::PROV_URI . 'Bundle', $this->expandCompactIri((string) $bundleNode['@type'], $context));
        $this->assertSame('http://foreign.example/prov#e1', $this->expandCompactIri(
            (string) $bundleGraph[0]['@id'],
            $context,
        ));
    }

    public function testJsonLdKeepsAForeignXsdBindingOnIdentifiersAndDatatypes(): void
    {
        $document = new Document(
            [
                new Entity(
                    $this->foreignXsd->qualifiedName('e1'),
                    Attributes::single(
                        $this->ex->qualifiedName('k'),
                        new Literal('v', $this->foreignXsd->qualifiedName('myType')),
                    ),
                ),
            ],
            [],
            [$this->ex, $this->foreignXsd],
        );

        $data = $this->jsonLd($document);
        /** @var array<string, string> $context */
        $context = $data['@context'];
        /** @var array<string, string> $value */
        $value = $data['ex:k'];

        $this->assertSame('http://www.w3.org/2001/XMLSchema#', $context['xsd']);
        $this->assertSame('http://foreign.example/xsd#e1', $this->expandCompactIri((string) $data['@id'], $context));
        $this->assertSame('http://foreign.example/xsd#myType', $this->expandCompactIri($value['@type'], $context));
    }
}
