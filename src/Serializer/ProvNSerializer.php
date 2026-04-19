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
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
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
 * Writes Documents in PROV-N, the W3C's human-readable textual notation
 * for PROV. Paired with `ProvNDeserializer` for the read direction.
 *
 * @mago-ignore analysis:unused-method
 */
class ProvNSerializer implements ProvSerializerInterface
{
    public function __construct(
        public readonly int $indentation = 2,
        public readonly bool $includeDefaultNamespace = true,
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

        $indent = $this->indentPrefix();
        $lines = [];
        $lines[] = 'document';

        foreach ($document->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                if ($this->includeDefaultNamespace) {
                    $lines[] = $indent . "default <{$ns->uri}>";
                }
            } else {
                $lines[] = $indent . "prefix {$ns->prefix} <{$ns->uri}>";
            }
        }

        if ($document->namespaces !== []) {
            $lines[] = '';
        }

        foreach ($document->records as $record) {
            $line = $this->serializeRecord($record, $nsManager);
            if ($line !== null) {
                $lines[] = $indent . $line;
            }
        }

        foreach ($document->bundles as $bundle) {
            $lines[] = '';
            $this->serializeBundle($bundle, $lines, $nsManager);
        }

        $lines[] = 'endDocument';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function serializeBundle(Bundle $bundle, array &$lines, NamespaceManager $parentNsManager): void
    {
        $nsManager = new NamespaceManager($parentNsManager);
        foreach ($bundle->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $nsManager->setDefault($ns);
            } else {
                $nsManager->add($ns);
            }
        }

        $indent = $this->indentPrefix();
        $indent2 = $indent . $indent;
        $lines[] = $indent . "bundle {$bundle->identifier}";

        foreach ($bundle->namespaces as $ns) {
            if ($ns->prefix === 'default') {
                if ($this->includeDefaultNamespace) {
                    $lines[] = $indent2 . "default <{$ns->uri}>";
                }
            } else {
                $lines[] = $indent2 . "prefix {$ns->prefix} <{$ns->uri}>";
            }
        }

        if ($bundle->namespaces !== []) {
            $lines[] = '';
        }

        foreach ($bundle->records as $record) {
            $line = $this->serializeRecord($record, $nsManager);
            if ($line !== null) {
                $lines[] = $indent2 . $line;
            }
        }

