<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An activity was started at a given time, optionally in response to a
 * triggering entity or a starter activity.
 */
readonly class Start extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $activity = null,
        public ?QualifiedName $trigger = null,
        public ?QualifiedName $starter = null,
        public ?\DateTimeImmutable $time = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
