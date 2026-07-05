<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An entity was generated (produced) by an activity.
 */
readonly class Generation extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier,
        public QualifiedName $entity,
        public ?QualifiedName $activity = null,
        public ?\DateTimeImmutable $time = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
