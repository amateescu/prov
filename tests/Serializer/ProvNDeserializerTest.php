<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Exception\DeserializationException;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Influence;
use Prov\Relation\Invalidation;
use Prov\Relation\Membership;
use Prov\Relation\Specialization;
use Prov\Relation\Start;
use Prov\Relation\Usage;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;

final class ProvNDeserializerTest extends TestCase
{
    private ProvNDeserializer $deserializer;

    protected function setUp(): void
    {
        $this->deserializer = new ProvNDeserializer();
    }

    private function parse(string $provn): \Prov\Document
    {
        return $this->deserializer->deserialize($provn);
    }

    // --- Basic structure ---

    public function testEmptyDocument(): void
    {
        $doc = $this->parse("document\nendDocument");
        $this->assertCount(0, $doc->records);
        $this->assertCount(0, $doc->bundles);
    }

    public function testNamespaceDeclarations(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nendDocument");
        $byPrefix = [];
        foreach ($doc->namespaces as $ns) {
            $byPrefix[$ns->prefix] = $ns->uri;
        }
        $this->assertSame('http://example.org/', $byPrefix['ex'] ?? null);
    }

    public function testNamespaceIsResolvableInSubsequentRecords(): void
    {
        // Parser-internal nsManager must know the prefix so that records referencing
        // it resolve to the correct URI (not just document-level tracking).
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nentity(ex:e1)\nendDocument");
        $this->assertCount(1, $doc->entities);
        $this->assertSame('http://example.org/e1', $doc->entities[0]->identifier->uri);
    }

    public function testUnknownPrefixInRecordThrows(): void
    {
        // If the prefix declaration is missing, the input is malformed and the
        // failure surfaces under the deserialization contract.
        $this->expectException(\Prov\Exception\DeserializationException::class);
        $this->expectExceptionMessage("Prefix 'unknown' is not registered");
        $this->parse("document\nentity(unknown:e1)\nendDocument");
    }

    public function testDefaultNamespace(): void
    {
        $doc = $this->parse("document\ndefault <http://example.org/>\nentity(e1)\nendDocument");
        $this->assertCount(1, $doc->entities);
        $this->assertSame('http://example.org/e1', $doc->entities[0]->identifier->uri);
    }

    // --- Elements ---

    public function testEntity(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nentity(ex:e1)\nendDocument");
        $this->assertCount(1, $doc->entities);
        $this->assertSame('http://example.org/e1', $doc->entities[0]->identifier->uri);
    }

