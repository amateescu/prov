<?php

declare(strict_types=1);

namespace Prov\Tests\Identifier;

use PHPUnit\Framework\TestCase;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

final class QualifiedNameTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    public function testConstruction(): void
    {
        $qn = new QualifiedName($this->ex, 'entity1');
        $this->assertSame($this->ex, $qn->namespace);
        $this->assertSame('entity1', $qn->localPart);
    }

    public function testUriIsComputed(): void
    {
        $qn = new QualifiedName($this->ex, 'entity1');
        $this->assertSame('http://example.org/entity1', $qn->uri);
    }

    public function testGetUri(): void
    {
        $qn = new QualifiedName($this->ex, 'entity1');
        $this->assertSame('http://example.org/entity1', $qn->getUri());
    }

    public function testToStringReturnsPrefixedForm(): void
    {
        $qn = new QualifiedName($this->ex, 'entity1');
        $this->assertSame('ex:entity1', (string) $qn);
    }

    public function testIsStringable(): void
    {
        $qn = new QualifiedName($this->ex, 'entity1');
        $this->assertInstanceOf(\Stringable::class, $qn);
    }

    public function testEmptyLocalPart(): void
    {
        $qn = new QualifiedName($this->ex, '');
        $this->assertSame('http://example.org/', $qn->uri);
        $this->assertSame('ex:', (string) $qn);
    }
}
