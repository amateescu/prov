<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Document;

/**
 * Serializes a Document into a specific PROV output format.
 *
 * Implemented once per supported format (PROV-JSON, PROV-N, PROV-XML,
 * PROV-JSON-LD). Prefer the `Prov::serialize()` facade for normal use;
 * code against this interface when you need to accept any serializer.
 */
interface ProvSerializerInterface
{
    /**
     * Serializes a Document into the implementation's target format.
     */
    #[\NoDiscard]
    public function serialize(Document $document): string;
}
