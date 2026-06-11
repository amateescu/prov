<?php

declare(strict_types=1);

namespace Prov\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\AttributesBuilder;
use Prov\Attribute\Literal;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

final class AttributesBuilderTest extends TestCase
{
    private ProvNamespace $ex;
    private NamespaceManager $nsManager;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
        $this->nsManager = new NamespaceManager();
        $this->nsManager->add($this->ex);
    }

    public function testAddWithQualifiedNameKeyWithoutManager(): void
    {
        $key = $this->ex->qualifiedName('tag');
        $attrs = new AttributesBuilder()->add($key, 'value')->build();

        $this->assertSame(['value'], $attrs->get($key));
        $this->assertSame([$key], $attrs->keys());
    }

    public function testAddWithStringKeyResolvesViaManager(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('ex:tag', 'value')->build();

        $this->assertSame(['value'], $attrs->get($this->ex->qualifiedName('tag')));
        $this->assertSame('ex:tag', (string) $attrs->keys()[0]);
    }

    public function testAddWithStringKeyWithoutManagerThrows(): void
    {
        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage('no NamespaceManager is bound');
        new AttributesBuilder()->add('ex:tag', 'value');
    }

    public function testRepeatedKeysAccumulate(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)
            ->add('ex:tag', 'a')
            ->add('ex:tag', 'b')
            ->build();

        $this->assertSame(['a', 'b'], $attrs->get($this->ex->qualifiedName('tag')));
    }

    public function testAddAllAppendsEveryValue(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)
            ->add('prov:atLocation', 'here')
            ->addAll('prov:atLocation', ['there', 'everywhere'])
            ->build();

        $key = $this->nsManager->resolve('prov:atLocation');
        $this->assertSame(['here', 'there', 'everywhere'], $attrs->get($key));
    }

    public function testProvTypeStringShorthandResolvesToQualifiedName(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('prov:type', 'ex:Document')->build();

        $value = $attrs->firstValue($this->nsManager->resolve('prov:type'));
        $this->assertInstanceOf(QualifiedName::class, $value);
        $this->assertSame('http://example.org/Document', $value->getUri());
    }

    public function testProvTypeFullUriValueResolvesToQualifiedName(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('prov:type', 'http://example.org/Document')->build();

        $value = $attrs->firstValue($this->nsManager->resolve('prov:type'));
        $this->assertInstanceOf(QualifiedName::class, $value);
        $this->assertSame('http://example.org/Document', $value->getUri());
    }

    public function testProvTypeUnregisteredPrefixStaysString(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('prov:type', 'workspace:stage')->build();

        $this->assertSame('workspace:stage', $attrs->firstValue($this->nsManager->resolve('prov:type')));
    }

    public function testProvTypeValueWithoutColonStaysString(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('prov:type', 'Document')->build();

        $this->assertSame('Document', $attrs->firstValue($this->nsManager->resolve('prov:type')));
    }

    public function testProvTypeRuleAppliesWhenKeyIsQualifiedName(): void
    {
        $typeKey = ProvNamespace::prov()->qualifiedName('type');
        $attrs = new AttributesBuilder($this->nsManager)->add($typeKey, 'ex:Document')->build();

        $this->assertInstanceOf(QualifiedName::class, $attrs->firstValue($typeKey));
    }

    public function testProvTypeStringValueStaysStringWithoutManager(): void
    {
        $typeKey = ProvNamespace::prov()->qualifiedName('type');
        $attrs = new AttributesBuilder()->add($typeKey, 'ex:Document')->build();

        $this->assertSame('ex:Document', $attrs->firstValue($typeKey));
    }

    public function testNonTypeKeyStringValueStaysString(): void
    {
        $attrs = new AttributesBuilder($this->nsManager)->add('ex:related', 'ex:Document')->build();

        $this->assertSame('ex:Document', $attrs->firstValue($this->ex->qualifiedName('related')));
    }

    public function testLiteralAndQualifiedNameValuesPassThrough(): void
    {
        $literal = Literal::string('typed');
        $qn = $this->ex->qualifiedName('ref');
        $attrs = new AttributesBuilder($this->nsManager)
            ->add('ex:a', $literal)
            ->add('ex:b', $qn)
            ->build();

        $this->assertSame($literal, $attrs->firstValue($this->ex->qualifiedName('a')));
        $this->assertSame($qn, $attrs->firstValue($this->ex->qualifiedName('b')));
    }

    public function testBuilderStaysUsableAfterBuild(): void
    {
        $builder = new AttributesBuilder($this->nsManager)->add('ex:tag', 'a');
        $first = $builder->build();
        $second = $builder->add('ex:tag', 'b')->build();

        $key = $this->ex->qualifiedName('tag');
        $this->assertSame(['a'], $first->get($key));
        $this->assertSame(['a', 'b'], $second->get($key));
    }
}
