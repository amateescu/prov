<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * Declares the key/entity pairs that a dictionary-typed entity contains.
 * Part of the PROV-DICT extension.
 *
 * Construct with named arguments; the positional order follows the PROV-N
 * grammar (identifier first).
 */
readonly class DictionaryMembership extends ProvRelation
{
    /**
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $keyEntityPairs
     */
    public function __construct(
        ?QualifiedName $identifier,
        public QualifiedName $dictionary,
        public array $keyEntityPairs = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
