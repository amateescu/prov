<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * One entity is a more specific view of another: same real-world thing,
 * narrower aspects (e.g. a dated revision of a general document).
 *
 * Construct with named arguments; the positional order follows the PROV-N
 * grammar (identifier first).
 */
readonly class Specialization extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier,
        public QualifiedName $specificEntity,
        public QualifiedName $generalEntity,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
