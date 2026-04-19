<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An activity was carried out in association with an agent, optionally
 * following a plan (an entity describing how to perform the activity).
 */
readonly class Association extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $activity = null,
        public ?QualifiedName $agent = null,
        public ?QualifiedName $plan = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
