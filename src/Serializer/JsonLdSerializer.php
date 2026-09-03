<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\ProvException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvElement;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\Dictionary\DictionaryEntry;

/**
 * Writes a Document as PROV-JSONLD (the JSON-LD encoding of PROV-O). This
 * format is serialize-only per the W3C specification; there is no matching
 * deserializer.
 *
 * JSON-LD has one `@context` for the whole document, so this serializer works
 * in one namespace scope: the document's declarations plus every bundle
 * declaration whose prefix is free there. A bundle prefix that rebinds a
 * document prefix cannot be promoted, and names under it get a minted prefix
 * instead, so every compact IRI in the output expands to the URI the model
 * carries.
 *
 * The context always binds prov and xsd to their canonical namespaces, because
 * the serializer writes prov:* and xsd:* terms of its own. A document that
 * binds either prefix elsewhere keeps that namespace under a minted prefix.
 *
 * PROV-O models specializationOf, alternateOf, hadMember and mentionOf as plain
 * object properties with no qualified form, and PROV-Dictionary does the same
 * for hadDictionaryMember. Those relations have nowhere to put an identifier or
 * attributes, so a record carrying either is rejected rather than written
 * without them.
 */
class JsonLdSerializer implements ProvSerializerInterface
{
    private const string PROV_URI = 'http://www.w3.org/ns/prov#';
    private const string XSD_URI = 'http://www.w3.org/2001/XMLSchema#';

    private BlankLabelMinter $blankLabelMinter;

    private PrefixMinter $minter;

