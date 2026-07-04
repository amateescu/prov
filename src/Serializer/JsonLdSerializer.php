<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvElement;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\Mention;

/**
 * Writes a Document as PROV-JSONLD (the JSON-LD encoding of PROV-O). This
 * format is serialize-only per the W3C specification; there is no matching
 * deserializer.
 */
class JsonLdSerializer implements ProvSerializerInterface
{
    private BlankLabelMinter $blankLabelMinter;

    private ?PrefixMinter $minter = null;

    public function __construct(
        public readonly bool $prettyPrint = false,
        public readonly bool $sortRecords = false,
    ) {
        $this->blankLabelMinter = new BlankLabelMinter(new Document([], [], []));
    }

    /**
     * {@inheritdoc}
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $this->blankLabelMinter = new BlankLabelMinter($document);

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

        $context = $this->buildContext($document);
        $documentRecords = $this->sortRecords ? OutputOrder::records($document->records) : $document->records;
        $graph = $this->buildGraph($documentRecords, $nsManager);

        foreach ($document->bundles as $bundle) {
            $bundleNsManager = new NamespaceManager($nsManager);
            foreach ($bundle->namespaces as $ns) {
                if ($ns->prefix === 'default') {
                    $bundleNsManager->setDefault($ns);
                } else {
                    $bundleNsManager->addOrReplace($ns);
                }
            }

            $bundleRecords = $this->sortRecords ? OutputOrder::records($bundle->records) : $bundle->records;
            $bundleNode = [
                '@id' => $this->jsonLdId($bundle->identifier),
                '@type' => 'prov:Bundle',
                '@graph' => $this->buildGraph($bundleRecords, $bundleNsManager),
            ];
            $graph[] = $bundleNode;
        }

        foreach ($minter->getMintedNamespaces() as $ns) {
            $context[$ns->prefix] = $ns->uri;
        }
        $context = OutputOrder::prefixMap($context);

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
     * Builds the JSON-LD `@context` block from the document's namespace
     * declarations. The library's "default" prefix maps to `@vocab`. The prov
     * and xsd namespaces are always included: the serializer emits prov:* and
     * xsd:* terms structurally, and unlike the library's deserializers, an
     * external JSON-LD consumer has no built-in bindings for them.
     *
     * @return array<string, string>
     */
    private function buildContext(Document $document): array
    {
        $context = [
            'prov' => 'http://www.w3.org/ns/prov#',
            'xsd' => 'http://www.w3.org/2001/XMLSchema#',
        ];
        foreach ($document->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $context['@vocab'] = $ns->uri;
            } else {
                $context[$ns->prefix] = $ns->uri;
            }
        }

