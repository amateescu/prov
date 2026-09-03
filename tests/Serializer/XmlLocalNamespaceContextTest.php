<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Generation;
use Prov\Serializer\XmlSerializer;

/**
 * XML resolves a prefix at the element that uses it, so a declaration on the
 * element carrying `prov:id`, `prov:ref`, or `xsi:type` is in scope for that
 * value even though the document root never declares it.
 */
final class XmlLocalNamespaceContextTest extends TestCase
{
    private XmlSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new XmlSerializer();
    }

    private function parse(string $body): Document
    {
        return $this->serializer->deserialize(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . $body
            . '</prov:document>',
        );
    }

    /**
     * @return list<string>
     */
    private function identifierUris(Document $document): array
    {
        $uris = [];
        foreach ($document->records as $record) {
            $id = $record->identifier;
            if ($id !== null) {
                $uris[] = $id->getUri();
            }
        }
        return $uris;
    }

    public function testLocalPrefixOnAnEntityIdResolves(): void
    {
        $document = $this->parse('<prov:entity xmlns:ex="http://example.org/" prov:id="ex:e1"/>');

        $this->assertSame(['http://example.org/e1'], $this->identifierUris($document));
    }

    public function testLocalPrefixOnAFormalRefResolves(): void
    {
        $document = $this->parse(
            '<prov:wasGeneratedBy>'
            . '<prov:entity xmlns:ex="http://example.org/" prov:ref="ex:e1"/>'
            . '<prov:activity xmlns:other="http://other.example/" prov:ref="other:a1"/>'
            . '</prov:wasGeneratedBy>',
        );

        $generation = $document->records[0];
        $this->assertInstanceOf(Generation::class, $generation);
        $this->assertSame('http://example.org/e1', $generation->entity->getUri());
        $this->assertSame('http://other.example/a1', $generation->activity?->getUri());
    }

    public function testLocalPrefixOnDictionaryChildrenResolves(): void
    {
        $document = $this->parse(
            '<prov:hadDictionaryMember>'
            . '<prov:dictionary xmlns:ex="http://example.org/" prov:ref="ex:d1"/>'
            . '<prov:keyEntityPair>'
            . '<prov:key xsi:type="xsd:string" xmlns:xsd="http://www.w3.org/2001/XMLSchema">k1</prov:key>'
            . '<prov:entity xmlns:ex="http://example.org/" prov:ref="ex:v1"/>'
            . '</prov:keyEntityPair>'
            . '</prov:hadDictionaryMember>',
        );

        $membership = $document->records[0];
        $this->assertInstanceOf(DictionaryMembership::class, $membership);
        $this->assertSame('http://example.org/d1', $membership->dictionary->getUri());
        $this->assertSame('http://example.org/v1', $membership->keyEntityPairs[0]->entity?->getUri());
    }

    public function testLocalXsdAliasAndLocalPrefixInAQNameValue(): void
    {
        $document = $this->parse(
            '<prov:entity xmlns:ex="http://example.org/" prov:id="ex:e1">'
            . '<ex:ref xmlns:xs="http://www.w3.org/2001/XMLSchema#" xmlns:v="http://vocab.example/"'
            . ' xsi:type="xs:QName">v:thing</ex:ref>'
            . '</prov:entity>',
        );

        $entity = $document->records[0];
        $this->assertInstanceOf(Entity::class, $entity);
        $values = $entity->attributes->all()['http://example.org/ref'] ?? [];
        $this->assertCount(1, $values);
        $this->assertInstanceOf(QualifiedName::class, $values[0]);
        $this->assertSame('http://vocab.example/thing', $values[0]->getUri());
    }

    public function testLocalPrefixInATypedLiteralDatatype(): void
    {
        $document = $this->parse(
            '<prov:entity xmlns:ex="http://example.org/" prov:id="ex:e1">'
            . '<ex:size xmlns:u="http://units.example/" xsi:type="u:Metres">5</ex:size>'
            . '</prov:entity>',
        );

        $entity = $document->records[0];
        $this->assertInstanceOf(Entity::class, $entity);
        $values = $entity->attributes->all()['http://example.org/size'] ?? [];
        $this->assertCount(1, $values);
        $this->assertInstanceOf(Literal::class, $values[0]);
        $this->assertSame('http://units.example/Metres', $values[0]->datatype?->getUri());
    }

    public function testNestedPrefixShadowingResolvesPerElement(): void
    {
        $document = $this->serializer->deserialize(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://a.example/">'
            . '<prov:entity prov:id="ex:e1"/>'
            . '<prov:entity xmlns:ex="http://b.example/" prov:id="ex:e1"/>'
            . '</prov:document>',
        );

        $this->assertSame(['http://a.example/e1', 'http://b.example/e1'], $this->identifierUris($document));
    }

    public function testLocalPrefixOnABundleIdAndItsRecords(): void
    {
        $document = $this->serializer->deserialize(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#">'
            . '<prov:bundleContent xmlns:ex="http://example.org/" prov:id="ex:b1">'
            . '<prov:entity prov:id="ex:inBundle"/>'
            . '</prov:bundleContent>'
            . '</prov:document>',
        );

        $this->assertCount(1, $document->bundles);
        $this->assertSame('http://example.org/b1', $document->bundles[0]->identifier->getUri());
        $this->assertSame(
            ['http://example.org/inBundle'],
            $this->identifierUris(new Document($document->bundles[0]->records, [], [])),
        );
    }

    public function testUndeclaredPrefixStillFails(): void
    {
        $this->expectException(\Prov\Exception\DeserializationException::class);
        $this->parse('<prov:entity prov:id="nowhere:e1"/>');
    }

    public function testElementLocalDefaultNamespaceStillResolvesBareNames(): void
    {
        $document = $this->parse('<prov:entity xmlns="http://default.example/" prov:id="e1"/>');

        $this->assertSame(['http://default.example/e1'], $this->identifierUris($document));
    }
}