    public function __construct(
        public readonly bool $prettyPrint = false,
        public readonly bool $sortRecords = false,
    ) {
        $this->blankLabelMinter = new BlankLabelMinter(new Document([], [], []));
        $this->minter = new PrefixMinter(new NamespaceManager());
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Prov\Exception\ProvException
     *   When a relation carries an identifier or attributes that PROV-JSONLD
     *   has nowhere to put.
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $this->blankLabelMinter = new BlankLabelMinter($document);

        $nsManager = self::contextManager($document);
        $minter = new PrefixMinter($nsManager);
        $this->minter = $minter;

        $this->promoteBundleNamespaces($document, $nsManager);

        $documentRecords = $this->sortRecords ? OutputOrder::records($document->records) : $document->records;
        $graph = $this->buildGraph($documentRecords, $nsManager);

        foreach ($document->bundles as $bundle) {
            $bundleRecords = $this->sortRecords ? OutputOrder::records($bundle->records) : $bundle->records;
            $bundleNode = [
                '@id' => $this->jsonLdId($bundle->identifier, $nsManager),
                '@type' => 'prov:Bundle',
                '@graph' => $this->buildGraph($bundleRecords, $nsManager),
            ];
            $graph[] = $bundleNode;
        }

        // Built last: the manager has collected every promoted and minted
        // binding the body wrote by then.
        $context = OutputOrder::prefixMap($this->buildContext($nsManager));

        $output = ['@context' => $context];
        if (count($graph) === 1 && !isset($graph[0]['@graph'])) {
            $output = array_merge($output, $graph[0]);
        } else {
            $output['@graph'] = $graph;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        // JSON_THROW_ON_ERROR guarantees string-or-throw, never false.
        $encoded = json_encode($output, $flags);
        assert(is_string($encoded));
        return $encoded;
    }

    /**
     * Promotes each bundle's namespace declarations into the document-wide
     * context manager when their prefix is still free there. A bundle prefix
     * that rebinds a document prefix is left out: promoting it would change what
     * the document-level prefix means. Names under such a prefix go through the
     * minter, which gives them one that is free.
     *
     * A bundle's default namespace is never promoted either: `@vocab` is
     * document-wide, and identifiers in a default namespace are written as full
     * URIs anyway.
     */
    private function promoteBundleNamespaces(Document $document, NamespaceManager $context): void
    {
        foreach ($document->bundles as $bundle) {
            foreach ($bundle->namespaces as $ns) {
                if ($ns->prefix === 'default') {
                    continue;
                }
                if ($context->getNamespace($ns->prefix) === null) {
                    $context->addOrReplace($ns);
                }
            }
        }
    }

    /**
     * The namespace scope the document's names are written against.
     *
     * The context binds prov and xsd to their canonical namespaces because the
     * serializer writes prov:* and xsd:* terms itself. A document that binds
     * either prefix to another namespace keeps that namespace, but not the
     * prefix: the declaration stays out of the scope, so `PrefixMinter` gives
     * its names a prefix of their own and every compact IRI still expands to
     * the URI the model carries.
     */
    private static function contextManager(Document $document): NamespaceManager
    {
        $namespaces = [];
        foreach ($document->namespaces as $ns) {
            if (($ns->prefix === 'prov' || $ns->prefix === 'xsd') && !$ns->isCanonicalReservedBinding()) {
                continue;
            }
            $namespaces[] = $ns;
        }
        return NamespaceManager::forContainer($namespaces);
    }

    /**
     * Builds the JSON-LD `@context` block from the namespace declarations the
     * document wrote against. The library's "default" prefix maps to `@vocab`.
     * The prov and xsd namespaces are always included, and are written last so
     * nothing can rebind them: the serializer emits prov:* and xsd:* terms
     * structurally, and unlike the library's deserializers, an external JSON-LD
     * consumer has no built-in bindings for them.
     *
     * @return array<string, string>
     */
    private function buildContext(NamespaceManager $context): array
    {
        $bindings = [];
        foreach ($context->getRegisteredNamespaces() as $ns) {
            if ($ns->prefix === 'default') {
                $bindings['@vocab'] = $ns->uri;
            } else {
                $bindings[$ns->prefix] = $ns->uri;
            }
        }
        $bindings['prov'] = self::PROV_URI;
        $bindings['xsd'] = self::XSD_URI;
        return $bindings;
    }

    /**
     * Builds the JSON-LD `@graph` array from a list of records in two passes:
     * first create a node per element, then attach each relation to its
     * subject node as a property (qualified or shortcut form).
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return list<array<string, mixed>>
     *
     * @throws \Prov\Exception\ProvException
     *   When a relation carries a value PROV-JSONLD cannot represent.
     */
    private function buildGraph(array $records, NamespaceManager $nsManager): array
    {
        // Collect element nodes indexed by URI.
        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = [];

        // First pass: create nodes for all elements. An identifier-less
        // element has no QualifiedName, so no relation can point at it, but
        // skipping it here would drop its own attributes from the output.
        // JSON-LD represents an anonymous node fine, so mint it a blank id,
        // the same way JsonSerializer does.
        foreach ($records as $record) {
            if ($record instanceof ProvElement) {
                $id = $record->identifier !== null
                    ? $this->jsonLdId($record->identifier, $nsManager)
                    : $this->blankLabelMinter->labelFor($record);
                if (!isset($nodes[$id])) {
                    $nodes[$id] = ['@id' => $id];
                }
                $node = &$nodes[$id];

                $node['@type'] = match (true) {
                    $record instanceof Entity => 'prov:Entity',
                    $record instanceof Activity => 'prov:Activity',
                    $record instanceof Agent => 'prov:Agent',
                    default => throw new \LogicException('Unknown ProvElement subtype: ' . $record::class),
                };

                if ($record instanceof Activity) {
                    if ($record->startTime !== null) {
                        $node['prov:startedAtTime'] = $this->formatDateTime($record->startTime);
                    }
                    if ($record->endTime !== null) {
                        $node['prov:endedAtTime'] = $this->formatDateTime($record->endTime);
                    }
                }

                $this->addAttributes($node, $record->attributes, $nsManager);
                unset($node);
            }
        }

        // Second pass: attach relations to subject nodes.
        foreach ($records as $record) {
            if (!$record instanceof ProvRelation) {
                continue;
            }

            $this->attachRelation($record, $nodes, $nsManager);
        }

        return array_values($nodes);
    }

    /**
     * Attaches a relation to its subject node: in qualified form (a nested
     * node typed per PROV-O) when the relation carries an identifier, extra
     * attributes, or secondary formals, and as a plain object property
     * otherwise. The encoding is table-driven by RelationMetadata::JSONLD.
     *
     * JSON-LD attaches a relation as a property of its subject node, so a
     * relation whose subject formal is null has no node to attach to. One that
     * carries nothing else states nothing and is skipped; one carrying an
     * identifier, attributes, or another formal is rejected.
     *
     * @param array<string, array<string, mixed>> $nodes
     *
     * @throws \Prov\Exception\ProvException
     *   When the relation has no subject to attach to and still carries
     *   content, or has no qualified form and is missing the object formal or
     *   carrying an identifier or attributes that form is the only place for.
     */
    private function attachRelation(ProvRelation $relation, array &$nodes, NamespaceManager $nsManager): void
    {
        $spec = RelationMetadata::JSONLD[$relation::class] ?? null;
        if ($spec === null) {
            throw new ProvException(
                'PROV-JSONLD has no encoding for ' . $relation::class . '; it would be dropped from the output.',
            );
        }

        $formals = RelationMetadata::extractFormals($relation);
        $subjectProp = array_key_first($formals);
        // @mago-expect analysis:mixed-assignment
        $subject = $subjectProp !== null ? $formals[$subjectProp] : null;
        if (!$subject instanceof QualifiedName) {
            $this->assertSubjectLessRelationIsEmpty($relation, $formals, $subjectProp);
            return;
        }
        $subjectId = $this->jsonLdId($subject, $nsManager);
        $this->ensureNode($nodes, $subjectId);

        $properties = $spec['properties'];
        $objectProp = array_key_first($properties);
        // @mago-expect analysis:mixed-assignment
        $object = $objectProp !== null ? $formals[$objectProp] ?? null : null;

        if ($spec['qualifiedProperty'] === null) {
            $this->assertNoUnrepresentableMetadata($relation, $spec['shortcutProperty']);
            if ($objectProp !== null && !$this->isPopulatedFormal($object)) {
                throw new ProvException(
                    'PROV-JSONLD writes a '
                    . $relation::class
                    . " as the plain object property {$spec['shortcutProperty']}, which has no qualified form, "
                    . "so a record with no {$objectProp} has nothing left to write. "
                    . "Serialize to PROV-JSON, PROV-N, or PROV-XML instead, or set the {$objectProp}.",
                );
            }
            foreach ($properties as $prop => $jsonLdProperty) {
                $property = $jsonLdProperty === '' ? $spec['shortcutProperty'] : $jsonLdProperty;
                // @mago-expect analysis:mixed-assignment
                $value = $formals[$prop] ?? null;
                if ($value instanceof QualifiedName) {
                    $this->appendProperty($nodes[$subjectId], $property, $this->idRef($value, $nsManager));
                } elseif (is_array($value)) {
                    // @mago-expect analysis:mixed-assignment
                    foreach ($this->dictionaryValues($value, $nsManager) as $item) {
                        $this->appendProperty($nodes[$subjectId], $property, $item);
                    }
                }
            }
            return;
        }

        $hasExtraFormals = false;
        foreach (array_keys($properties) as $prop) {
            // @mago-expect analysis:mixed-assignment
            $value = $formals[$prop] ?? null;
            if ($prop !== $objectProp && $this->isPopulatedFormal($value)) {
                $hasExtraFormals = true;
                break;
            }
        }

        // A relation with no object formal still gets the qualified node: PROV-O
        // allows a qualified node without its object, and the shortcut property
        // needs an object to point at, so this is the shape that keeps the
        // relation and everything it carries.
        if (
            $relation->identifier !== null
            || !$relation->attributes->isEmpty()
            || $hasExtraFormals
            || !$object instanceof QualifiedName
        ) {
            $qNode = $this->makeQualifiedNode((string) $spec['type'], $relation, $nsManager);
            foreach ($properties as $prop => $jsonLdProperty) {
                // @mago-expect analysis:mixed-assignment
                $value = $formals[$prop] ?? null;
                if ($value instanceof QualifiedName) {
                    $qNode[$jsonLdProperty] = $this->idRef($value, $nsManager);
                } elseif ($value instanceof \DateTimeImmutable) {
                    $qNode[$jsonLdProperty] = $this->formatDateTime($value);
                } elseif (is_array($value) && $value !== []) {
                    $qNode[$jsonLdProperty] = $this->dictionaryValues($value, $nsManager);
                }
            }
            $this->appendProperty($nodes[$subjectId], $spec['qualifiedProperty'], $qNode);
        } else {
            $this->appendProperty($nodes[$subjectId], $spec['shortcutProperty'], $this->idRef($object, $nsManager));
        }
    }

    /**
     * Rejects a relation with no subject that still carries content.
     *
     * JSON-LD writes a relation as a property of its subject node, so a
     * relation with no subject has no node to write it on. One that carries
     * nothing else states nothing and is dropped; one carrying an identifier,
     * attributes, or another formal would lose that content, so serialization
     * fails instead.
     *
     * @param array<string, mixed> $formals
     *
     * @throws \Prov\Exception\ProvException
     */
    private function assertSubjectLessRelationIsEmpty(
        ProvRelation $relation,
        array $formals,
        ?string $subjectProp,
    ): void {
        $lost = [];
        if ($relation->identifier !== null) {
            $lost[] = "identifier '{$relation->identifier}'";
        }
        if (!$relation->attributes->isEmpty()) {
            $lost[] = 'attributes';
        }
        $populated = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($formals as $prop => $value) {
            if ($prop !== $subjectProp && $this->isPopulatedFormal($value)) {
                $populated[] = $prop;
            }
        }
        if ($populated !== []) {
            $lost[] = implode(' and ', $populated);
        }
        if ($lost === []) {
            return;
        }

        $named = $subjectProp !== null ? " ({$subjectProp})" : '';
        throw new ProvException(
            'PROV-JSONLD writes a relation as a property of its subject node, and this '
            . $relation::class
            . " has no subject{$named}, so its "
            . implode(', ', $lost)
            . ' would be dropped. Serialize to PROV-JSON, PROV-N, or PROV-XML instead, or set the subject'
            . $named
            . '.',
        );
    }

    /**
     * Whether a formal holds a value: a set reference or time, or a non-empty
     * dictionary key set. Null and an empty set count as absent.
     */
    private function isPopulatedFormal(mixed $value): bool
    {
        return $value !== null && $value !== [];
    }

    /**
     * Rejects a relation whose identifier or attributes have nowhere to go.
     * PROV-O models specializationOf, alternateOf, hadMember and mentionOf as
     * plain object properties, and PROV-Dictionary does the same for
     * hadDictionaryMember: the statement is one triple on the subject node, with
     * no node of its own to carry an identifier or extra attributes. Writing the
     * triple anyway would silently drop model data, so the whole serialization
     * fails instead.
     *
     * @throws \Prov\Exception\ProvException
     */
    private function assertNoUnrepresentableMetadata(ProvRelation $relation, string $property): void
    {
        $lost = [];
        if ($relation->identifier !== null) {
            $lost[] = "identifier '{$relation->identifier}'";
        }
        if (!$relation->attributes->isEmpty()) {
            $lost[] = 'attributes';
        }
        if ($lost === []) {
            return;
        }

        throw new ProvException(
            'PROV-JSONLD cannot represent the '
            . implode(' and ', $lost)
            . ' of a '
            . $relation::class
            . ": it is written as the plain object property {$property}, which has no qualified form to carry them. "
            . 'Serialize to PROV-JSON, PROV-N, or PROV-XML instead, or drop the value.',
        );
    }

    /**
     * Encodes an array-typed formal: a dictionary's key/entity pairs as
     * prov:KeyEntityPair nodes, and a removal's removed keys as plain values.
     *
     * @param array<array-key, mixed> $items
     *
     * @return list<mixed>
     */
    private function dictionaryValues(array $items, NamespaceManager $nsManager): array
    {
        $out = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($items as $item) {
            if ($item instanceof DictionaryEntry) {
                $pair = ['@type' => 'prov:KeyEntityPair'];
                if ($item->key !== null) {
                    $pair['prov:pairKey'] = $this->serializeValue($item->key, $nsManager);
                }
                if ($item->entity !== null) {
                    $pair['prov:pairEntity'] = $this->idRef($item->entity, $nsManager);
                }
                $out[] = $pair;
            } elseif ($item instanceof QualifiedName || $item instanceof Literal || is_scalar($item)) {
                $out[] = $this->serializeValue($item, $nsManager);
            }
        }
        return $out;
    }

    // Helpers

    /**
     * @return array<string, mixed>
     */
    private function makeQualifiedNode(string $type, ProvRelation $relation, NamespaceManager $nsManager): array
    {
        $node = ['@type' => $type];
        if ($relation->identifier !== null) {
            $node['@id'] = $this->jsonLdId($relation->identifier, $nsManager);
        }
        $this->addAttributes($node, $relation->attributes, $nsManager);
        return $node;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function addAttributes(array &$node, Attributes $attributes, NamespaceManager $nsManager): void
    {
        if ($attributes->isEmpty()) {
            return;
        }

        foreach ($attributes->all() as $uri => $values) {
            $key = $this->minter->uriToPrefixed($uri, $nsManager);
            $key = NamespaceManager::stripDefaultSentinel($key);
            foreach ($values as $value) {
                $this->appendProperty($node, $key, $this->serializeValue($value, $nsManager));
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function appendProperty(array &$node, string $key, mixed $value): void
    {
        if (!isset($node[$key])) {
            $node[$key] = $value;
        } elseif (is_array($node[$key]) && array_is_list($node[$key])) {
            $node[$key][] = $value;
        } else {
            $node[$key] = [$node[$key], $value];
        }
    }

    /**
     * Ensures a node exists at `$id` in the graph, initializing it with
     * just `@id` if the URI hasn't been seen yet.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function ensureNode(array &$nodes, string $id): void
    {
        if (!isset($nodes[$id])) {
            $nodes[$id] = ['@id' => $id];
        }
    }

    /**
     * The IRI to write for an identifier. JSON-LD resolves a relative `@id`
     * against the document base (not `@vocab`), so a default-namespace
     * identifier is emitted as its full URI; the reserved `default:` prefix is
     * never written. A blank node keeps its `_:` label, which JSON-LD reads as a
     * blank node identifier. Every other name goes through the minter, so the
     * prefix it is written with is one the emitted `@context` binds to the
     * name's own namespace URI.
     */
    private function jsonLdId(QualifiedName $qn, NamespaceManager $context): string
    {
        if ($qn->namespace->prefix === 'default') {
            return $qn->getUri();
        }
        return $this->minter->token($qn, $context);
    }

    /**
     * @return array{'@id': string}
     */
    private function idRef(QualifiedName $qn, NamespaceManager $context): array
    {
        return ['@id' => $this->jsonLdId($qn, $context)];
    }

    /**
     * @return array{'@value': string, '@type': string}
     */
    private function formatDateTime(\DateTimeImmutable $dt): array
    {
        return [
            '@value' => Literal::formatDateTime($dt),
            '@type' => 'xsd:dateTime',
        ];
    }

    private function serializeValue(
        QualifiedName|Literal|string|int|float|bool $value,
        NamespaceManager $context,
    ): mixed {
        if ($value instanceof QualifiedName) {
            return ['@id' => $this->jsonLdId($value, $context)];
        }

        if ($value instanceof Literal) {
            $result = ['@value' => $value->value];
            if ($value->datatype !== null) {
                $result['@type'] = $this->jsonLdId($value->datatype, $context);
            }
            if ($value->languageTag !== null) {
                $result['@language'] = $value->languageTag;
            }
            return $result;
        }

        return $value;
    }
}
