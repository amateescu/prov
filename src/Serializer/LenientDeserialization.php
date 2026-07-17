<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Document;

/**
 * Result of a lenient deserialization: the Document built from the records that
 * parsed, plus one warning for each record that was skipped as malformed. A
 * clean parse leaves the warnings list empty.
 */
final readonly class LenientDeserialization
{
    /**
     * @param \Prov\Document $document
     *   The document assembled from the readable records.
     * @param list<string> $warnings
     *   One message per skipped record, naming the section, record id, and the
     *   reason (the text the strict parse would have thrown).
     */
    public function __construct(
        public Document $document,
        public array $warnings = [],
    ) {}
}
