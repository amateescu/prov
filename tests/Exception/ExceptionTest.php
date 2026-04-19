<?php

declare(strict_types=1);

namespace Prov\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Prov\Exception\DeserializationException;
use Prov\Exception\NamespaceException;
use Prov\Exception\ProvException;

final class ExceptionTest extends TestCase
{
    public function testProvExceptionExtendsRuntimeException(): void
    {
        $e = new ProvException('test');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testNamespaceExceptionExtendsProvException(): void
    {
        $e = new NamespaceException('test');
        $this->assertInstanceOf(ProvException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testDeserializationExceptionExtendsProvException(): void
    {
        $e = new DeserializationException('test');
        $this->assertInstanceOf(ProvException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testCatchProvExceptionCatchesBoth(): void
    {
        $caught = [];

        try {
            throw new NamespaceException('ns error');
        } catch (ProvException $e) {
            $caught[] = 'namespace';
        }

        try {
            throw new DeserializationException('deser error');
        } catch (ProvException $e) {
            $caught[] = 'deserialization';
        }

        $this->assertSame(['namespace', 'deserialization'], $caught);
    }
}
