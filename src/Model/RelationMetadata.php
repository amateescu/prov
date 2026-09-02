<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Identifier\QualifiedName;
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryEntry;
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
     * Maps relation class names to their formal attribute properties. Each
     * entry is a map of `propertyName => type`, in PROV-N positional order,
     * where type is 'ref' (QualifiedName reference), 'time' (DateTimeImmutable),
     * or 'array' (dictionary key-entity pairs / removed keys).
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, array<string, 'ref'|'time'|'array'>>
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
     * Maps relation class names to the element role each formal reference must
     * have, as `property => 'entity'|'activity'|'agent'`. Drives the PROV-CONSTRAINTS
     * typing check (constraint 50): a referenced identifier acquires the role its
     * position dictates, and an identifier used in two incompatible roles is a
     * typing violation. Only positions with a fixed element type appear; event
     * references (a derivation's generation/usage), bundle references, and the
     * polymorphic influencee/influencer of Influence carry no role and are omitted.
     * Dictionary key-entity pairs live in an array-typed formal this table cannot
     * express; the validator walks their member entities separately.
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, array<string, 'entity'|'activity'|'agent'>>
     */
    public const array TYPING_ROLES = [
        Generation::class => ['entity' => 'entity', 'activity' => 'activity'],
        Usage::class => ['activity' => 'activity', 'entity' => 'entity'],
        Communication::class => ['informed' => 'activity', 'informant' => 'activity'],
        Start::class => ['activity' => 'activity', 'trigger' => 'entity', 'starter' => 'activity'],
        End::class => ['activity' => 'activity', 'trigger' => 'entity', 'ender' => 'activity'],
        Invalidation::class => ['entity' => 'entity', 'activity' => 'activity'],
        Derivation::class => ['generatedEntity' => 'entity', 'usedEntity' => 'entity', 'activity' => 'activity'],
        Attribution::class => ['entity' => 'entity', 'agent' => 'agent'],
        Association::class => ['activity' => 'activity', 'agent' => 'agent', 'plan' => 'entity'],
        Delegation::class => ['delegate' => 'agent', 'responsible' => 'agent', 'activity' => 'activity'],
        Specialization::class => ['specificEntity' => 'entity', 'generalEntity' => 'entity'],
        Alternate::class => ['alternate1' => 'entity', 'alternate2' => 'entity'],
        Membership::class => ['collection' => 'entity', 'entity' => 'entity'],
        Mention::class => ['specificEntity' => 'entity', 'generalEntity' => 'entity'],
        DictionaryMembership::class => ['dictionary' => 'entity'],
        DictionaryInsertion::class => ['after' => 'entity', 'before' => 'entity'],
        DictionaryRemoval::class => ['after' => 'entity', 'before' => 'entity'],
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
     * Every identifier a relation references through its formal endpoints, in
     * PROV-N positional order, followed by the entities of its dictionary
     * entries. Null endpoints are skipped. The single source of truth for
     * "what does this relation point at"; `ProvGraph::referencedIdentifiers()`
     * is a thin delegate.
     *
     * @return list<\Prov\Identifier\QualifiedName>
     */
    public static function refEndpoints(ProvRelation $relation): array
    {
        $out = [];
        $vars = get_object_vars($relation);
        foreach (self::FORMALS[$relation::class] ?? [] as $prop => $type) {
            if ($type !== 'ref') {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            $value = $vars[$prop] ?? null;
            if ($value instanceof QualifiedName) {
                $out[] = $value;
            }
        }
        foreach (self::dictionaryEntities($relation) as $entity) {
            $out[] = $entity;
        }
        return $out;
    }

    /**
     * Every entity-typed endpoint of a relation, as `role`/`entity` pairs in
     * PROV-N positional order, followed by the entities of its dictionary
     * entries (role `keyEntity`). The role is the formal property name
     * (`entity`, `specificEntity`, `generatedEntity`, `plan`, ...). Null
     * endpoints are skipped. "Entity-typed" is read from TYPING_ROLES, so
     * activity, agent, event (a derivation's generation/usage), and bundle
     * endpoints are excluded.
     *
     * @return list<array{role: string, entity: \Prov\Identifier\QualifiedName}>
     */
    public static function entityEndpoints(ProvRelation $relation): array
    {
        $out = [];
        $vars = get_object_vars($relation);
        foreach (self::TYPING_ROLES[$relation::class] ?? [] as $prop => $role) {
            if ($role !== 'entity') {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            $value = $vars[$prop] ?? null;
            if ($value instanceof QualifiedName) {
                $out[] = ['role' => $prop, 'entity' => $value];
            }
        }
        foreach (self::dictionaryEntities($relation) as $entity) {
            $out[] = ['role' => 'keyEntity', 'entity' => $entity];
        }
        return $out;
    }

    /**
     * TYPING_ROLES re-keyed by PROV-JSON relation key instead of relation
     * class, so a reader working off decoded PROV-JSON (which knows the
     * section name, not the relation class) can classify an endpoint without
     * its own class lookup. A key TYPING_ROLES does not classify (an event
     * reference, a bundle reference, the polymorphic influencee/influencer of
     * Influence) is absent, the same as looking it up on TYPING_ROLES
     * directly. Built once and cached.
     *
     * @return array<string, 'entity'|'activity'|'agent'>
     *   Formal property name (unprefixed, matching TYPING_ROLES itself) =>
     *   kind.
     */
    public static function jsonTypingRoles(string $jsonKey): array
    {
        /** @var array<string, array<string, 'entity'|'activity'|'agent'>>|null $map */
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::JSON_KEYS as $class => $section) {
                $map[$section] = self::TYPING_ROLES[$class] ?? [];
            }
        }
        return $map[$jsonKey] ?? [];
    }

    /**
     * The PROV-N keyword for a relation, e.g. `wasGeneratedBy`, `used`,
     * `specializationOf`. A Derivation carrying a `prov:type` of `prov:Revision`,
     * `prov:Quotation`, or `prov:PrimarySource` reports the subtype shortcut
     * (`wasRevisionOf`, ...) rather than the bare `wasDerivedFrom`.
     */
    public static function relationLabel(ProvRelation $relation): string
    {
        if ($relation instanceof Derivation) {
            $subtype = self::derivationSubtypeLabel($relation);
            if ($subtype !== null) {
                return $subtype;
            }
        }
        return self::JSON_KEYS[$relation::class] ?? $relation::class;
    }

    /**
     * The derivation-subtype keyword (`wasRevisionOf`, `wasQuotedFrom`,
     * `hadPrimarySource`) carried by a Derivation's `prov:type` attribute, or
     * null when it is a plain derivation.
     */
    private static function derivationSubtypeLabel(Derivation $relation): ?string
    {
        $types = $relation->attributes->all()['http://www.w3.org/ns/prov#type'] ?? [];
        foreach ($types as $type) {
            if ($type instanceof QualifiedName && $type->namespace->uri === 'http://www.w3.org/ns/prov#') {
                $keyword = array_search($type->localPart, self::DERIVATION_SUBTYPES, true);
                if ($keyword !== false) {
                    return $keyword;
                }
            }
        }
        return null;
    }

    /**
     * The entities referenced by a relation's dictionary entries, if any.
     *
     * @return list<\Prov\Identifier\QualifiedName>
     */
    public static function dictionaryEntities(ProvRelation $relation): array
    {
        $out = [];
        $vars = get_object_vars($relation);
        foreach (self::FORMALS[$relation::class] ?? [] as $prop => $type) {
            if ($type !== 'array') {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            $items = $vars[$prop] ?? null;
            if (!is_array($items)) {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            foreach ($items as $item) {
                if ($item instanceof DictionaryEntry && $item->entity !== null) {
                    $out[] = $item->entity;
                }
            }
        }
        return $out;
    }

    /**
     * Returns the PROV-JSON formal attribute key names for a given JSON relation key.
     * Keys are prefixed with 'prov:' as used in PROV-JSON.
     *
     * @return list<string>
     */
    public static function jsonFormalKeys(string $jsonKey): array
    {
        return array_keys(self::jsonFormalKinds($jsonKey));
    }

    /**
     * FORMALS re-keyed by PROV-JSON relation key and PROV-JSON formal key, so a
     * reader working off decoded PROV-JSON gets the key it sees in the document
     * rather than the property name. The two array-typed formals have keys of
     * their own (`prov:key-entity-set`, `prov:key-set`); every other formal is
     * its property name under the `prov:` prefix. A non-relation or unknown
     * section has no formals. Built once and cached.
     *
     * @return array<string, 'ref'|'time'|'array'>
     *   PROV-JSON formal key => the kind of value it holds, in PROV-N
     *   positional order.
     */
    public static function jsonFormalKinds(string $jsonKey): array
    {
        /** @var array<string, array<string, 'ref'|'time'|'array'>>|null $map */
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::JSON_KEYS as $class => $section) {
                $kinds = [];
                foreach (self::FORMALS[$class] as $prop => $kind) {
                    $key = match ($prop) {
                        'keyEntityPairs' => 'prov:key-entity-set',
                        'removedKeys' => 'prov:key-set',
                        default => 'prov:' . $prop,
                    };
                    $kinds[$key] = $kind;
                }
                $map[$section] = $kinds;
            }
        }
        return $map[$jsonKey] ?? [];
    }
}
