<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * Two entities refer to the same real-world thing from different viewpoints.
 * Unlike Specialization, the relation is symmetric: neither entity is the
 * narrower one.
 */
readonly class Alternate extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $alternate1 = null,
        public ?QualifiedName $alternate2 = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
