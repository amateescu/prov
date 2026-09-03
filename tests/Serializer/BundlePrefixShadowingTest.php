<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryMembership;

/**
 * A bundle may bind a prefix the document already binds to another URI. Every
 * format must keep both URIs intact: a name from the shadowed document
 * namespace cannot be written with the prefix the bundle rebound.
 */
final class BundlePrefixShadowingTest extends TestCase
{
    private ProvNamespace $parent;
    private ProvNamespace $child;

    protected function setUp(): void
    {
        $this->parent = new ProvNamespace('ex', 'http://parent.example/');
        $this->child = new ProvNamespace('ex', 'http://child.example/');
    }

    /**
     * @return list<array{string}>
     */
    public static function roundTripFormats(): array
    {
        return [[Format::Json->value], [Format::ProvN->value], [Format::Xml->value]];
    }

    /**
     * Every URI a bundle record references, in the order the records declare
     * them.
     *
     * @return list<string>
     */
    private function bundleIdentifierUris(Document $document): array
    {
        $uris = [];
        foreach ($document->bundles as $bundle) {
            foreach ($bundle->records as $record) {
                $id = $record->identifier;
                if ($id !== null) {
                    $uris[] = $id->getUri();
                }
            }
        }
        sort($uris);
        return $uris;
    }

    private function roundTrip(Document $document, string $format): Document
    {
        $enum = Format::from($format);
        return $enum->createDeserializer()->deserialize($enum->createSerializer()->serialize($document));
    }

    #[DataProvider('roundTripFormats')]
    public function testShadowedNamedPrefixKeepsIdentifierUris(string $format): void
    {
        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: new QualifiedName($this->parent, 'b1'),
                    records: [
                        new Entity(new QualifiedName($this->child, 'inside')),
                        new Entity(new QualifiedName($this->parent, 'inside')),
                    ],
                    namespaces: [$this->child],
                ),
            ],
            namespaces: [$this->parent],
        );

        $this->assertSame(
            ['http://child.example/inside', 'http://parent.example/inside'],
            $this->bundleIdentifierUris($this->roundTrip($document, $format)),
        );
    }

    #[DataProvider('roundTripFormats')]
    public function testShadowedDefaultNamespaceKeepsIdentifierUris(string $format): void
    {
        $documentDefault = new ProvNamespace('default', 'http://parent.example/');
        $bundleDefault = new ProvNamespace('default', 'http://child.example/');

        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: new QualifiedName($documentDefault, 'b1'),
                    records: [
                        new Entity(new QualifiedName($bundleDefault, 'inside')),
                        new Entity(new QualifiedName($documentDefault, 'inside')),
                    ],
                    namespaces: [$bundleDefault],
                ),
            ],
            namespaces: [$documentDefault],
        );

        $this->assertSame(
            ['http://child.example/inside', 'http://parent.example/inside'],
            $this->bundleIdentifierUris($this->roundTrip($document, $format)),
        );
    }

    #[DataProvider('roundTripFormats')]
    public function testShadowedPrefixKeepsEndpointAndAttributeUris(string $format): void
    {
        $datatype = new QualifiedName($this->parent, 'MyType');
        $attributes = new Attributes([
            'http://parent.example/tag' => [
                new QualifiedName($this->parent, 'value'),
                new Literal('42', $datatype),
            ],
        ], ['http://parent.example/tag' => new QualifiedName($this->parent, 'tag')]);

        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: new QualifiedName($this->parent, 'b1'),
                    records: [
                        new Entity(new QualifiedName($this->child, 'e1')),
                        new Derivation(
                            identifier: null,
                            generatedEntity: new QualifiedName($this->child, 'e1'),
                            usedEntity: new QualifiedName($this->parent, 'e0'),
                            attributes: $attributes,
                        ),
                    ],
                    namespaces: [$this->child],
                ),
            ],
            namespaces: [$this->parent],
        );

        $back = $this->roundTrip($document, $format);
        $bundle = $back->bundles[0];

        $derivation = null;
        foreach ($bundle->records as $record) {
            if ($record instanceof Derivation) {
                $derivation = $record;
            }
        }
        $this->assertInstanceOf(Derivation::class, $derivation);
        $this->assertSame('http://child.example/e1', $derivation->generatedEntity->getUri());
        $this->assertSame('http://parent.example/e0', $derivation->usedEntity?->getUri());

        $values = $derivation->attributes->all()['http://parent.example/tag'] ?? [];
        $this->assertCount(2, $values);
        $qnames = [];
        $datatypes = [];
        foreach ($values as $value) {
            if ($value instanceof QualifiedName) {
                $qnames[] = $value->getUri();
            } elseif ($value instanceof Literal) {
                $datatypes[] = $value->datatype?->getUri();
            }
        }
        $this->assertSame(['http://parent.example/value'], $qnames);
        $this->assertSame(['http://parent.example/MyType'], $datatypes);
    }

    #[DataProvider('roundTripFormats')]
    public function testShadowedPrefixKeepsDictionaryValueUris(string $format): void
    {
        $document = new Document(
            records: [],
            bundles: [
                new Bundle(
                    identifier: new QualifiedName($this->parent, 'b1'),
                    records: [
                        new DictionaryMembership(
                            identifier: null,
                            dictionary: new QualifiedName($this->child, 'd1'),
                            keyEntityPairs: [
                                new DictionaryEntry(
                                    new QualifiedName($this->parent, 'k1'),
                                    new QualifiedName($this->parent, 'v1'),
                                ),
                            ],
                        ),
                    ],
                    namespaces: [$this->child],
                ),
            ],
            namespaces: [$this->parent],
        );

        $back = $this->roundTrip($document, $format);
        $membership = $back->bundles[0]->records[0];
        $this->assertInstanceOf(DictionaryMembership::class, $membership);
        $this->assertSame('http://child.example/d1', $membership->dictionary->getUri());
        $entry = $membership->keyEntityPairs[0];
        $this->assertInstanceOf(QualifiedName::class, $entry->key);
        $this->assertSame('http://parent.example/k1', $entry->key->getUri());
        $this->assertSame('http://parent.example/v1', $entry->entity?->getUri());
    }
}