        return $context;
    }

    /**
     * Builds the JSON-LD `@graph` array from a list of records in two passes:
     * first create a node per element, then attach each relation to its
     * subject node as a property (qualified or shortcut form).
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return list<array<string, mixed>>
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
                    ? $this->jsonLdId($record->identifier)
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
     * Mention keeps a hand-written shape (its object nests prov:asInBundle),
     * and the Dictionary extension relations have no PROV-O shortcut form.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachRelation(ProvRelation $relation, array &$nodes, NamespaceManager $nsManager): void
    {
        if ($relation instanceof Mention) {
            $this->attachMention($relation, $nodes);
            return;
        }

        $spec = RelationMetadata::JSONLD[$relation::class] ?? null;
        if ($spec === null) {
            return;
        }

        $formals = RelationMetadata::extractFormals($relation);
        $subjectProp = array_key_first($formals);
        // @mago-expect analysis:mixed-assignment
        $subject = $subjectProp !== null ? $formals[$subjectProp] : null;
        if (!$subject instanceof QualifiedName) {
            return;
        }
        $subjectId = $this->jsonLdId($subject);
        $this->ensureNode($nodes, $subjectId);

        $properties = $spec['properties'];
        $objectProp = array_key_first($properties);
        // @mago-expect analysis:mixed-assignment
        $object = $objectProp !== null ? $formals[$objectProp] ?? null : null;

        if ($spec['qualifiedProperty'] === null) {
            // Plain object property (specializationOf, alternateOf, hadMember).
            if ($object instanceof QualifiedName) {
                $this->appendProperty($nodes[$subjectId], $spec['shortcutProperty'], $this->idRef($object));
            }
            return;
        }

        $hasExtraFormals = false;
        foreach (array_keys($properties) as $prop) {
            if ($prop !== $objectProp && ($formals[$prop] ?? null) !== null) {
                $hasExtraFormals = true;
                break;
            }
        }

        if ($relation->identifier !== null || !$relation->attributes->isEmpty() || $hasExtraFormals) {
            $qNode = $this->makeQualifiedNode((string) $spec['type'], $relation, $nsManager);
            foreach ($properties as $prop => $jsonLdProperty) {
                // @mago-expect analysis:mixed-assignment
                $value = $formals[$prop] ?? null;
                if ($value instanceof QualifiedName) {
                    $qNode[$jsonLdProperty] = $this->idRef($value);
                } elseif ($value instanceof \DateTimeImmutable) {
                    $qNode[$jsonLdProperty] = $this->formatDateTime($value);
                }
            }
            $this->appendProperty($nodes[$subjectId], $spec['qualifiedProperty'], $qNode);
        } elseif ($object instanceof QualifiedName) {
            $this->appendProperty($nodes[$subjectId], $spec['shortcutProperty'], $this->idRef($object));
        }
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function attachMention(Mention $men, array &$nodes): void
    {
        $subjectId = $men->specificEntity !== null ? $this->jsonLdId($men->specificEntity) : null;
        $general = $men->generalEntity;
        if ($subjectId === null || $general === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);
        $value = $this->idRef($general);
        if ($men->bundle !== null) {
            $value = [
                'prov:asInBundle' => $this->idRef($men->bundle),
                'prov:mentionOf' => $this->idRef($general),
            ];
        }
        $this->appendProperty($nodes[$subjectId], 'prov:mentionOf', $value);
    }

    // Helpers

    /**
     * @return array<string, mixed>
     */
    private function makeQualifiedNode(string $type, ProvRelation $relation, NamespaceManager $nsManager): array
    {
        $node = ['@type' => $type];
        if ($relation->identifier !== null) {
            $node['@id'] = $this->jsonLdId($relation->identifier);
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
            $key = $this->minter !== null
                ? $this->minter->uriToPrefixed($uri, $nsManager)
                : $nsManager->uriToPrefixed($uri);
            // The reserved `default:` prefix is never written; the bare local
            // term expands against the context's `@vocab` (the default namespace).
            if (str_starts_with($key, 'default:')) {
                $key = substr($key, strlen('default:'));
            }
            foreach ($values as $value) {
                $this->appendProperty($node, $key, $this->serializeValue($value));
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
     * never written. Prefixed names expand against their `@context` entry.
     */
    private function jsonLdId(QualifiedName $qn): string
    {
        return $qn->namespace->prefix === 'default' ? $qn->getUri() : (string) $qn;
    }

    /**
     * @return array{'@id': string}
     */
    private function idRef(QualifiedName $qn): array
    {
        return ['@id' => $this->jsonLdId($qn)];
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

    private function serializeValue(QualifiedName|Literal|string|int|float|bool $value): mixed
    {
        if ($value instanceof QualifiedName) {
            return ['@id' => $this->jsonLdId($value)];
        }

        if ($value instanceof Literal) {
            $result = ['@value' => $value->value];
            if ($value->datatype !== null) {
                $result['@type'] = $this->jsonLdId($value->datatype);
            }
            if ($value->languageTag !== null) {
                $result['@language'] = $value->languageTag;
            }
            return $result;
        }

        return $value;
    }
}
