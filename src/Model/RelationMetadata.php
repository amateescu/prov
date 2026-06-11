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
     * Maps relation class names to their PROV-JSONLD (PROV-O) encoding:
     *  - type: the @type of the qualified node (null for relations PROV-O
     *    models as a plain object property, with no qualified form).
     *  - qualifiedProperty: the property linking the subject to the qualified
     *    node (null when type is null).
     *  - shortcutProperty: the binary object-property form.
     *  - properties: JSON-LD property per non-subject formal, in emission
     *    order; the first entry is the shortcut form's object.
     *
     * The subject is always the relation's first formal property. Dictionary
     * extension relations have no PROV-O shortcut encoding and are absent.
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, array{
     *   type: ?string,
     *   qualifiedProperty: ?string,
     *   shortcutProperty: string,
     *   properties: array<string, string>,
     * }>
     */
    public const array JSONLD = [
        Generation::class => [
            'type' => 'prov:Generation',
            'qualifiedProperty' => 'prov:qualifiedGeneration',
            'shortcutProperty' => 'prov:wasGeneratedBy',
            'properties' => ['activity' => 'prov:activity', 'time' => 'prov:atTime'],
        ],
        Usage::class => [
            'type' => 'prov:Usage',
            'qualifiedProperty' => 'prov:qualifiedUsage',
            'shortcutProperty' => 'prov:used',
            'properties' => ['entity' => 'prov:entity', 'time' => 'prov:atTime'],
        ],
        Communication::class => [
            'type' => 'prov:Communication',
            'qualifiedProperty' => 'prov:qualifiedCommunication',
            'shortcutProperty' => 'prov:wasInformedBy',
            'properties' => ['informant' => 'prov:activity'],
        ],
        Start::class => [
            'type' => 'prov:Start',
            'qualifiedProperty' => 'prov:qualifiedStart',
            'shortcutProperty' => 'prov:wasStartedBy',
            'properties' => ['trigger' => 'prov:entity', 'starter' => 'prov:hadActivity', 'time' => 'prov:atTime'],
        ],
        End::class => [
            'type' => 'prov:End',
            'qualifiedProperty' => 'prov:qualifiedEnd',
            'shortcutProperty' => 'prov:wasEndedBy',
            'properties' => ['trigger' => 'prov:entity', 'ender' => 'prov:hadActivity', 'time' => 'prov:atTime'],
        ],
        Invalidation::class => [
            'type' => 'prov:Invalidation',
            'qualifiedProperty' => 'prov:qualifiedInvalidation',
            'shortcutProperty' => 'prov:wasInvalidatedBy',
            'properties' => ['activity' => 'prov:activity', 'time' => 'prov:atTime'],
        ],
        Derivation::class => [
            'type' => 'prov:Derivation',
            'qualifiedProperty' => 'prov:qualifiedDerivation',
            'shortcutProperty' => 'prov:wasDerivedFrom',
            'properties' => [
                'usedEntity' => 'prov:entity',
                'activity' => 'prov:hadActivity',
                'generation' => 'prov:hadGeneration',
                'usage' => 'prov:hadUsage',
            ],
        ],
        Attribution::class => [
            'type' => 'prov:Attribution',
            'qualifiedProperty' => 'prov:qualifiedAttribution',
            'shortcutProperty' => 'prov:wasAttributedTo',
            'properties' => ['agent' => 'prov:agent'],
        ],
        Association::class => [
            'type' => 'prov:Association',
            'qualifiedProperty' => 'prov:qualifiedAssociation',
            'shortcutProperty' => 'prov:wasAssociatedWith',
            'properties' => ['agent' => 'prov:agent', 'plan' => 'prov:hadPlan'],
        ],
        Delegation::class => [
            'type' => 'prov:Delegation',
            'qualifiedProperty' => 'prov:qualifiedDelegation',
            'shortcutProperty' => 'prov:actedOnBehalfOf',
            'properties' => ['responsible' => 'prov:agent', 'activity' => 'prov:hadActivity'],
        ],
        Influence::class => [
            'type' => 'prov:Influence',
            'qualifiedProperty' => 'prov:qualifiedInfluence',
            'shortcutProperty' => 'prov:wasInfluencedBy',
            'properties' => ['influencer' => 'prov:influencer'],
        ],
        Specialization::class => [
            'type' => null,
            'qualifiedProperty' => null,
            'shortcutProperty' => 'prov:specializationOf',
            'properties' => ['generalEntity' => ''],
        ],
        Alternate::class => [
            'type' => null,
            'qualifiedProperty' => null,
            'shortcutProperty' => 'prov:alternateOf',
            'properties' => ['alternate2' => ''],
        ],
        Membership::class => [
            'type' => null,
            'qualifiedProperty' => null,
            'shortcutProperty' => 'prov:hadMember',
            'properties' => ['entity' => ''],
        ],
    ];

    /**
     * XML child element names for the formal properties whose element name
     * differs from the property name (the PROV-DICT relations). Properties
     * not listed here use their own name as the element name.
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, array<string, string>>
     */
    public const array XML_FORMAL_OVERRIDES = [
        DictionaryMembership::class => ['keyEntityPairs' => 'keyEntityPair'],
        DictionaryInsertion::class => [
            'after' => 'newDictionary',
            'before' => 'oldDictionary',
            'keyEntityPairs' => 'keyEntityPair',
        ],
        DictionaryRemoval::class => ['after' => 'newDictionary', 'before' => 'oldDictionary', 'removedKeys' => 'key'],
    ];

    /**
     * PROV-N / PROV-XML shortcut forms that desugar to a Derivation carrying a
     * `prov:type` attribute, keyed by the PROV-N keyword. The values are the
     * local names of the prov:type QualifiedName and double as the PROV-XML
     * shortcut element names (lowercased first letter).
     *
     * @var array<string, string>
     */
    public const array DERIVATION_SUBTYPES = [
        'wasRevisionOf' => 'Revision',
        'wasQuotedFrom' => 'Quotation',
        'hadPrimarySource' => 'PrimarySource',
    ];

    /**
     * Maps PROV-JSON relation keys to their PROV-XML child element layout:
     * element local name => formal property name, with array-typed properties
     * marked by an underscore-prefixed element name (their content needs
     * per-relation handling).
     *
     * @return array<string, array<string, string>>
     */
    public static function xmlChildElements(): array
    {
        /** @var array<string, array<string, string>>|null $map */
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::FORMALS as $class => $props) {
            $elements = [];
            foreach ($props as $prop => $type) {
                $element = self::XML_FORMAL_OVERRIDES[$class][$prop] ?? $prop;
                $elements[$element] = $type === 'array' ? '_' . $element : $prop;
            }
            $map[self::JSON_KEYS[$class]] = $elements;
        }
        return $map;
    }

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
