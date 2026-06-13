<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\DeserializationException;
use Prov\Exception\NamespaceException;
use Prov\Exception\ProvException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
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
 * Serializes Documents to and parses them from PROV-JSON, the W3C's
 * JSON-based interchange format for PROV.
 *
 * @mago-ignore analysis:mixed-argument
 * @mago-ignore analysis:mixed-assignment
 * @mago-ignore analysis:mixed-array-assignment
 * @mago-ignore analysis:invalid-iterator
 * @mago-ignore analysis:less-specific-argument
 * @mago-ignore analysis:less-specific-nested-argument-type
 * @mago-ignore analysis:imprecise-type
 */
class JsonSerializer implements ProvSerializerInterface, ProvDeserializerInterface
{
    /** @var \WeakMap<\Prov\Model\ProvRecord, string> */
    private \WeakMap $blankNodes;

    private int $blankNodeCounter = 0;

    /** @var array<string, true> Blank labels the document already uses. */
    private array $usedBlankLabels = [];

    private ?PrefixMinter $minter = null;

    public function __construct(
        public readonly bool $prettyPrint = false,
    ) {
        $this->blankNodes = new \WeakMap();
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Prov\Exception\ProvException
     *   When two bundles share an identifier (their JSON keys would collide).
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $this->blankNodes = new \WeakMap();
        $this->blankNodeCounter = 0;
        $this->collectUsedBlankLabels($document);

        $nsManager = new NamespaceManager();
        foreach ($document->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $nsManager->setDefault($ns);
            } else {
                $nsManager->addOrReplace($ns);
            }
        }
        $minter = new PrefixMinter($nsManager);
        $this->minter = $minter;

        $output = [];

        $output['prefix'] = $this->serializePrefixes($document->namespaces);

        $this->serializeRecords($document->records, $output, $nsManager);

        foreach ($document->bundles as $bundle) {
            $bundleKey = $this->jsonQName($bundle->identifier);
            $bundleNsManager = new NamespaceManager($nsManager);
            foreach ($bundle->namespaces as $ns) {
                if ($ns->prefix === 'default') {
                    $bundleNsManager->setDefault($ns);
                } else {
                    $bundleNsManager->addOrReplace($ns);
                }
            }
            $bundleData = [];
            $bundlePrefixes = $this->serializePrefixes($bundle->namespaces, $document->namespaces);
            if ($bundlePrefixes !== []) {
                $bundleData['prefix'] = $bundlePrefixes;
            }
            $this->serializeRecords($bundle->records, $bundleData, $bundleNsManager);
            if (isset($output['bundle'][$bundleKey])) {
                throw new ProvException(
                    "Cannot serialize a document with two bundles sharing the identifier '{$bundleKey}'; "
                    . 'merge them first.',
                );
            }
            $output['bundle'][$bundleKey] = $bundleData;
        }

        // Declarations for namespaces minted while serializing records. Assigning
        // into the existing 'prefix' key keeps its position in the output.
        foreach ($minter->getMintedNamespaces() as $ns) {
            $output['prefix'][$ns->prefix] = $ns->uri;
        }

        // An empty prefix map must not encode as a JSON array `[]`; drop it so
        // the document object never carries an array-valued 'prefix'.
        if ($output['prefix'] === []) {
            unset($output['prefix']);
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        // JSON_THROW_ON_ERROR guarantees string-or-throw, never false. An empty
        // document must still encode as the object `{}`, never the array `[]`.
        $encoded = json_encode($output === [] ? new \stdClass() : $output, $flags);
        assert(is_string($encoded));
        return $encoded;
    }

    /**
     * Mints a serialization-only blank id, skipping every label the document
     * itself uses: a collision would alias the minted record with an existing
     * blank node and fabricate an identity link on deserialization.
     */
    private function mintBlankLabel(): string
    {
        do {
            $label = '_:b' . ++$this->blankNodeCounter;
        } while (isset($this->usedBlankLabels[$label]));
        return $label;
    }

    private function collectUsedBlankLabels(Document $document): void
    {
        $labels = [];
        $records = $document->records;
        foreach ($document->bundles as $bundle) {
            $records = array_merge($records, $bundle->records);
        }
        foreach ($records as $record) {
            $id = $record->identifier;
            if ($id !== null && $id->isBlank()) {
                $labels[$id->getUri()] = true;
            }
            if ($record instanceof ProvRelation) {
                foreach (RelationMetadata::extractFormals($record) as $value) {
                    if ($value instanceof QualifiedName && $value->isBlank()) {
                        $labels[$value->getUri()] = true;
                    } elseif (is_array($value)) {
                        $this->collectBlankDictionaryLabels($value, $labels);
                    }
                }
            }
            foreach ($record->attributes->all() as $values) {
                foreach ($values as $value) {
                    if ($value instanceof QualifiedName && $value->isBlank()) {
                        $labels[$value->getUri()] = true;
                    }
                }
            }
        }
        $this->usedBlankLabels = $labels;
    }

