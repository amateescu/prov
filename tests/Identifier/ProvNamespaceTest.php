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

    public function testContainsMatchingQualifiedName(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $qn = $ns->qualifiedName('foo');

        $this->assertTrue($ns->contains($qn));
    }

    public function testContainsNonMatchingQualifiedName(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $other = new ProvNamespace('other', 'http://other.org/');
        $qn = $other->qualifiedName('foo');

        $this->assertFalse($ns->contains($qn));
    }

    public function testToString(): void
    {
        $ns = new ProvNamespace('ex', 'http://example.org/');
        $this->assertSame('http://example.org/', (string) $ns);
    }
}
