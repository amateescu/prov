<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Dictionary\DictionaryRemoval;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Influence;
use Prov\Relation\Invalidation;
use Prov\Relation\Membership;
use Prov\Relation\Mention;
use Prov\Relation\Specialization;
use Prov\Relation\Start;
use Prov\Relation\Usage;

/**
 * Single source of truth for relation type metadata.
 *
 * Used by serializers, DocumentComparator, and RecordIndex to avoid
 * duplicating relation-to-property mappings across the codebase. The
 * constant shapes (FORMALS, JSON_KEYS) are implementation details and
 * may change between releases.
 *
 * @internal
 */
final class RelationMetadata
{
    /**
     * Maps relation class names to their formal attribute property names.
     * Each entry is a list of [propertyName, type] pairs where type is
     * 'ref' (QualifiedName reference), 'time' (DateTimeImmutable), or
     * 'array' (for dictionary key-entity pairs / removed keys).
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, array<string, string>>
     */
    public const array FORMALS = [
        Generation::class => ['entity' => 'ref', 'activity' => 'ref', 'time' => 'time'],
        Usage::class => ['activity' => 'ref', 'entity' => 'ref', 'time' => 'time'],
        Communication::class => ['informed' => 'ref', 'informant' => 'ref'],
        Start::class => ['activity' => 'ref', 'trigger' => 'ref', 'starter' => 'ref', 'time' => 'time'],
        End::class => ['activity' => 'ref', 'trigger' => 'ref', 'ender' => 'ref', 'time' => 'time'],
        Invalidation::class => ['entity' => 'ref', 'activity' => 'ref', 'time' => 'time'],
        Derivation::class => [
            'generatedEntity' => 'ref',
            'usedEntity' => 'ref',
            'activity' => 'ref',
            'generation' => 'ref',
            'usage' => 'ref',
        ],
        Attribution::class => ['entity' => 'ref', 'agent' => 'ref'],
        Association::class => ['activity' => 'ref', 'agent' => 'ref', 'plan' => 'ref'],
        Delegation::class => ['delegate' => 'ref', 'responsible' => 'ref', 'activity' => 'ref'],
        Influence::class => ['influencee' => 'ref', 'influencer' => 'ref'],
        Specialization::class => ['specificEntity' => 'ref', 'generalEntity' => 'ref'],
        Alternate::class => ['alternate1' => 'ref', 'alternate2' => 'ref'],
        Membership::class => ['collection' => 'ref', 'entity' => 'ref'],
        Mention::class => ['specificEntity' => 'ref', 'generalEntity' => 'ref', 'bundle' => 'ref'],
        DictionaryMembership::class => ['dictionary' => 'ref', 'keyEntityPairs' => 'array'],
        DictionaryInsertion::class => ['after' => 'ref', 'before' => 'ref', 'keyEntityPairs' => 'array'],
        DictionaryRemoval::class => ['after' => 'ref', 'before' => 'ref', 'removedKeys' => 'array'],
    ];

    /**
     * Maps relation class names to their PROV-JSON key names.
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, string>
     */
    public const array JSON_KEYS = [
        Generation::class => 'wasGeneratedBy',
        Usage::class => 'used',
        Communication::class => 'wasInformedBy',
        Start::class => 'wasStartedBy',
        End::class => 'wasEndedBy',
        Invalidation::class => 'wasInvalidatedBy',
        Derivation::class => 'wasDerivedFrom',
        Attribution::class => 'wasAttributedTo',
        Association::class => 'wasAssociatedWith',
        Delegation::class => 'actedOnBehalfOf',
        Influence::class => 'wasInfluencedBy',
        Specialization::class => 'specializationOf',
        Alternate::class => 'alternateOf',
        Membership::class => 'hadMember',
        Mention::class => 'mentionOf',
        DictionaryMembership::class => 'hadDictionaryMember',
        DictionaryInsertion::class => 'derivedByInsertionFrom',
        DictionaryRemoval::class => 'derivedByRemovalFrom',
    ];

    /**
     * Extracts formal attribute values from a relation record as an associative array.
     *
     * @return array<string, mixed>
     *   Property name => value (QualifiedName, DateTimeImmutable, array, or null).
     */
    public static function extractFormals(ProvRelation $record): array
    {
        $meta = self::FORMALS[$record::class] ?? [];
        $vars = get_object_vars($record);
        $result = [];
        foreach ($meta as $prop => $type) {
            $result[$prop] = $vars[$prop] ?? null;
        }
        return $result;
    }

    /**
     * Returns the PROV-JSON formal attribute key names for a given JSON relation key.
     * Keys are prefixed with 'prov:' as used in PROV-JSON.
     *
     * @return list<string>
     */
    public static function jsonFormalKeys(string $jsonKey): array
    {
        $class = array_search($jsonKey, self::JSON_KEYS, true);
        if ($class === false) {
            return [];
        }

        $result = [];
        foreach (self::FORMALS[$class] as $prop => $type) {
            if ($type === 'array') {
                $result[] = match ($prop) {
                    'keyEntityPairs' => 'prov:key-entity-set',
                    'removedKeys' => 'prov:key-set',
                    default => 'prov:' . $prop,
                };
            } else {
                $result[] = 'prov:' . $prop;
            }
        }

        return $result;
    }
}
