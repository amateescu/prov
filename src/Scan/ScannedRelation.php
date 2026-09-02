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

    /**
     * The formal entries keyed by their bare role name, with the `prov:`
     * prefix a document spells them with stripped. Use it to walk a relation's
     * positions without caring how the writer prefixed the keys.
     *
     * @return array<string, mixed>
     */
    public function endpointsByRole(): array
    {
        $out = [];
        foreach (array_keys($this->endpoints) as $key) {
            $out[str_starts_with($key, 'prov:') ? substr($key, 5) : $key] = $this->endpoints[$key];
        }
        return $out;
    }

    /**
     * The reference one role names (`entity`, `agent`, `generatedEntity`, ...),
     * or null when the relation has no such role or the value is not a
     * reference string. The `prov:` prefix is optional, as it is in the
     * document. Resolve the result with `JsonScanner::tryResolve()` when you
     * need URI identity.
     */
    public function endpoint(string $role): ?string
    {
        foreach (['prov:' . $role, $role] as $key) {
            if (isset($this->endpoints[$key]) && is_string($this->endpoints[$key])) {
                return $this->endpoints[$key];
            }
        }
        return null;
    }
}
