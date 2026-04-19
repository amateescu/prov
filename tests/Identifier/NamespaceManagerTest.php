<?php

declare(strict_types=1);

namespace Prov\Tests\Identifier;

use PHPUnit\Framework\TestCase;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

final class NamespaceManagerTest extends TestCase
{
    public function testKnownNamespacesRegisteredByDefault(): void
    {
        $manager = new NamespaceManager();

        $prov = $manager->getNamespace('prov');
        $this->assertNotNull($prov);
        $this->assertSame('http://www.w3.org/ns/prov#', $prov->uri);

        $xsd = $manager->getNamespace('xsd');
        $this->assertNotNull($xsd);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#', $xsd->uri);
    }

    public function testAddAndGetNamespace(): void
    {
        $manager = new NamespaceManager();
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $manager->add($ex);

        $this->assertSame($ex, $manager->getNamespace('ex'));
    }

    public function testAddDuplicatePrefixSameUri(): void
    {
        $manager = new NamespaceManager();
        $ex1 = new ProvNamespace('ex', 'http://example.org/');
        $ex2 = new ProvNamespace('ex', 'http://example.org/');

        $manager->add($ex1);
        $manager->add($ex2); // should not throw

        $resolved = $manager->getNamespace('ex');
        $this->assertSame('ex', $resolved->prefix);
        $this->assertSame('http://example.org/', $resolved->uri);
    }

    public function testAddDuplicatePrefixDifferentUriThrows(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $this->expectException(NamespaceException::class);
        $manager->add(new ProvNamespace('ex', 'http://other.org/'));
    }

    public function testResolvePrefixedString(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $qn = $manager->resolve('ex:entity1');
        $this->assertInstanceOf(QualifiedName::class, $qn);
        $this->assertSame('http://example.org/entity1', $qn->uri);
        $this->assertSame('ex', $qn->namespace->prefix);
        $this->assertSame('entity1', $qn->localPart);
    }

    public function testResolveUnprefixedStringWithDefault(): void
    {
        $manager = new NamespaceManager();
        $default = new ProvNamespace('default', 'http://default.org/');
        $manager->setDefault($default);

        $qn = $manager->resolve('myEntity');
        $this->assertSame('http://default.org/myEntity', $qn->uri);
    }

    public function testResolveUnprefixedStringWithoutDefaultThrows(): void
    {
        $manager = new NamespaceManager();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage('No default namespace');
        $manager->resolve('myEntity');
    }

    public function testResolveUnknownPrefixThrows(): void
    {
        $manager = new NamespaceManager();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage("Prefix 'unknown' is not registered");
        $manager->resolve('unknown:foo');
    }

    public function testResolveKnownPrefix(): void
    {
        $manager = new NamespaceManager();
        $qn = $manager->resolve('prov:Entity');
        $this->assertSame('http://www.w3.org/ns/prov#Entity', $qn->uri);
    }

    public function testParentChaining(): void
    {
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('ex', 'http://example.org/'));

        $child = new NamespaceManager($parent);

