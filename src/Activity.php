<?php

declare(strict_types=1);

namespace Prov;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvElement;

/**
 * A PROV-DM Activity: something that happens over time and acts upon or with
 * entities (a computation, an edit, a decision). Set the interval directly
 * with `$startTime` and `$endTime`, or record it through separate
 * `wasStartedBy` and `wasEndedBy` events.
 */
readonly class Activity extends ProvElement
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?\DateTimeImmutable $startTime = null,
        public ?\DateTimeImmutable $endTime = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
