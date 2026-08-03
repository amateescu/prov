<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\JsonSerializer;

final class DocumentComparatorTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    public function testEmptyDocumentsAreEqual(): void
    {
        $a = new DocumentBuilder()->build();
        $b = new DocumentBuilder()->build();
        $this->assertTrue(DocumentComparator::equals($a, $b));
        $this->assertSame([], DocumentComparator::diff($a, $b));
    }

    public function testDiffReportsOnlyInFirstAndOnlyInSecond(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1');
        $a->entity('ex:e2');

        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->entity('ex:e3');

        $diff = DocumentComparator::diff($a->build(), $b->build());
        $this->assertCount(2, $diff);
        $this->assertStringContainsString('only in first', $diff[0]);
        $this->assertStringContainsString('Entity(http://example.org/e2)', $diff[0]);
        $this->assertStringContainsString('only in second', $diff[1]);
        $this->assertStringContainsString('Entity(http://example.org/e3)', $diff[1]);
    }

    public function testDiffReportsBundleOnlyInOneSide(): void
    {
        $a = $this->buildDoc();
        $a->bundle('ex:b1')->entity('ex:e1');

        $b = $this->buildDoc()->build();

        $diff = DocumentComparator::diff($a->build(), $b);
        $this->assertContains("Bundle 'http://example.org/b1' only in first document.", $diff);
    }

    public function testIdenticalDocumentsAreEqual(): void
    {
        $build = function () {
            $b = $this->buildDoc();
            $b->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
            $b->activity('ex:a1', new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
            $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
            return $b->build();
        };

        $this->assertTrue(DocumentComparator::equals($build(), $build()));
    }

    public function testSameInstantInDifferentOffsetsIsEqual(): void
    {
        $build = function (string $start, string $generated) {
            $b = $this->buildDoc();
            $b->activity('ex:a1', new \DateTimeImmutable($start));
            $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1', time: new \DateTimeImmutable($generated));
            return $b->build();
        };

        // Activity times and relation time formals sign by instant, so the same
        // moment written in two UTC offsets compares equal.
        $this->assertTrue(DocumentComparator::equals(
            $build('2026-01-01T12:00:00+00:00', '2026-01-01T13:30:00.500000+00:00'),
            $build('2026-01-01T14:00:00+02:00', '2026-01-01T15:30:00.500000+02:00'),
        ));

        // Same lexical clock reading in two offsets is two different instants.
        $this->assertFalse(DocumentComparator::equals(
            $build('2026-01-01T12:00:00+00:00', '2026-01-01T13:30:00.500000+00:00'),
            $build('2026-01-01T12:00:00+02:00', '2026-01-01T13:30:00.500000+02:00'),
        ));
    }

    public function testDifferentRecordOrderIsEqual(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1');
        $a->entity('ex:e2');

        $b = $this->buildDoc();
        $b->entity('ex:e2');
        $b->entity('ex:e1');

        $this->assertTrue(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDifferentEntityCountNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1');

        $b = $this->buildDoc();
        $b->entity('ex:e1');
        $b->entity('ex:e2');

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDifferentIdentifiersNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1');

        $b = $this->buildDoc();
        $b->entity('ex:e2');

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDifferentAttributesNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1', ['prov:type' => Literal::string('Document')]);

        $b = $this->buildDoc();
        $b->entity('ex:e1', ['prov:type' => Literal::string('Article')]);

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDifferentRelationTypesNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $b = $this->buildDoc();
        $b->used(activity: 'ex:a1', entity: 'ex:e1');

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testBundleComparison(): void
    {
        $build = function () {
            $b = $this->buildDoc();
            $bb = $b->bundle('ex:b1');
            $bb->entity('ex:e1');
            return $b->build();
        };

        $this->assertTrue(DocumentComparator::equals($build(), $build()));
    }

    public function testDifferentBundleCountNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->bundle('ex:b1')->entity('ex:e1');

        $b = $this->buildDoc();

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDifferentBundleContentNotEqual(): void
    {
        $a = $this->buildDoc();
        $a->bundle('ex:b1')->entity('ex:e1');

        $b = $this->buildDoc();
        $b->bundle('ex:b1')->entity('ex:e2');

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testAnonymousRecordsWithSameFormalAttrsAreEqual(): void
    {
        $a = $this->buildDoc();
        $a->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $b = $this->buildDoc();
        $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $this->assertTrue(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testCrossFormatEquality(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->activity('ex:a1');
        $builder->agent('ex:ag1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');
        $doc = $builder->build();

        $jsonSerializer = new \Prov\Serializer\JsonSerializer();
        $json = $jsonSerializer->serialize($doc);
        $docFromJson = $jsonSerializer->deserialize($json);

        $xmlSerializer = new \Prov\Serializer\XmlSerializer();
        $xml = $xmlSerializer->serialize($doc);
        $docFromXml = $xmlSerializer->deserialize($xml);

        $this->assertTrue(DocumentComparator::equals($docFromJson, $docFromXml));
    }

    public function testCraftedAttributeValueDoesNotForgeEquality(): void
    {
        // A string value embedding the signature delimiters must not let a single-attribute
        // record match a genuinely different two-attribute record.
        $a = $this->buildDoc();
        $a->entity('ex:e', ['ex:a' => 'p^^http://www.w3.org/2001/XMLSchema#string;http://example.org/b=lit:q']);
        $b = $this->buildDoc();
        $b->entity('ex:e', ['ex:a' => 'p', 'ex:b' => 'q']);

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testLanguageTagIsSignificant(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e', ['ex:a' => new Literal('hello', null, 'en')]);
        $b = $this->buildDoc();
        $b->entity('ex:e', ['ex:a' => new Literal('hello', null, 'fr')]);

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDatatypeIsSignificant(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e', ['ex:a' => new Literal('1', $this->ex->qualifiedName('custom'))]);
        $b = $this->buildDoc();
        $b->entity('ex:e', ['ex:a' => new Literal('1', $this->ex->qualifiedName('other'))]);

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testNativeScalarEqualsCanonicalLiteral(): void
    {
        // A native int signs identically to the xsd:int Literal a round-trip produces.
        $a = $this->buildDoc();
        $a->entity('ex:e', ['ex:n' => 42]);
        $b = $this->buildDoc();
        $b->entity('ex:e', ['ex:n' => Literal::int(42)]);

        $this->assertTrue(DocumentComparator::equals($a->build(), $b->build()));
    }

    /**
     * Builds the same linked-blank structure with caller-chosen blank labels.
     */
    private function docWithBlankLabels(string $entityLabel, string $agentLabel): \Prov\Document
    {
        $blank = new ProvNamespace('_', '_:');
        $b = $this->buildDoc();
        $b->entity($blank->qualifiedName($entityLabel));
        $b->agent($blank->qualifiedName($agentLabel));
        $b->wasAttributedTo(entity: $blank->qualifiedName($entityLabel), agent: $blank->qualifiedName($agentLabel));
        return $b->build();
    }

    public function testBlankNodeRenamingComparesEqual(): void
    {
        $a = $this->docWithBlankLabels('b1', 'b2');
        $b = $this->docWithBlankLabels('x7', 'y9');

        $this->assertTrue(DocumentComparator::equals($a, $b));
    }

    public function testSwappedBlankRolesAreNotEqual(): void
    {
        // In $b the attribution points the other way around, so no renaming
        // of blank labels can make the two documents coincide.
        $blank = new ProvNamespace('_', '_:');
        $a = $this->docWithBlankLabels('b1', 'b2');

        $b = $this->buildDoc();
        $b->entity($blank->qualifiedName('b1'));
        $b->agent($blank->qualifiedName('b2'));
        $b->wasAttributedTo(entity: $blank->qualifiedName('b2'), agent: $blank->qualifiedName('b1'));

        $this->assertFalse(DocumentComparator::equals($a, $b->build()));
    }

    public function testDanglingBlankReferenceIsNotEqualToLinkedOne(): void
    {
        $blank = new ProvNamespace('_', '_:');
        $a = $this->buildDoc();
        $a->entity($blank->qualifiedName('b1'));
        $a->wasAttributedTo(entity: $blank->qualifiedName('b1'), agent: 'ex:alice');

        // Same shape, but the attribution references a blank with no entity record.
        $b = $this->buildDoc();
        $b->entity($blank->qualifiedName('b1'));
        $b->wasAttributedTo(entity: $blank->qualifiedName('b2'), agent: 'ex:alice');

        $this->assertFalse(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDistinctAnonymousRecordsCountAsMultiset(): void
    {
        $a = $this->buildDoc();
        $a->entity(null, ['ex:tag' => 'same']);
        $a->entity(null, ['ex:tag' => 'same']);
        $docA = $a->build();

        $b = $this->buildDoc();
        $b->entity(null, ['ex:tag' => 'same']);

        $this->assertFalse(DocumentComparator::equals($docA, $b->build()));

        $c = $this->buildDoc();
        $c->entity(null, ['ex:tag' => 'same']);
        $c->entity(null, ['ex:tag' => 'same']);

        $this->assertTrue(DocumentComparator::equals($docA, $c->build()));
    }

    public function testAnonymousMultiplicityDiffMessageReportsCounts(): void
    {
        $a = $this->buildDoc();
        $a->entity(null, ['ex:tag' => 'same']);
        $a->entity(null, ['ex:tag' => 'same']);

        $b = $this->buildDoc();
        $b->entity(null, ['ex:tag' => 'same']);

        $diff = DocumentComparator::diff($a->build(), $b->build());
        $this->assertCount(1, $diff);
        $this->assertStringContainsString('appears 2 times in first but 1 times in second', $diff[0]);
    }

    public function testBlankAttributeValueComparesUpToRenaming(): void
    {
        $blank = new ProvNamespace('_', '_:');
        $a = $this->buildDoc();
        $a->entity('ex:e1', ['ex:ref' => $blank->qualifiedName('b1')]);
        $a->entity($blank->qualifiedName('b1'));

        $b = $this->buildDoc();
        $b->entity('ex:e1', ['ex:ref' => $blank->qualifiedName('z3')]);
        $b->entity($blank->qualifiedName('z3'));

        $this->assertTrue(DocumentComparator::equals($a->build(), $b->build()));
    }

    public function testDictionaryKeyTypedLiteralEqualsDeserializedPrefixedDatatype(): void
    {
        // A dictionary key typed as a full-URI Literal must compare equal to the
        // same key deserialized from a raw PROV-JSON typed-literal object using
        // a prefixed datatype (xsd:int). Deserialization resolves the object to
        // a real Literal, with its datatype QualifiedName resolved through the
        // document's own namespace bindings, before it ever reaches a
        // DictionaryEntry; the comparator never sees a raw array.
        $a = $this->buildDoc();
        $a->hadDictionaryMember('ex:dict', [
            new \Prov\Relation\Dictionary\DictionaryEntry(Literal::int(5), $this->ex->qualifiedName('e1')),
        ]);

        $json =
            '{"prefix":{"ex":"http://example.org/"},"hadDictionaryMember":{"_:m1":{'
            . '"prov:dictionary":"ex:dict",'
            . '"prov:key-entity-set":[{"key":{"$":"5","type":"xsd:int"},"$":"ex:e1"}]'
            . '}}}';
        $b = new JsonSerializer()->deserialize($json);

        $this->assertTrue(DocumentComparator::equals($a->build(), $b));
    }
}
