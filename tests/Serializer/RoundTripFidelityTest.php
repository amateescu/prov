<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Format;
use Prov\Operation\DocumentComparator;
use Prov\Prov;
use Prov\Serializer\ProvNSerializer;

/**
 * Round-trip fidelity regressions for data that historically survived only on the
 * deserialize-first fixture path (sub-second times, blank-node references, native
 * scalars, large integers), plus the PROV-N output-injection guards.
 */
final class RoundTripFidelityTest extends TestCase
{
    /**
     * @return list<\Prov\Format>
     */
    private static function roundTripFormats(): array
    {
        return [Format::Json, Format::ProvN, Format::Xml];
    }

    public function testSubSecondDateTimeSurvivesRoundTrip(): void
    {
        foreach (self::roundTripFormats() as $format) {
            $builder = new DocumentBuilder();
            $builder->namespace('ex', 'http://example.org/');
            $builder->activity('ex:a', startTime: new \DateTimeImmutable('2024-03-15T14:30:00.123456+05:30'));
            $document = $builder->build();

            $roundTripped = Prov::deserialize(Prov::serialize($document, $format), $format);
            $time = $roundTripped->activities[0]->startTime;

            $this->assertNotNull($time);
            $this->assertSame(
                '2024-03-15T14:30:00.123456 +05:30',
                $time->format('Y-m-d\TH:i:s.u P'),
                "Sub-second precision lost in {$format->name}.",
            );
        }
    }

    public function testBlankNodeReferenceRoundTrips(): void
    {
        foreach (self::roundTripFormats() as $format) {
            $builder = new DocumentBuilder();
            $builder->namespace('ex', 'http://example.org/');
            $entity = $builder->blank();
            $agent = $builder->blank();
            $builder->entity($entity);
            $builder->agent($agent);
            $builder->wasAttributedTo(entity: $entity, agent: $agent);
            $document = $builder->build();

            $roundTripped = Prov::deserialize(Prov::serialize($document, $format), $format);

            $this->assertTrue(
                DocumentComparator::equals($document, $roundTripped),
                "Blank-node reference did not round-trip in {$format->name}.",
            );
        }
    }

    public function testNativeScalarAttributesRoundTrip(): void
    {
        foreach (self::roundTripFormats() as $format) {
            $builder = new DocumentBuilder();
            $builder->namespace('ex', 'http://example.org/');
            $builder->entity('ex:e', ['ex:i' => 42, 'ex:f' => 0.1 + 0.2, 'ex:b' => true]);
            $document = $builder->build();

            $roundTripped = Prov::deserialize(Prov::serialize($document, $format), $format);

            $this->assertTrue(
                DocumentComparator::equals($document, $roundTripped),
                "Native scalar attributes did not round-trip in {$format->name}.",
            );
        }
    }

    public function testLargeIntegerLiteralIsNotClampedInProvN(): void
    {
        $provn = 'document prefix ex <http://example.org/> entity(ex:e, [ex:v = 99999999999999999999]) endDocument';
        $document = Prov::deserialize($provn, Format::ProvN);

        $values = $document->entities[0]->attributes->all();
        $value = array_values($values)[0][0];

        $this->assertInstanceOf(Literal::class, $value);
        $this->assertSame('99999999999999999999', $value->value);
    }

    public function testProvNSerializerRejectsInjectableIdentifier(): void
    {
        // A hostile identifier (here arriving via PROV-JSON) must never be emitted as
        // structure that injects records when the document is re-serialized to PROV-N.
        $json = (string) json_encode([
            'prefix' => ['ex' => 'http://example.org/#'],
            'entity' => ['ex:a)' . "\n" . 'wasInformedBy(ex:p, ex:q' => new \stdClass()],
        ]);
        $document = Prov::deserialize($json, Format::Json);

        try {
            $output = new ProvNSerializer()->serialize($document);
            $this->fail('Hostile identifier was not rejected; got: ' . $output);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PROV-N', $e->getMessage());
        }
    }

    public function testProvNSerializerRejectsInjectableLanguageTag(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->entity('ex:e', ['ex:a' => new Literal('v', null, 'en] entity(ex:pwn) [')]);
        $document = $builder->build();

        try {
            $output = new ProvNSerializer()->serialize($document);
            $this->fail('Hostile language tag was not rejected; got: ' . $output);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PROV-N', $e->getMessage());
        }
    }

    public function testProvNSerializerRejectsInjectableRelationEndpoint(): void
    {
        // The hostile value is a relation endpoint (not the record id), so this guards that
        // validation happens at every qualified-name emission site, not just on identifiers.
        $json = (string) json_encode([
            'prefix' => ['ex' => 'http://example.org/#'],
            'wasInformedBy' => ['ex:r' => ['prov:informed' => 'ex:a) entity(ex:pwn', 'prov:informant' => 'ex:b']],
        ]);
        $document = Prov::deserialize($json, Format::Json);

        try {
            $output = new ProvNSerializer()->serialize($document);
            $this->fail('Hostile relation endpoint was not rejected; got: ' . $output);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PROV-N', $e->getMessage());
        }
    }
}
