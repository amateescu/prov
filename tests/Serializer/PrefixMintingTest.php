<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Document;
use Prov\Entity;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Prov;

/**
 * An attribute key whose namespace was never declared on the document used to
 * serialize as a bare URI (PROV-JSON, PROV-N) or throw (PROV-XML). The
 * serializers now mint a synthetic prefix and declare it, so such documents
 * serialize to parseable output and round-trip.
 */
final class PrefixMintingTest extends TestCase
{
    private function documentWithUndeclaredAttributeKey(): Document
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $undeclared = new ProvNamespace('ghost', 'http://undeclared.example/vocab#');

        $entity = new Entity(
            $ex->qualifiedName('e1'),
            Attributes::single($undeclared->qualifiedName('shade'), 'value'),
        );

        // Constructed directly: only `ex` is declared, the attribute key is not.
        return new Document(records: [$entity], bundles: [], namespaces: [$ex]);
    }

    public function testUndeclaredAttributeKeyRoundTrips(): void
    {
        $document = $this->documentWithUndeclaredAttributeKey();
        $keyUri = 'http://undeclared.example/vocab#shade';

        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            $serialized = Prov::serialize($document, $format);
            $roundTripped = Prov::deserialize($serialized, $format);

            $key = new ProvNamespace('any', 'http://undeclared.example/vocab#')->qualifiedName('shade');
            $this->assertSame(
                ['value'],
                $roundTripped->entities[0]->attributes->get($key),
                "Attribute key '{$keyUri}' did not survive {$format->name}.",
            );
        }
    }

    public function testMintedPrefixIsDeclaredInProvNHeader(): void
    {
        $output = Prov::serialize($this->documentWithUndeclaredAttributeKey(), Format::ProvN);

        $this->assertMatchesRegularExpression('/prefix ns\d+ <http:\/\/undeclared\.example\/vocab#>/', $output);
    }

    public function testMintedPrefixIsDeclaredInJsonPrefixBlock(): void
    {
        $output = Prov::serialize($this->documentWithUndeclaredAttributeKey(), Format::Json);
        $data = json_decode($output, true);

        $this->assertContains('http://undeclared.example/vocab#', $data['prefix']);
    }

    public function testMintedPrefixIsDeclaredInJsonLdContext(): void
    {
        $output = Prov::serialize($this->documentWithUndeclaredAttributeKey(), Format::JsonLd);
        $data = json_decode($output, true);

        $this->assertContains('http://undeclared.example/vocab#', $data['@context']);
    }

    public function testUndeclaredKeyInBundleRecordsRoundTrips(): void
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $undeclared = new ProvNamespace('ghost', 'http://undeclared.example/vocab#');
        $entity = new Entity(
            $ex->qualifiedName('e1'),
            Attributes::single($undeclared->qualifiedName('shade'), 'value'),
        );
        $bundle = new \Prov\Bundle($ex->qualifiedName('b1'), [$entity], []);
        $document = new Document(records: [], bundles: [$bundle], namespaces: [$ex]);

        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($document, $format), $format);

            $key = $undeclared->qualifiedName('shade');
            $this->assertSame(
                ['value'],
                $roundTripped->bundles[0]->entities[0]->attributes->get($key),
                "Bundle attribute key did not survive {$format->name}.",
            );
        }
    }
}
