<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * An entity in one bundle refers to an entity described in another bundle.
 * Lets records carry cross-bundle references without flattening the bundles.
 */
readonly class Mention extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $specificEntity = null,
        public ?QualifiedName $generalEntity = null,
        public ?QualifiedName $bundle = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
