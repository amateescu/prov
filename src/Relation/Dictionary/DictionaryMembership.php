<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * Declares the key/entity pairs that a dictionary-typed entity contains.
 * Part of the PROV-DICT extension.
 */
readonly class DictionaryMembership extends ProvRelation
{
    /**
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $keyEntityPairs
     */
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $dictionary = null,
        public array $keyEntityPairs = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
