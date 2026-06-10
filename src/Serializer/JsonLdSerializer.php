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
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
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
 * Writes a Document as PROV-JSONLD (the JSON-LD encoding of PROV-O). This
 * format is serialize-only per the W3C specification; there is no matching
 * deserializer.
 */
class JsonLdSerializer implements ProvSerializerInterface
{
    public function __construct(
        public readonly bool $prettyPrint = false,
    ) {}

    /**
     * {@inheritdoc}
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $nsManager = new NamespaceManager();
        foreach ($document->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $nsManager->setDefault($ns);
            } else {
                $nsManager->add($ns);
            }
        }

        $context = $this->buildContext($document);
        $graph = $this->buildGraph($document->records, $nsManager);

        foreach ($document->bundles as $bundle) {
            $bundleNsManager = new NamespaceManager($nsManager);
            foreach ($bundle->namespaces as $ns) {
                if ($ns->prefix === 'default') {
                    $bundleNsManager->setDefault($ns);
                } else {
                    $bundleNsManager->add($ns);
                }
            }

            $bundleNode = [
                '@id' => (string) $bundle->identifier,
                '@type' => 'prov:Bundle',
                '@graph' => $this->buildGraph($bundle->records, $bundleNsManager),
            ];
            $graph[] = $bundleNode;
        }

        $output = ['@context' => $context];
        if (count($graph) === 1 && !isset($graph[0]['@graph'])) {
            $output = array_merge($output, $graph[0]);
        } else {
            $output['@graph'] = $graph;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
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
     * declarations. The library's "default" prefix maps to `@vocab`.
     *
     * @return array<string, string>
     */
    private function buildContext(Document $document): array
    {
        $context = [];
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

        // First pass: create nodes for all elements.
        foreach ($records as $record) {
            if ($record instanceof ProvElement && $record->identifier !== null) {
                $id = (string) $record->identifier;
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
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachRelation(ProvRelation $relation, array &$nodes, NamespaceManager $nsManager): void
    {
        match (true) {
            $relation instanceof Generation => $this->attachGeneration($relation, $nodes, $nsManager),
            $relation instanceof Usage => $this->attachUsage($relation, $nodes, $nsManager),
            $relation instanceof Communication => $this->attachCommunication($relation, $nodes, $nsManager),
            $relation instanceof Start => $this->attachStart($relation, $nodes, $nsManager),
            $relation instanceof End => $this->attachEnd($relation, $nodes, $nsManager),
            $relation instanceof Invalidation => $this->attachInvalidation($relation, $nodes, $nsManager),
            $relation instanceof Derivation => $this->attachDerivation($relation, $nodes, $nsManager),
            $relation instanceof Attribution => $this->attachAttribution($relation, $nodes, $nsManager),
            $relation instanceof Association => $this->attachAssociation($relation, $nodes, $nsManager),
            $relation instanceof Delegation => $this->attachDelegation($relation, $nodes, $nsManager),
            $relation instanceof Influence => $this->attachInfluence($relation, $nodes, $nsManager),
            $relation instanceof Specialization => $this->attachSpecialization($relation, $nodes),
            $relation instanceof Alternate => $this->attachAlternate($relation, $nodes),
            $relation instanceof Membership => $this->attachMembership($relation, $nodes),
            $relation instanceof Mention => $this->attachMention($relation, $nodes),
            default => null,
        };
    }

    // @mago-expect lint:no-boolean-flag-parameter
    private function needsQualification(ProvRelation $relation, bool $hasExtraFormals = false): bool
    {
        return $relation->identifier !== null || !$relation->attributes->isEmpty() || $hasExtraFormals;
    }

    /**
     * Generation: subject=entity, object=activity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachGeneration(Generation $gen, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $gen->entity !== null ? (string) $gen->entity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $gen->time !== null;
        if ($this->needsQualification($gen, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Generation', $gen, $nsManager);
            if ($gen->activity !== null) {
                $qNode['prov:activity'] = $this->idRef($gen->activity);
            }
            if ($gen->time !== null) {
                $qNode['prov:atTime'] = $this->formatDateTime($gen->time);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedGeneration', $qNode);
        } elseif ($gen->activity !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasGeneratedBy', $this->idRef($gen->activity));
        }
    }

    /**
     * Usage: subject=activity, object=entity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachUsage(Usage $usage, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $usage->activity !== null ? (string) $usage->activity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $usage->time !== null;
        if ($this->needsQualification($usage, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Usage', $usage, $nsManager);
            if ($usage->entity !== null) {
                $qNode['prov:entity'] = $this->idRef($usage->entity);
            }
            if ($usage->time !== null) {
                $qNode['prov:atTime'] = $this->formatDateTime($usage->time);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedUsage', $qNode);
        } elseif ($usage->entity !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:used', $this->idRef($usage->entity));
        }
    }

    /**
     * Communication: subject=informed, object=informant.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachCommunication(Communication $comm, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $comm->informed !== null ? (string) $comm->informed : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        if ($this->needsQualification($comm)) {
            $qNode = $this->makeQualifiedNode('prov:Communication', $comm, $nsManager);
            if ($comm->informant !== null) {
                $qNode['prov:activity'] = $this->idRef($comm->informant);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedCommunication', $qNode);
        } elseif ($comm->informant !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasInformedBy', $this->idRef($comm->informant));
        }
    }

    /**
     * Start: subject=activity, trigger=entity, starter=activity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachStart(Start $start, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $start->activity !== null ? (string) $start->activity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $start->time !== null || $start->starter !== null;
        if ($this->needsQualification($start, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Start', $start, $nsManager);
            if ($start->trigger !== null) {
                $qNode['prov:entity'] = $this->idRef($start->trigger);
            }
            if ($start->starter !== null) {
                $qNode['prov:hadActivity'] = $this->idRef($start->starter);
            }
            if ($start->time !== null) {
                $qNode['prov:atTime'] = $this->formatDateTime($start->time);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedStart', $qNode);
        } elseif ($start->trigger !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasStartedBy', $this->idRef($start->trigger));
        }
    }

    /**
     * End: subject=activity, trigger=entity, ender=activity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachEnd(End $end, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $end->activity !== null ? (string) $end->activity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $end->time !== null || $end->ender !== null;
        if ($this->needsQualification($end, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:End', $end, $nsManager);
            if ($end->trigger !== null) {
                $qNode['prov:entity'] = $this->idRef($end->trigger);
            }
            if ($end->ender !== null) {
                $qNode['prov:hadActivity'] = $this->idRef($end->ender);
            }
            if ($end->time !== null) {
                $qNode['prov:atTime'] = $this->formatDateTime($end->time);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedEnd', $qNode);
        } elseif ($end->trigger !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasEndedBy', $this->idRef($end->trigger));
        }
    }

    /**
     * Invalidation: subject=entity, object=activity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachInvalidation(Invalidation $inv, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $inv->entity !== null ? (string) $inv->entity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $inv->time !== null;
        if ($this->needsQualification($inv, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Invalidation', $inv, $nsManager);
            if ($inv->activity !== null) {
                $qNode['prov:activity'] = $this->idRef($inv->activity);
            }
            if ($inv->time !== null) {
                $qNode['prov:atTime'] = $this->formatDateTime($inv->time);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedInvalidation', $qNode);
        } elseif ($inv->activity !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasInvalidatedBy', $this->idRef($inv->activity));
        }
    }

    /**
     * Derivation: subject=generatedEntity, object=usedEntity.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachDerivation(Derivation $der, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $der->generatedEntity !== null ? (string) $der->generatedEntity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $der->activity !== null || $der->generation !== null || $der->usage !== null;
        if ($this->needsQualification($der, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Derivation', $der, $nsManager);
            if ($der->usedEntity !== null) {
                $qNode['prov:entity'] = $this->idRef($der->usedEntity);
            }
            if ($der->activity !== null) {
                $qNode['prov:hadActivity'] = $this->idRef($der->activity);
            }
            if ($der->generation !== null) {
                $qNode['prov:hadGeneration'] = $this->idRef($der->generation);
            }
            if ($der->usage !== null) {
                $qNode['prov:hadUsage'] = $this->idRef($der->usage);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedDerivation', $qNode);
        } elseif ($der->usedEntity !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasDerivedFrom', $this->idRef($der->usedEntity));
        }
    }

    /**
     * Attribution: subject=entity, object=agent.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachAttribution(Attribution $attr, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $attr->entity !== null ? (string) $attr->entity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        if ($this->needsQualification($attr)) {
            $qNode = $this->makeQualifiedNode('prov:Attribution', $attr, $nsManager);
            if ($attr->agent !== null) {
                $qNode['prov:agent'] = $this->idRef($attr->agent);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedAttribution', $qNode);
        } elseif ($attr->agent !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasAttributedTo', $this->idRef($attr->agent));
        }
    }

    /**
     * Association: subject=activity, object=agent.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachAssociation(Association $assoc, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $assoc->activity !== null ? (string) $assoc->activity : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $assoc->plan !== null;
        if ($this->needsQualification($assoc, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Association', $assoc, $nsManager);
            if ($assoc->agent !== null) {
                $qNode['prov:agent'] = $this->idRef($assoc->agent);
            }
            if ($assoc->plan !== null) {
                $qNode['prov:hadPlan'] = $this->idRef($assoc->plan);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedAssociation', $qNode);
        } elseif ($assoc->agent !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasAssociatedWith', $this->idRef($assoc->agent));
        }
    }

    /**
     * Delegation: subject=delegate, object=responsible.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachDelegation(Delegation $del, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $del->delegate !== null ? (string) $del->delegate : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        $hasExtraFormals = $del->activity !== null;
        if ($this->needsQualification($del, $hasExtraFormals)) {
            $qNode = $this->makeQualifiedNode('prov:Delegation', $del, $nsManager);
            if ($del->responsible !== null) {
                $qNode['prov:agent'] = $this->idRef($del->responsible);
            }
            if ($del->activity !== null) {
                $qNode['prov:hadActivity'] = $this->idRef($del->activity);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedDelegation', $qNode);
        } elseif ($del->responsible !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:actedOnBehalfOf', $this->idRef($del->responsible));
        }
    }

    /**
     * Influence: subject=influencee, object=influencer.
     *
     * @param array<string, array<string, mixed>> $nodes
     */
    private function attachInfluence(Influence $inf, array &$nodes, NamespaceManager $nsManager): void
    {
        $subjectId = $inf->influencee !== null ? (string) $inf->influencee : null;
        if ($subjectId === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);

        if ($this->needsQualification($inf)) {
            $qNode = $this->makeQualifiedNode('prov:Influence', $inf, $nsManager);
            if ($inf->influencer !== null) {
                $qNode['prov:influencer'] = $this->idRef($inf->influencer);
            }
            $this->appendProperty($nodes[$subjectId], 'prov:qualifiedInfluence', $qNode);
        } elseif ($inf->influencer !== null) {
            $this->appendProperty($nodes[$subjectId], 'prov:wasInfluencedBy', $this->idRef($inf->influencer));
        }
    }

    // Non-qualifiable binary relations

    /** @param array<string, array<string, mixed>> $nodes */
    private function attachSpecialization(Specialization $spec, array &$nodes): void
    {
        $subjectId = $spec->specificEntity !== null ? (string) $spec->specificEntity : null;
        if ($subjectId === null || $spec->generalEntity === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);
        $this->appendProperty($nodes[$subjectId], 'prov:specializationOf', $this->idRef($spec->generalEntity));
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function attachAlternate(Alternate $alt, array &$nodes): void
    {
        $subjectId = $alt->alternate1 !== null ? (string) $alt->alternate1 : null;
        if ($subjectId === null || $alt->alternate2 === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);
        $this->appendProperty($nodes[$subjectId], 'prov:alternateOf', $this->idRef($alt->alternate2));
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function attachMembership(Membership $mem, array &$nodes): void
    {
        $subjectId = $mem->collection !== null ? (string) $mem->collection : null;
        if ($subjectId === null || $mem->entity === null) {
            return;
        }
        $this->ensureNode($nodes, $subjectId);
        $this->appendProperty($nodes[$subjectId], 'prov:hadMember', $this->idRef($mem->entity));
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function attachMention(Mention $men, array &$nodes): void
    {
        $subjectId = $men->specificEntity !== null ? (string) $men->specificEntity : null;
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
            $node['@id'] = (string) $relation->identifier;
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
            $key = $nsManager->uriToPrefixed($uri);
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
     * @return array{'@id': string}
     */
    private function idRef(QualifiedName $qn): array
    {
        return ['@id' => (string) $qn];
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
            return ['@id' => (string) $value];
        }

        if ($value instanceof Literal) {
            $result = ['@value' => $value->value];
            if ($value->datatype !== null) {
                $result['@type'] = (string) $value->datatype;
            }
            if ($value->languageTag !== null) {
                $result['@language'] = $value->languageTag;
            }
            return $result;
        }

        return $value;
    }
}
