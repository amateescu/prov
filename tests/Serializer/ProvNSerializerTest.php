<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Serializer\ProvNSerializer;

final class ProvNSerializerTest extends TestCase
{
    private ProvNamespace $ex;
    private ProvNSerializer $serializer;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->serializer = new ProvNSerializer();
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    public function testEmptyDocument(): void
    {
        $doc = new DocumentBuilder()->build();
        $output = $this->serializer->serialize($doc);

        $this->assertStringStartsWith('document', $output);
        $this->assertStringEndsWith("endDocument\n", $output);
    }

    public function testNamespaceDeclarations(): void
    {
        $doc = $this->buildDoc()->build();
        $output = $this->serializer->serialize($doc);

        $this->assertStringContainsString('prefix ex <http://example.org/>', $output);
        $this->assertStringContainsString('prefix prov <http://www.w3.org/ns/prov#>', $output);
    }

    public function testDefaultNamespaceDeclaration(): void
    {
        $builder = new DocumentBuilder();
        $builder->setDefaultNamespace(new ProvNamespace('default', 'http://default.org/'));
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('default <http://default.org/>', $output);
    }

    public function testEntity(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('entity(ex:e1)', $output);
    }

    public function testEntityWithAttributes(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('entity(ex:e1, [prov:type = "Document" %% xsd:string])', $output);
    }

    public function testActivity(): void
    {
        $builder = $this->buildDoc();
        $start = new \DateTimeImmutable('2023-01-15T00:00:00+00:00');
        $builder->activity('ex:a1', startTime: $start);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('activity(ex:a1, 2023-01-15T00:00:00+00:00, -)', $output);
    }

    public function testActivityWithBothTimes(): void
    {
        $builder = $this->buildDoc();
        $start = new \DateTimeImmutable('2023-01-01T00:00:00+00:00');
        $end = new \DateTimeImmutable('2023-12-31T23:59:59+00:00');
        $builder->activity('ex:a1', $start, $end);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString(
            'activity(ex:a1, 2023-01-01T00:00:00+00:00, 2023-12-31T23:59:59+00:00)',
            $output,
        );
    }

