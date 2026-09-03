<?php

declare(strict_types=1);

namespace Prov\Scan;

use Prov\Attribute\ValueIdentity;
use Prov\Exception\DeserializationException;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\RelationMetadata;
use Prov\Serializer\QualifiedNameEscaper;

/**
 * A read-only scanner over a PROV-JSON document. It decodes the text once
 * (json_decode plus the namespace table) and answers targeted slice queries
 * straight off the decoded arrays, so a consumer reading a few attributes,
 * the relations touching one record, or the agents behind an activity never
 * pays for building the full Document object graph or a ProvGraph.
 *
 * Use this instead of `Prov::deserialize()` + `ProvGraph` when the document is
 * large and each read wants only a small slice of it. For anything that needs
 * the model (validation, serialization, comparison, mutation) deserialize the
 * document proper; the scanner never constructs a ProvRecord or a Document.
 *
 * Identity is namespace-aware and URI-level. Every query that takes an
 * identifier accepts a `prefix:local` string (or a QualifiedName) and matches
 * a record regardless of which registered prefix the document spelled it with,
 * because both sides normalize through the document's prefix table (the
 * embedded `prefix` map plus the default namespace and the prov/xsd built-ins,
 * bootstrapped the same way `JsonSerializer` does). Attribute names are
 * reported by their full URI for the same reason, and the `PN_CHARS_ESC`
 * escapes a serializer puts in a written name are decoded away, so a scanned
 * identifier matches the one `Prov::deserialize()` reports.
 *
 * Scope: the scanner reads the top-level document only. Bundle sections carry
 * their own nested prefix tables and would push a bundle tag through every
 * result shape and a per-bundle namespace manager through every lookup;
 * mirroring `ProvGraph` (which also does not descend into bundles) keeps the
 * surface flat. Deserialize the document proper to work across bundles.
 *
 * Damage tolerance follows the lenient-deserialization philosophy: structural
 * damage (invalid JSON, a non-map root, a section or prefix map that is not a
 * map) throws at construction, while record-level damage (a record body that
 * is not a map, a malformed typed value) is skipped by the queries rather than
 * thrown.
 *
 * @mago-ignore analysis:mixed-assignment
 * @mago-ignore analysis:less-specific-return-statement
 */
final class JsonScanner
{
    private const string XSD_URI = 'http://www.w3.org/2001/XMLSchema#';

    /** The element sections, in PROV-JSON layout order. */
    private const array ELEMENT_SECTIONS = ['entity', 'activity', 'agent'];

    /** xsd local parts that collapse to a PHP int. */
    private const array INTEGER_TYPES = [
        'int' => true,
        'integer' => true,
        'long' => true,
        'short' => true,
        'byte' => true,
        'nonNegativeInteger' => true,
        'nonPositiveInteger' => true,
        'negativeInteger' => true,
        'positiveInteger' => true,
        'unsignedLong' => true,
        'unsignedInt' => true,
        'unsignedShort' => true,
        'unsignedByte' => true,
    ];

    /** xsd local parts that collapse to the lexical PHP string. */
    private const array STRING_TYPES = [
        'string' => true,
        'normalizedString' => true,
        'token' => true,
        'anyURI' => true,
    ];

    /** @var array<array-key, mixed> The decoded document root. */
    private readonly array $json;

    private readonly NamespaceManager $nsManager;

    /**
     * @var ?list<array{section: string, id: string, body: array<array-key, mixed>}>
     *   Every relation record, in scan order; null until the endpoint index is built.
     */
    private ?array $relationLocators = null;

    /**
     * @var array<string, list<int>>
     *   Endpoint URI => indexes into `$relationLocators`. Empty and meaningless
     *   until `$relationLocators` is set.
     */
    private array $byEndpoint = [];

    /**
     * @var ?array<string, list<array{responsibleRaw: string, responsibleUri: string, activityUri: ?string}>>
     *   Delegate URI => its outgoing delegation edges, in document order; null until built.
     */
    private ?array $delegationsByDelegate = null;

    /** @var array<string, array<string, string>> Section name => (record URI => raw id key). */
    private array $sectionUriIndexes = [];

    /** @var array<string, list<string>> Section name => its formal PROV-JSON keys. */
    private array $formalKeysCache = [];