    /**
     * @param array<array-key, mixed> $items
     * @param array<string, true> $labels
     */
    private function collectBlankDictionaryLabels(array $items, array &$labels): void
    {
        foreach ($items as $item) {
            if ($item instanceof DictionaryEntry && $item->entity !== null && $item->entity->isBlank()) {
                $labels[$item->entity->getUri()] = true;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $data): Document
    {
        $json = json_decode($data, true);
        if (!is_array($json)) {
            throw new DeserializationException('Invalid PROV-JSON: could not decode JSON.');
        }

        try {
            return $this->deserializeDocument($json);
        } catch (NamespaceException|\InvalidArgumentException $e) {
            // An unresolvable or invalid identifier (undeclared prefix, missing
            // default namespace, conflicting declarations, empty local part)
            // means the input is malformed; surface it under the
            // deserialization contract.
            throw new DeserializationException('Invalid PROV-JSON: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private function deserializeDocument(array $json): Document
    {
        $nsManager = new NamespaceManager();
        $prefixes = $json['prefix'] ?? [];
        foreach ($prefixes as $prefix => $uri) {
            if (!is_string($uri)) {
                throw new DeserializationException(
                    "Invalid PROV-JSON: namespace URI for prefix '{$prefix}' must be a string.",
                );
            }
            if ($prefix === 'default') {
                $nsManager->setDefault(new ProvNamespace('default', $uri));
                continue;
            }
            // Skip redeclarations that match an already-registered prefix (the
            // common xsd/prov case) to avoid an allocation.
            $existing = $nsManager->getNamespace($prefix);
            if ($existing !== null && $existing->uri === $uri) {
                continue;
            }
            $nsManager->addOrReplace(new ProvNamespace($prefix, $uri));
        }

        $records = [];
        $this->deserializeRecords($json, $nsManager, $records);

        $bundles = [];
        if (isset($json['bundle'])) {
            foreach ($json['bundle'] as $bundleId => $bundleData) {
                $bundleNsManager = new NamespaceManager($nsManager);
                if (isset($bundleData['prefix'])) {
                    foreach ($bundleData['prefix'] as $prefix => $uri) {
                        if (!is_string($uri)) {
                            throw new DeserializationException(
                                "Invalid PROV-JSON: namespace URI for prefix '{$prefix}' must be a string.",
                            );
                        }
                        if ($prefix === 'default') {
                            $bundleNsManager->setDefault(new ProvNamespace('default', $uri));
                            continue;
                        }
                        // Bundle prefix that redeclares a document-level (or
                        // built-in) prefix with the same URI is a no-op.
                        $existing = $bundleNsManager->getNamespace($prefix);
                        if ($existing !== null && $existing->uri === $uri) {
                            continue;
                        }
                        $bundleNsManager->addOrReplace(new ProvNamespace($prefix, $uri));
                    }
                }

                $bundleRecords = [];
                $this->deserializeRecords($bundleData, $bundleNsManager, $bundleRecords);
                $bundles[] = new Bundle(
                    identifier: $this->resolveQName((string) $bundleId, $nsManager),
                    records: $bundleRecords,
                    namespaces: $bundleNsManager->getRegisteredNamespaces(),
                );
            }
        }

        return new Document(records: $records, bundles: $bundles, namespaces: $nsManager->getRegisteredNamespaces());
    }

    /**
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     * @param list<\Prov\Identifier\ProvNamespace> $parentNamespaces
     *
     * @return array<string, string>
     */
    private function serializePrefixes(array $namespaces, array $parentNamespaces = []): array
    {
        $parentUris = [];
        foreach ($parentNamespaces as $pns) {
            $parentUris[$pns->prefix] = $pns->uri;
        }

        $prefixes = [];
        foreach ($namespaces as $ns) {
            if (isset($parentUris[$ns->prefix]) && $parentUris[$ns->prefix] === $ns->uri) {
                continue;
            }
            $prefixes[$ns->prefix] = $ns->uri;
        }

        return $prefixes;
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     * @param array<string, mixed> $output
     */
    private function serializeRecords(array $records, array &$output, NamespaceManager $nsManager): void
    {
        foreach ($records as $record) {
            match (true) {
                $record instanceof Entity => $this->serializeElement($record, 'entity', $output, $nsManager),
                $record instanceof Activity => $this->serializeActivity($record, $output, $nsManager),
                $record instanceof Agent => $this->serializeElement($record, 'agent', $output, $nsManager),
                default => $this->serializeRelation($record, $output, $nsManager),
            };
        }
    }

    /**
     * @param array<string, mixed> $output
     */
    private function serializeElement(
        Entity|Agent $record,
        string $key,
        array &$output,
        NamespaceManager $nsManager,
    ): void {
        $id = $record->identifier !== null
            ? $this->jsonQName($record->identifier)
            : ($this->blankNodes[$record] ??= $this->mintBlankLabel());
        $attrs = $record->attributes->isEmpty()
            ? new \stdClass()
            : ($this->serializeAttributes($record->attributes, $nsManager) ?: new \stdClass());
        $this->appendToSection($output, $key, $id, $attrs);
    }

    /**
     * @param array<string, mixed> $output
     */
    private function serializeActivity(Activity $record, array &$output, NamespaceManager $nsManager): void
    {
        $id = $record->identifier !== null
            ? $this->jsonQName($record->identifier)
            : ($this->blankNodes[$record] ??= $this->mintBlankLabel());
        $attrs = $record->attributes->isEmpty() ? [] : $this->serializeAttributes($record->attributes, $nsManager);

        if ($record->startTime !== null) {
            $attrs['prov:startTime'] = Literal::formatDateTime($record->startTime);
        }
        if ($record->endTime !== null) {
            $attrs['prov:endTime'] = Literal::formatDateTime($record->endTime);
        }

        $this->appendToSection($output, 'activity', $id, $attrs ?: new \stdClass());
    }

    /**
     * @param array<string, mixed> $output
     */
    private function serializeRelation(ProvRecord $record, array &$output, NamespaceManager $nsManager): void
    {
        $relationKey = RelationMetadata::JSON_KEYS[$record::class] ?? null;
        if ($relationKey === null) {
            return;
        }

        $attrs = $this->serializeRelationFormalAttrs($record);
        if (!$record->attributes->isEmpty()) {
            $attrs = array_merge($attrs, $this->serializeAttributes($record->attributes, $nsManager));
        }

        $id = $record->identifier !== null
            ? $this->jsonQName($record->identifier)
            : ($this->blankNodes[$record] ??= $this->mintBlankLabel());
        $this->appendToSection($output, $relationKey, $id, $attrs ?: new \stdClass());
    }

    /**
     * Append a record to a JSON section, handling scruffy provenance
     * (multiple records sharing the same identifier become a JSON array).
     *
     * @param array<string, mixed> $output
     */
    private function appendToSection(array &$output, string $section, string $id, mixed $data): void
    {
        if (!isset($output[$section][$id])) {
            $output[$section][$id] = $data;
        } elseif (is_array($output[$section][$id]) && array_is_list($output[$section][$id])) {
            $output[$section][$id][] = $data;
        } else {
            $output[$section][$id] = [$output[$section][$id], $data];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRelationFormalAttrs(ProvRecord $record): array
    {
        if (!$record instanceof ProvRelation) {
            return [];
        }

        $attrs = [];
        $meta = RelationMetadata::FORMALS[$record::class] ?? [];
        // Inline of RelationMetadata::extractFormals to avoid the intermediate
        // array allocation for what is a per-relation hot path.
        $vars = get_object_vars($record);

        foreach ($meta as $prop => $type) {
            $value = $vars[$prop] ?? null;
            if ($value === null && $type !== 'array') {
                continue;
            }
            $key = 'prov:' . $prop;
            if ($type === 'ref') {
                $attrs[$key] = $value instanceof QualifiedName ? $this->jsonQName($value) : (string) $value;
            } elseif ($type === 'time') {
                $attrs[$key] = Literal::formatDateTime($value);
            } elseif ($prop === 'keyEntityPairs') {
                $this->addDictKeyEntitySet($attrs, $value ?? []);
            } elseif ($prop === 'removedKeys') {
                $this->addDictKeySet($attrs, $value ?? []);
            }
        }

        return $attrs;
    }

    /**
     * Writes the PROV-DICT `prov:key-entity-set` entry: the compact simple-map
     * form when all keys share one datatype, or the expanded array-of-objects
     * form when keys mix datatypes, languages, or include QualifiedName keys.
     *
     * @param array<string, mixed> $attrs
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $pairs
     */
    private function addDictKeyEntitySet(array &$attrs, array $pairs): null
    {
        if ($pairs === []) {
            return null;
        }

        // "Complex" keys need the array-of-objects format because the simple-map
        // format can only hold string-stringifiable keys with a shared datatype.
        // Literals with a lang tag, QualifiedName-typed keys, and raw array values
        // from an untyped deserialization path all fall here.
        $hasComplexKeys = false;
        $seenDatatype = null;
        foreach ($pairs as $pair) {
            $k = $pair->key;
            if (is_array($k) || $k instanceof QualifiedName || $k instanceof Literal && $k->languageTag !== null) {
                $hasComplexKeys = true;
                break;
            }
            $dt = $k instanceof Literal && $k->datatype !== null ? (string) $k->datatype : 'xsd:string';
            if ($seenDatatype === null) {
                $seenDatatype = $dt;
            } elseif ($seenDatatype !== $dt) {
                $hasComplexKeys = true;
                break;
            }
        }

        if ($hasComplexKeys) {
            $set = [];
            foreach ($pairs as $pair) {
                $entry = ['$' => $pair->entity !== null ? $this->jsonQName($pair->entity) : null];
                $k = $pair->key;
                if (is_array($k)) {
                    $entry['key'] = $k;
                } elseif ($k instanceof QualifiedName) {
                    $entry['key'] = ['$' => $this->jsonQName($k), 'type' => 'prov:QUALIFIED_NAME'];
                } elseif ($k instanceof Literal) {
                    $entry['key'] = $this->serializeAttributeValue($k);
                } elseif ($k !== null) {
                    $entry['key'] = $k;
                }
                $set[] = $entry;
            }
            $attrs['prov:key-entity-set'] = $set;
            return null;
        }

        // Simple map format. Pick a $key-datatype (xsd:string is the default and can
        // be omitted; a homogeneous non-string-literal datatype goes in the header).
        $headerDatatype = null;
        foreach ($pairs as $pair) {
            $k = $pair->key;
            if ($k instanceof Literal && $k->datatype !== null) {
                $dt = (string) $k->datatype;
                if ($dt === 'xsd:string') {
                    continue;
                }
                $headerDatatype = $dt;
                break;
            }
        }

        $set = [];
        if ($headerDatatype !== null) {
            $set['$key-datatype'] = $headerDatatype;
        }
        foreach ($pairs as $pair) {
            $k = $pair->key;
            if ($k instanceof Literal) {
                $stringKey = $k->value;
            } elseif (is_scalar($k)) {
                $stringKey = (string) $k;
            } elseif ($k instanceof \Stringable) {
                $stringKey = (string) $k;
            } else {
                // Array/null keys shouldn't reach here for valid PROV-JSON output;
                // skip rather than corrupt the output.
                continue;
            }
            $set[$stringKey] = $pair->entity !== null ? $this->jsonQName($pair->entity) : null;
        }
        $attrs['prov:key-entity-set'] = $set;
        return null;
    }

    /**
     * Writes the PROV-DICT `prov:key-set` entry used by derivedByRemovalFrom:
     * the list of keys removed from the input dictionary.
     *
     * @param array<string, mixed> $attrs
     * @param list<mixed> $keys
     */
    private function addDictKeySet(array &$attrs, array $keys): null
    {
        if ($keys !== []) {
            $set = [];
            foreach ($keys as $key) {
                if (is_array($key)) {
                    // Raw JSON value from deserialization, pass through.
                    $set[] = $key;
                } else {
                    $set[] = $this->serializeAttributeValue($key);
                }
            }
            $attrs['prov:key-set'] = $set;
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttributes(Attributes $attributes, NamespaceManager $nsManager): array
    {
        $result = [];

        foreach ($attributes->all() as $uri => $values) {
            $key = $this->minter !== null
                ? $this->minter->uriToPrefixed($uri, $nsManager)
                : $nsManager->uriToPrefixed($uri);
            // The reserved "default:" sentinel never appears literally; the bare
            // local name resolves against the `default` member of the prefix map.
            if (str_starts_with($key, 'default:')) {
                $key = substr($key, strlen('default:'));
            }
            $key = $this->escapeAttributeKey($key);
            $isMultiValue = count($values) > 1;
            foreach ($values as $value) {
                $serialized = $this->serializeAttributeValue($value);
                if ($isMultiValue) {
                    $result[$key][] = $serialized;
                } else {
                    $result[$key] = $serialized;
                }
            }
        }

        return $result;
    }

    private function serializeAttributeValue(QualifiedName|Literal|string|int|float|bool $value): mixed
    {
        if ($value instanceof QualifiedName) {
            return [
                '$' => $this->jsonQName($value),
                'type' => 'prov:QUALIFIED_NAME',
            ];
        }

        if ($value instanceof Literal) {
            $result = ['$' => $value->value];
            if ($value->datatype !== null) {
                $result['type'] = $this->jsonQName($value->datatype);
            }
            if ($value->languageTag !== null) {
                $result['lang'] = $value->languageTag;
            }
            return $result;
        }

        return $value;
    }

    /**
     * The `prefix:local` (or bare-local default-namespace) form of a qualified
     * name, with `PN_CHARS_ESC` punctuation in the local part backslash-escaped.
     * ProvToolbox's PROV-JSON uses the same escaping inside its `prefix:local`
     * strings, so a name's canonical local part stays identical across formats.
     */
    private function jsonQName(QualifiedName $qn): string
    {
        $local = QualifiedNameEscaper::escape($qn->localPart);

        // A default-namespace name is written bare; a blank-node label keeps its
        // reserved "_" prefix. Neither needs (or can take) a declaration.
        if ($qn->namespace->prefix === 'default') {
            return $local;
        }
        if ($qn->isBlank()) {
            return $qn->namespace->prefix . ':' . $local;
        }

        // Route through the minter so an otherwise-undeclared namespace gets a
        // declaration emitted in the prefix map.
        $prefix = $this->minter !== null ? $this->minter->prefixFor($qn) : $qn->namespace->prefix;

        return $prefix . ':' . $local;
    }

    /**
     * Escapes the local part of a `prefix:local` (or bare-local) attribute key,
     * leaving the prefix untouched.
     */
    private function escapeAttributeKey(string $key): string
    {
        $colon = strpos($key, ':');
        if ($colon === false) {
            return QualifiedNameEscaper::escape($key);
        }
        return substr($key, 0, $colon + 1) . QualifiedNameEscaper::escape(substr($key, $colon + 1));
    }

    /**
     * Resolves a `prefix:local` string from PROV-JSON to a QualifiedName,
     * decoding any `PN_CHARS_ESC` escapes in the local part so the result
     * matches the same name parsed from a format that needs no escaping.
     */
    private function resolveQName(string $raw, NamespaceManager $nsManager): QualifiedName
    {
        $qn = $nsManager->resolve($raw);
        $decoded = QualifiedNameEscaper::decode($qn->localPart);
        if ($decoded === $qn->localPart) {
            return $qn;
        }
        return new QualifiedName($qn->namespace, $decoded);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeRecords(array $json, NamespaceManager $nsManager, array &$records): void
    {
        if (isset($json['entity'])) {
            $emptyAttrs = Attributes::empty();
            foreach ($json['entity'] as $id => $attrs) {
                $idStr = (string) $id;
                $deserId = $this->resolveQName($idStr, $nsManager);
                // Fast path: the overwhelmingly common "no attributes" case.
                if ($attrs === [] || $attrs === null) {
                    $records[] = new Entity($deserId, $emptyAttrs);
                    continue;
                }
                foreach ($this->unpackScruffy($attrs) as $instance) {
                    if (!is_array($instance)) {
                        continue;
                    }
                    $records[] = new Entity(
                        $deserId,
                        $this->deserializeExtraAttributes($instance, $nsManager) ?? $emptyAttrs,
                    );
                }
            }
        }

        if (isset($json['activity'])) {
            $emptyAttrs = Attributes::empty();
            foreach ($json['activity'] as $id => $attrs) {
                $idStr = (string) $id;
                $deserId = $this->resolveQName($idStr, $nsManager);
                if ($attrs === [] || $attrs === null) {
                    $records[] = new Activity($deserId, null, null, $emptyAttrs);
                    continue;
                }
                foreach ($this->unpackScruffy($attrs) as $instance) {
                    if (!is_array($instance)) {
                        continue;
                    }
                    $startTime = isset($instance['prov:startTime'])
                        ? $this->parseDateTime($instance['prov:startTime'])
                        : null;
                    $endTime = isset($instance['prov:endTime'])
                        ? $this->parseDateTime($instance['prov:endTime'])
                        : null;
                    unset($instance['prov:startTime'], $instance['prov:endTime']);
                    $records[] = new Activity(
                        $deserId,
                        $startTime,
                        $endTime,
                        $this->deserializeExtraAttributes($instance, $nsManager) ?? $emptyAttrs,
                    );
                }
            }
        }

        if (isset($json['agent'])) {
            $emptyAttrs = Attributes::empty();
            foreach ($json['agent'] as $id => $attrs) {
                $idStr = (string) $id;
                $deserId = $this->resolveQName($idStr, $nsManager);
                if ($attrs === [] || $attrs === null) {
                    $records[] = new Agent($deserId, $emptyAttrs);
                    continue;
                }
                foreach ($this->unpackScruffy($attrs) as $instance) {
                    if (!is_array($instance)) {
                        continue;
                    }
                    $records[] = new Agent(
                        $deserId,
                        $this->deserializeExtraAttributes($instance, $nsManager) ?? $emptyAttrs,
                    );
                }
            }
        }

        // Skip the relations pass (which iterates 18 relation keys) when the
        // document has none. Short-circuits tiny docs like bare entities.
        foreach (RelationMetadata::JSON_KEYS as $jsonKey) {
            if (isset($json[$jsonKey])) {
                $this->deserializeRelations($json, $nsManager, $records);
                break;
            }
        }
    }

    /**
     * PROV-JSON scruffy provenance: multiple records with the same identifier are
     * serialized as a JSON array. Unpack to a list of per-record attribute maps.
     *
     * An empty JSON object `{}` decodes into an empty PHP array, which is also a
     * valid list; treat that as a single record with no attributes, not as zero
     * scruffy records. A real scruffy list always contains at least one map.
     *
     * @return list<mixed>
     */
    private function unpackScruffy(mixed $attrs): array
    {
        if (is_array($attrs) && $attrs !== [] && array_is_list($attrs)) {
            return $attrs;
        }
        return [$attrs];
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeRelations(array $json, NamespaceManager $nsManager, array &$records): void
    {
        foreach (RelationMetadata::JSON_KEYS as $jsonKey) {
            if (!isset($json[$jsonKey])) {
                continue;
            }

            $formalAttrs = RelationMetadata::jsonFormalKeys($jsonKey);

            foreach ($json[$jsonKey] as $id => $attrs) {
                // Scruffy provenance: an ID can map to an array of instances.
                if (is_array($attrs) && array_is_list($attrs)) {
                    foreach ($attrs as $instance) {
                        if (!is_array($instance)) {
                            continue;
                        }
                        $this->deserializeSingleRelation(
                            $jsonKey,
                            (string) $id,
                            $instance,
                            $formalAttrs,
                            $nsManager,
                            $records,
                        );
                    }
                    continue;
                }

                if (!is_array($attrs)) {
                    continue;
                }
                $this->deserializeSingleRelation($jsonKey, (string) $id, $attrs, $formalAttrs, $nsManager, $records);
            }
        }
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<string> $formalAttrs
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeSingleRelation(
        string $jsonKey,
        string $id,
        array $attrs,
        array $formalAttrs,
        NamespaceManager $nsManager,
        array &$records,
    ): void {
        $deserId = $this->resolveQName($id, $nsManager);

        // hadMember prov:entity can be an array of entity references.
        if (
            $jsonKey === 'hadMember'
            && isset($attrs['prov:entity'])
            && is_array($attrs['prov:entity'])
            && array_is_list($attrs['prov:entity'])
        ) {
            $entities = $attrs['prov:entity'];
            unset($attrs['prov:entity']);
            $formal = $this->extractFormalAttrs($attrs, $formalAttrs, $nsManager);
            $extra = $attrs === []
                ? Attributes::empty()
                : $this->deserializeExtraAttributes($attrs, $nsManager) ?? Attributes::empty();
            $collection = $formal['prov:collection'] ?? null;
            foreach ($entities as $entityRef) {
                $records[] = new Membership(
                    $deserId,
                    $collection,
                    $this->resolveQName((string) $entityRef, $nsManager),
                    $extra,
                );
            }
            return;
        }

        $formal = $this->extractFormalAttrs($attrs, $formalAttrs, $nsManager);
        // Fast path: no extra attrs survived after formal extraction.
        $extra = $attrs === []
            ? Attributes::empty()
            : $this->deserializeExtraAttributes($attrs, $nsManager) ?? Attributes::empty();

        $record = match ($jsonKey) {
            'wasGeneratedBy' => new Generation(
                $deserId,
                $formal['prov:entity'] ?? null,
                $formal['prov:activity'] ?? null,
                $formal['prov:time'] ?? null,
                $extra,
            ),
            'used' => new Usage(
                $deserId,
                $formal['prov:activity'] ?? null,
                $formal['prov:entity'] ?? null,
                $formal['prov:time'] ?? null,
                $extra,
            ),
            'wasInformedBy' => new Communication(
                $deserId,
                $formal['prov:informed'] ?? null,
                $formal['prov:informant'] ?? null,
                $extra,
            ),
            'wasStartedBy' => new Start(
                $deserId,
                $formal['prov:activity'] ?? null,
                $formal['prov:trigger'] ?? null,
                $formal['prov:starter'] ?? null,
                $formal['prov:time'] ?? null,
                $extra,
            ),
            'wasEndedBy' => new End(
                $deserId,
                $formal['prov:activity'] ?? null,
                $formal['prov:trigger'] ?? null,
                $formal['prov:ender'] ?? null,
                $formal['prov:time'] ?? null,
                $extra,
            ),
            'wasInvalidatedBy' => new Invalidation(
                $deserId,
                $formal['prov:entity'] ?? null,
                $formal['prov:activity'] ?? null,
                $formal['prov:time'] ?? null,
                $extra,
            ),
            'wasDerivedFrom' => new Derivation(
                $deserId,
                $formal['prov:generatedEntity'] ?? null,
                $formal['prov:usedEntity'] ?? null,
                $formal['prov:activity'] ?? null,
                $formal['prov:generation'] ?? null,
                $formal['prov:usage'] ?? null,
                $extra,
            ),
            'wasAttributedTo' => new Attribution(
                $deserId,
                $formal['prov:entity'] ?? null,
                $formal['prov:agent'] ?? null,
                $extra,
            ),
            'wasAssociatedWith' => new Association(
                $deserId,
                $formal['prov:activity'] ?? null,
                $formal['prov:agent'] ?? null,
                $formal['prov:plan'] ?? null,
                $extra,
            ),
            'actedOnBehalfOf' => new Delegation(
                $deserId,
                $formal['prov:delegate'] ?? null,
                $formal['prov:responsible'] ?? null,
                $formal['prov:activity'] ?? null,
                $extra,
            ),
            'wasInfluencedBy' => new Influence(
                $deserId,
                $formal['prov:influencee'] ?? null,
                $formal['prov:influencer'] ?? null,
                $extra,
            ),
            'specializationOf' => new Specialization(
                $deserId,
                $formal['prov:specificEntity'] ?? null,
                $formal['prov:generalEntity'] ?? null,
                $extra,
            ),
            'alternateOf' => new Alternate(
                $deserId,
                $formal['prov:alternate1'] ?? null,
                $formal['prov:alternate2'] ?? null,
                $extra,
            ),
            'hadMember' => new Membership(
                $deserId,
                $formal['prov:collection'] ?? null,
                $formal['prov:entity'] ?? null,
                $extra,
            ),
            'mentionOf' => new Mention(
                $deserId,
                $formal['prov:specificEntity'] ?? null,
                $formal['prov:generalEntity'] ?? null,
                $formal['prov:bundle'] ?? null,
                $extra,
            ),
            'hadDictionaryMember' => new DictionaryMembership(
                $deserId,
                $formal['prov:dictionary'] ?? null,
                $this->deserializeKeyEntitySet($formal['prov:key-entity-set'] ?? null, $nsManager),
                $extra,
            ),
            'derivedByInsertionFrom' => new DictionaryInsertion(
                $deserId,
                $formal['prov:after'] ?? null,
                $formal['prov:before'] ?? null,
                $this->deserializeKeyEntitySet($formal['prov:key-entity-set'] ?? null, $nsManager),
                $extra,
            ),
            'derivedByRemovalFrom' => new DictionaryRemoval(
                $deserId,
                $formal['prov:after'] ?? null,
                $formal['prov:before'] ?? null,
                $this->deserializeKeySet($formal['prov:key-set'] ?? [], $nsManager),
                $extra,
            ),
            default => null,
        };
        if ($record !== null) {
            $records[] = $record;
        }
    }

    /**
     * @param list<mixed>|mixed $keys
     *
     * @return list<mixed>
     */
    private function deserializeKeySet(mixed $keys, NamespaceManager $nsManager): array
    {
        if (!is_array($keys)) {
            return [];
        }
        $out = [];
        foreach ($keys as $key) {
            if (is_array($key) && isset($key['$'])) {
                $out[] = $this->deserializeTypedValue($key, $nsManager);
            } else {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param list<string> $formalKeys
     *
     * @return array<string, mixed>
     */
    private function extractFormalAttrs(array &$attrs, array $formalKeys, NamespaceManager $nsManager): array
    {
        $formal = [];
        foreach ($formalKeys as $key) {
            if (!isset($attrs[$key])) {
                continue;
            }
            $value = $attrs[$key];
            if ($key === 'prov:time') {
                $formal[$key] = $this->parseDateTime($value);
            } elseif ($key === 'prov:key-entity-set' || $key === 'prov:key-set') {
                $formal[$key] = $value;
            } else {
                // Identifier reference: pre-resolve so the downstream record
                // constructor receives a QualifiedName directly.
                $formal[$key] = $this->resolveQName((string) $value, $nsManager);
            }
            unset($attrs[$key]);
        }
        return $formal;
    }

    /**
     * @return list<\Prov\Relation\Dictionary\DictionaryEntry>
     */
    private function deserializeKeyEntitySet(mixed $set, NamespaceManager $nsManager): array
    {
        if (!is_array($set) || $set === []) {
            return [];
        }

        $pairs = [];

        // Array-of-objects format: [{"key": {...}, "$": "ex:e0"}, ...]
        if (array_is_list($set)) {
            foreach ($set as $entry) {
                if (is_array($entry) && isset($entry['$'])) {
                    $rawKey = $entry['key'] ?? null;
                    $key = is_array($rawKey) && isset($rawKey['$'])
                        ? $this->deserializeTypedValue($rawKey, $nsManager)
                        : $rawKey;
                    $entity = is_string($entry['$']) ? $this->resolveQName($entry['$'], $nsManager) : null;
                    $pairs[] = new DictionaryEntry($key, $entity);
                }
            }
            return $pairs;
        }

        // Map format: {"$key-datatype": "xsd:string", "a": "ex:e0", ...}
        // $key-datatype promotes bare keys to typed values: prov:QUALIFIED_NAME makes them
        // QualifiedNames; any other datatype makes them Literals. xsd:string is the default
        // and stays as a bare string (the comparator normalizes it against xsd:string Literals).
        $keyDatatypeRaw = isset($set['$key-datatype']) && is_string($set['$key-datatype'])
            ? $set['$key-datatype']
            : null;
        $keyDatatype =
            $keyDatatypeRaw !== null && $keyDatatypeRaw !== 'xsd:string' && $keyDatatypeRaw !== 'prov:QUALIFIED_NAME'
                ? $this->resolveQName($keyDatatypeRaw, $nsManager)
                : null;
        $keysAsQn = $keyDatatypeRaw === 'prov:QUALIFIED_NAME';

        foreach ($set as $key => $value) {
            if ($key === '$key-datatype') {
                continue;
            }
            if ($keysAsQn) {
                $resolvedKey = $this->resolveQName((string) $key, $nsManager);
            } elseif ($keyDatatype !== null) {
                $resolvedKey = new Literal((string) $key, $keyDatatype);
            } else {
                $resolvedKey = $key;
            }
            $entity = is_string($value) ? $this->resolveQName($value, $nsManager) : null;
            $pairs[] = new DictionaryEntry($resolvedKey, $entity);
        }
        return $pairs;
    }

    /**
     * Converts the non-formal attribute entries of a record (everything left
     * after the formal properties of its type are stripped) into an Attributes
     * instance. Returns null if the input is empty.
     *
     * @param array<string, mixed> $attrs
     */
    private function deserializeExtraAttributes(array $attrs, NamespaceManager $nsManager): ?Attributes
    {
        if ($attrs === []) {
            return null;
        }

        // Accumulate into the raw URI-keyed shape and build Attributes once. Using
        // with() here would copy the backing array on every value (O(n^2) per record).
        $data = [];
        $keys = [];
        foreach ($attrs as $key => $value) {
            $keyName = $this->resolveQName($key, $nsManager);
            $uri = $keyName->getUri();
            $keys[$uri] ??= $keyName;
            if (is_array($value) && isset($value['$'])) {
                $data[$uri][] = $this->deserializeTypedValue($value, $nsManager);
            } elseif (is_array($value) && !isset($value['$'])) {
                foreach ($value as $item) {
                    if (is_array($item) && isset($item['$'])) {
                        $data[$uri][] = $this->deserializeTypedValue($item, $nsManager);
                    } else {
                        $data[$uri][] = $item;
                    }
                }
            } else {
                $data[$uri][] = $value;
            }
        }

        return new Attributes($data, $keys);
    }

    /**
     * Parses a PROV-JSON dateTime, accepting either a bare string or a typed
     * object (`{"$": "...", "type": "xsd:dateTime"}`). Any malformed or
     * non-string value surfaces as a DeserializationException rather than a
     * leaked \DateMalformedStringException or \TypeError.
     */
    private function parseDateTime(mixed $value): \DateTimeImmutable
    {
        if (is_array($value) && isset($value['$']) && is_string($value['$'])) {
            $value = $value['$'];
        }
        if (!is_string($value)) {
            throw new DeserializationException('Invalid PROV-JSON: expected a dateTime string.');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\DateException $e) {
            throw new DeserializationException("Invalid PROV-JSON: malformed dateTime '{$value}'.", previous: $e);
        }
    }

    private function deserializeTypedValue(array $value, NamespaceManager $nsManager): QualifiedName|Literal
    {
        // The "$" member carries the lexical value and must be a string; a
        // numeric or structured value here is malformed and would otherwise
        // surface as a \TypeError out of resolve()/Literal construction.
        $lexical = $value['$'] ?? null;
        if (!is_string($lexical)) {
            throw new DeserializationException('Invalid PROV-JSON: typed value "$" must be a string.');
        }

        $type = $value['type'] ?? null;
        if ($type !== null && !is_string($type)) {
            throw new DeserializationException('Invalid PROV-JSON: typed value "type" must be a string.');
        }

        // prov:QUALIFIED_NAME is the PROV-JSON-native tag; xsd:QName is the equivalent
        // from PROV-XML that some JSON fixtures emit. Both indicate a QualifiedName.
        if ($type === 'prov:QUALIFIED_NAME' || $type === 'xsd:QName') {
            return $this->resolveQName($lexical, $nsManager);
        }

        $datatype = $type !== null ? $this->resolveQName($type, $nsManager) : null;
        $lang = $value['lang'] ?? null;
        if ($lang !== null && !is_string($lang)) {
            throw new DeserializationException('Invalid PROV-JSON: typed value "lang" must be a string.');
        }

        return new Literal($lexical, $datatype, $lang);
    }
}
