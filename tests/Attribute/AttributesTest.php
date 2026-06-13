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

    public function testKeysReturnsQualifiedNameObjects(): void
    {
        $key1 = $this->prov->qualifiedName('type');
        $key2 = $this->prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key1, 'Document')
            ->with($key2, 'My Doc')
            ->with($key1, 'Article');

        $this->assertSame([$key1, $key2], $attrs->keys());
    }

    public function testKeysPreservedByFromAndSingle(): void
    {
        $key = $this->prov->qualifiedName('type');

        $this->assertSame([$key], Attributes::from([[$key, 'a']])->keys());
        $this->assertSame([$key], Attributes::single($key, 'a')->keys());
    }

    public function testKeysOnEmptyBag(): void
    {
        $this->assertSame([], new Attributes()->keys());
    }

    public function testKeysDerivedFromRawUriData(): void
    {
        $attrs = new Attributes(['http://example.org/ns#label' => ['x']]);

        $keys = $attrs->keys();
        $this->assertCount(1, $keys);
        $this->assertSame('label', $keys[0]->localPart);
        $this->assertSame('http://example.org/ns#', $keys[0]->namespace->uri);
        $this->assertSame('http://example.org/ns#label', $keys[0]->getUri());
    }

    public function testKeysDerivedFromSlashOnlyUri(): void
    {
        $attrs = new Attributes(['http://example.org/ns/label' => ['x']]);

        $keys = $attrs->keys();
        $this->assertCount(1, $keys);
        $this->assertSame('label', $keys[0]->localPart);
        $this->assertSame('http://example.org/ns/', $keys[0]->namespace->uri);
    }

    public function testKeysDerivedFromOpaqueColonOnlyIri(): void
    {
        // An opaque IRI (urn:, tag:, ...) has neither '#' nor '/', so the key
        // splits at the last ':' rather than crashing on an empty namespace.
        $attrs = new Attributes(['urn:uuid:1234' => ['x']]);

        $keys = $attrs->keys();
        $this->assertCount(1, $keys);
        $this->assertSame('1234', $keys[0]->localPart);
        $this->assertSame('urn:uuid:', $keys[0]->namespace->uri);
        $this->assertSame('urn:uuid:1234', $keys[0]->getUri());
    }

    public function testIterationOverOpaqueColonOnlyIriKeyDoesNotCrash(): void
    {
        $attrs = new Attributes(['tag:example' => ['x']]);

        $pairs = [];
        foreach ($attrs as $key => $value) {
            $pairs[] = [$key->getUri(), $value];
        }

        $this->assertSame([['tag:example', 'x']], $pairs);
    }

    public function testKeyUriEndingInItsSeparatorIsRejectedLoudly(): void
    {
        // A key URI with nothing after its last separator has no local part;
        // the rejection must name the key, not surface as an unrelated
        // QualifiedName or ProvNamespace construction error.
        $attrs = new Attributes(['urn:uuid:' => ['x']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("attribute key URI 'urn:uuid:'");
        $attrs->keys();
    }

    public function testKeyUriWithoutAnySeparatorIsRejectedLoudly(): void
    {
        $attrs = new Attributes(['word' => ['x']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("attribute key URI 'word'");
        $attrs->keys();
    }

    public function testIterationYieldsEachValueWithQualifiedNameKey(): void
    {
        $key1 = $this->prov->qualifiedName('type');
        $key2 = $this->prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key1, 'Document')
            ->with($key1, 'Article')
            ->with($key2, 42);

        $pairs = [];
        foreach ($attrs as $key => $value) {
            $pairs[] = [$key, $value];
        }

        $this->assertSame([[$key1, 'Document'], [$key1, 'Article'], [$key2, 42]], $pairs);
    }

    public function testCountIsTotalNumberOfValues(): void
    {
        $key1 = $this->prov->qualifiedName('type');
        $key2 = $this->prov->qualifiedName('label');
        $attrs = new Attributes()
            ->with($key1, 'Document')
            ->with($key1, 'Article')
            ->with($key2, 'My Doc');

        $this->assertCount(3, $attrs);
        $this->assertCount(0, new Attributes());
    }

    public function testMergeAppendsValuesUnderSharedKey(): void
    {
        $type = $this->prov->qualifiedName('type');
        $label = $this->prov->qualifiedName('label');

        $a = new Attributes()->with($type, 'Document');
        $b = new Attributes()
            ->with($type, 'Article')
            ->with($label, 'My Doc');

        $merged = $a->merge($b);

        $this->assertSame(['Document', 'Article'], $merged->get($type));
        $this->assertSame(['My Doc'], $merged->get($label));
        // Operands are untouched (immutable).
        $this->assertSame(['Document'], $a->get($type));
    }

    public function testMergeWithEmptyReturnsSelf(): void
    {
        $a = new Attributes()->with($this->prov->qualifiedName('type'), 'Document');
        $this->assertSame($a, $a->merge(new Attributes()));
    }
}
