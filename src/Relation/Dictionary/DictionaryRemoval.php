<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * The `after` dictionary was derived from the `before` dictionary by
 * removing the listed keys. Part of the PROV-DICT extension.
 */
readonly class DictionaryRemoval extends ProvRelation
{
    /**
     * @param list<mixed> $removedKeys
     */
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $after = null,
        public ?QualifiedName $before = null,
        public array $removedKeys = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
