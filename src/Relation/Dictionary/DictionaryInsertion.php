<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * The `after` dictionary was derived from the `before` dictionary by
 * inserting the given key/entity pairs. Part of the PROV-DICT extension.
 */
readonly class DictionaryInsertion extends ProvRelation
{
    /**
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $keyEntityPairs
     */
    public function __construct(
        ?QualifiedName $identifier,
        public QualifiedName $after,
        public QualifiedName $before,
        public array $keyEntityPairs = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
