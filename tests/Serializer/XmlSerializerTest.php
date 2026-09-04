<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Exception\DeserializationException;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Derivation;
use Prov\Relation\Generation;
use Prov\Relation\Usage;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\XmlSerializer;

final class XmlSerializerTest extends TestCase
{
    private ProvNamespace $ex;
    private XmlSerializer $serializer;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->serializer = new XmlSerializer();
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    // --- Serialization tests ---

    public function testSerializeEmptyDocument(): void
    {
        $doc = new DocumentBuilder()->build();
        $xml = $this->serializer->serialize($doc);

        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('prov:document', $xml);
    }

    public function testSerializeEntity(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('prov:entity', $xml);
        $this->assertStringContainsString('ex:e1', $xml);
    }

    public function testSpecialCharactersAreEscapedAndRoundTrip(): void
    {
        $value = 'a < b && "c" > \'d\'';
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:label' => Literal::string($value)]);
        $xml = $this->serializer->serialize($builder->build());

        // The markup-significant characters must be escaped in the output and
        // the original value restored on parse.
        $this->assertStringContainsString('a &lt; b &amp;&amp;', $xml);
        $this->assertStringNotContainsString($value, $xml);

        $roundTripped = $this->serializer->deserialize($xml);
        $literals = $roundTripped->entities[0]->attributes->getLiterals($this->ex->qualifiedName('label'));
        $this->assertSame($value, $literals[0]->value ?? null);
    }

    public function testSerializeActivity(): void
    {
        $builder = $this->buildDoc();
        $builder->activity('ex:a1', new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('prov:activity', $xml);
        $this->assertStringContainsString('prov:startTime', $xml);
        $this->assertStringContainsString('2023-01-15T00:00:00+00:00', $xml);
    }

    public function testSerializeRelation(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('prov:wasGeneratedBy', $xml);
        $this->assertStringContainsString('prov:ref="ex:e1"', $xml);
    }

    public function testSerializeBundle(): void
    {
        $builder = $this->buildDoc();
        $bb = $builder->bundle('ex:b1');
        $bb->entity('ex:e1');
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('prov:bundleContent', $xml);
        $this->assertStringContainsString('ex:b1', $xml);
    }

    public function testSerializeTypedAttribute(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('xsi:type="xsd:string"', $xml);
        $this->assertStringContainsString('Document', $xml);
    }

    public function testSerializeLanguageTaggedAttribute(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('label'), new Literal('bonjour', languageTag: 'fr'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('xml:lang="fr"', $xml);
        $this->assertStringContainsString('bonjour', $xml);
    }

    public function testSerializeQualifiedNameAttribute(): void
    {
        $prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $attrs = Attributes::single($prov->qualifiedName('type'), $this->ex->qualifiedName('MyType'));

        $builder = $this->buildDoc();
        $builder->entity('ex:e1', $attrs);
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('xsi:type="xsd:QName"', $xml);
        $this->assertStringContainsString('ex:MyType', $xml);
    }

    // --- Deserialization tests ---

    public function testDeserializeEntity(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:entity prov:id="ex:e1"/></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc->entities);
        $this->assertSame('http://example.org/e1', $doc->entities[0]->identifier->uri);
    }

    public function testDeserializeActivity(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:activity prov:id="ex:a1"><prov:startTime>2023-01-15T00:00:00+00:00</prov:startTime></prov:activity></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $a = $doc->activities[0];
        $this->assertSame('2023-01-15T00:00:00+00:00', $a->startTime->format(\DateTimeInterface::ATOM));
    }

    public function testDeserializeOffsetLessDateTimeIsTimezoneIndependent(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:activity prov:id="ex:a1"><prov:startTime>2011-11-16T16:05:00</prov:startTime></prov:activity></prov:document>';
        $original = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $doc = $this->serializer->deserialize($xml);
        } finally {
            date_default_timezone_set($original);
        }

        $this->assertSame(
            '2011-11-16T16:05:00+00:00',
            $doc->activities[0]->startTime->format(\DateTimeInterface::ATOM),
        );
    }

