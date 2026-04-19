<?php

declare(strict_types=1);

namespace Prov\Tests\Relation\Dictionary;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;

final class DictionaryEntryTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testStoresKeyAndEntityVerbatim(): void
    {
        $e = $this->ex->qualifiedName('e1');
        $entry = new DictionaryEntry('k1', $e);
        $this->assertSame('k1', $entry->key);
        $this->assertSame($e, $entry->entity);
    }

    public function testAcceptsNullEntity(): void
    {
        $entry = new DictionaryEntry('k1', null);
        $this->assertNull($entry->entity);
    }

    public function testAcceptsLiteralKey(): void
    {
        $lit = new Literal('k1', $this->ex->qualifiedName('MyKey'));
        $entry = new DictionaryEntry($lit, $this->ex->qualifiedName('e1'));
        $this->assertSame($lit, $entry->key);
    }

    public function testBuilderBuildsDictionaryMembershipFromEntries(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->hadDictionaryMember(dictionary: 'ex:d1', keyEntityPairs: [
            new DictionaryEntry('k1', $this->ex->qualifiedName('e1')),
            new DictionaryEntry('k2', $this->ex->qualifiedName('e2')),
        ]);
        $doc = $builder->build();

        $rel = $doc->getRecordsByType(DictionaryMembership::class)[0];
        $this->assertCount(2, $rel->keyEntityPairs);
        $this->assertInstanceOf(DictionaryEntry::class, $rel->keyEntityPairs[0]);
        $this->assertSame('k1', $rel->keyEntityPairs[0]->key);
        $this->assertSame('http://example.org/e1', $rel->keyEntityPairs[0]->entity->getUri());
    }

    public function testJsonRoundTripPreservesEntries(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->derivedByInsertionFrom(after: 'ex:d2', before: 'ex:d1', keyEntityPairs: [
            new DictionaryEntry('alpha', $this->ex->qualifiedName('e1')),
            new DictionaryEntry('beta', $this->ex->qualifiedName('e2')),
        ]);
        $original = $builder->build();

        $json = new JsonSerializer();
        $roundTripped = $json->deserialize($json->serialize($original));

        $rel = $roundTripped->getRecordsByType(DictionaryInsertion::class)[0];
        $this->assertCount(2, $rel->keyEntityPairs);
        $this->assertContainsOnlyInstancesOf(DictionaryEntry::class, $rel->keyEntityPairs);

        $byKey = [];
        foreach ($rel->keyEntityPairs as $pair) {
            $byKey[(string) $pair->key] = $pair->entity?->getUri();
        }
        $this->assertSame(
            [
                'alpha' => 'http://example.org/e1',
                'beta' => 'http://example.org/e2',
            ],
            $byKey,
        );
    }

    public function testProvNRoundTripPreservesEntries(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->hadDictionaryMember(dictionary: 'ex:d1', keyEntityPairs: [new DictionaryEntry(
            'solo',
            $this->ex->qualifiedName('e1'),
        )]);
        $original = $builder->build();

        $serializer = new ProvNSerializer();
        $deserializer = new ProvNDeserializer();
        $roundTripped = $deserializer->deserialize($serializer->serialize($original));

        $rel = $roundTripped->getRecordsByType(DictionaryMembership::class)[0];
        $this->assertCount(1, $rel->keyEntityPairs);
        $pair = $rel->keyEntityPairs[0];
        $this->assertInstanceOf(DictionaryEntry::class, $pair);
        $this->assertSame('solo', $pair->key);
        $this->assertSame('http://example.org/e1', $pair->entity->getUri());
    }
}
