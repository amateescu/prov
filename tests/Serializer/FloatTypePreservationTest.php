<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentComparator;
use Prov\Prov;

/**
 * Regression coverage for review item 1.6: a whole-valued float attribute
 * (e.g. 2.0) must keep its float type through PROV-JSON. Without
 * JSON_PRESERVE_ZERO_FRACTION the document-level encode drops the fraction,
 * and the value deserializes as an int.
 */
final class FloatTypePreservationTest extends TestCase
{
    public function testWholeAndNegativeFloatsKeepTheirTypeThroughJson(): void
    {
        $doc = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('ex', 'http://example.org/'))
            ->entity('ex:e1', [
                'ex:whole' => 2.0,
                'ex:negative' => -3.0,
                'ex:fractional' => 1.5,
                'ex:int' => -7,
            ])
            ->build();

        $roundTripped = Prov::deserialize(Prov::serialize($doc, Format::Json), Format::Json);

        $this->assertTrue(
            DocumentComparator::equals($doc, $roundTripped),
            'A whole-valued float lost its type through the PROV-JSON round trip',
        );
    }

    public function testWholeFloatIsEncodedWithItsFraction(): void
    {
        $doc = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('ex', 'http://example.org/'))
            ->entity('ex:e1', ['ex:whole' => 2.0])
            ->build();

        $this->assertStringContainsString('2.0', Prov::serialize($doc, Format::Json));
    }
}
