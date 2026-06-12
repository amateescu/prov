<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;
use Prov\Prov;
use Prov\Serializer\ProvNDeserializer;

/**
 * Lexical handling of PROV-N qualified names and string literals (review
 * items 2.1-2.5): the extended PN_CHARS_OTHERS set, backslash escaping and
 * decoding of PN_CHARS_ESC punctuation in local names, the remaining ECHAR
 * sequences, and routing every qualified-name emission through the prefix
 * minter so undeclared namespaces still get a declaration.
 */
final class ProvNLexicalHandlingTest extends TestCase
{
    private const EX = 'http://example.org/';

    /**
     * @return iterable<string, list<string>>
     */
    public static function extendedQNameCharProvider(): iterable
    {
        // 2.1: PN_CHARS_OTHERS characters the parser previously rejected.
        yield 'at' => ['a@b'];
        yield 'ampersand' => ['a&b'];
        yield 'plus' => ['a+b'];
        yield 'star' => ['a*b'];
        yield 'dollar' => ['a$b'];
        yield 'bang' => ['a!b'];
        yield 'percent-encoded' => ['a%20b'];
    }

    #[DataProvider('extendedQNameCharProvider')]
    public function testParserAcceptsExtendedPnCharsOthers(string $localPart): void
    {
        $input = "document\nprefix ex <" . self::EX . ">\nentity(ex:{$localPart})\nendDocument\n";
        $doc = new ProvNDeserializer()->deserialize($input);

        $this->assertCount(1, $doc->entities);
        $this->assertSame(self::EX . $localPart, $doc->entities[0]->identifier?->getUri());
    }

    /**
     * @return iterable<string, list<string>>
     */
    public static function escapableLocalPartProvider(): iterable
    {
        // 2.2/2.3: punctuation the serializer must escape rather than reject,
        // and the parser must decode back to the same local part.
        yield 'comma' => ['a,b'];
        yield 'brackets' => ['a[b]c'];
        yield 'parens-quote' => ["a'b(c)"];
        yield 'equals-semicolon-dot' => ['a=b;c.d'];
        yield 'colon' => ['a:b'];
        yield 'leading-dot' => ['.cfg'];
        yield 'trailing-dot' => ['cfg.'];
        yield 'leading-dash' => ['-flag'];
        yield 'all' => ["=')(,-:;[]."];
    }

    #[DataProvider('escapableLocalPartProvider')]
    public function testEscapablePunctuationRoundTripsViaProvNAndJson(string $localPart): void
    {
        $ex = new ProvNamespace('ex', self::EX);
        $doc = new DocumentBuilder()
            ->addNamespace($ex)
            ->entity($ex->qualifiedName($localPart), ['ex:p' => $ex->qualifiedName($localPart)])
            ->build();

        foreach ([Format::ProvN, Format::Json] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Escapable local part '{$localPart}' did not round trip via {$format->name}",
            );
        }
    }

    #[DataProvider('escapableLocalPartProvider')]
    public function testProvNAndJsonAgreeOnEscapableLocalParts(string $localPart): void
    {
        $ex = new ProvNamespace('ex', self::EX);
        $doc = new DocumentBuilder()
            ->addNamespace($ex)
            ->entity($ex->qualifiedName($localPart))
            ->build();

        $viaProvN = Prov::deserialize(Prov::serialize($doc, Format::ProvN), Format::ProvN);
        $viaJson = Prov::deserialize(Prov::serialize($doc, Format::Json), Format::Json);

        $this->assertTrue(
            DocumentComparator::equals($viaProvN, $viaJson),
            "PROV-N and PROV-JSON disagree on local part '{$localPart}'",
        );
        $this->assertSame(self::EX . $localPart, $viaProvN->entities[0]->identifier?->getUri());
    }

    public function testSerializedProvNCarriesBackslashEscapes(): void
    {
        $ex = new ProvNamespace('ex', self::EX);
        $doc = new DocumentBuilder()
            ->addNamespace($ex)
            ->entity($ex->qualifiedName('a,b'))
            ->build();

        $this->assertStringContainsString('entity(ex:a\,b)', Prov::serialize($doc, Format::ProvN));
    }

    public function testMedialDotsStayBare(): void
    {
        // PN_LOCAL carries medial dots without escapes, so the common dotted
        // shape (config names, versions) keeps its familiar lexical form;
        // only a leading or trailing dot needs the backslash.
        $ex = new ProvNamespace('ex', self::EX);
        $doc = new DocumentBuilder()
            ->addNamespace($ex)
            ->entity($ex->qualifiedName('node.article.body'))
            ->build();

        $this->assertStringContainsString('entity(ex:node.article.body)', Prov::serialize($doc, Format::ProvN));
        $this->assertStringContainsString('"ex:node.article.body"', Prov::serialize($doc, Format::Json));
    }

    public function testStringLiteralEchorSequencesDecode(): void
    {
        // 2.4: \b, \f and \' were previously left verbatim.
        $input = <<<'PROVN'
            document
            prefix ex <http://example.org/>
            entity(ex:e, [ex:p = "a\bb\fc\'d"])
            endDocument
            PROVN;

        $doc = new ProvNDeserializer()->deserialize($input);
        $value = $doc->entities[0]->attributes->firstValue(new ProvNamespace('ex', self::EX)->qualifiedName('p'));

        $this->assertSame("a\x08b\x0cc'd", $value);
    }

    public function testUndeclaredNamespaceValueRoundTrips(): void
    {
        // 2.5: an attribute value in a namespace the builder never declared must
        // still get a declaration emitted, in every format.
        $undeclared = new ProvNamespace('undecl', 'http://undeclared.example/');
        $doc = new DocumentBuilder()
            ->namespace('ex', self::EX)
            ->entity('ex:e1', ['ex:p' => $undeclared->qualifiedName('val')])
            ->build();

        foreach ([Format::ProvN, Format::Json, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Undeclared-namespace value did not round trip via {$format->name}",
            );
        }
    }

    public function testUndeclaredNamespaceIdentifierRoundTripsIncludingXml(): void
    {
        // Identifiers and relation endpoints (not just attribute values) in an
        // undeclared namespace must get a declaration in every format; in XML
        // this lands as a root xmlns for the minted prefix instead of an
        // unbound prefix in prov:id/prov:ref.
        $undeclared = new ProvNamespace('undecl', 'http://undeclared.example/');
        $doc = new DocumentBuilder()
            ->namespace('ex', self::EX)
            ->entity($undeclared->qualifiedName('e1'))
            ->activity('ex:a1')
            ->wasGeneratedBy(entity: $undeclared->qualifiedName('e1'), activity: 'ex:a1')
            ->build();

        foreach ([Format::ProvN, Format::Json, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Undeclared-namespace identifier did not round trip via {$format->name}",
            );
            $this->assertSame('http://undeclared.example/e1', $roundTripped->entities[0]->identifier?->getUri());
        }
    }

    public function testUndeclaredNamespaceIdentifierWithSlashRoundTrips(): void
    {
        // The minted declaration must preserve the qualified name's own namespace
        // boundary, so a versioned local part containing '/' is not re-split.
        $versioned = new ProvNamespace('node', 'http://example.org/node/');
        $doc = new DocumentBuilder()
            ->entity($versioned->qualifiedName('42/rev/7'))
            ->build();

        foreach ([Format::ProvN, Format::Json] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($doc, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($doc, $roundTripped),
                "Versioned identifier did not round trip via {$format->name}",
            );
            $this->assertSame('http://example.org/node/42/rev/7', $roundTripped->entities[0]->identifier?->getUri());
        }
    }
}