        $qn = $child->resolve('ex:entity1');
        $this->assertSame('http://example.org/entity1', $qn->uri);
    }

    public function testParentChainingDefaultNamespace(): void
    {
        $parent = new NamespaceManager();
        $parent->setDefault(new ProvNamespace('default', 'http://default.org/'));

        $child = new NamespaceManager($parent);

        $qn = $child->resolve('myEntity');
        $this->assertSame('http://default.org/myEntity', $qn->uri);
    }

    public function testChildOverridesParent(): void
    {
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('test', 'http://parent.org/'));

        $child = new NamespaceManager($parent);
        $child->add(new ProvNamespace('test', 'http://child.org/'));

        $qn = $child->resolve('test:foo');
        $this->assertSame('http://child.org/foo', $qn->uri);
    }

    public function testGetRegisteredNamespaces(): void
    {
        $manager = new NamespaceManager();
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $manager->add($ex);

        $namespaces = $manager->getRegisteredNamespaces();
        $prefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $namespaces);

        $this->assertContains('prov', $prefixes);
        $this->assertContains('xsd', $prefixes);
        $this->assertContains('ex', $prefixes);
    }

    public function testResolveUri(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $qn = $manager->resolveUri('http://example.org/entity1');
        $this->assertNotNull($qn);
        $this->assertSame('ex:entity1', (string) $qn);
    }

    public function testResolveUriNoMatch(): void
    {
        $manager = new NamespaceManager();
        $result = $manager->resolveUri('http://unknown.org/foo');
        $this->assertNull($result);
    }

    public function testUriToPrefixed(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $this->assertSame('ex:entity1', $manager->uriToPrefixed('http://example.org/entity1'));
        $this->assertSame('prov:type', $manager->uriToPrefixed('http://www.w3.org/ns/prov#type'));
    }

    public function testUriToPrefixedFallsBackToUri(): void
    {
        $manager = new NamespaceManager();
        $this->assertSame('http://unknown.org/foo', $manager->uriToPrefixed('http://unknown.org/foo'));
    }

    public function testSetDefaultAlsoRegistersNamespace(): void
    {
        $manager = new NamespaceManager();
        $default = new ProvNamespace('myns', 'http://myns.org/');
        $manager->setDefault($default);

        $this->assertSame($default, $manager->getNamespace('myns'));
    }

    public function testResolveMultipleColonsSplitsOnFirst(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $qn = $manager->resolve('ex:foo:bar');
        $this->assertSame('foo:bar', $qn->localPart);
        $this->assertSame('http://example.org/foo:bar', $qn->uri);
    }

    public function testResolveFullUriViaPrefixedPath(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $qn = $manager->resolve('http://example.org/entity1');
        $this->assertSame('http://example.org/entity1', $qn->uri);
        $this->assertSame('entity1', $qn->localPart);
    }

    public function testResolveUriViaParent(): void
    {
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('ex', 'http://example.org/'));

        $child = new NamespaceManager($parent);
        $qn = $child->resolveUri('http://example.org/entity1');
        $this->assertNotNull($qn);
        $this->assertSame('ex:entity1', (string) $qn);
    }

    public function testGetRegisteredNamespacesExcludesParent(): void
    {
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('parentonly', 'http://parent.org/'));

        $child = new NamespaceManager($parent);
        $child->add(new ProvNamespace('childonly', 'http://child.org/'));

        $childPrefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $child->getRegisteredNamespaces());

        $this->assertContains('childonly', $childPrefixes);
        $this->assertNotContains('parentonly', $childPrefixes);
    }

    public function testReRegisterKnownPrefixWithSameUriSucceeds(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('prov', 'http://www.w3.org/ns/prov#'));

        $ns = $manager->getNamespace('prov');
        $this->assertSame('http://www.w3.org/ns/prov#', $ns->uri);
    }

    public function testReRegisterBuiltinWithDifferentUriOverridesSilently(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('prov', 'http://other.org/prov#'));

        $ns = $manager->getNamespace('prov');
        $this->assertSame('http://other.org/prov#', $ns->uri);
    }

    public function testReRegisterUserPrefixWithDifferentUriThrows(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('ex', 'http://example.org/'));

        $this->expectException(NamespaceException::class);
        $manager->add(new ProvNamespace('ex', 'http://other.org/'));
    }

    public function testBuiltinOverrideIsOneShot(): void
    {
        // Once a user has overridden a built-in, subsequent conflicts throw.
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('prov', 'http://a.example/'));

        $this->expectException(NamespaceException::class);
        $manager->add(new ProvNamespace('prov', 'http://b.example/'));
    }

    public function testChildCanIndependentlyOverrideBuiltinAlreadyOverriddenByParent(): void
    {
        // A bundle-scoped NamespaceManager has its own fresh builtin tracking, so
        // each scope gets one independent shot at rebinding prov/xsd even when an
        // ancestor has already rebound the same prefix. Resolutions in the child
        // pick up the child's binding, not the parent's.
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('prov', 'http://parent.example/prov#'));

        $child = new NamespaceManager($parent);
        $child->add(new ProvNamespace('prov', 'http://child.example/prov#'));

        $this->assertSame('http://child.example/prov#', $child->resolve('prov:Entity')->namespace->uri);
        $this->assertSame('http://parent.example/prov#', $parent->resolve('prov:Entity')->namespace->uri);
    }
}
