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
 * via `Format::Json->createSerializer()`. PROV-JSONLD is serialize-only per
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
     *
     * @param bool|null $prettyPrint
     *   Whether to indent the output. Null keeps each format's own default:
     *   PROV-XML and PROV-N indent, PROV-JSON and PROV-JSONLD do not. For
     *   PROV-N, false writes every record at column zero.
     * @param bool $sortRecords
     *   Whether to order records into PROV-DM concept order instead of keeping
     *   the document's own order. Namespace declarations are always sorted.
     */
    public function createSerializer(?bool $prettyPrint = null, bool $sortRecords = false): ProvSerializerInterface
    {
        return match ($this) {
            self::Json => new JsonSerializer(prettyPrint: $prettyPrint ?? false, sortRecords: $sortRecords),
            self::ProvN => new ProvNSerializer(indentation: $prettyPrint ?? true ? 2 : 0, sortRecords: $sortRecords),
            self::Xml => new XmlSerializer(prettyPrint: $prettyPrint ?? true, sortRecords: $sortRecords),
            self::JsonLd => new JsonLdSerializer(prettyPrint: $prettyPrint ?? false, sortRecords: $sortRecords),
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
                'PROV-JSONLD deserialization is not supported (serialize-only format).',
            ),
        };
    }
}
