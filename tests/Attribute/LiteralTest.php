<?php

declare(strict_types=1);

namespace Prov\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Identifier\ProvNamespace;

final class LiteralTest extends TestCase
{
    public function testConstruction(): void
    {
        $lit = new Literal('hello');
        $this->assertSame('hello', $lit->value);
        $this->assertNull($lit->datatype);
        $this->assertNull($lit->languageTag);
    }

    public function testConstructionWithDatatype(): void
    {
        $dt = ProvNamespace::xsd()->qualifiedName('string');
        $lit = new Literal('hello', datatype: $dt);
        $this->assertSame('hello', $lit->value);
        $this->assertSame($dt->uri, $lit->datatype->uri);
    }

    public function testConstructionWithLanguageTag(): void
    {
        $lit = new Literal('hello', languageTag: 'en');
        $this->assertSame('hello', $lit->value);
        $this->assertSame('en', $lit->languageTag);
    }

    public function testDatatypeAndLanguageTagMutuallyExclusive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot have both a datatype and a language tag');

        new Literal('hello', datatype: ProvNamespace::xsd()->qualifiedName('string'), languageTag: 'en');
    }

    public function testStringFactory(): void
    {
        $lit = Literal::string('hello');
        $this->assertSame('hello', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#string', $lit->datatype->uri);
    }

    public function testIntFactory(): void
    {
        $lit = Literal::int(42);
        $this->assertSame('42', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#int', $lit->datatype->uri);
    }

    public function testBooleanFactoryTrue(): void
    {
        $lit = Literal::boolean(true);
        $this->assertSame('true', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#boolean', $lit->datatype->uri);
    }

    public function testBooleanFactoryFalse(): void
    {
        $lit = Literal::boolean(false);
        $this->assertSame('false', $lit->value);
    }

    public function testDateTimeFactory(): void
    {
        $dt = new \DateTimeImmutable('2023-01-15T10:30:00+00:00');
        $lit = Literal::dateTime($dt);
        $this->assertSame('2023-01-15T10:30:00+00:00', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#dateTime', $lit->datatype->uri);
    }

    public function testFloatFactory(): void
    {
        $lit = Literal::float(3.14);
        $this->assertSame('3.14', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#float', $lit->datatype->uri);
    }

    public function testToString(): void
    {
        $lit = new Literal('hello');
        $this->assertSame('hello', (string) $lit);
    }

    public function testImplementsStringable(): void
    {
        $lit = new Literal('test');
        $this->assertInstanceOf(\Stringable::class, $lit);
    }

    public function testDoubleFactory(): void
    {
        $lit = Literal::double(3.14);
        $this->assertSame('3.14', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#double', $lit->datatype->uri);
    }

    public function testLongFactory(): void
    {
        $lit = Literal::long(9_999_999_999);
        $this->assertSame('9999999999', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#long', $lit->datatype->uri);
    }

    public function testDecimalFactory(): void
    {
        $lit = Literal::decimal('10.5');
        $this->assertSame('10.5', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#decimal', $lit->datatype->uri);
    }

    public function testAnyURIFactory(): void
    {
        $lit = Literal::anyURI('http://example.org/');
        $this->assertSame('http://example.org/', $lit->value);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#anyURI', $lit->datatype->uri);
    }
}
