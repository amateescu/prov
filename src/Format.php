<?php

declare(strict_types=1);

namespace Prov;

use Prov\Exception\ProvException;
use Prov\Serializer\JsonLdSerializer;
use Prov\Serializer\JsonSerializer;
use Prov\Serializer\ProvDeserializerInterface;
use Prov\Serializer\ProvNDeserializer;
use Prov\Serializer\ProvNSerializer;
use Prov\Serializer\ProvSerializerInterface;
use Prov\Serializer\XmlSerializer;

/**
 * Identifies a PROV serialization format.
 *
 * Use with the Prov facade (`Prov::serialize($doc, Format::Json)`) or directly
 * via `Format::Json->createSerializer()`. PROV-JSON-LD is serialize-only per
 * the W3C spec; calling `createDeserializer()` on it throws.
 */
enum Format: string
{
    case Json = 'json';
    case ProvN = 'provn';
    case Xml = 'xml';
    case JsonLd = 'jsonld';

    /**
     * Returns a serializer for this format.
     */
    public function createSerializer(): ProvSerializerInterface
    {
        return match ($this) {
            self::Json => new JsonSerializer(),
            self::ProvN => new ProvNSerializer(),
            self::Xml => new XmlSerializer(),
            self::JsonLd => new JsonLdSerializer(),
        };
    }

    /**
     * Returns a deserializer for this format.
     *
     * @throws \Prov\Exception\ProvException
     *   When called on Format::JsonLd, which is serialize-only.
     */
    public function createDeserializer(): ProvDeserializerInterface
    {
        return match ($this) {
            self::Json => new JsonSerializer(),
            self::ProvN => new ProvNDeserializer(),
            self::Xml => new XmlSerializer(),
            self::JsonLd => throw new ProvException(
                'PROV-JSON-LD deserialization is not supported (serialize-only format).',
            ),
        };
    }
}