    public function testAgent(): void
    {
        $builder = $this->buildDoc();
        $builder->agent('ex:ag1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('agent(ex:ag1)', $output);
    }

    public function testWasGeneratedBy(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasGeneratedBy(ex:e1, ex:a1, -)', $output);
    }

    public function testWasGeneratedByWithIdentifier(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasGeneratedBy(ex:g1; ex:e1, ex:a1, -)', $output);
    }

    public function testUsed(): void
    {
        $builder = $this->buildDoc();
        $builder->used(activity: 'ex:a1', entity: 'ex:e1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('used(ex:a1, ex:e1, -)', $output);
    }

    public function testWasDerivedFrom(): void
    {
        $builder = $this->buildDoc();
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasDerivedFrom(ex:e2, ex:e1, -, -, -)', $output);
    }

    public function testWasAttributedTo(): void
    {
        $builder = $this->buildDoc();
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasAttributedTo(ex:e1, ex:ag1)', $output);
    }

    public function testSpecializationOf(): void
    {
        $builder = $this->buildDoc();
        $builder->specializationOf(specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('specializationOf(ex:e1, ex:e2)', $output);
    }

    public function testAlternateOf(): void
    {
        $builder = $this->buildDoc();
        $builder->alternateOf(alternate1: 'ex:e1', alternate2: 'ex:e2');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('alternateOf(ex:e1, ex:e2)', $output);
    }

    public function testHadMember(): void
    {
        $builder = $this->buildDoc();
        $builder->hadMember(collection: 'ex:c1', entity: 'ex:e1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('hadMember(ex:c1, ex:e1)', $output);
    }

    public function testBundleOutput(): void
    {
        $builder = $this->buildDoc();
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');

        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('bundle ex:b1', $output);
        $this->assertStringContainsString('entity(ex:e1)', $output);
        $this->assertStringContainsString('endBundle', $output);
    }

    public function testCustomIndentation(): void
    {
        $serializer = new ProvNSerializer(indentation: 4);
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $output = $serializer->serialize($builder->build());

        $this->assertStringContainsString('    entity(ex:e1)', $output);
    }

    public function testFullDocumentStructure(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:article', ['prov:type' => Literal::string('Document')]);
        $builder->activity('ex:composing', startTime: new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $builder->agent('ex:alice');
        $builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:composing');
        $builder->wasAttributedTo(entity: 'ex:article', agent: 'ex:alice');

        $bundleBuilder = $builder->bundle('ex:provenance');
        $bundleBuilder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:composing');

        $output = $this->serializer->serialize($builder->build());

        $this->assertStringStartsWith('document', $output);
        $this->assertStringContainsString('prefix ex <http://example.org/>', $output);
        $this->assertStringContainsString('entity(ex:article', $output);
        $this->assertStringContainsString('activity(ex:composing', $output);
        $this->assertStringContainsString('agent(ex:alice)', $output);
        $this->assertStringContainsString('wasGeneratedBy(', $output);
        $this->assertStringContainsString('wasAttributedTo(', $output);
        $this->assertStringContainsString('bundle ex:provenance', $output);
        $this->assertStringContainsString('endBundle', $output);
        $this->assertStringEndsWith("endDocument\n", $output);
    }

    public function testIncludeDefaultNamespaceFalse(): void
    {
        $serializer = new ProvNSerializer(includeDefaultNamespace: false);
        $builder = new DocumentBuilder();
        $builder->setDefaultNamespace(new ProvNamespace('default', 'http://default.org/'));
        $builder->entity('myEntity');

        $output = $serializer->serialize($builder->build());

        $this->assertStringNotContainsString('default <http://default.org/>', $output);
    }

    public function testLiteralWithLanguageTag(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('label'), new Literal('Mon Article', languageTag: 'fr'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"Mon Article"@fr', $output);
    }

    public function testQualifiedNameAttributeValue(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $this->ex->qualifiedName('MyType'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString("'ex:MyType'", $output);
    }

    public function testAllRelationTypesInProvN(): void
    {
        $builder = $this->buildDoc();
        $builder->wasInformedBy(informed: 'ex:a1', informant: 'ex:a2');
        $builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:ag1');
        $builder->actedOnBehalfOf(delegate: 'ex:ag1', responsible: 'ex:ag2');
        $builder->wasInfluencedBy(influencee: 'ex:e1', influencer: 'ex:e2');

        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasInformedBy(ex:a1, ex:a2)', $output);
        $this->assertStringContainsString('wasStartedBy(ex:a1, ex:e1, -, -)', $output);
        $this->assertStringContainsString('wasEndedBy(ex:a1, ex:e1, -, -)', $output);
        $this->assertStringContainsString('wasAssociatedWith(ex:a1, ex:ag1, -)', $output);
        $this->assertStringContainsString('actedOnBehalfOf(ex:ag1, ex:ag2, -)', $output);
        $this->assertStringContainsString('wasInfluencedBy(ex:e1, ex:e2)', $output);
    }

    public function testRelationWithIdentifierUseSemicolonSyntax(): void
    {
        $builder = $this->buildDoc();
        $builder->used(identifier: 'ex:u1', activity: 'ex:a1', entity: 'ex:e1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('used(ex:u1; ex:a1, ex:e1, -)', $output);
    }

    public function testBundleDoubleIndentation(): void
    {
        $builder = $this->buildDoc();
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');

        $output = $this->serializer->serialize($builder->build());
        $lines = explode("\n", $output);

        $entityLine = null;
        foreach ($lines as $line) {
            if (str_contains($line, 'entity(ex:e1)')) {
                $entityLine = $line;
                break;
            }
        }

        $this->assertNotNull($entityLine);
        // Default indent=2, bundle records should be double-indented (4 spaces)
        $this->assertStringStartsWith('    entity(ex:e1)', $entityLine);
    }

    // W3C PROV-DM spec compliance tests

    public function testWasInvalidatedBy(): void
    {
        $builder = $this->buildDoc();
        $builder->wasInvalidatedBy(entity: 'ex:e1', activity: 'ex:a1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasInvalidatedBy(ex:e1, ex:a1, -)', $output);
    }

    public function testWasInvalidatedByWithIdentifier(): void
    {
        $builder = $this->buildDoc();
        $builder->wasInvalidatedBy(identifier: 'ex:inv1', entity: 'ex:e1', activity: 'ex:a1');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasInvalidatedBy(ex:inv1; ex:e1, ex:a1, -)', $output);
    }

    public function testWasStartedByWithStarter(): void
    {
        $builder = $this->buildDoc();
        $builder->wasStartedBy(activity: 'ex:a1', trigger: 'ex:e1', starter: 'ex:a2');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasStartedBy(ex:a1, ex:e1, ex:a2, -)', $output);
    }

    public function testWasEndedByWithEnder(): void
    {
        $builder = $this->buildDoc();
        $builder->wasEndedBy(activity: 'ex:a1', trigger: 'ex:e1', ender: 'ex:a2');
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('wasEndedBy(ex:a1, ex:e1, ex:a2, -)', $output);
    }

    public function testDerivationSubtypeRevision(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $prov->qualifiedName('Revision'));

        $builder = $this->buildDoc();
        $builder->wasDerivedFrom(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', attributes: $attrs);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString(
            "wasDerivedFrom(ex:e2, ex:e1, -, -, -, [prov:type = 'prov:Revision'])",
            $output,
        );
    }

    // Phase 1: PROV-N string escaping tests

    public function testEscapingDoubleQuoteInString(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string('He said "hello"')]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"He said \\"hello\\"" %% xsd:string', $output);
    }

    public function testEscapingBackslashInString(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string('C:\\path\\to\\file')]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"C:\\\\path\\\\to\\\\file" %% xsd:string', $output);
    }

    public function testEscapingNewlineInString(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string("line1\nline2")]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"line1\\nline2" %% xsd:string', $output);
    }

    public function testEscapingTabInString(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string("col1\tcol2")]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"col1\\tcol2" %% xsd:string', $output);
    }

    public function testEscapingCarriageReturnInString(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string("line1\rline2")]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"line1\\rline2" %% xsd:string', $output);
    }

    public function testEscapingMixedSpecialChars(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string("He said \"hi\"\nand\\left")]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"He said \\"hi\\"\\nand\\\\left" %% xsd:string', $output);
    }

    public function testUnicodePassesThroughUnescaped(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:label' => Literal::string("\u{2019} \u{4e16}\u{754c}")]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString("\u{2019} \u{4e16}\u{754c}", $output);
    }

    public function testPlainStringValueEscaping(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('label'), 'contains "quotes"');

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('"contains \\"quotes\\""', $output);
    }
}
