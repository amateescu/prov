<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An entity was invalidated (destroyed, consumed, or made unusable) by an
 * activity at a given time.
 */
readonly class Invalidation extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $entity = null,
        public ?QualifiedName $activity = null,
        public ?\DateTimeImmutable $time = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
