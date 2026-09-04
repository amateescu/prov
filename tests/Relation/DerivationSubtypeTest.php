<?php

declare(strict_types=1);

namespace Prov\Tests\Relation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Builder\DocumentBuilder;
use Prov\Identifier\ProvNamespace;
use Prov\Relation\Derivation;
use Prov\Relation\DerivationSubtype;

final class DerivationSubtypeTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    /**
     * @return iterable<string, array{DerivationSubtype, string, string}>
     */
    public static function subtypes(): iterable
    {
        yield 'Revision' => [DerivationSubtype::Revision, 'wasRevisionOf', 'Revision'];
        yield 'Quotation' => [DerivationSubtype::Quotation, 'wasQuotedFrom', 'Quotation'];
        yield 'PrimarySource' => [DerivationSubtype::PrimarySource, 'hadPrimarySource', 'PrimarySource'];
    }

    #[DataProvider('subtypes')]
    public function testNamesAndKeywordRoundTrip(DerivationSubtype $subtype, string $keyword, string $typeName): void
    {
        $this->assertSame($keyword, $subtype->keyword());
        $this->assertSame($typeName, $subtype->value);
        $this->assertSame('http://www.w3.org/ns/prov#' . $typeName, $subtype->qualifiedName()->uri);
        $this->assertSame('prov:' . $typeName, (string) $subtype->qualifiedName());

        $this->assertSame($subtype, DerivationSubtype::fromKeyword($keyword));
        $this->assertSame($subtype, DerivationSubtype::fromType($subtype->qualifiedName()));
    }

    public function testFromTypeMatchesByUriNotPrefix(): void
    {
        $p = new ProvNamespace('p', 'http://www.w3.org/ns/prov#');

        $this->assertSame(DerivationSubtype::Revision, DerivationSubtype::fromType($p->qualifiedName('Revision')));
    }

    public function testFromTypeRejectsForeignNamespaceAndUnknownName(): void
    {
        $this->assertNull(DerivationSubtype::fromType($this->ex->qualifiedName('Revision')));
        $this->assertNull(DerivationSubtype::fromType(ProvNamespace::prov()->qualifiedName('Entity')));
    }

    public function testFromKeywordRejectsOtherKeywords(): void
    {
        $this->assertNull(DerivationSubtype::fromKeyword('wasDerivedFrom'));
        $this->assertNull(DerivationSubtype::fromKeyword('wasGeneratedBy'));
    }

    public function testDerivationSubtypeReadsProvType(): void
    {
        $plain = new Derivation(
            generatedEntity: $this->ex->qualifiedName('e2'),
            usedEntity: $this->ex->qualifiedName('e1'),
        );
        $this->assertNull($plain->subtype());

        $typed = new Derivation(
            generatedEntity: $this->ex->qualifiedName('e2'),
            usedEntity: $this->ex->qualifiedName('e1'),
            attributes: Attributes::empty()->with(
                ProvNamespace::prov()->qualifiedName('type'),
                ProvNamespace::prov()->qualifiedName('Quotation'),
            ),
        );
        $this->assertSame(DerivationSubtype::Quotation, $typed->subtype());
    }

    public function testDerivationSubtypeIgnoresStringLiteralAndOtherTypes(): void
    {
        $prov = ProvNamespace::prov();
        $literal = new Derivation(
            generatedEntity: $this->ex->qualifiedName('e2'),
            usedEntity: $this->ex->qualifiedName('e1'),
            attributes: Attributes::empty()->with($prov->qualifiedName('type'), 'prov:Revision'),
        );
        $this->assertNull($literal->subtype());

        // A foreign type alongside the PROV one does not hide the subtype.
        $mixed = new Derivation(
            generatedEntity: $this->ex->qualifiedName('e2'),
            usedEntity: $this->ex->qualifiedName('e1'),
            attributes: Attributes::empty()
                ->with($prov->qualifiedName('type'), $this->ex->qualifiedName('Custom'))
                ->with($prov->qualifiedName('type'), $prov->qualifiedName('PrimarySource')),
        );
        $this->assertSame(DerivationSubtype::PrimarySource, $mixed->subtype());
    }

    /**
     * @return iterable<string, array{DerivationSubtype}>
     */
    public static function cases(): iterable
    {
        foreach (DerivationSubtype::cases() as $subtype) {
            yield $subtype->name => [$subtype];
        }
    }

    #[DataProvider('cases')]
    public function testBuilderShortcutAgreesWithEnum(DerivationSubtype $subtype): void
    {
        $keyword = $subtype->keyword();
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        $builder->{$keyword}(generatedEntity: 'ex:e2', usedEntity: 'ex:e1');

        $derivation = $builder->build()->getRecordsByType(Derivation::class)[0];
        $this->assertSame($subtype, $derivation->subtype());
    }
}
