<?php

declare(strict_types=1);

namespace Prov\Tests\Identifier;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, list<string>>
     */
    public static function emptyLocalPartProvider(): array
    {
        return [
            'registered prefix' => ['prov:'],
            'blank-node sentinel' => ['_:'],
            'bare default-namespace name' => [''],
        ];
    }

    /**
     * Every way resolve() can fail reports a NamespaceException, including the
     * empty local parts, which is the type callers that tolerate unresolvable
     * identifiers catch.
     */
    #[DataProvider('emptyLocalPartProvider')]
    public function testResolveEmptyLocalPartThrowsNamespaceException(string $shorthand): void
    {
        $manager = new NamespaceManager();
        $manager->setDefault(new ProvNamespace('default', 'http://default.org/'));

        $this->expectException(NamespaceException::class);
        $manager->resolve($shorthand);
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

        $namespaces = $manager->registeredNamespaces;
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

    public function testSetDefaultToAnAlreadyRegisteredNamespaceRefreshesResolutions(): void
    {
        $manager = new NamespaceManager();
        $a = new ProvNamespace('a', 'http://a.org/');
        $b = new ProvNamespace('b', 'http://b.org/');

        $manager->setDefault($a);
        $this->assertSame('http://a.org/foo', $manager->resolve('foo')->uri);

        // add() registers b, then setDefault(b) finds it already registered. The
        // unprefixed name still has to follow the new default, cached or not.
        $manager->add($b);
        $this->assertSame('http://a.org/foo', $manager->resolve('foo')->uri);
        $manager->setDefault($b);
        $this->assertSame('http://b.org/foo', $manager->resolve('foo')->uri);
    }

    public function testRejectedSetDefaultKeepsThePreviousDefault(): void
    {
        $manager = new NamespaceManager();
        $manager->setDefault(new ProvNamespace('ex', 'http://example.org/'));

        try {
            $manager->setDefault(new ProvNamespace('ex', 'http://other.org/'));
            $this->fail('Expected a NamespaceException for the conflicting rebinding.');
        } catch (NamespaceException $e) {
            $this->assertStringContainsString("Prefix 'ex' is already registered", $e->getMessage());
        }

        // The declaration was rejected, so it must not take effect either.
        $this->assertSame('http://example.org/', $manager->getNamespace('ex')->uri);
        $this->assertSame('http://example.org/foo', $manager->resolve('foo')->uri);
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

    public function testResolveUnmatchedFullUriReportsUriNotPrefix(): void
    {
        $manager = new NamespaceManager();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage("No registered namespace matches URI 'http://unknown.example/foo'.");
        $manager->resolve('http://unknown.example/foo');
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

        $childPrefixes = array_map(static fn(ProvNamespace $ns) => $ns->prefix, $child->registeredNamespaces);

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

    public function testAddBuiltinWithDifferentUriThrows(): void
    {
        // add() is strict: rebinding a built-in is a likely typo, so it throws
        // and points the caller at addOrReplace().
        $manager = new NamespaceManager();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage('library built-in');
        $manager->add(new ProvNamespace('prov', 'http://other.org/prov#'));
    }

    public function testAddOrReplaceOverridesBuiltin(): void
    {
        $manager = new NamespaceManager();
        $manager->addOrReplace(new ProvNamespace('prov', 'http://other.org/prov#'));

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

    public function testAddOrReplaceCanRebindRepeatedly(): void
    {
        // addOrReplace() always takes the latest binding, built-in or not.
        $manager = new NamespaceManager();
        $manager->addOrReplace(new ProvNamespace('prov', 'http://a.example/'));
        $manager->addOrReplace(new ProvNamespace('prov', 'http://b.example/'));

        $ns = $manager->getNamespace('prov');
        $this->assertSame('http://b.example/', $ns->uri);
    }

    public function testResolveFullUrnMatchesRegisteredNamespace(): void
    {
        // A URN namespace has no '//' authority, so the input "looks" prefixed.
        // resolve() must match it against the registered namespace URI rather
        // than treating "urn" as a prefix.
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('node', 'urn:uuid:abc#node/'));

        $qn = $manager->resolve('urn:uuid:abc#node/42');
        $this->assertSame('urn:uuid:abc#node/42', $qn->uri);
        $this->assertSame('node', $qn->namespace->prefix);
        $this->assertSame('42', $qn->localPart);
    }

    public function testResolvePrefersRegisteredPrefixOverUriMatch(): void
    {
        // When the leading segment is itself a registered prefix, the prefixed
        // reading wins over treating the whole string as a URI.
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('urn', 'http://urn.example/'));

        $qn = $manager->resolve('urn:foo');
        $this->assertSame('http://urn.example/foo', $qn->uri);
    }

    public function testResolveUnregisteredPrefixWithNoUriMatchThrows(): void
    {
        $manager = new NamespaceManager();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage("Prefix 'urn' is not registered");
        $manager->resolve('urn:uuid:abc');
    }

    public function testResolveUriAcceptsSlashInLocalPart(): void
    {
        // Versioned identifiers (id/rev/version) live under a single namespace.
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('node', 'http://example.org/node/'));

        $qn = $manager->resolveUri('http://example.org/node/42/rev/7');
        $this->assertNotNull($qn);
        $this->assertSame('node', $qn->namespace->prefix);
        $this->assertSame('42/rev/7', $qn->localPart);
    }

    public function testResolveUriAcceptsFragmentInLocalPart(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('site', 'urn:uuid:abc'));

        $qn = $manager->resolveUri('urn:uuid:abc#node/42');
        $this->assertNotNull($qn);
        $this->assertSame('#node/42', $qn->localPart);
    }

    public function testResolveUriPrefersLongestNamespace(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('a', 'http://example.org/'));
        $manager->add(new ProvNamespace('b', 'http://example.org/sub/'));

        $qn = $manager->resolveUri('http://example.org/sub/x');
        $this->assertNotNull($qn);
        $this->assertSame('b', $qn->namespace->prefix);
        $this->assertSame('x', $qn->localPart);
    }

    public function testUriToPrefixedPrefersLongestNamespace(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('a', 'http://example.org/'));
        $manager->add(new ProvNamespace('b', 'http://example.org/sub/'));

        $this->assertSame('b:x', $manager->uriToPrefixed('http://example.org/sub/x'));
    }

    public function testUriToPrefixedKeepsSlashInLocalPart(): void
    {
        $manager = new NamespaceManager();
        $manager->add(new ProvNamespace('node', 'http://example.org/node/'));

        $this->assertSame('node:42/rev/7', $manager->uriToPrefixed('http://example.org/node/42/rev/7'));
    }

    public function testChildCanIndependentlyOverrideBuiltinAlreadyOverriddenByParent(): void
    {
        // A bundle-scoped NamespaceManager has its own fresh builtin tracking, so
        // each scope can independently rebind prov/xsd via addOrReplace() even
        // when an ancestor has already rebound the same prefix. Resolutions in
        // the child pick up the child's binding, not the parent's.
        $parent = new NamespaceManager();
        $parent->addOrReplace(new ProvNamespace('prov', 'http://parent.example/prov#'));

        $child = new NamespaceManager($parent);
        $child->addOrReplace(new ProvNamespace('prov', 'http://child.example/prov#'));

        $this->assertSame('http://child.example/prov#', $child->resolve('prov:Entity')->namespace->uri);
        $this->assertSame('http://parent.example/prov#', $parent->resolve('prov:Entity')->namespace->uri);
    }

    public function testForContainerRoutesDefaultPrefixToSetDefault(): void
    {
        $manager = NamespaceManager::forContainer([
            new ProvNamespace('ex', 'http://example.org/'),
            new ProvNamespace('default', 'http://default.org/'),
        ]);

        $qn = $manager->resolve('myEntity');
        $this->assertSame('http://default.org/myEntity', $qn->uri);
        $this->assertSame('http://example.org/', $manager->getNamespace('ex')->uri);
    }

    public function testForContainerRebindsBuiltinsLikeAddOrReplace(): void
    {
        $manager = NamespaceManager::forContainer([
            new ProvNamespace('prov', 'http://other.org/prov#'),
        ]);

        $this->assertSame('http://other.org/prov#', $manager->getNamespace('prov')->uri);
    }

    public function testForContainerAcceptsParent(): void
    {
        $parent = new NamespaceManager();
        $parent->add(new ProvNamespace('ex', 'http://example.org/'));

        $child = NamespaceManager::forContainer([], $parent);

        $qn = $child->resolve('ex:entity1');
        $this->assertSame('http://example.org/entity1', $qn->uri);
    }

    public function testStripDefaultSentinelRemovesReservedPrefix(): void
    {
        $this->assertSame('title', NamespaceManager::stripDefaultSentinel('default:title'));
    }

    public function testStripDefaultSentinelLeavesOtherKeysUntouched(): void
    {
        $this->assertSame('dct:title', NamespaceManager::stripDefaultSentinel('dct:title'));
    }
}