    public function testDeserializeQNameAttributeWithNonStandardXsdPrefix(): void
    {
        // The document binds "xs", not "xsd", to the XSD namespace; the QName
        // type must still be detected by the resolved datatype URI.
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xs="http://www.w3.org/2001/XMLSchema#" xmlns:ex="http://example.org/"><prov:entity prov:id="ex:e1"><ex:ref xsi:type="xs:QName">ex:e2</ex:ref></prov:entity></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $value = $doc->entities[0]->attributes->all()['http://example.org/ref'][0];
        $this->assertInstanceOf(QualifiedName::class, $value);
        $this->assertSame('http://example.org/e2', $value->getUri());
    }

    public function testDeserializeRelation(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:wasGeneratedBy prov:id="ex:g1"><prov:entity prov:ref="ex:e1"/><prov:activity prov:ref="ex:a1"/></prov:wasGeneratedBy></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $gens = $doc->getRecordsByType(Generation::class);
        $this->assertCount(1, $gens);
        $this->assertSame('http://example.org/e1', $gens[0]->entity->uri);
    }

    public function testDeserializeUsageWithoutEntity(): void
    {
        // PROV-DM marks usage's entity as optional; a record carrying only the
        // mandatory activity is valid input.
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:used prov:id="ex:u1"><prov:activity prov:ref="ex:a1"/></prov:used></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $usages = $doc->getRecordsByType(Usage::class);
        $this->assertCount(1, $usages);
        $this->assertSame('http://example.org/a1', $usages[0]->activity->uri);
        $this->assertNull($usages[0]->entity);
    }

    public function testDeserializeBundle(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/"><prov:bundleContent prov:id="ex:b1"><prov:entity prov:id="ex:e1"/></prov:bundleContent></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc->bundles);
        $this->assertCount(1, $doc->bundles[0]->entities);
    }

