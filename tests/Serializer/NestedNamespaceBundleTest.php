<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Prov;

/**
 * Regression coverage for review item 1.2: a bundle identifier whose namespace
 * URI is a string extension of another declared namespace must keep its own
 * namespace through PROV-JSON, not be rewritten to the shorter prefix.
 */
final class NestedNamespaceBundleTest extends TestCase
{
    public function testNestedNamespaceBundleIdentifierIsNotRewritten(): void
    {
        $doc = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('outer', 'http://example.org/'))
            ->addNamespace(new ProvNamespace('inner', 'http://example.org/sub/'))
            ->withBundle('inner:x', static fn($bundle) => $bundle->entity('inner:e1'))
            ->build();

        $this->assertSame('http://example.org/sub/x', $doc->bundles[0]->identifier->getUri());

        $roundTripped = Prov::deserialize(Prov::serialize($doc, Format::Json), Format::Json);

        $this->assertCount(1, $roundTripped->bundles);
        $this->assertSame(
            'http://example.org/sub/x',
            $roundTripped->bundles[0]->identifier->getUri(),
            'Nested-namespace bundle identifier was silently rewritten to the shorter prefix',
        );
    }
}
