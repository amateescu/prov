<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Literal;
use Prov\Identifier\QualifiedName;

/**
 * A single key → entity pair carried by PROV-DICT relations (hadDictionaryMember,
 * derivedByInsertionFrom).
 *
 * The key is `mixed` by spec: PROV-DICT allows arbitrary typed literals, QualifiedName
 * keys, and (on the deserialization side) raw passthrough values from untyped JSON.
 */
final readonly class DictionaryEntry
{
    public function __construct(
        public QualifiedName|Literal|string|int|float|bool|array|null $key,
        public ?QualifiedName $entity,
    ) {}
}
