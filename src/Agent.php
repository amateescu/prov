<?php

declare(strict_types=1);

namespace Prov;

use Prov\Model\ProvElement;

/**
 * A PROV-DM Agent: a party that bears responsibility for an activity or
 * entity. Typically a person, organization, or software process. Agents can
 * delegate responsibility to another agent via `actedOnBehalfOf`.
 */
readonly class Agent extends ProvElement {}