        $lines[] = $indent . 'endBundle';
    }

    /**
     * Dispatches a record to its per-type serialize method via a hash lookup on
     * the concrete class name.
     *
     * @var array<class-string<\Prov\Model\ProvRecord>, string>
     */
    private const array RECORD_DISPATCH = [
        Entity::class => 'serializeEntity',
        Activity::class => 'serializeActivity',
        Agent::class => 'serializeAgent',
        Generation::class => 'serializeGeneration',
        Usage::class => 'serializeUsage',
        Communication::class => 'serializeCommunication',
        Start::class => 'serializeStart',
        End::class => 'serializeEnd',
        Invalidation::class => 'serializeInvalidation',
        Derivation::class => 'serializeDerivation',
        Attribution::class => 'serializeAttribution',
        Association::class => 'serializeAssociation',
        Delegation::class => 'serializeDelegation',
        Influence::class => 'serializeInfluence',
        Specialization::class => 'serializeSpecialization',
        Alternate::class => 'serializeAlternate',
        Membership::class => 'serializeMembership',
        Mention::class => 'serializeMention',
        DictionaryMembership::class => 'serializeDictMembership',
        DictionaryInsertion::class => 'serializeDictInsertion',
        DictionaryRemoval::class => 'serializeDictRemoval',
    ];

    private function serializeRecord(ProvRecord $record, NamespaceManager $nsManager): ?string
    {
        $method = self::RECORD_DISPATCH[$record::class] ?? null;
        if ($method === null) {
            return null;
        }
        // @mago-expect analysis:string-member-selector
        // @mago-expect analysis:mixed-assignment
        $result = $this->$method($record, $nsManager);
        assert(is_string($result));
        return $result;
    }

    private function serializeEntity(Entity $entity, NamespaceManager $nsManager): string
    {
        $id = $entity->identifier !== null ? (string) $entity->identifier : '-';
        if ($entity->attributes->isEmpty()) {
            return "entity({$id})";
        }
        return "entity({$id}" . $this->formatAttributes($entity->attributes, $nsManager) . ')';
    }

    private function serializeActivity(Activity $activity, NamespaceManager $nsManager): string
    {
        $id = $activity->identifier !== null ? (string) $activity->identifier : '-';
        $time = '';
        if ($activity->startTime !== null || $activity->endTime !== null) {
            $start = $activity->startTime !== null ? $activity->startTime->format(\DateTimeInterface::ATOM) : '-';
            $end = $activity->endTime !== null ? $activity->endTime->format(\DateTimeInterface::ATOM) : '-';
            $time = ", {$start}, {$end}";
        }
        if ($activity->attributes->isEmpty()) {
            return "activity({$id}{$time})";
        }
        return "activity({$id}{$time}" . $this->formatAttributes($activity->attributes, $nsManager) . ')';
    }

    private function serializeAgent(Agent $agent, NamespaceManager $nsManager): string
    {
        $id = $agent->identifier !== null ? (string) $agent->identifier : '-';
        if ($agent->attributes->isEmpty()) {
            return "agent({$id})";
        }
        return "agent({$id}" . $this->formatAttributes($agent->attributes, $nsManager) . ')';
    }

    private function serializeGeneration(Generation $gen, NamespaceManager $nsManager): string
    {
        $entity = $gen->entity !== null ? (string) $gen->entity : '-';
        $activity = $gen->activity !== null ? (string) $gen->activity : '-';
        $time = $gen->time !== null ? $gen->time->format(\DateTimeInterface::ATOM) : '-';
        $prefix = $gen->identifier !== null
            ? 'wasGeneratedBy(' . (string) $gen->identifier . "; {$entity}, {$activity}, {$time}"
            : "wasGeneratedBy({$entity}, {$activity}, {$time}";
        if ($gen->attributes->isEmpty()) {
            return $prefix . ')';
        }
        return $prefix . $this->formatAttributes($gen->attributes, $nsManager) . ')';
    }

    private function serializeUsage(Usage $usage, NamespaceManager $nsManager): string
    {
        $activity = $usage->activity !== null ? (string) $usage->activity : '-';
        $entity = $usage->entity !== null ? (string) $usage->entity : '-';
        $time = $usage->time !== null ? $usage->time->format(\DateTimeInterface::ATOM) : '-';
        $prefix = $usage->identifier !== null
            ? 'used(' . (string) $usage->identifier . "; {$activity}, {$entity}, {$time}"
            : "used({$activity}, {$entity}, {$time}";
        if ($usage->attributes->isEmpty()) {
            return $prefix . ')';
        }
        return $prefix . $this->formatAttributes($usage->attributes, $nsManager) . ')';
    }

    private function serializeCommunication(Communication $comm, NamespaceManager $nsManager): string
    {
        $informed = $comm->informed !== null ? (string) $comm->informed : '-';
        $informant = $comm->informant !== null ? (string) $comm->informant : '-';
        $prefix = $comm->identifier !== null
            ? 'wasInformedBy(' . (string) $comm->identifier . "; {$informed}, {$informant}"
            : "wasInformedBy({$informed}, {$informant}";
        return $comm->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($comm->attributes, $nsManager) . ')';
    }

    private function serializeStart(Start $start, NamespaceManager $nsManager): string
    {
        $activity = $start->activity !== null ? (string) $start->activity : '-';
        $trigger = $start->trigger !== null ? (string) $start->trigger : '-';
        $starter = $start->starter !== null ? (string) $start->starter : '-';
        $time = $start->time !== null ? $start->time->format(\DateTimeInterface::ATOM) : '-';
        $prefix = $start->identifier !== null
            ? 'wasStartedBy(' . (string) $start->identifier . "; {$activity}, {$trigger}, {$starter}, {$time}"
            : "wasStartedBy({$activity}, {$trigger}, {$starter}, {$time}";
        return $start->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($start->attributes, $nsManager) . ')';
    }

    private function serializeEnd(End $end, NamespaceManager $nsManager): string
    {
        $activity = $end->activity !== null ? (string) $end->activity : '-';
        $trigger = $end->trigger !== null ? (string) $end->trigger : '-';
        $ender = $end->ender !== null ? (string) $end->ender : '-';
        $time = $end->time !== null ? $end->time->format(\DateTimeInterface::ATOM) : '-';
        $prefix = $end->identifier !== null
            ? 'wasEndedBy(' . (string) $end->identifier . "; {$activity}, {$trigger}, {$ender}, {$time}"
            : "wasEndedBy({$activity}, {$trigger}, {$ender}, {$time}";
        return $end->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($end->attributes, $nsManager) . ')';
    }

    private function serializeInvalidation(Invalidation $inv, NamespaceManager $nsManager): string
    {
        $entity = $inv->entity !== null ? (string) $inv->entity : '-';
        $activity = $inv->activity !== null ? (string) $inv->activity : '-';
        $time = $inv->time !== null ? $inv->time->format(\DateTimeInterface::ATOM) : '-';
        $prefix = $inv->identifier !== null
            ? 'wasInvalidatedBy(' . (string) $inv->identifier . "; {$entity}, {$activity}, {$time}"
            : "wasInvalidatedBy({$entity}, {$activity}, {$time}";
        return $inv->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($inv->attributes, $nsManager) . ')';
    }

    private function serializeDerivation(Derivation $der, NamespaceManager $nsManager): string
    {
        $generatedEntity = $der->generatedEntity !== null ? (string) $der->generatedEntity : '-';
        $usedEntity = $der->usedEntity !== null ? (string) $der->usedEntity : '-';
        $activity = $der->activity !== null ? (string) $der->activity : '-';
        $generation = $der->generation !== null ? (string) $der->generation : '-';
        $usage = $der->usage !== null ? (string) $der->usage : '-';
        $prefix = $der->identifier !== null
            ? 'wasDerivedFrom('
            . (string) $der->identifier
            . "; {$generatedEntity}, {$usedEntity}, {$activity}, {$generation}, {$usage}"
            : "wasDerivedFrom({$generatedEntity}, {$usedEntity}, {$activity}, {$generation}, {$usage}";
        return $der->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($der->attributes, $nsManager) . ')';
    }

    private function serializeAttribution(Attribution $attr, NamespaceManager $nsManager): string
    {
        $entity = $attr->entity !== null ? (string) $attr->entity : '-';
        $agent = $attr->agent !== null ? (string) $attr->agent : '-';
        $prefix = $attr->identifier !== null
            ? 'wasAttributedTo(' . (string) $attr->identifier . "; {$entity}, {$agent}"
            : "wasAttributedTo({$entity}, {$agent}";
        return $attr->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($attr->attributes, $nsManager) . ')';
    }

    private function serializeAssociation(Association $assoc, NamespaceManager $nsManager): string
    {
        $activity = $assoc->activity !== null ? (string) $assoc->activity : '-';
        $agent = $assoc->agent !== null ? (string) $assoc->agent : '-';
        $plan = $assoc->plan !== null ? (string) $assoc->plan : '-';
        $prefix = $assoc->identifier !== null
            ? 'wasAssociatedWith(' . (string) $assoc->identifier . "; {$activity}, {$agent}, {$plan}"
            : "wasAssociatedWith({$activity}, {$agent}, {$plan}";
        return $assoc->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($assoc->attributes, $nsManager) . ')';
    }

    private function serializeDelegation(Delegation $del, NamespaceManager $nsManager): string
    {
        $delegate = $del->delegate !== null ? (string) $del->delegate : '-';
        $responsible = $del->responsible !== null ? (string) $del->responsible : '-';
        $activity = $del->activity !== null ? (string) $del->activity : '-';
        $prefix = $del->identifier !== null
            ? 'actedOnBehalfOf(' . (string) $del->identifier . "; {$delegate}, {$responsible}, {$activity}"
            : "actedOnBehalfOf({$delegate}, {$responsible}, {$activity}";
        return $del->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($del->attributes, $nsManager) . ')';
    }

    private function serializeInfluence(Influence $inf, NamespaceManager $nsManager): string
    {
        $influencee = $inf->influencee !== null ? (string) $inf->influencee : '-';
        $influencer = $inf->influencer !== null ? (string) $inf->influencer : '-';
        $prefix = $inf->identifier !== null
            ? 'wasInfluencedBy(' . (string) $inf->identifier . "; {$influencee}, {$influencer}"
            : "wasInfluencedBy({$influencee}, {$influencer}";
        return $inf->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($inf->attributes, $nsManager) . ')';
    }

    private function serializeSpecialization(Specialization $spec, NamespaceManager $nsManager): string
    {
        $specific = $spec->specificEntity !== null ? (string) $spec->specificEntity : '-';
        $general = $spec->generalEntity !== null ? (string) $spec->generalEntity : '-';
        return $spec->attributes->isEmpty()
            ? "specializationOf({$specific}, {$general})"
            : "specializationOf({$specific}, {$general}" . $this->formatAttributes($spec->attributes, $nsManager) . ')';
    }

    private function serializeAlternate(Alternate $alt, NamespaceManager $nsManager): string
    {
        $a1 = $alt->alternate1 !== null ? (string) $alt->alternate1 : '-';
        $a2 = $alt->alternate2 !== null ? (string) $alt->alternate2 : '-';
        return $alt->attributes->isEmpty()
            ? "alternateOf({$a1}, {$a2})"
            : "alternateOf({$a1}, {$a2}" . $this->formatAttributes($alt->attributes, $nsManager) . ')';
    }

    private function serializeMembership(Membership $mem, NamespaceManager $nsManager): string
    {
        $collection = $mem->collection !== null ? (string) $mem->collection : '-';
        $entity = $mem->entity !== null ? (string) $mem->entity : '-';
        return $mem->attributes->isEmpty()
            ? "hadMember({$collection}, {$entity})"
            : "hadMember({$collection}, {$entity}" . $this->formatAttributes($mem->attributes, $nsManager) . ')';
    }

    private function serializeMention(Mention $men, NamespaceManager $nsManager): string
    {
        $specific = $men->specificEntity !== null ? (string) $men->specificEntity : '-';
        $general = $men->generalEntity !== null ? (string) $men->generalEntity : '-';
        $bundle = $men->bundle !== null ? (string) $men->bundle : '-';
        return $men->attributes->isEmpty()
            ? "mentionOf({$specific}, {$general}, {$bundle})"
            : "mentionOf({$specific}, {$general}, {$bundle}"
            . $this->formatAttributes($men->attributes, $nsManager)
            . ')';
    }

    // @mago-expect analysis:unused-parameter
    private function serializeDictMembership(DictionaryMembership $dm, NamespaceManager $nsManager): string
    {
        $lines = [];
        foreach ($dm->keyEntityPairs as $pair) {
            $dict = $this->formatOptionalId($dm->dictionary);
            $entity = $pair->entity !== null ? (string) $pair->entity : '-';
            $key = $this->formatDictKey($pair->key);
            $lines[] = "prov:hadDictionaryMember({$dict}, {$entity}, {$key})";
        }
        return implode("\n" . str_repeat(' ', $this->indentation), $lines);
    }

    private function serializeDictInsertion(DictionaryInsertion $di, NamespaceManager $nsManager): string
    {
        $after = $this->formatOptionalId($di->after);
        $before = $this->formatOptionalId($di->before);
        $set = $this->formatKeyEntitySet($di->keyEntityPairs);
        $attrs = $this->formatAttributes($di->attributes, $nsManager);

        if ($di->identifier !== null) {
            return "prov:derivedByInsertionFrom({$di->identifier}; {$after}, {$before}, {$set}{$attrs})";
        }
        return "prov:derivedByInsertionFrom({$after}, {$before}, {$set}{$attrs})";
    }

    private function serializeDictRemoval(DictionaryRemoval $dr, NamespaceManager $nsManager): string
    {
        $after = $this->formatOptionalId($dr->after);
        $before = $this->formatOptionalId($dr->before);
        $set = $this->formatKeySet($dr->removedKeys);
        $attrs = $this->formatAttributes($dr->attributes, $nsManager);

        if ($dr->identifier !== null) {
            return "prov:derivedByRemovalFrom({$dr->identifier}; {$after}, {$before}, {$set}{$attrs})";
        }
        return "prov:derivedByRemovalFrom({$after}, {$before}, {$set}{$attrs})";
    }

    /**
     * Formats a list of dictionary entries as the PROV-N `{(key, entity), ...}`
     * set used by derivedByInsertionFrom.
     *
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $pairs
     */
    private function formatKeyEntitySet(array $pairs): string
    {
        if ($pairs === []) {
            return '{}';
        }
        $items = [];
        foreach ($pairs as $pair) {
            $key = $this->formatDictKey($pair->key);
            $entity = $pair->entity !== null ? (string) $pair->entity : '-';
            $items[] = "({$key}, {$entity})";
        }
        return '{' . implode(', ', $items) . '}';
    }

    /**
     * Formats a list of removed keys as the PROV-N `{key, ...}` set used
     * by derivedByRemovalFrom.
     *
     * @param list<mixed> $keys
     */
    private function formatKeySet(array $keys): string
    {
        if ($keys === []) {
            return '{}';
        }
        $items = [];
        // @mago-expect analysis:mixed-assignment
        foreach ($keys as $key) {
            $items[] = $this->formatDictKey($key);
        }
        return '{' . implode(', ', $items) . '}';
    }

    /**
     * Formats the full `DictionaryEntry::$key` union (QN/Literal/scalar/array/null)
     * as a PROV-N token. Arrays carry a raw JSON typed value from deserialization.
     */
    private function formatDictKey(mixed $key): string
    {
        if ($key === null) {
            return '-';
        }
        if (is_array($key)) {
            $val = is_scalar($key['$'] ?? null) ? (string) $key['$'] : '';
            $type = isset($key['type']) && is_string($key['type']) ? $key['type'] : null;
            $lang = isset($key['lang']) && is_string($key['lang']) ? $key['lang'] : null;
            if ($type !== null) {
                return '"' . $this->escapeString($val) . '" %% ' . $type;
            }
            if ($lang !== null) {
                return '"' . $this->escapeString($val) . '"@' . $lang;
            }
            return '"' . $this->escapeString($val) . '"';
        }
        if ($key instanceof QualifiedName || $key instanceof Literal || is_scalar($key)) {
            return $this->formatAttributeValue($key);
        }
        return '-';
    }

    private function formatOptionalId(?QualifiedName $id): string
    {
        return $id !== null ? (string) $id : '-';
    }

    private function formatAttributes(Attributes $attributes, NamespaceManager $nsManager): string
    {
        if ($attributes->isEmpty()) {
            return '';
        }

        $pairs = [];
        foreach ($attributes->all() as $uri => $values) {
            $key = $nsManager->uriToPrefixed($uri);
            foreach ($values as $value) {
                $formattedValue = $this->formatAttributeValue($value);
                $pairs[] = "{$key} = {$formattedValue}";
            }
        }

        return ', [' . implode(', ', $pairs) . ']';
    }

    private function formatAttributeValue(QualifiedName|Literal|string|int|float|bool $value): string
    {
        if ($value instanceof QualifiedName) {
            return "'" . (string) $value . "'";
        }

        if ($value instanceof Literal) {
            $str = '"' . $this->escapeString($value->value) . '"';
            if ($value->datatype !== null) {
                $str .= ' %% ' . (string) $value->datatype;
            }
            if ($value->languageTag !== null) {
                $str .= "@{$value->languageTag}";
            }
            return $str;
        }

        if (is_bool($value)) {
            return $value ? '"true" %% xsd:boolean' : '"false" %% xsd:boolean';
        }

        if (is_int($value)) {
            return "\"{$value}\" %% xsd:int";
        }

        if (is_float($value)) {
            return "\"{$value}\" %% xsd:float";
        }

        return '"' . $this->escapeString($value) . '"';
    }

    private function escapeString(string $s): string
    {
        return str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    }

    private function indentPrefix(): string
    {
        return $this->indentPrefix ??= str_repeat(' ', $this->indentation);
    }

    private ?string $indentPrefix = null;
}
