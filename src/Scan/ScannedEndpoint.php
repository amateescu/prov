<?php

declare(strict_types=1);

namespace Prov\Scan;

use Prov\Identifier\QualifiedName;

/**
 * One reference a relation record makes to another element, read from
 * decoded PROV-JSON. The role is the formal property name PROV-N gives that
 * position (`entity`, `plan`, `agent`, `keyEntity` for a dictionary member,
 * ...); the kind is the element type the position requires (`entity`,
 * `activity`, or `agent`), read off `Prov\Model\RelationMetadata::TYPING_ROLES`.
 */
final readonly class ScannedEndpoint
{
    /**
     * @param string $section
     *   The PROV-JSON relation section the endpoint belongs to (e.g.
     *   `wasGeneratedBy`, `wasAssociatedWith`).
     * @param string $role
     *   The formal property name of the endpoint (`entity`, `plan`, `agent`,
     *   `keyEntity`, ...), unprefixed.
     * @param 'entity'|'activity'|'agent' $kind
     *   The element type the referenced identifier is required to have.
     * @param \Prov\Identifier\QualifiedName $identifier
     *   The referenced identifier, resolved against the document's namespace
     *   table.
     */
    public function __construct(
        public string $section,
        public string $role,
        public string $kind,
        public QualifiedName $identifier,
    ) {}
}
