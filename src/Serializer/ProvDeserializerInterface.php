<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Document;

/**
 * Parses a serialized PROV document in a specific format into a Document.
 *
 * Implemented once per supported input format (PROV-JSON, PROV-N, PROV-XML).
 * Prefer the `Prov::deserialize()` facade for normal use; code against this
 * interface when you need to accept any deserializer.
 *
 * @api
 */
interface ProvDeserializerInterface
{
    /**
     * Parses a serialized PROV document in the implementation's format into a
     * Document.
     *
     * @throws \Prov\Exception\DeserializationException
     *   If the input isn't a valid document in that format.
     */
    public function deserialize(string $data): Document;
}