    public function testDeserializeBundleCapturesLocallyDeclaredNamespaces(): void
    {
        $xml =
            '<?xml version="1.0"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/">'
            . '<prov:bundleContent prov:id="ex:b1" xmlns:foo="http://foo.example/">'
            . '<prov:entity prov:id="foo:e1"/>'
            . '</prov:bundleContent>'
            . '</prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc->bundles);
        $bundle = $doc->bundles[0];
        $prefixes = array_map(static fn($ns) => $ns->prefix, $bundle->namespaces);
        $this->assertContains('foo', $prefixes, 'bundle-local xmlns:foo should be recorded on the Bundle');
        $this->assertNotContains('ex', $prefixes, 'inherited xmlns:ex should not be duplicated on the Bundle');
        $this->assertSame('http://foo.example/e1', (string) $bundle->entities[0]->identifier->getUri());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function derivationSubtypeElements(): iterable
    {
        yield 'revision' => ['revision', 'Revision'];
        yield 'quotation' => ['quotation', 'Quotation'];
        yield 'primarySource' => ['primarySource', 'PrimarySource'];
    }

    #[DataProvider('derivationSubtypeElements')]
    public function testDeserializeDerivationSubtypeShortcut(string $element, string $subtype): void
    {
        $xml =
            '<?xml version="1.0"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/">'
            . '<prov:'
            . $element
            . ' prov:id="ex:d1">'
            . '<prov:generatedEntity prov:ref="ex:e2"/>'
            . '<prov:usedEntity prov:ref="ex:e1"/>'
            . '</prov:'
            . $element
            . '>'
            . '</prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $ders = $doc->getRecordsByType(Derivation::class);
        $this->assertCount(1, $ders);
        $this->assertSame('http://example.org/e2', $ders[0]->generatedEntity->uri);
        $this->assertSame('http://example.org/e1', $ders[0]->usedEntity->uri);

        $provType = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
        $typeValues = $ders[0]->attributes->get($provType->qualifiedName('type'));
        $this->assertCount(1, $typeValues);
        $this->assertSame('http://www.w3.org/ns/prov#' . $subtype, $typeValues[0]->uri);
    }

    public function testDeserializeTypedAttribute(): void
    {
        $xml = '<?xml version="1.0"?><prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:ex="http://example.org/"><prov:entity prov:id="ex:e1"><prov:type xsi:type="xsd:string">Document</prov:type></prov:entity></prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $entity = $doc->entities[0];
        $this->assertFalse($entity->attributes->isEmpty());
    }

    public function testDeserializeInvalidXmlThrows(): void
    {
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('not xml');
    }

    public function testDeserializeEmptyInputThrowsDeserializationException(): void
    {
        // Dom\XMLDocument::createFromString raises ValueError on empty input;
        // the library must surface that as its own exception type so callers catching
        // ProvException catch this case.
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize('');
    }

    // --- Round-trip tests ---

    public function testRoundTripBasicDocument(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['prov:type' => Literal::string('Document')]);
        $builder->activity('ex:a1', new \DateTimeImmutable('2023-01-15T00:00:00+00:00'));
        $builder->agent('ex:ag1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e1');
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:ag1');

        $doc = $builder->build();
        $xml = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc2->entities);
        $this->assertCount(1, $doc2->activities);
        $this->assertCount(1, $doc2->agents);
        $this->assertCount(3, $doc2->relations);
    }

    public function testRoundTripWithBundles(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $bb = $builder->bundle('ex:b1');
        $bb->entity('ex:e2');
        $bb->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1');

        $doc = $builder->build();
        $xml = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc2->entities);
        $this->assertCount(1, $doc2->bundles);
        $this->assertCount(1, $doc2->bundles[0]->entities);
        $this->assertCount(1, $doc2->bundles[0]->relations);
    }

    public function testRoundTripAll14RelationTypes(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy(identifier: 'ex:g1', entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(identifier: 'ex:u1', activity: 'ex:a1', entity: 'ex:e1');
        $builder->wasInformedBy(identifier: 'ex:c1', informed: 'ex:a1', informant: 'ex:a2');
        $builder->wasStartedBy(identifier: 'ex:s1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasEndedBy(identifier: 'ex:end1', activity: 'ex:a1', trigger: 'ex:e1');
        $builder->wasInvalidatedBy(identifier: 'ex:inv1', entity: 'ex:e1', activity: 'ex:a1');
        $builder->wasDerivedFrom(identifier: 'ex:d1', generatedEntity: 'ex:e2', usedEntity: 'ex:e1');
        $builder->wasAttributedTo(identifier: 'ex:at1', entity: 'ex:e1', agent: 'ex:ag1');
        $builder->wasAssociatedWith(identifier: 'ex:as1', activity: 'ex:a1', agent: 'ex:ag1');
        $builder->actedOnBehalfOf(identifier: 'ex:del1', delegate: 'ex:ag1', responsible: 'ex:ag2');
        $builder->wasInfluencedBy(identifier: 'ex:inf1', influencee: 'ex:e1', influencer: 'ex:e2');
        $builder->specializationOf(identifier: 'ex:sp1', specificEntity: 'ex:e1', generalEntity: 'ex:e2');
        $builder->alternateOf(identifier: 'ex:alt1', alternate1: 'ex:e1', alternate2: 'ex:e2');
        $builder->hadMember(identifier: 'ex:m1', collection: 'ex:c1', entity: 'ex:e1');

        $doc = $builder->build();
        $xml = $this->serializer->serialize($doc);
        $doc2 = $this->serializer->deserialize($xml);

        $this->assertCount(14, $doc2->relations);
    }

    public function testCrossFormatRoundTripXmlToJson(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');

        $doc = $builder->build();
        $xml = $this->serializer->serialize($doc);
        $docFromXml = $this->serializer->deserialize($xml);

        $jsonSerializer = new JsonSerializer();
        $json = $jsonSerializer->serialize($docFromXml);
        $docFromJson = $jsonSerializer->deserialize($json);

        $this->assertCount(1, $docFromJson->entities);
        $this->assertCount(1, $docFromJson->relations);
    }

    public function testSerializeOutputIsValidXml(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $xml = $this->serializer->serialize($builder->build());

        $dom = \Dom\XMLDocument::createFromString($xml);
        $this->assertSame('document', $dom->documentElement?->localName);
    }

    public function testDeserializeRejectsInternalEntityDoctype(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE prov:document [
                <!ENTITY payload "PWNED">
            ]>
            <prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/">
                <prov:entity prov:id="ex:e1">
                    <prov:label>&payload;</prov:label>
                </prov:entity>
            </prov:document>
            XML;
        $this->expectException(DeserializationException::class);
        $this->expectExceptionMessage('DOCTYPE');
        $this->serializer->deserialize($xml);
    }

    public function testDeserializeRejectsExternalDtd(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE prov:document SYSTEM "http://example.invalid/does-not-resolve.dtd">
            <prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/">
                <prov:entity prov:id="ex:e1"/>
            </prov:document>
            XML;
        $this->expectException(DeserializationException::class);
        $this->serializer->deserialize($xml);
    }

    public function testIntAttributeTypedByRange(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:small' => 42, 'ex:big' => 9_999_999_999]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('xsi:type="xsd:int"', $output);
        $this->assertStringContainsString('xsi:type="xsd:long"', $output);
        $this->assertStringContainsString('9999999999', $output);
    }

    public function testUnderscoreDigitAttributeRoundTrips(): void
    {
        // `0foo` escapes to `_0foo`; a genuine `_0foo` must escape to `__0foo`
        // so both survive a round trip instead of colliding.
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:0foo' => 'a', 'ex:_0foo' => 'b']);
        $doc = $builder->build();

        $back = $this->serializer->deserialize($this->serializer->serialize($doc));
        $attrs = $back->entities[0]->attributes;

        $this->assertSame(['a'], $attrs->get($this->ex->qualifiedName('0foo')));
        $this->assertSame(['b'], $attrs->get($this->ex->qualifiedName('_0foo')));
    }

    public function testRootDeclaresXsiAndXsdBindings(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:n' => 1]);
        $xml = $this->serializer->serialize($builder->build());

        preg_match('~<prov:document[^>]*>~', $xml, $root);
        $this->assertStringContainsString('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"', $root[0]);
        $this->assertStringContainsString('xmlns:xsd="http://www.w3.org/2001/XMLSchema"', $root[0]);
    }

    public function testPrettyPrintIsOnByDefaultAndCanBeTurnedOff(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $doc = $builder->build();

        $this->assertStringContainsString("\n  <prov:entity", new XmlSerializer()->serialize($doc));
        $this->assertStringNotContainsString("\n  ", new XmlSerializer(prettyPrint: false)->serialize($doc));
    }

    public function testXmlLiteralKeepsElementStructureThroughRoundTrip(): void
    {
        $rdf = new ProvNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $builder = $this->buildDoc();
        $builder->addNamespace($rdf);
        $builder->entity('ex:e1', [
            'ex:note' => new Literal('<b>bold</b> and <i>italic</i>', $rdf->qualifiedName('XMLLiteral')),
        ]);
        $serializer = new XmlSerializer(prettyPrint: false);

        $xml = $serializer->serialize($builder->build());
        // The fragment is written as child elements, not as escaped text.
        $this->assertStringContainsString('<b>bold</b> and <i>italic</i>', $xml);
        $this->assertStringNotContainsString('&lt;b&gt;', $xml);

        $back = $serializer->deserialize($xml);
        $value = $back->entities[0]->attributes->get($this->ex->qualifiedName('note'))[0];
        $this->assertInstanceOf(Literal::class, $value);
        $this->assertSame('http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral', $value->datatype?->getUri());
        $this->assertSame('<b>bold</b> and <i>italic</i>', $value->value);
    }

    public function testDefaultNamespaceNamesAreWrittenBare(): void
    {
        $default = new ProvNamespace('default', 'http://default.example/');
        $builder = $this->buildDoc();
        $builder->setDefaultNamespace($default);
        $builder->entity('e1', ['note' => 'v']);
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('xmlns="http://default.example/"', $xml);
        $this->assertStringContainsString('prov:id="e1"', $xml);
        $this->assertStringContainsString('<note>v</note>', $xml);
        $this->assertStringNotContainsString('default:', $xml);

        $back = $this->serializer->deserialize($xml);
        $this->assertSame('http://default.example/e1', $back->entities[0]->identifier?->getUri());
        $this->assertSame(['v'], $back->entities[0]->attributes->get($default->qualifiedName('note')));
    }

    public function testBundleLocalNamespaceIsDeclaredOnBundleContent(): void
    {
        $builder = $this->buildDoc();
        $bundle = $builder->bundle('ex:b1');
        $bundle->namespace('foo', 'http://foo.example/');
        $bundle->entity('foo:e1');
        $xml = $this->serializer->serialize($builder->build());

        $this->assertMatchesRegularExpression('~<prov:bundleContent[^>]*xmlns:foo="http://foo\.example/"~', $xml);
        $this->assertDoesNotMatchRegularExpression('~<prov:document[^>]*xmlns:foo=~', $xml);
        $this->assertStringContainsString('prov:id="foo:e1"', $xml);
    }

    public function testSortRecordsOrdersDocumentAndBundleRecords(): void
    {
        $builder = $this->buildDoc();
        $builder->wasGeneratedBy('ex:e1', 'ex:a1', identifier: 'ex:g1');
        $builder->agent('ex:ag1');
        $builder->activity('ex:a1');
        $builder->entity('ex:e1');
        $bundle = $builder->bundle('ex:b1');
        $bundle->activity('ex:a2');
        $bundle->entity('ex:e2');
        $doc = $builder->build();

        $ids = static function (string $xml): array {
            preg_match_all('~<prov:(\w+) prov:id="ex:(\w+)"~', $xml, $m);
            return $m[2];
        };

        $this->assertSame(['g1', 'ag1', 'a1', 'e1', 'b1', 'a2', 'e2'], $ids(new XmlSerializer()->serialize($doc)));
        $this->assertSame(
            ['e1', 'a1', 'ag1', 'g1', 'b1', 'e2', 'a2'],
            $ids(new XmlSerializer(sortRecords: true)->serialize($doc)),
        );
    }

    public function testDeserializeMalformedXmlReportsTheParserMessage(): void
    {
        try {
            $this->serializer->deserialize(
                '<prov:document xmlns:prov="http://www.w3.org/ns/prov#"><prov:entity></prov:document>',
            );
            $this->fail('Malformed XML must not deserialize.');
        } catch (DeserializationException $e) {
            $this->assertStringStartsWith('Invalid PROV-XML: ', $e->getMessage());
            $this->assertStringContainsString('Opening and ending tag mismatch', $e->getMessage());
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertInstanceOf(\DOMException::class, $e->getPrevious());
        }
    }

    public function testDeserializeFailureRestoresLibxmlErrorHandling(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            try {
                $this->serializer->deserialize('<broken');
                $this->fail('Malformed XML must not deserialize.');
            } catch (DeserializationException $e) {
                $this->assertStringStartsWith('Invalid PROV-XML: ', $e->getMessage());
            }
            $this->assertFalse(libxml_use_internal_errors(false), 'the caller\'s error mode is restored');
            $this->assertSame([], libxml_get_errors(), 'the parser errors are cleared');
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testUnknownProvElementsAreIgnored(): void
    {
        $xml =
            '<?xml version="1.0"?>'
            . '<prov:document xmlns:prov="http://www.w3.org/ns/prov#" xmlns:ex="http://example.org/">'
            . '<prov:other><prov:thing/></prov:other>'
            . '<prov:entity prov:id="ex:e1"/>'
            . '</prov:document>';
        $doc = $this->serializer->deserialize($xml);

        $this->assertCount(1, $doc->records);
        $this->assertSame('http://example.org/e1', $doc->entities[0]->identifier?->getUri());
    }

    public function testDeserializedNamespacesExcludeXmlPlumbing(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:n' => 1]);
        $back = $this->serializer->deserialize($this->serializer->serialize($builder->build()));

        $prefixes = array_map(static fn(ProvNamespace $ns): string => $ns->prefix, $back->namespaces);
        $this->assertContains('ex', $prefixes);
        $this->assertNotContains('xsi', $prefixes);
    }

    public function testUnderscoreDigitInsideAttributeNameIsUntouched(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:foo_1' => 'a']);
        $xml = $this->serializer->serialize($builder->build());

        $this->assertStringContainsString('<ex:foo_1>', $xml);
        $back = $this->serializer->deserialize($xml);
        $this->assertSame(['a'], $back->entities[0]->attributes->get($this->ex->qualifiedName('foo_1')));
    }

    public function testIntRangeBoundariesTypeAsInt(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1', ['ex:min' => Literal::XSD_INT_MIN, 'ex:max' => Literal::XSD_INT_MAX]);
        $output = $this->serializer->serialize($builder->build());

        $this->assertSame(2, substr_count($output, 'xsi:type="xsd:int"'));
        $this->assertStringNotContainsString('xsd:long', $output);
    }
}
