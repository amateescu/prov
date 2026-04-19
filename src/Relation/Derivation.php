<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * One entity was derived from another. The derivation can optionally cite
 * the activity responsible and the specific Generation/Usage events that
 * link the two.
 */
readonly class Derivation extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $generatedEntity = null,
        public ?QualifiedName $usedEntity = null,
        public ?QualifiedName $activity = null,
        public ?QualifiedName $generation = null,
        public ?QualifiedName $usage = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
