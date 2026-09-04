<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Attribute\Attributes;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRelation;

/**
 * One entity was derived from another. The derivation can optionally cite
 * the activity responsible and the specific Generation/Usage events that
 * link the two.
 *
 * Construct with named arguments; the positional order follows the PROV-N
 * grammar (identifier first).
 */
readonly class Derivation extends ProvRelation
{
    public function __construct(
        ?QualifiedName $identifier = null,
        public ?QualifiedName $generatedEntity = null,
        public ?QualifiedName $usedEntity = null,
        public ?QualifiedName $activity = null,
        public ?QualifiedName $generation = null,
        public ?QualifiedName $usage = null,
        Attributes $attributes = new Attributes(),
    ) {
        parent::__construct($identifier, $attributes);
    }

    /**
     * The typed-derivation subtype this record's `prov:type` attribute names
     * (Revision, Quotation, or PrimarySource), or null for a plain derivation.
     * Only a QualifiedName value counts; a string literal spelled like the
     * type name does not.
     */
    public function subtype(): ?DerivationSubtype
    {
        static $typeKey = ProvNamespace::prov()->qualifiedName('type');
        foreach ($this->attributes->get($typeKey) as $type) {
            if ($type instanceof QualifiedName) {
                $subtype = DerivationSubtype::fromType($type);
                if ($subtype !== null) {
                    return $subtype;
                }
            }
        }
        return null;
    }
}
