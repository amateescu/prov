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
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Operation\DocumentComparator;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;

/**
 * PROV-N declares the `prov` and `xsd` prefixes implicitly and forbids
 * redeclaring them, so a document that binds either prefix to another
 * namespace has its names written through a minted prefix. A written prefix
 * has to match the `PN_PREFIX` production. The serializer emits canonical
 * syntax even where the parser is more permissive.
 */
final class ProvNPrefixDeclarationTest extends TestCase
{
    private ProvNSerializer $serializer;
    private ProvNDeserializer $deserializer;
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->serializer = new ProvNSerializer();
        $this->deserializer = new ProvNDeserializer();
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    private function documentWithProvAndXsd(): Document
    {
        return new Document(
            [
                new Entity(
                    new QualifiedName($this->ex, 'e1'),
                    new Attributes([
                        'http://www.w3.org/ns/prov#label' => ['labelled'],
                        'http://example.org/size' => [
                            new Literal('5', ProvNamespace::xsd()->qualifiedName('int')),
                        ],
                    ], [
                        'http://www.w3.org/ns/prov#label' => ProvNamespace::prov()->qualifiedName('label'),
                        'http://example.org/size' => new QualifiedName($this->ex, 'size'),
                    ]),
                ),
            ],
            [],
            [$this->ex, ProvNamespace::prov(), ProvNamespace::xsd()],
        );
    }

    public function testReservedPrefixesAreNotDeclared(): void
    {
        $output = $this->serializer->serialize($this->documentWithProvAndXsd());

        $this->assertStringNotContainsString('prefix prov ', $output);
        $this->assertStringNotContainsString('prefix xsd ', $output);
        $this->assertStringContainsString('prefix ex <http://example.org/>', $output);
        $this->assertStringContainsString('prov:label', $output);
        $this->assertStringContainsString('xsd:int', $output);
    }

    public function testDocumentWithoutReservedDeclarationsStillRoundTrips(): void
    {
        $document = $this->documentWithProvAndXsd();
        $back = $this->deserializer->deserialize($this->serializer->serialize($document));

        $this->assertTrue(
            DocumentComparator::equals($document, $back),
            implode("\n", DocumentComparator::diff($document, $back)),
        );
    }

    public function testReservedPrefixesAreNotDeclaredInsideBundles(): void
    {
        $document = new Document(
            [],
            [
                new Bundle(
                    new QualifiedName($this->ex, 'b1'),
                    [new Entity(new QualifiedName($this->ex, 'inBundle'))],
                    [ProvNamespace::prov(), ProvNamespace::xsd(), new ProvNamespace('local', 'http://local.example/')],
                ),
            ],
            [$this->ex],
        );

        $output = $this->serializer->serialize($document);

        $this->assertStringNotContainsString('prefix prov ', $output);
        $this->assertStringNotContainsString('prefix xsd ', $output);
        $this->assertStringContainsString('prefix local <http://local.example/>', $output);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function invalidPrefixes(): iterable
    {
        yield 'leading digit' => ['1bad'];
        yield 'leading hyphen' => ['-bad'];
        yield 'leading underscore' => ['_bad'];
        yield 'leading dot' => ['.bad'];
        yield 'trailing dot' => ['bad.'];
        yield 'contains slash' => ['ba/d'];
        yield 'contains percent' => ['ba%d'];
    }

    #[DataProvider('invalidPrefixes')]
    public function testInvalidPrefixIsRejected(string $prefix): void
    {
        $document = new Document(
            [new Entity(new QualifiedName(new ProvNamespace($prefix, 'http://example.org/'), 'e1'))],
            [],
            [new ProvNamespace($prefix, 'http://example.org/')],
        );

        $this->assertRejected($document);
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function validPrefixes(): iterable
    {
        yield 'plain' => ['ex'];
        yield 'inner dot' => ['a.b'];
        yield 'inner hyphen' => ['a-b'];
        yield 'inner digit' => ['a1'];
        yield 'inner underscore' => ['a_b'];
        yield 'accented latin' => ['éx'];
        yield 'greek' => ['πρ'];
        yield 'cjk' => ['名前'];
    }

    #[DataProvider('validPrefixes')]
    public function testValidPrefixIsEmittedAndParsesBack(string $prefix): void
    {
        $namespace = new ProvNamespace($prefix, 'http://example.org/');
        $document = new Document([new Entity(new QualifiedName($namespace, 'e1'))], [], [$namespace]);

        $output = $this->serializer->serialize($document);
        $this->assertStringContainsString("prefix {$prefix} <http://example.org/>", $output);
        $this->assertStringContainsString("entity({$prefix}:e1)", $output);

        $back = $this->deserializer->deserialize($output);
        $this->assertSame('http://example.org/e1', $back->records[0]->identifier?->getUri());
    }

    /**
     * @return iterable<string, list<\Prov\Identifier\ProvNamespace>>
     */
    public static function reservedRebindings(): iterable
    {
        yield 'prov rebound' => [new ProvNamespace('prov', 'http://elsewhere.example/prov#')];
        yield 'xsd rebound' => [new ProvNamespace('xsd', 'http://elsewhere.example/xsd#')];
    }

    /**
     * PROV-N cannot write a `prov` or `xsd` declaration, so a document that
     * binds either prefix to another namespace gets a minted prefix for the
     * names under it. The namespace itself survives.
     */
    #[DataProvider('reservedRebindings')]
    public function testRebindingAReservedPrefixGetsAMintedPrefix(ProvNamespace $namespace): void
    {
        $document = new Document([new Entity(new QualifiedName($namespace, 'e1'))], [], [$namespace]);

        $output = $this->serializer->serialize($document);
        $this->assertStringNotContainsString("prefix {$namespace->prefix} ", $output);

        $back = $this->deserializer->deserialize($output);
        $this->assertSame($namespace->uri . 'e1', $back->records[0]->identifier?->getUri());
    }

    /**
     * Asserts that PROV-N serialization of `$document` is refused rather than
     * producing text with an illegal declaration.
     */
    private function assertRejected(Document $document): void
    {
        try {
            $output = $this->serializer->serialize($document);
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
            return;
        }
        $this->fail('Expected the serializer to refuse the document; it produced: ' . $output);
    }

    #[DataProvider('reservedRebindings')]
    public function testRebindingAReservedPrefixInABundleGetsAMintedPrefix(ProvNamespace $namespace): void
    {
        $document = new Document(
            [],
            [new Bundle(
                new QualifiedName($this->ex, 'b1'),
                [new Entity(new QualifiedName($namespace, 'e1'))],
                [
                    $namespace,
                ],
            )],
            [$this->ex],
        );

        $output = $this->serializer->serialize($document);
        $this->assertStringNotContainsString("prefix {$namespace->prefix} ", $output);

        $back = $this->deserializer->deserialize($output);
        $this->assertSame($namespace->uri . 'e1', $back->bundles[0]->records[0]->identifier?->getUri());
    }
}