    /** @var array<string, list<string>> Section name => its reference-typed formal PROV-JSON keys. */
    private array $refKeysCache = [];

    /**
     * @throws \Prov\Exception\DeserializationException
     *   On structural damage: invalid JSON, a non-map root, or a prefix/section
     *   map that is not a map.
     */
    public function __construct(string $json)
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new DeserializationException('Invalid PROV-JSON: could not decode JSON.');
        }
        // json_decode(..., true) cannot tell `{}` from `[]`, so an empty array
        // is an acceptable empty document; a non-empty list root is not a map.
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new DeserializationException('Invalid PROV-JSON: the document root must be a map.');
        }

        $this->json = $decoded;
        $this->nsManager = $this->buildNamespaceTable($decoded['prefix'] ?? []);
        $this->assertSectionsAreMaps($decoded);
    }

    /**
     * The document's namespace table as `prefix => uri`. Includes the default
     * namespace (under the reserved `default` prefix) and the prov/xsd
     * built-ins, so it is the table the scanner actually resolves against.
     *
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        $out = [];
        foreach ($this->nsManager->getRegisteredNamespaces() as $ns) {
            $out[$ns->prefix] = $ns->uri;
        }
        return $out;
    }

    /**
     * Resolves a `prefix:local`, unprefixed, or full-URI string against the
     * document's namespace table. `PN_CHARS_ESC` escapes in the local part are
     * decoded, so a name the serializer escaped comes back in its canonical
     * form, the same one the deserializer reports.
     *
     * @throws \Prov\Exception\NamespaceException
     *   When the prefix is not registered, no default namespace is set, or the
     *   local part is empty.
     */
    public function resolve(string $identifier): QualifiedName
    {
        return QualifiedNameEscaper::resolveDecoded($identifier, $this->nsManager);
    }

    /**
     * The same resolve, returning null instead of throwing when the identifier
     * names nothing this document can resolve. Use it for the values a
     * document supplies (a relation endpoint, a reference-typed attribute),
     * where an unknown prefix is bad input rather than a programming error.
     * The InvalidArgumentException arm keeps the queries damage-tolerant even
     * if a namespace rejects a local part `resolve()` let through.
     */
    public function tryResolve(string $shorthand): ?QualifiedName
    {
        try {
            return $this->resolve($shorthand);
        } catch (NamespaceException|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The ids of a section, as they appear in the document, in document order.
     * Works for `entity`, `activity`, `agent`, and any relation section name.
     * An absent section yields an empty list.
     *
     * @return list<string>
     */
    public function ids(string $section): array
    {
        $out = [];
        foreach ($this->section($section) as $id => $_body) {
            $out[] = (string) $id;
        }
        return $out;
    }

    /**
     * The attributes of one record, keyed by the full URI of each attribute
     * name and mapping to its list of normalized values. For a relation
     * section this includes the formal endpoint entries too, since the reader
     * is generic and does not special-case relations; use `relations()` to
     * split endpoints from annotations.
     *
     * Records sharing an id (scruffy provenance) merge: values from every
     * instance are accumulated. A record body that is not a map, or a value
     * that is a malformed typed object, is skipped.
     *
     * @return array<string, list<string|int|float|bool|array<string, mixed>>>
     */
    public function attributesOf(string $section, QualifiedName|string $id): array
    {
        $out = [];
        foreach ($this->recordInstances($section, $id) as $body) {
            foreach ($body as $rawKey => $rawValue) {
                $uri = $this->attributeKeyUri((string) $rawKey);
                if ($uri === null) {
                    continue;
                }
                foreach ($this->normalizeValues($rawValue) as $value) {
                    $out[$uri][] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * The first value of one attribute on one record, the common slice-read.
     * The attribute is given as a `prefix:local` string, a full URI, or a
     * QualifiedName, and matched by URI. Returns null when the record or the
     * attribute is absent, or the attribute holds no readable value.
     *
     * @return string|int|float|bool|array<string, mixed>|null
     */
    public function attributeValue(
        string $section,
        QualifiedName|string $id,
        QualifiedName|string $attribute,
    ): string|int|float|bool|array|null {
        $directKey = is_string($attribute) ? $attribute : null;
        $targetUri = null;
        foreach ($this->recordInstances($section, $id) as $body) {
            // Fast path: the attribute is spelled exactly as the document key.
            if ($directKey !== null && array_key_exists($directKey, $body)) {
                $values = $this->normalizeValues($body[$directKey]);
                if ($values !== []) {
                    return $values[0];
                }
                continue;
            }
            $targetUri ??= $this->attributeUri($attribute);
            if ($targetUri === null) {
                return null;
            }
            foreach ($body as $rawKey => $rawValue) {
                if ($this->attributeKeyUri((string) $rawKey) !== $targetUri) {
                    continue;
                }
                $values = $this->normalizeValues($rawValue);
                if ($values !== []) {
                    return $values[0];
                }
            }
        }
        return null;
    }

    /**
     * The relation records of a section, in document order, each split into its
     * formal endpoints and its non-formal attributes. The section name drives
     * which keys are formal, off the same PROV-JSON relation layout the
     * serializer uses. A record body that is not a map is skipped.
     *
     * @return list<\Prov\Scan\ScannedRelation>
     */
    public function relations(string $section): array
    {
        $out = [];
        foreach ($this->section($section) as $rawId => $raw) {
            foreach ($this->recordBodies($raw) as $body) {
                $out[] = $this->buildRelation($section, (string) $rawId, $body);
            }
        }
        return $out;
    }

    /**
     * Every relation record, across all relation sections, that references the
     * given identifier in any endpoint (including secondary ones like a
     * derivation's activity or an association's plan, and the entities of
     * dictionary entries). Each relation appears once even when it references
     * the identifier in several roles. The endpoint index is built lazily on
     * the first call and reused.
     *
     * @return list<\Prov\Scan\ScannedRelation>
     */
    public function relationsReferencing(QualifiedName|string $identifier): array
    {
        $locators = $this->relationLocators ?? $this->buildEndpointIndex();
        $out = [];
        foreach ($this->byEndpoint[$this->toUri($identifier)] ?? [] as $locatorIndex) {
            $locator = $locators[$locatorIndex];
            $out[] = $this->buildRelation($locator['section'], $locator['id'], $locator['body']);
        }
        return $out;
    }

    /**
     * The agents an activity was associated with (via `wasAssociatedWith`),
     * each with its plan, the association's attributes, and its
     * `actedOnBehalfOf` chain. The array-level mirror of `ProvGraph::agentsOf`:
     * a consumer can answer "who or what performed this, and on behalf of whom"
     * without building the graph. Associations with no agent, or whose activity
     * is not the queried one, are skipped.
     *
     * @return list<\Prov\Scan\ScannedAgent>
     */
    public function agentsOf(QualifiedName|string $activity): array
    {
        $activityUri = $this->toUri($activity);
        $out = [];
        foreach ($this->section('wasAssociatedWith') as $rawId => $raw) {
            foreach ($this->recordBodies($raw) as $body) {
                $agent = $body['prov:agent'] ?? null;
                $assocActivity = $body['prov:activity'] ?? null;
                if (!is_string($agent) || !is_string($assocActivity)) {
                    continue;
                }
                if ($this->toUri($assocActivity) !== $activityUri) {
                    continue;
                }
                $plan = isset($body['prov:plan']) && is_string($body['prov:plan']) ? $body['prov:plan'] : null;
                $out[] = new ScannedAgent(
                    agent: $agent,
                    plan: $plan,
                    attributes: $this->buildRelation('wasAssociatedWith', (string) $rawId, $body)->attributes,
                    onBehalfOf: $this->delegationChain($agent, $activityUri),
                );
            }
        }
        return $out;
    }

    /**
     * Every reference a relation record makes to another element, across every
     * relation section. The role and kind come off
     * `RelationMetadata::jsonTypingRoles()`, so a position with no fixed
     * element type (an event reference, a bundle reference, the polymorphic
     * influencee/influencer of Influence) contributes nothing. A dictionary
     * relation's key-entity pairs are included too, under role `keyEntity` and
     * kind `entity`, matching `RelationMetadata::entityEndpoints()`. A null,
     * non-string, or unresolvable endpoint is skipped. Every relation record
     * in the document's own top-level sections is walked, whether or not the
     * identifiers it references are themselves declared as a full entity,
     * activity, or agent record elsewhere. Relations inside bundles are not:
     * the scanner as a whole reads the top-level document only (see the
     * class docblock).
     *
     * @return list<\Prov\Scan\ScannedEndpoint>
     */
    public function relationEndpoints(): array
    {
        $out = [];
        foreach (RelationMetadata::JSON_KEYS as $section) {
            $kinds = RelationMetadata::jsonTypingRoles($section);
            $hasDictSet = in_array('prov:key-entity-set', $this->formalKeys($section), true);
            if ($kinds === [] && !$hasDictSet) {
                continue;
            }
            foreach ($this->relations($section) as $relation) {
                foreach ($kinds as $prop => $kind) {
                    $key = 'prov:' . $prop;
                    foreach ($this->endpointRefs($section, $key, $relation->endpoints[$key] ?? null) as $endpoint) {
                        $identifier = $this->tryResolve($endpoint);
                        if ($identifier !== null) {
                            $out[] = new ScannedEndpoint($section, $prop, $kind, $identifier);
                        }
                    }
                }
                if ($hasDictSet) {
                    foreach ($this->keyEntitySetEntities(
                        $relation->endpoints['prov:key-entity-set'] ?? null,
                    ) as $entityRef) {
                        $identifier = $this->tryResolve($entityRef);
                        if ($identifier !== null) {
                            $out[] = new ScannedEndpoint($section, 'keyEntity', 'entity', $identifier);
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * The formal PROV-JSON keys of a relation section, mapped to what each key
     * holds: 'ref' names another record, 'time' is a `prov:time` instant, and
     * 'array' is a dictionary key set (`prov:key-entity-set` or
     * `prov:key-set`). A key that is not listed is an attribute of the
     * relation, and an element section or an unknown section has no formals at
     * all.
     *
     * This is the PROV-JSON layout itself rather than one document's, so it is
     * static and answers the same for every document. A consumer that rewrites
     * stored PROV-JSON needs it to tell an entry it must follow from an entry
     * that holds data: rewriting a `prov:time` or a removed key as if it named
     * a record corrupts a literal. The kind here is the shape of the value;
     * the `kind` of `ScannedEndpoint` is the element type a reference points
     * at.
     *
     * @return array<string, 'ref'|'time'|'array'>
     *   PROV-JSON formal key => kind, in PROV-N positional order.
     */
    public static function formalKinds(string $section): array
    {
        return RelationMetadata::jsonFormalKinds($section);
    }

    /**
     * Whether a decoded PROV-JSON value names a record rather than holding a
     * literal, that is whether it is a typed value tagged as a qualified name.
     * The tag is `prov:QUALIFIED_NAME`, which ProvToolbox and python-prov
     * write and this library writes too, or `xsd:QName`, the spelling in the
     * 2013 PROV-JSON submission examples. Both are matched by their resolved
     * datatype URI, so a document that binds another prefix to the PROV or XSD
     * namespace is read the same way, and one that binds prov or xsd itself to
     * a foreign namespace gets a literal. Anything else, a bare scalar and a
     * literal under any other datatype included, is data.
     *
     * The reference-typed formals of a relation always name a record and need
     * no tag; see `formalKinds()`. This is for the positions where either is
     * allowed: an attribute value, and a formal that is not a reference. A
     * consumer rewriting stored PROV-JSON has to tell the two apart, because
     * a literal whose text reads like an identifier is still a literal.
     *
     * @param mixed $value
     *   One decoded PROV-JSON value: a scalar, or the `{"$": ..., "type": ...}`
     *   map a typed value is written as.
     */
    public function isQualifiedNameValue(mixed $value): bool
    {
        if (!is_array($value) || !is_string($value['$'] ?? null)) {
            return false;
        }

        $type = $value['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }
        $datatype = $this->tryResolve($type);
        return $datatype !== null && ValueIdentity::isQualifiedNameDatatype($datatype->getUri());
    }

    /**
     * Builds the document's namespace manager from the raw `prefix` map, the
     * same way `JsonSerializer` bootstraps one: the reserved `default` prefix
     * becomes the default namespace, and every other prefix is registered,
     * with prov/xsd preloaded as built-ins.
     *
     * @throws \Prov\Exception\DeserializationException
     *   When the prefix section is not a map, a URI is not a string, or a
     *   declaration is otherwise invalid.
     */
    private function buildNamespaceTable(mixed $prefixSection): NamespaceManager
    {
        if (!is_array($prefixSection)) {
            throw new DeserializationException('Invalid PROV-JSON: the prefix section must be a map.');
        }

        $namespaces = [];
        foreach ($prefixSection as $prefix => $uri) {
            if (!is_string($uri)) {
                throw new DeserializationException(
                    "Invalid PROV-JSON: namespace URI for prefix '{$prefix}' must be a string.",
                );
            }
            try {
                $namespaces[] = new ProvNamespace((string) $prefix, $uri);
            } catch (\InvalidArgumentException $e) {
                throw new DeserializationException('Invalid PROV-JSON: ' . $e->getMessage(), previous: $e);
            }
        }

        try {
            return NamespaceManager::forContainer($namespaces);
        } catch (NamespaceException $e) {
            throw new DeserializationException('Invalid PROV-JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Checks that every present element, relation, and bundle section is a map,
     * the document-level structural contract the deserializer upholds. Record
     * bodies are left unchecked; their damage is tolerated by the queries.
     *
     * @param array<array-key, mixed> $json
     *
     * @throws \Prov\Exception\DeserializationException
     *   When a present section is a scalar.
     */
    private function assertSectionsAreMaps(array $json): void
    {
        foreach (self::ELEMENT_SECTIONS as $section) {
            $this->assertSectionIsMap($json, $section);
        }
        foreach (RelationMetadata::JSON_KEYS as $section) {
            $this->assertSectionIsMap($json, $section);
        }
        $this->assertSectionIsMap($json, 'bundle');
    }

    /**
     * @param array<array-key, mixed> $json
     *
     * @throws \Prov\Exception\DeserializationException
     */
    private function assertSectionIsMap(array $json, string $section): void
    {
        if (isset($json[$section]) && !is_array($json[$section])) {
            throw new DeserializationException("Invalid PROV-JSON: the {$section} section must be a map.");
        }
    }

    /**
     * A section's decoded map, or an empty array when the section is absent or
     * (defensively) not a map. Present sections are already checked to be maps
     * at construction.
     *
     * @return array<array-key, mixed>
     */
    private function section(string $section): array
    {
        $value = $this->json[$section] ?? null;
        return is_array($value) ? $value : [];
    }

    /**
     * The readable record bodies at a section id, scruffy provenance expanded.
     * Non-map instances are dropped.
     *
     * @return list<array<array-key, mixed>>
     */
    private function recordInstances(string $section, QualifiedName|string $id): array
    {
        $sectionData = $this->section($section);
        if ($sectionData === []) {
            return [];
        }

        if (is_string($id) && array_key_exists($id, $sectionData)) {
            return $this->recordBodies($sectionData[$id]);
        }

        $rawKey = $this->rawKeyForUri($section, $this->toUri($id));
        if ($rawKey === null) {
            return [];
        }
        return $this->recordBodies($sectionData[$rawKey]);
    }

    /**
     * Splits a scruffy value into its record bodies. A single map yields one
     * body; a JSON array yields one per element; a scalar yields none.
     *
     * @return list<array<array-key, mixed>>
     */
    private function recordBodies(mixed $raw): array
    {
        if (is_array($raw) && $raw !== [] && array_is_list($raw)) {
            $out = [];
            foreach ($raw as $instance) {
                if (is_array($instance)) {
                    $out[] = $instance;
                }
            }
            return $out;
        }
        if (is_array($raw)) {
            return [$raw];
        }
        return [];
    }

    /**
     * The raw id key of a section whose record resolves to the given URI, or
     * null. Backed by a per-section URI index built on first use, so a
     * cross-prefix lookup stays O(1) after the first.
     */
    private function rawKeyForUri(string $section, string $uri): ?string
    {
        if (!isset($this->sectionUriIndexes[$section])) {
            $index = [];
            foreach ($this->section($section) as $rawKey => $_body) {
                $resolved = $this->tryResolveUri((string) $rawKey);
                if ($resolved !== null) {
                    $index[$resolved] ??= (string) $rawKey;
                }
            }
            $this->sectionUriIndexes[$section] = $index;
        }
        return $this->sectionUriIndexes[$section][$uri] ?? null;
    }

    /**
     * Builds one ScannedRelation from a record body: keys that are formal for
     * the section become endpoints (kept raw), everything else becomes
     * normalized attributes.
     *
     * @param array<array-key, mixed> $body
     */
    private function buildRelation(string $section, string $id, array $body): ScannedRelation
    {
        $formalKeys = $this->formalKeys($section);
        $formalSet = $formalKeys === [] ? [] : array_fill_keys($formalKeys, true);

        $endpoints = [];
        $attributes = [];
        foreach ($body as $rawKey => $rawValue) {
            $key = (string) $rawKey;
            if (isset($formalSet[$key])) {
                $endpoints[$key] = $rawValue;
                continue;
            }
            $uri = $this->attributeKeyUri($key);
            if ($uri === null) {
                continue;
            }
            foreach ($this->normalizeValues($rawValue) as $value) {
                $attributes[$uri][] = $value;
            }
        }
        return new ScannedRelation($section, $id, $endpoints, $attributes);
    }

    /**
     * Indexes every relation, across all sections, by the URI of each endpoint
     * it references (reference-typed formals plus dictionary-entry entities),
     * matching what `ProvGraph::relationsReferencing` covers. The index keeps a
     * locator per relation and maps each endpoint URI to those locators, so a
     * `ScannedRelation` is built only for the relations a query actually hits,
     * not for the whole document. A relation is listed once per distinct
     * endpoint URI.
     *
     * @return list<array{section: string, id: string, body: array<array-key, mixed>}>
     *   The relation locators, also stored on the scanner for reuse.
     */
    private function buildEndpointIndex(): array
    {
        $locators = [];
        $index = [];
        foreach (RelationMetadata::JSON_KEYS as $section) {
            $sectionData = $this->section($section);
            if ($sectionData === []) {
                continue;
            }
            $refKeys = $this->refKeys($section);
            $hasDictSet = in_array('prov:key-entity-set', $this->formalKeys($section), true);

            foreach ($sectionData as $rawId => $raw) {
                foreach ($this->recordBodies($raw) as $body) {
                    $refs = [];
                    foreach ($refKeys as $key) {
                        foreach ($this->endpointRefs($section, $key, $body[$key] ?? null) as $ref) {
                            $refs[] = $ref;
                        }
                    }
                    if ($hasDictSet && isset($body['prov:key-entity-set'])) {
                        foreach ($this->keyEntitySetEntities($body['prov:key-entity-set']) as $entity) {
                            $refs[] = $entity;
                        }
                    }
                    if ($refs === []) {
                        continue;
                    }

                    $locatorIndex = count($locators);
                    $locators[] = ['section' => $section, 'id' => (string) $rawId, 'body' => $body];
                    $seen = [];
                    foreach ($refs as $ref) {
                        $uri = $this->toUri($ref);
                        if (isset($seen[$uri])) {
                            continue;
                        }
                        $seen[$uri] = true;
                        $index[$uri][] = $locatorIndex;
                    }
                }
            }
        }
        $this->relationLocators = $locators;
        $this->byEndpoint = $index;
        return $locators;
    }

    /**
     * The record references one formal endpoint value holds. Normally that is a
     * single reference string. PROV-JSON also lets `hadMember` list several
     * entities under one `prov:entity` key, and the deserializer expands that
     * into one Membership per entity, so the scanner reports one reference per
     * entity too. Anything else is unreadable and contributes nothing.
     *
     * @return list<string>
     */
    private function endpointRefs(string $section, string $key, mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if ($section !== 'hadMember' || $key !== 'prov:entity' || !is_array($value) || !array_is_list($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $member) {
            if (is_string($member)) {
                $out[] = $member;
            }
        }
        return $out;
    }

    /**
     * The entity reference strings inside a `prov:key-entity-set`, in either
     * the array-of-objects or the simple-map form. Malformed entries are
     * skipped.
     *
     * @return list<string>
     */
    private function keyEntitySetEntities(mixed $set): array
    {
        if (!is_array($set) || $set === []) {
            return [];
        }

        $out = [];
        if (array_is_list($set)) {
            foreach ($set as $entry) {
                if (is_array($entry) && isset($entry['$']) && is_string($entry['$'])) {
                    $out[] = $entry['$'];
                }
            }
            return $out;
        }

        foreach ($set as $key => $value) {
            if ($key === '$key-datatype') {
                continue;
            }
            if (is_string($value)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Walks `actedOnBehalfOf` out of an agent, nearest responsible first,
     * honoring activity scoping, following the first edge at each hop, and
     * stopping on a cycle. Returns the responsible ids as they appear.
     *
     * @return list<string>
     */
    private function delegationChain(string $agent, string $activityUri): array
    {
        if ($this->delegationsByDelegate === null) {
            $this->buildDelegationIndex();
        }

        $chain = [];
        $currentUri = $this->toUri($agent);
        $seen = [$currentUri => true];
        while (true) {
            $next = null;
            foreach ($this->delegationsByDelegate[$currentUri] ?? [] as $edge) {
                if ($edge['activityUri'] !== null && $edge['activityUri'] !== $activityUri) {
                    continue;
                }
                $next = $edge;
                break;
            }
            if ($next === null || isset($seen[$next['responsibleUri']])) {
                break;
            }
            $seen[$next['responsibleUri']] = true;
            $chain[] = $next['responsibleRaw'];
            $currentUri = $next['responsibleUri'];
        }
        return $chain;
    }

    /**
     * Indexes `actedOnBehalfOf` edges by the delegate's URI, in document order,
     * so `delegationChain()` can follow them without rescanning the section.
     */
    private function buildDelegationIndex(): void
    {
        $index = [];
        foreach ($this->section('actedOnBehalfOf') as $raw) {
            foreach ($this->recordBodies($raw) as $body) {
                $delegate = $body['prov:delegate'] ?? null;
                $responsible = $body['prov:responsible'] ?? null;
                if (!is_string($delegate) || !is_string($responsible)) {
                    continue;
                }
                $activity = isset($body['prov:activity']) && is_string($body['prov:activity'])
                    ? $body['prov:activity']
                    : null;
                $index[$this->toUri($delegate)][] = [
                    'responsibleRaw' => $responsible,
                    'responsibleUri' => $this->toUri($responsible),
                    'activityUri' => $activity !== null ? $this->toUri($activity) : null,
                ];
            }
        }
        $this->delegationsByDelegate = $index;
    }

    /**
     * The formal PROV-JSON keys of a relation section (`prov:entity`,
     * `prov:activity`, `prov:time`, the dictionary key sets, ...), or an empty
     * list for a non-relation section.
     *
     * @return list<string>
     */
    private function formalKeys(string $section): array
    {
        return $this->formalKeysCache[$section] ??= array_keys(self::formalKinds($section));
    }

    /**
     * The reference-typed formal keys of a relation section: the endpoints that
     * name another record, excluding time and the dictionary key sets.
     *
     * @return list<string>
     */
    private function refKeys(string $section): array
    {
        return $this->refKeysCache[$section] ??= array_keys(array_filter(
            self::formalKinds($section),
            static fn(string $kind): bool => $kind === 'ref',
        ));
    }

    /**
     * Normalizes one record value into a list of scanner values. A JSON array
     * with no `$` member is a multi-value list, normalized element by element;
     * anything else is a single value. Values that cannot be read are dropped.
     *
     * @return list<string|int|float|bool|array<string, mixed>>
     */
    private function normalizeValues(mixed $rawValue): array
    {
        if (is_array($rawValue) && !isset($rawValue['$']) && array_is_list($rawValue)) {
            $out = [];
            foreach ($rawValue as $item) {
                $value = $this->normalizeValue($item);
                if ($value !== null) {
                    $out[] = $value;
                }
            }
            return $out;
        }
        $value = $this->normalizeValue($rawValue);
        return $value === null ? [] : [$value];
    }

    /**
     * Normalizes a single PROV-JSON value. A bare scalar stays as decoded. A
     * typed-value object collapses to a PHP scalar for the common xsd types
     * (string family, integer family, float/double, boolean); a
     * language-tagged, `prov:QUALIFIED_NAME`, decimal, dateTime, or otherwise
     * unrecognized typed value stays raw so nothing is lost. A malformed typed
     * value (no scalar `$`) returns null so the caller can drop it.
     *
     * @return string|int|float|bool|array<string, mixed>|null
     */
    private function normalizeValue(mixed $raw): string|int|float|bool|array|null
    {
        if (is_scalar($raw)) {
            return $raw;
        }
        if (!is_array($raw)) {
            return null;
        }

        $lexical = $raw['$'] ?? null;
        if (!is_scalar($lexical)) {
            return null;
        }
        // A language tag makes it a language-tagged literal, not a plain scalar.
        if (isset($raw['lang'])) {
            return $raw;
        }

        $type = $raw['type'] ?? null;
        if ($type === null) {
            return is_string($lexical) ? $lexical : $raw;
        }
        if (!is_string($type)) {
            return $raw;
        }

        $local = $this->xsdLocalPart($type);
        if ($local === null) {
            return $raw;
        }

        $lexicalString = (string) $lexical;
        if ($local === 'boolean') {
            return match ($lexicalString) {
                'true', '1' => true,
                'false', '0' => false,
                default => $raw,
            };
        }
        if (isset(self::INTEGER_TYPES[$local])) {
            return $this->integerValue($lexicalString) ?? $raw;
        }
        if ($local === 'float' || $local === 'double') {
            return is_numeric($lexicalString) ? (float) $lexicalString : $raw;
        }
        if (isset(self::STRING_TYPES[$local])) {
            return $lexicalString;
        }
        return $raw;
    }

    /**
     * The PHP int an xsd integer lexical form collapses to, or null when it does
     * not fit one. xsd:integer and its unsigned variants are unbounded while a
     * PHP int is not, and a cast saturates at PHP_INT_MAX/MIN, so a huge literal
     * would be reported as a different number. Keeping the cast only when it
     * round-trips against the canonical form leaves those values raw instead.
     */
    private function integerValue(string $lexical): ?int
    {
        if (preg_match('/^[+-]?\d+$/', $lexical) !== 1) {
            return null;
        }
        $int = (int) $lexical;
        return (string) $int === self::canonicalInteger($lexical) ? $int : null;
    }

    /**
     * The canonical form of an xsd integer lexical value: no leading plus, no
     * leading zeros, no signed zero.
     */
    private static function canonicalInteger(string $lexical): string
    {
        $digits = ltrim(ltrim($lexical, '+-'), '0');
        if ($digits === '') {
            return '0';
        }
        return str_starts_with($lexical, '-') ? '-' . $digits : $digits;
    }

    /**
     * The xsd local part of a datatype token (`xsd:int` -> `int`), or null when
     * the token does not resolve into the XSD namespace. Tolerates the
     * hash-less xsd namespace form some documents use.
     */
    private function xsdLocalPart(string $type): ?string
    {
        $qn = $this->tryResolve($type);
        if ($qn === null) {
            return null;
        }
        $uri = ValueIdentity::normalizeDatatypeUri($qn->getUri());
        return str_starts_with($uri, self::XSD_URI) ? substr($uri, strlen(self::XSD_URI)) : null;
    }

    /**
     * The full URI of an attribute name, or null when it does not resolve.
     */
    private function attributeUri(QualifiedName|string $attribute): ?string
    {
        if ($attribute instanceof QualifiedName) {
            return $attribute->getUri();
        }
        return $this->tryResolveUri($attribute);
    }

    /**
     * The full URI of a raw attribute key, or null when it does not resolve.
     */
    private function attributeKeyUri(string $key): ?string
    {
        return $this->tryResolveUri($key);
    }

    /**
     * URI form of a query identifier for URI-level matching. Authority-form
     * URIs and blank-node labels are already index keys; a prefixed or
     * unprefixed shorthand resolves against the namespace table, and an
     * unresolvable identifier is used as-is so the lookup simply misses.
     */
    private function toUri(QualifiedName|string $identifier): string
    {
        if ($identifier instanceof QualifiedName) {
            return $identifier->getUri();
        }
        if (str_contains($identifier, '://') || str_starts_with($identifier, '_:')) {
            return $identifier;
        }
        return $this->tryResolveUri($identifier) ?? $identifier;
    }

    private function tryResolveUri(string $shorthand): ?string
    {
        return $this->tryResolve($shorthand)?->getUri();
    }
}
