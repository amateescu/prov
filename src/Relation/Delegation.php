<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * One agent (the delegate) acted on behalf of another (the responsible
 * agent), optionally in the context of a specific activity.
 */
readonly class Delegation extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $delegate = null,
        public ?QualifiedName $responsible = null,
        public ?QualifiedName $activity = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
