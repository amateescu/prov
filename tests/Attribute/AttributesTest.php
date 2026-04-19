<?php

declare(strict_types=1);

namespace Prov\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

final class AttributesTest extends TestCase
{
    private ProvNamespace $prov;

    protected function setUp(): void
    {
        $this->prov = new ProvNamespace('prov', 'http://www.w3.org/ns/prov#');
    }

    public function testEmptyConstruction(): void
    {
        $attrs = new Attributes();
        $this->assertTrue($attrs->isEmpty());
        $this->assertSame([], $attrs->all());
    }

    public function testWithReturnsNewInstance(): void
    {
        $key = $this->prov->qualifiedName('type');
        $attrs1 = new Attributes();
        $attrs2 = $attrs1->with($key, 'value');

        $this->assertTrue($attrs1->isEmpty());
        $this->assertFalse($attrs2->isEmpty());
    }

    public function testWithAddsValue(): void
    {
        $key = $this->prov->qualifiedName('type');
        $attrs = new Attributes()->with($key, 'Document');

        $this->assertTrue($attrs->has($key));
        $this->assertSame(['Document'], $attrs->get($key));
    }

    public function testMultimapBehavior(): void
    {
        $key = $this->prov->qualifiedName('type');
        $attrs = new Attributes()
            ->with($key, 'Document')
            ->with($key, 'Article');

        $values = $attrs->get($key);
        $this->assertCount(2, $values);
        $this->assertSame('Document', $values[0]);
        $this->assertSame('Article', $values[1]);
    }

    public function testGetMissingKey(): void
    {
        $attrs = new Attributes();
        $key = $this->prov->qualifiedName('missing');
        $this->assertSame([], $attrs->get($key));
    }

    public function testHasMissingKey(): void
    {
        $attrs = new Attributes();
        $key = $this->prov->qualifiedName('missing');
        $this->assertFalse($attrs->has($key));
    }

    public function testSingleFactory(): void
    {
        $key = $this->prov->qualifiedName('type');
        $attrs = Attributes::single($key, 'Document');

        $this->assertFalse($attrs->isEmpty());
        $this->assertSame(['Document'], $attrs->get($key));
    }

    public function testFromFactory(): void
    {
        $key1 = $this->prov->qualifiedName('type');
        $key2 = $this->prov->qualifiedName('label');
        $attrs = Attributes::from([
            [$key1, 'Document'],
            [$key2, 'My Document'],
            [$key1, 'Article'],
        ]);

        $this->assertCount(2, $attrs->get($key1));
        $this->assertCount(1, $attrs->get($key2));
    }

    public function testAllReturnsFullData(): void
    {
        $key1 = $this->prov->qualifiedName('type');
        $key2 = $this->prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key1, 'Document')
            ->with($key2, 'My Doc');

        $all = $attrs->all();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey($key1->getUri(), $all);
        $this->assertArrayHasKey($key2->getUri(), $all);
    }

    public function testDifferentValueTypes(): void
    {
        $key = $this->prov->qualifiedName('value');
        $literal = Literal::string('hello');
        $qn = $this->prov->qualifiedName('SomeType');

        $attrs = new Attributes()
            ->with($key, $literal)
            ->with($key, $qn)
            ->with($key, 42)
            ->with($key, 3.14)
            ->with($key, true)
            ->with($key, 'plain');

        $values = $attrs->get($key);
        $this->assertCount(6, $values);
        $this->assertInstanceOf(Literal::class, $values[0]);
        $this->assertInstanceOf(QualifiedName::class, $values[1]);
        $this->assertSame(42, $values[2]);
        $this->assertSame(3.14, $values[3]);
        $this->assertTrue($values[4]);
        $this->assertSame('plain', $values[5]);
    }

    public function testFromEmptyArray(): void
    {
        $attrs = Attributes::from([]);
        $this->assertTrue($attrs->isEmpty());
    }

    public function testWithDoesNotMutateOriginal(): void
    {
        $key = $this->prov->qualifiedName('type');
        $original = new Attributes();
        $modified = $original->with($key, 'Document');

        $this->assertTrue($original->isEmpty());
        $this->assertFalse($modified->isEmpty());
        $this->assertNotSame($original, $modified);
    }

    public function testFirstValueReturnsFirst(): void
    {
        $key = $this->prov->qualifiedName('role');
        $attrs = new Attributes()
            ->with($key, 'first')
            ->with($key, 'second');
        $this->assertSame('first', $attrs->firstValue($key));
    }

    public function testFirstValueReturnsNullForMissingKey(): void
    {
        $attrs = new Attributes();
        $this->assertNull($attrs->firstValue($this->prov->qualifiedName('missing')));
    }

    public function testGetLiteralsFiltersToLiteralValues(): void
    {
        $key = $this->prov->qualifiedName('label');
        $literal = \Prov\Attribute\Literal::string('hi');
        $qn = $this->prov->qualifiedName('other');
        $attrs = new Attributes()
            ->with($key, 'plain-string')
            ->with($key, $literal)
            ->with($key, $qn)
            ->with($key, 42);
        $this->assertSame([$literal], $attrs->getLiterals($key));
    }

    public function testGetQualifiedNamesFiltersToQnValues(): void
    {
        $key = $this->prov->qualifiedName('role');
        $qn1 = $this->prov->qualifiedName('author');
        $qn2 = $this->prov->qualifiedName('editor');
        $attrs = new Attributes()
            ->with($key, $qn1)
            ->with($key, 'plain')
            ->with($key, $qn2);
        $this->assertSame([$qn1, $qn2], $attrs->getQualifiedNames($key));
    }

    public function testGetScalarsFiltersToNativeScalars(): void
    {
        $key = $this->prov->qualifiedName('count');
        $attrs = new Attributes()
            ->with($key, 1)
            ->with($key, \Prov\Attribute\Literal::int(2))
            ->with($key, 'three')
            ->with($key, true);
        $this->assertSame([1, 'three', true], $attrs->getScalars($key));
    }
}
