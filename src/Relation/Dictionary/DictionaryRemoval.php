<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * The `after` dictionary was derived from the `before` dictionary by
 * removing the listed keys. Part of the PROV-DICT extension.
 *
 * Construct with named arguments; the positional order follows the PROV-N
 * grammar (identifier first).
 */
readonly class DictionaryRemoval extends ProvRelation
{
    /**
     * @param list<QualifiedName|Literal|string|int|float|bool> $removedKeys
     */
    public function __construct(
        ?QualifiedName $identifier,
        public QualifiedName $after,
        public QualifiedName $before,
        public array $removedKeys = [],
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }
}
