<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;
use Prov\Operation\ProvGraph;
use Prov\Prov;

/**
 * Regression coverage for default-namespace identifier serialization
 * (review item 1.1) and the related nested-namespace bundle id and float-type
 * round trips (1.2, 1.6). The reserved "default" prefix is an internal
 * sentinel and must never appear literally in any serialized form; an
 * unprefixed identifier resolves against the format's default declaration.
 */
final class DefaultNamespaceSerializationTest extends TestCase
{
    private function defaultNamespaceDocument(): Document
    {
        $default = new ProvNamespace('default', 'http://default.example/');
        return new DocumentBuilder()
            ->setDefaultNamespace($default)
            ->addNamespace(new ProvNamespace('ex', 'http://other.example/'))
            ->entity('e1', [
                'prov:label' => 'first',
                // A typed literal whose datatype lives in the default namespace:
                // datatype emission must not leak the reserved prefix either.
                'ex:measure' => new Literal('5', $default->qualifiedName('Unit')),
            ])
            ->activity('a1')
            ->wasGeneratedBy(entity: 'e1', activity: 'a1', identifier: 'gen1')
            ->wasDerivedFrom(generatedEntity: 'e2', usedEntity: 'e1')
            ->build();
    }

    /**
     * @return iterable<string, list<\Prov\Format>>
     */
    public static function roundTripFormatProvider(): iterable
    {
        yield 'json' => [Format::Json];
        yield 'provn' => [Format::ProvN];
        yield 'xml' => [Format::Xml];
    }

    #[DataProvider('roundTripFormatProvider')]
    public function testDefaultNamespaceIdentifiersRoundTrip(Format $format): void
    {
        $doc = $this->defaultNamespaceDocument();
        $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);

        $this->assertTrue(
            DocumentComparator::equals($doc, $roundTripped),
            "Default-namespace round trip via {$format->name} produced a non-equal document",
        );
    }

    /**
     * @return iterable<string, list<\Prov\Format>>
     */
    public static function allFormatProvider(): iterable
    {
        yield 'json' => [Format::Json];
        yield 'provn' => [Format::ProvN];
        yield 'xml' => [Format::Xml];
        yield 'jsonld' => [Format::JsonLd];
    }

    #[DataProvider('allFormatProvider')]
    public function testReservedDefaultPrefixNeverAppearsInOutput(Format $format): void
    {
        $serialized = Prov::serialize($this->defaultNamespaceDocument(), $format);

        $this->assertStringNotContainsString(
            'default:',
            $serialized,
            "The reserved default prefix leaked into {$format->name} output",
        );
    }

    public function testDefaultNamespaceSurvivesCrossFormatConversion(): void
    {
        $doc = $this->defaultNamespaceDocument();

        // JSON -> XML -> PROV-N, deserializing at each hop.
        $viaJson = Prov::deserialize(Prov::serialize($doc, Format::Json), Format::Json);
        $viaXml = Prov::deserialize(Prov::serialize($viaJson, Format::Xml), Format::Xml);
        $viaProvN = Prov::deserialize(Prov::serialize($viaXml, Format::ProvN), Format::ProvN);

        $this->assertTrue(
            DocumentComparator::equals($doc, $viaProvN),
            'Default-namespace document drifted across a JSON -> XML -> PROV-N conversion chain',
        );
    }

    public function testDefaultNamespaceIdentifiersResolveToTheirUri(): void
    {
        $roundTripped = Prov::deserialize(
            Prov::serialize($this->defaultNamespaceDocument(), Format::Json),
            Format::Json,
        );

        $graph = new ProvGraph($roundTripped);
        $entity = $graph->recordByIdentifier('http://default.example/e1');

        $this->assertNotNull($entity, 'Default-namespace entity did not resolve to its full URI after round trip');
    }

    public function testBundleWithDefaultNamespaceRoundTrips(): void
    {
        $doc = new DocumentBuilder()
            ->setDefaultNamespace(new ProvNamespace('default', 'http://doc.example/'))
            ->addNamespace(new ProvNamespace('shared', 'http://bundle.example/'))
            ->entity('docEntity')
            ->withBundle('bundle1', static function ($bundle): void {
                $bundle
                    ->setDefaultNamespace(new ProvNamespace('default', 'http://bundle.example/'))
                    ->entity('bundleEntity');
            })
            ->build();

        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Bundle with its own default namespace drifted via {$format->name}",
            );
        }
    }

    public function testBundleDefaultNamespaceDeclaredNowhereElseRoundTrips(): void
    {
        // Unlike the test above, the bundle's default URI has no document-level
        // declaration under any prefix, so the XML serializer cannot reuse one:
        // it must mint a prefix for the bundle's identifiers and declare it on
        // the root element.
        $doc = new DocumentBuilder()
            ->setDefaultNamespace(new ProvNamespace('default', 'http://doc.example/'))
            ->entity('docEntity')
            ->withBundle('bundle1', static function ($bundle): void {
                $bundle
                    ->setDefaultNamespace(new ProvNamespace('default', 'http://only-bundle.example/'))
                    ->entity('bundleEntity');
            })
            ->build();

        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Bundle default namespace with no other declaration drifted via {$format->name}",
            );
        }
    }
}
