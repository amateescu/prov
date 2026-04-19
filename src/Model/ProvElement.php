<?php

declare(strict_types=1);

namespace Prov\Model;

/**
 * Abstract base for the three PROV-DM element types: Entity, Activity, and
 * Agent. Anything that isn't a relation.
 */
abstract readonly class ProvElement extends ProvRecord implements ProvElementInterface {}
