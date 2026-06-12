<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Document;
use Prov\Entity;
use Prov\Format;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Prov;
use Prov\Serializer\PrefixMinter;

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

    public function testPrefixForReturnsDeclaredOwnPrefixWithoutMinting(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));
        $minter = new PrefixMinter($manager);

        $qn = new ProvNamespace('ex', 'http://example.org/')->qualifiedName('e1');
        $this->assertSame('ex', $minter->prefixFor($qn));
        $this->assertSame([], $minter->getMintedNamespaces());
    }

    public function testPrefixForMintsOwnPrefixWhenFree(): void
    {
        $manager = new NamespaceManager();
        $minter = new PrefixMinter($manager);

        $qn = new ProvNamespace('foo', 'http://foo.example/')->qualifiedName('x');
        $this->assertSame('foo', $minter->prefixFor($qn));

        $minted = $minter->getMintedNamespaces();
        $this->assertCount(1, $minted);
        $this->assertSame('http://foo.example/', $minted[0]->uri);
        $this->assertSame('http://foo.example/', $manager->getNamespace('foo')?->uri);
    }

    public function testPrefixForMintsSyntheticWhenOwnPrefixTakenByDifferentUri(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('foo', 'http://existing.example/'));
        $minter = new PrefixMinter($manager);

        $qn = new ProvNamespace('foo', 'http://other.example/')->qualifiedName('x');
        $prefix = $minter->prefixFor($qn);

        $this->assertNotSame('foo', $prefix);
        $this->assertStringStartsWith('ns', $prefix);
    }

    public function testPrefixForReusesExistingDeclarationOfSameUriInsteadOfAliasing(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));
        $minter = new PrefixMinter($manager);

        // A different prefix object bound to an already-declared URI.
        $qn = new ProvNamespace('other', 'http://example.org/')->qualifiedName('e1');
        $this->assertSame('ex', $minter->prefixFor($qn));
        $this->assertSame([], $minter->getMintedNamespaces());
    }

    public function testPrefixForCachesMintedPrefixPerUri(): void
    {
        $manager = new NamespaceManager();
        $minter = new PrefixMinter($manager);

        $first = $minter->prefixFor(new ProvNamespace('foo', 'http://foo.example/')->qualifiedName('x'));
        $second = $minter->prefixFor(new ProvNamespace('foo', 'http://foo.example/')->qualifiedName('y'));

        $this->assertSame($first, $second);
        $this->assertCount(1, $minter->getMintedNamespaces());
    }

    public function testPrefixForReusesRealPrefixForDefaultSentinelName(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));
        $minter = new PrefixMinter($manager);

        $qn = new ProvNamespace('default', 'http://example.org/')->qualifiedName('e1');
        $this->assertSame('ex', $minter->prefixFor($qn));
        $this->assertSame([], $minter->getMintedNamespaces());
    }

    public function testPrefixForMintsRealPrefixForUndeclaredDefaultSentinelName(): void
    {
        // The reserved "default" prefix is never written or declared, so a
        // default-namespace name with no other declaration of its URI gets a
        // minted real prefix rather than reusing "default".
        $manager = new NamespaceManager();
        $manager->setDefault(new ProvNamespace('default', 'http://only-default.example/'));
        $minter = new PrefixMinter($manager);

        $qn = new ProvNamespace('default', 'http://only-default.example/')->qualifiedName('e1');
        $prefix = $minter->prefixFor($qn);

        $this->assertNotSame('default', $prefix);
        $this->assertStringStartsWith('ns', $prefix);
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
