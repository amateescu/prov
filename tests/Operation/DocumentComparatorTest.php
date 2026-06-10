<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;

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
}
