<?php

declare(strict_types=1);

namespace Prov\Scan;

/**
 * One agent an activity was associated with, read from decoded PROV-JSON. The
 * array-level mirror of a `\Prov\Model\AgentInvolvement`: it reports ids and
 * the delegation chain, not objects. Classifying an agent (person, software
 * agent, ...) stays the caller's job, read from the agent record's `prov:type`.
 */
final readonly class ScannedAgent
{
    /**
     * @param string $agent
     *   The associated agent's id as it appears in the document.
     * @param ?string $plan
     *   The association's plan id, or null when the association names none.
     * @param array<string, list<string|int|float|bool|array<string, mixed>>> $attributes
     *   The association's non-formal attributes, keyed by the full URI of the
     *   attribute name, each mapping to its list of normalized values.
     * @param list<string> $onBehalfOf
     *   The `actedOnBehalfOf` chain out of the agent, nearest responsible
     *   first, as the responsible ids appear in the document. Honors an
     *   activity-scoped delegation only when its activity matches the queried
     *   one; a delegation with no activity applies in any context.
     */
    public function __construct(
        public string $agent,
        public ?string $plan,
        public array $attributes,
        public array $onBehalfOf,
    ) {}
}
