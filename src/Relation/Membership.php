<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An entity is a member of a collection entity.
 *
 * Construct with named arguments; the positional order follows the PROV-N
 * grammar (identifier first).
 */
readonly class Membership extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $collection = null,
        public ?QualifiedName $entity = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
