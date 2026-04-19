<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An activity was ended at a given time, optionally by a triggering entity
 * or a finishing activity.
 */
readonly class End extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $activity = null,
        public ?QualifiedName $trigger = null,
        public ?QualifiedName $ender = null,
        public ?\DateTimeImmutable $time = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
