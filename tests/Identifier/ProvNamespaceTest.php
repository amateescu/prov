<?php

declare(strict_types=1);

namespace Prov\Tests\Identifier;

use PHPUnit\Framework\TestCase;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

final class ProvNamespaceTest extends TestCase
{
    public function testConstruction(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $this->assertSame('ex', $ns->prefix);
        $this->assertSame('http://example.org/', $ns->uri);
    }

    public function testEmptyPrefixIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prefix cannot be empty');
        new ProvNamespace('', 'http://example.org/');
    }

    public function testEmptyUriIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URI cannot be empty');
        new ProvNamespace('ex', '');
    }

    public function testBlankNodeSentinelNamespaceIsAllowed(): void
    {
        $ns = new ProvNamespace('_', '_:');
        $this->assertSame('_:b1', $ns->qualifiedName('b1')->uri);
    }

    public function testQualifiedNameFactory(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $qn = $ns->qualifiedName('entity1');

        $this->assertInstanceOf(QualifiedName::class, $qn);
        $this->assertSame($ns, $qn->namespace);
        $this->assertSame('entity1', $qn->localPart);
        $this->assertSame('http://example.org/entity1', $qn->uri);
    }

    public function testToString(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $this->assertSame('http://example.org/', (string) $ns);
    }

    public function testCanonicalReservedBinding(): void
    {
        $this->assertTrue(ProvNamespace::prov()->isCanonicalReservedBinding());
        $this->assertTrue(ProvNamespace::xsd()->isCanonicalReservedBinding());
        // PROV-XML spells the XSD namespace without the trailing '#'.
        $this->assertTrue(new ProvNamespace('xsd', 'http://www.w3.org/2001/XMLSchema')->isCanonicalReservedBinding());

        $this->assertFalse(new ProvNamespace('prov', 'http://foreign.example/prov#')->isCanonicalReservedBinding());
        $this->assertFalse(new ProvNamespace('xsd', 'http://foreign.example/xsd#')->isCanonicalReservedBinding());
        // Only the two reserved prefixes count, whatever namespace they name.
        $this->assertFalse(new ProvNamespace('p', 'http://www.w3.org/ns/prov#')->isCanonicalReservedBinding());
    }
}
