<?php

declare(strict_types=1);

namespace Prov\Relation\Dictionary;

use Prov\Attribute\Literal;
use Prov\Identifier\QualifiedName;

/**
 * A single key → entity pair carried by PROV-DICT relations (hadDictionaryMember,
 * derivedByInsertionFrom).
 *
 * PROV-DICT allows arbitrary typed literals or QualifiedName keys. Every
 * deserializer resolves a key to one of these before constructing the entry;
 * none of them keep the raw JSON-object shape a typed value arrives in.
 */
final readonly class DictionaryEntry
{
    public function __construct(
        public QualifiedName|Literal|string|int|float|bool|null $key,
        public ?QualifiedName $entity,
    ) {}
}