    public function testEntityWithAttributes(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:type = "Document" %% xsd:string, prov:label = "hello"])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testActivity(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            activity(ex:a1, 2023-01-15T00:00:00+00:00, 2023-12-31T23:59:59+00:00)
            endDocument
            PROVN);
        $a = $doc->activities[0];
        $this->assertSame('2023-01-15T00:00:00+00:00', $a->startTime->format(\DateTimeInterface::ATOM));
        $this->assertSame('2023-12-31T23:59:59+00:00', $a->endTime->format(\DateTimeInterface::ATOM));
    }

    public function testActivityWithDashTimes(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nactivity(ex:a1, -, -)\nendDocument");
        $a = $doc->activities[0];
        $this->assertNull($a->startTime);
        $this->assertNull($a->endTime);
    }

    public function testDateShapedIdentifierIsNotMisparsedAsDateTime(): void
    {
        // '2024-01-15-report' is legal PN_LOCAL and starts with a date
        // prefix but is not a full xsd:dateTime; it must parse as an
        // identifier in both an element position and a relation argument.
        $doc = $this->parse(<<<'PROVN'
            document
            default <http://example.org/>
            prefix ex <http://example.org/ns/>
            entity(2024-01-15-report)
            activity(ex:a1)
            wasGeneratedBy(2024-01-15-report, ex:a1, -)
            endDocument
            PROVN);
        $this->assertCount(1, $doc->entities);
        $this->assertSame('http://example.org/2024-01-15-report', $doc->entities[0]->identifier->uri);
        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        $this->assertSame('http://example.org/2024-01-15-report', $gens[0]->entity->uri);
    }

    public function testOffsetLessDateTimeIsTimezoneIndependent(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $doc = $this->parse(<<<'PROVN'
                document
                prefix ex <http://example.org/>
                activity(ex:a1, 2011-11-16T16:05:00, -)
                endDocument
                PROVN);
        } finally {
            date_default_timezone_set($original);
        }
        $a = $doc->activities[0];
        $this->assertSame('2011-11-16T16:05:00+00:00', $a->startTime->format(\DateTimeInterface::ATOM));
    }

    public function testAgent(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nagent(ex:ag1)\nendDocument");
        $this->assertCount(1, $doc->agents);
    }

    // --- Relations ---

    public function testWasGeneratedBy(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasGeneratedBy(ex:e1, ex:a1, -)\nendDocument");
        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        $this->assertSame('http://example.org/e1', $gens[0]->entity->uri);
        $this->assertSame('http://example.org/a1', $gens[0]->activity->uri);
    }

    public function testWasGeneratedByWithIdentifier(): void
    {
        $doc = $this->parse(
            "document\nprefix ex <http://example.org/>\nwasGeneratedBy(ex:g1; ex:e1, ex:a1, -)\nendDocument",
        );
        $gen = $doc->getRecordsByType(Generation::class)[0];
        $this->assertSame('http://example.org/g1', $gen->identifier->uri);
    }

    public function testUsed(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nused(ex:a1, ex:e1, -)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Usage::class));
    }

    public function testWasInformedBy(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasInformedBy(ex:a2, ex:a1)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Communication::class));
    }

    public function testWasStartedBy(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasStartedBy(ex:a1, ex:e1, -, -)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Start::class));
    }

    public function testWasEndedBy(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasEndedBy(ex:a1, ex:e1, -, -)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(End::class));
    }

    public function testWasInvalidatedBy(): void
    {
        $doc = $this->parse(
            "document\nprefix ex <http://example.org/>\nwasInvalidatedBy(ex:e1, ex:a1, -)\nendDocument",
        );
        $this->assertCount(1, $doc->getRecordsByType(Invalidation::class));
    }

    public function testWasDerivedFrom(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasDerivedFrom(ex:e2, ex:e1)\nendDocument");
        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertSame('http://example.org/e2', $ders[0]->generatedEntity->uri);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function derivationSubtypeKeywords(): iterable
    {
        yield 'wasRevisionOf' => ['wasRevisionOf', 'Revision'];
        yield 'wasQuotedFrom' => ['wasQuotedFrom', 'Quotation'];
        yield 'hadPrimarySource' => ['hadPrimarySource', 'PrimarySource'];
    }

    #[DataProvider('derivationSubtypeKeywords')]
    public function testDerivationSubtypeShortcut(string $keyword, string $subtype): void
    {
        $provn = "document\nprefix ex <http://example.org/>\n{$keyword}(ex:e2, ex:e1)\nendDocument";
        $doc = $this->parse($provn);

        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertSame('http://example.org/e2', $ders[0]->generatedEntity->uri);
        $this->assertSame('http://example.org/e1', $ders[0]->usedEntity->uri);

        $provType = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $typeValues = $ders[0]->attributes->get($provType->qualifiedName('type'));
        $this->assertCount(1, $typeValues);
        $this->assertSame('http://www.w3.org/ns/prov#' . $subtype, $typeValues[0]->uri);
    }

    public function testDerivationSubtypeShortcutWithExtraAttribute(): void
    {
        $provn =
            "document\nprefix ex <http://example.org/>\n"
            . "wasRevisionOf(ex:d1; ex:e2, ex:e1, [prov:label=\"v2\"])\nendDocument";
        $doc = $this->parse($provn);

        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $provType = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $this->assertCount(1, $ders[0]->attributes->get($provType->qualifiedName('type')));
        $this->assertCount(1, $ders[0]->attributes->get($provType->qualifiedName('label')));
    }

    public function testWasAttributedTo(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasAttributedTo(ex:e1, ex:ag1)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Attribution::class));
    }

    public function testWasAssociatedWith(): void
    {
        $doc = $this->parse(
            "document\nprefix ex <http://example.org/>\nwasAssociatedWith(ex:a1, ex:ag1, -)\nendDocument",
        );
        $this->assertCount(1, $doc->getRecordsByType(Association::class));
    }

    public function testActedOnBehalfOf(): void
    {
        $doc = $this->parse(
            "document\nprefix ex <http://example.org/>\nactedOnBehalfOf(ex:ag1, ex:ag2, ex:a1)\nendDocument",
        );
        $this->assertCount(1, $doc->getRecordsByType(Delegation::class));
    }

    public function testWasInfluencedBy(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nwasInfluencedBy(ex:e1, ex:e2)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Influence::class));
    }

    public function testSpecializationOf(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nspecializationOf(ex:e1, ex:e2)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Specialization::class));
    }

    public function testAlternateOf(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nalternateOf(ex:e1, ex:e2)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Alternate::class));
    }

    public function testHadMember(): void
    {
        $doc = $this->parse("document\nprefix ex <http://example.org/>\nhadMember(ex:c1, ex:e1)\nendDocument");
        $this->assertCount(1, $doc->getRecordsByType(Membership::class));
    }

    // --- Bundles ---

    public function testBundle(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            bundle ex:b1
            prefix ex <http://example.org/>
            entity(ex:e1)
            endBundle
            endDocument
            PROVN);
        $this->assertCount(1, $doc->bundles);
        $this->assertSame('http://example.org/b1', $doc->bundles[0]->identifier->uri);
        $this->assertCount(1, $doc->bundles[0]->entities);
    }

    public function testMultipleBundles(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            bundle ex:b1
            prefix ex <http://example.org/>
            entity(ex:e1)
            endBundle
            bundle ex:b2
            prefix ex <http://example.org/>
            entity(ex:e2)
            endBundle
            endDocument
            PROVN);
        $this->assertCount(2, $doc->bundles);
    }

    // --- Attribute values ---

    public function testTypedLiteralAttribute(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:type = "Document" %% xsd:string])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('type'));
        $this->assertCount(1, $values);
        $this->assertInstanceOf(Literal::class, $values[0]);
        $this->assertSame('Document', $values[0]->value);
    }

    public function testLanguageTaggedLiteral(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:label = "bonjour"@fr])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('label'));
        $this->assertInstanceOf(Literal::class, $values[0]);
        $this->assertSame('fr', $values[0]->languageTag);
    }

    public function testQualifiedNameLiteralValue(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:type = 'prov:Person'])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('type'));
        $this->assertInstanceOf(\Prov\Identifier\QualifiedName::class, $values[0]);
        $this->assertSame('http://www.w3.org/ns/prov#Person', $values[0]->uri);
    }

    public function testTripleQuotedString(): void
    {
        $provn = "document\nprefix ex <http://example.org/>\nentity(ex:e1, [prov:label = \"\"\"hello\nover\nlines\"\"\"])\nendDocument";
        $doc = $this->parse($provn);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('label'));
        $this->assertSame("hello\nover\nlines", $values[0]);
    }

    public function testMultipleAttributeValues(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:type = "a" %% xsd:string, prov:type = "b" %% xsd:string])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('type'));
        $this->assertCount(2, $values);
    }

    public function testEscapedStringValue(): void
    {
        $doc = $this->parse(<<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e1, [prov:label = "He said \"hello\""])
            endDocument
            PROVN);
        $entity = $doc->entities[0];
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $values = $entity->attributes->get($prov->qualifiedName('label'));
        $this->assertSame('He said "hello"', $values[0]);
    }

    // --- Comments ---

    public function testLineComments(): void
    {
        $doc = $this->parse(
            "document\n// this is a comment\nprefix ex <http://example.org/>\nentity(ex:e1)\nendDocument",
        );
        $this->assertCount(1, $doc->entities);
    }

    public function testBlockComments(): void
    {
        $doc = $this->parse(
            "document\n/* block\ncomment */\nprefix ex <http://example.org/>\nentity(ex:e1)\nendDocument",
        );
        $this->assertCount(1, $doc->entities);
    }

    // --- Error handling ---

    public function testInvalidInputThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->parse('not a document');
    }

    public function testMissingEndDocumentThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->parse("document\nprefix ex <http://example.org/>\nentity(ex:e1)");
    }

    // --- Round-trip: PROV-N serialize -> PROV-N deserialize -> JSON serialize -> JSON deserialize ---

    public function testRoundTripWithJsonSerializer(): void
    {
        $provn = <<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:article, [prov:type = "Document" %% xsd:string])
            entity(ex:dataset)
            activity(ex:composing, 2023-01-15T00:00:00+00:00, -)
            agent(ex:alice)
            wasGeneratedBy(ex:article, ex:composing, -)
            used(ex:composing, ex:dataset, -)
            wasAssociatedWith(ex:composing, ex:alice, -)
            wasAttributedTo(ex:article, ex:alice)
            wasDerivedFrom(ex:article, ex:dataset)
            endDocument
            PROVN;

        $doc = $this->deserializer->deserialize($provn);

        $this->assertCount(2, $doc->entities);
        $this->assertCount(1, $doc->activities);
        $this->assertCount(1, $doc->agents);
        $this->assertCount(5, $doc->relations);

        // Round-trip through JSON.
        $jsonSerializer = new \Prov\Serializer\JsonSerializer();
        $json = $jsonSerializer->serialize($doc);
        $doc2 = $jsonSerializer->deserialize($json);

        $this->assertCount(2, $doc2->entities);
        $this->assertCount(1, $doc2->activities);
        $this->assertCount(1, $doc2->agents);
        $this->assertCount(5, $doc2->relations);
    }

    public function testRoundTripProvNSerializeDeserialize(): void
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $builder = new DocumentBuilder();
        $builder->addNamespace($ex);
        $builder->entity('ex:e1');
        $builder->activity('ex:a1', new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $builder->agent('ex:ag1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $builder->build();

        $serializer = new ProvNSerializer();
        $provn = $serializer->serialize($doc);

        $doc2 = $this->deserializer->deserialize($provn);

        $this->assertCount(1, $doc2->entities);
        $this->assertCount(1, $doc2->activities);
        $this->assertCount(1, $doc2->agents);
        $this->assertCount(1, $doc2->relations);
    }
}
