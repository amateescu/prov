<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;

/**
 * One agent's association with one activity, as yielded by
 * `\Prov\Operation\ProvGraph::agentsOf()`.
 *
 * Carries the agent, the plan the activity followed (if the association named
 * one), the association's own attributes (so prov:role and the like survive),
 * and the chain of agents this one acted on behalf of, derived from
 * actedOnBehalfOf delegations.
 */
readonly class AgentInvolvement
{
    /**
     * @param \Prov\Identifier\QualifiedName $agent
     *   The associated agent's identifier. The identifier alone may not carry
     *   the agent's prov:type, so a consumer telling a person from a software
     *   agent reads the agent record via recordByIdentifier().
     * @param \Prov\Identifier\QualifiedName|null $plan
     *   The plan entity the association named (the entity describing how the
     *   activity was performed), or null.
     * @param \Prov\Attribute\Attributes $attributes
     *   The association's own attributes, e.g. prov:role.
     * @param list<\Prov\Identifier\QualifiedName> $onBehalfOf
     *   The agents this one acted on behalf of, nearest responsible first,
     *   following actedOnBehalfOf outward. Empty when the agent acted on no
     *   one's behalf.
     */
    public function __construct(
        public QualifiedName $agent,
        public ?QualifiedName $plan,
        public Attributes $attributes,
        public array $onBehalfOf,
    ) {}
}
