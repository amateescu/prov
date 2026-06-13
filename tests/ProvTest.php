<?php

declare(strict_types=1);

namespace Prov\Tests;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Exception\ProvException;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Prov;

final class ProvTest extends TestCase
{
    private function buildSimpleDoc(): \Prov\Document
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $b = new DocumentBuilder();
        $b->addNamespace($ex);
        $b->entity('ex:e1');
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        return $b->build();
    }

    public function testSerializeJson(): void
    {
        $json = Prov::serialize($this->buildSimpleDoc(), Format::Json);
        $data = json_decode($json, true);
        $this->assertArrayHasKey('entity', $data);
    }

    public function testSerializeProvN(): void
    {
        $provn = Prov::serialize($this->buildSimpleDoc(), Format::ProvN);
        $this->assertStringContainsString('document', $provn);
        $this->assertStringContainsString('entity(ex:e1)', $provn);
    }

    public function testSerializeXml(): void
    {
        $xml = Prov::serialize($this->buildSimpleDoc(), Format::Xml);
        $this->assertStringContainsString('prov:document', $xml);
    }

    public function testSerializeJsonLd(): void
    {
        $jsonld = Prov::serialize($this->buildSimpleDoc(), Format::JsonLd);
        $data = json_decode($jsonld, true);
        $this->assertArrayHasKey('@context', $data);
    }

    public function testDeserializeJson(): void
    {
        $json = Prov::serialize($this->buildSimpleDoc(), Format::Json);
        $doc = Prov::deserialize($json, Format::Json);
        $this->assertCount(1, $doc->entities);
    }

    public function testDeserializeProvN(): void
    {
        $provn = Prov::serialize($this->buildSimpleDoc(), Format::ProvN);
        $doc = Prov::deserialize($provn, Format::ProvN);
        $this->assertCount(1, $doc->entities);
    }

    public function testDeserializeXml(): void
    {
        $xml = Prov::serialize($this->buildSimpleDoc(), Format::Xml);
        $doc = Prov::deserialize($xml, Format::Xml);
        $this->assertCount(1, $doc->entities);
    }

    public function testDeserializeJsonLdThrows(): void
    {
        $this->expectException(ProvException::class);
        $document = Prov::deserialize('{}', Format::JsonLd);
        // Unreachable: the call above throws. Referencing the result keeps the
        // #[\NoDiscard] return from registering as discarded.
        $this->assertInstanceOf(\Prov\Document::class, $document);
    }

    public function testDefaultFormatIsJson(): void
    {
        $json = Prov::serialize($this->buildSimpleDoc());
        $doc = Prov::deserialize($json);
        $this->assertCount(1, $doc->entities);
    }

    public function testProvNamespace(): void
    {
        $prov = ProvNamespace::prov();
        $this->assertInstanceOf(ProvNamespace::class, $prov);
        $this->assertSame('prov', $prov->prefix);
        $this->assertSame('http://www.w3.org/ns/prov#', $prov->uri);
    }

    public function testXsdNamespace(): void
    {
        $xsd = ProvNamespace::xsd();
        $this->assertInstanceOf(ProvNamespace::class, $xsd);
        $this->assertSame('xsd', $xsd->prefix);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#', $xsd->uri);
    }

    public function testNamespaceFactoriesReturnCachedInstances(): void
    {
        $prov1 = ProvNamespace::prov();
        $prov2 = ProvNamespace::prov();
        $this->assertSame($prov1, $prov2);

        $xsd1 = ProvNamespace::xsd();
        $xsd2 = ProvNamespace::xsd();
        $this->assertSame($xsd1, $xsd2);
    }

    public function testQualifiedNameCreation(): void
    {
        $qn = ProvNamespace::prov()->qualifiedName('Entity');
        $this->assertSame('http://www.w3.org/ns/prov#Entity', $qn->uri);
        $this->assertSame('prov:Entity', (string) $qn);
    }
}
