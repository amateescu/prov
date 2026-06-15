<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Identifier\QualifiedName;

/**
 * One entity endpoint of one relation, as yielded by
 * `\Prov\Model\RecordContainer::entityInvolvements()`.
 *
 * A single forward pass over a container's relations produces these, so a
 * consumer building a secondary index (which entity took part in what) can
 * derive it from a finished Document or Bundle instead of mirroring each
 * builder call.
 */
readonly class EntityInvolvement
{
    /**
     * @param string $relationType
     *   The PROV-N keyword for the relation (`wasGeneratedBy`, `used`,
     *   `specializationOf`, ...). A typed derivation reports its subtype
     *   shortcut (`wasRevisionOf`, `wasQuotedFrom`, `hadPrimarySource`) rather
     *   than the bare `wasDerivedFrom`.
     * @param string $role
     *   The formal property the entity fills in that relation (`entity`,
     *   `specificEntity`, `generatedEntity`, `usedEntity`, `plan`,
     *   `collection`, ...; `keyEntity` for a dictionary entry's entity), so a
     *   consumer can tell, for example, the new revision (`generatedEntity`)
     *   from the source (`usedEntity`).
     * @param \Prov\Identifier\QualifiedName $entity
     *   The referenced entity identifier. For UUID-identified or blank-node
     *   participants the identifier alone may not carry the entity's type, so a
     *   consumer indexing those still needs its own reconciliation map.
     */
    public function __construct(
        public string $relationType,
        public string $role,
        public QualifiedName $entity,
    ) {}
}
