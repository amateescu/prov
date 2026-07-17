<?php

declare(strict_types=1);

namespace Prov\Scan;

/**
 * One relation read straight from decoded PROV-JSON, without building a
 * ProvRelation. Carries the section it came from, its own id, its formal
 * PROV-JSON entries kept as decoded, and its non-formal attributes in the
 * scanner's normalized shape.
 */
final readonly class ScannedRelation
{
    /**
     * @param string $section
     *   The PROV-JSON relation section name (e.g. `wasGeneratedBy`, `used`).
     * @param string $id
     *   The relation's id as it appears in the document.
     * @param array<string, mixed> $endpoints
     *   The relation's formal PROV-JSON entries, keyed by their role
     *   (`prov:entity`, `prov:activity`, `prov:agent`, `prov:time`, the
     *   dictionary key sets, ...). Values are kept as decoded: a reference is
     *   its prefixed string, a time is its raw string or typed object, a
     *   dictionary set its raw structure. Resolve a reference to a
     *   QualifiedName with the scanner's `resolve()` when you need URI identity.
     * @param array<string, list<string|int|float|bool|array<string, mixed>>> $attributes
     *   The relation's non-formal attributes, keyed by the full URI of the
     *   attribute name, each mapping to its list of normalized values.
     */
    public function __construct(
        public string $section,
        public string $id,
        public array $endpoints,
        public array $attributes,
    ) {}
}
