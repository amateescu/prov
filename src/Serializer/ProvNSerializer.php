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
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\Alternate;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Dictionary\DictionaryRemoval;
use Prov\Relation\Membership;
use Prov\Relation\Mention;
use Prov\Relation\Specialization;

/**
 * Writes Documents in PROV-N, the W3C's human-readable textual notation
 * for PROV. Paired with `ProvNDeserializer` for the read direction.
 */
class ProvNSerializer implements ProvSerializerInterface
{
    /**
     * PROV-N productions without an optional identifier slot; an identifier
     * on such a record is dropped, matching the grammar.
     *
     * @var array<class-string<\Prov\Model\ProvRelation>, true>
     */
    private const array RELATIONS_WITHOUT_ID = [
        Specialization::class => true,
        Alternate::class => true,
        Membership::class => true,
        Mention::class => true,
    ];

    private readonly string $indentPrefix;

    private ?PrefixMinter $minter = null;

    public function __construct(
        public readonly int $indentation = 2,
        public readonly bool $includeDefaultNamespace = true,
        public readonly bool $sortRecords = false,
    ) {
        if ($this->indentation < 0) {
            throw new \InvalidArgumentException('Indentation must be a non-negative number of spaces.');
        }
        $this->indentPrefix = str_repeat(' ', $this->indentation);
    }

    /**
     * {@inheritdoc}
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $nsManager = NamespaceManager::forContainer($document->namespaces);
        $minter = new PrefixMinter($nsManager);
        $this->minter = $minter;

        $indent = $this->indentPrefix;

        // The body is rendered before the prefix block so that namespaces minted
        // for undeclared attribute keys can still be declared in the header.
        $bodyLines = [];
        $records = $this->sortRecords ? OutputOrder::records($document->records) : $document->records;
        foreach ($records as $record) {
            $line = $this->serializeRecord($record, $nsManager);
            if ($line !== null) {
                $bodyLines[] = $indent . $line;
            }
        }

        foreach ($document->bundles as $bundle) {
            $bodyLines[] = '';
            $this->serializeBundle($bundle, $bodyLines, $nsManager);
        }

        $lines = [];
        $lines[] = 'document';

        $namespaces = OutputOrder::namespaces([...$document->namespaces, ...$minter->getMintedNamespaces()]);
        foreach ($namespaces as $ns) {
            $this->assertSafeNamespace($ns);
            if ($ns->prefix === 'default') {
                if ($this->includeDefaultNamespace) {
                    $lines[] = $indent . "default <{$ns->uri}>";
                }
            } else {
                $lines[] = $indent . "prefix {$ns->prefix} <{$ns->uri}>";
            }
        }

        if ($namespaces !== []) {
            $lines[] = '';
        }

        $lines = array_merge($lines, $bodyLines);
        $lines[] = 'endDocument';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function serializeBundle(Bundle $bundle, array &$lines, NamespaceManager $parentNsManager): void
    {
        $nsManager = NamespaceManager::forContainer($bundle->namespaces, $parentNsManager);

        $indent = $this->indentPrefix;
        $indent2 = $indent . $indent;
        $lines[] = $indent . 'bundle ' . $this->formatQualifiedName($bundle->identifier);

        foreach (OutputOrder::namespaces($bundle->namespaces) as $ns) {
            $this->assertSafeNamespace($ns);
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

        $records = $this->sortRecords ? OutputOrder::records($bundle->records) : $bundle->records;
        foreach ($records as $record) {
            $line = $this->serializeRecord($record, $nsManager);
            if ($line !== null) {
                $lines[] = $indent2 . $line;
            }
        }

        $lines[] = $indent . 'endBundle';
    }

    private function serializeRecord(ProvRecord $record, NamespaceManager $nsManager): ?string
    {
        return match (true) {
            $record instanceof Entity => $this->serializeEntity($record, $nsManager),
            $record instanceof Activity => $this->serializeActivity($record, $nsManager),
            $record instanceof Agent => $this->serializeAgent($record, $nsManager),
            $record instanceof DictionaryMembership => $this->serializeDictMembership($record),
            $record instanceof DictionaryInsertion => $this->serializeDictInsertion($record, $nsManager),
            $record instanceof DictionaryRemoval => $this->serializeDictRemoval($record, $nsManager),
            $record instanceof ProvRelation && isset(RelationMetadata::FORMALS[$record::class])
                => $this->serializeRelation($record, $nsManager),
            default => null,
        };
    }

    private function serializeEntity(Entity $entity, NamespaceManager $nsManager): string
    {
        $id = $this->formatOptionalId($entity->identifier);
        if ($entity->attributes->isEmpty()) {
            return "entity({$id})";
        }
        return "entity({$id}" . $this->formatAttributes($entity->attributes, $nsManager) . ')';
    }

    private function serializeActivity(Activity $activity, NamespaceManager $nsManager): string
    {
        $id = $this->formatOptionalId($activity->identifier);
        $time = '';
        if ($activity->startTime !== null || $activity->endTime !== null) {
            $start = $activity->startTime !== null ? Literal::formatDateTime($activity->startTime) : '-';
            $end = $activity->endTime !== null ? Literal::formatDateTime($activity->endTime) : '-';
            $time = ", {$start}, {$end}";
        }
        if ($activity->attributes->isEmpty()) {
            return "activity({$id}{$time})";
        }
        return "activity({$id}{$time}" . $this->formatAttributes($activity->attributes, $nsManager) . ')';
    }

    private function serializeAgent(Agent $agent, NamespaceManager $nsManager): string
    {
        $id = $this->formatOptionalId($agent->identifier);
        if ($agent->attributes->isEmpty()) {
            return "agent({$id})";
        }
        return "agent({$id}" . $this->formatAttributes($agent->attributes, $nsManager) . ')';
    }

    /**
     * Serializes any standard relation from its RelationMetadata definition:
     * `keyword(id; ref, ..., time, [attrs])` with `-` for absent formals and
     * the `id;` slot omitted for anonymous relations (and always for the
     * productions in RELATIONS_WITHOUT_ID). The PROV-DICT relations have
     * set-valued arguments and keep dedicated methods.
     */
    private function serializeRelation(ProvRelation $record, NamespaceManager $nsManager): string
    {
        $keyword = RelationMetadata::JSON_KEYS[$record::class];
        $formals = RelationMetadata::extractFormals($record);

        $parts = [];
        foreach (RelationMetadata::FORMALS[$record::class] as $prop => $type) {
            // @mago-expect analysis:mixed-assignment
            $value = $formals[$prop];
            if ($type === 'ref') {
                $parts[] = $this->formatOptionalId($value instanceof QualifiedName ? $value : null);
            } elseif ($type === 'time') {
                $parts[] = $value instanceof \DateTimeImmutable ? Literal::formatDateTime($value) : '-';
            }
        }
        $args = implode(', ', $parts);

        $identifier = isset(self::RELATIONS_WITHOUT_ID[$record::class]) ? null : $record->identifier;
        $prefix = $identifier !== null
            ? $keyword . '(' . $this->formatQualifiedName($identifier) . '; ' . $args
            : $keyword . '(' . $args;

        return $record->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($record->attributes, $nsManager) . ')';
    }

    private function serializeDictMembership(DictionaryMembership $dm): string
    {
        $lines = [];
        foreach ($dm->keyEntityPairs as $pair) {
            $dict = $this->formatOptionalId($dm->dictionary);
            $entity = $this->formatOptionalId($pair->entity);
            $key = $this->formatDictKey($pair->key);
            $lines[] = "prov:hadDictionaryMember({$dict}, {$entity}, {$key})";
        }
        return implode("\n" . $this->indentPrefix, $lines);
    }

    private function serializeDictInsertion(DictionaryInsertion $di, NamespaceManager $nsManager): string
    {
        $after = $this->formatOptionalId($di->after);
        $before = $this->formatOptionalId($di->before);
        $set = $this->formatKeyEntitySet($di->keyEntityPairs);
        $attrs = $this->formatAttributes($di->attributes, $nsManager);

        if ($di->identifier !== null) {
            return (
                'prov:derivedByInsertionFrom('
                . $this->formatQualifiedName($di->identifier)
                . "; {$after}, {$before}, {$set}{$attrs})"
            );
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
            return (
                'prov:derivedByRemovalFrom('
                . $this->formatQualifiedName($dr->identifier)
                . "; {$after}, {$before}, {$set}{$attrs})"
            );
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
            $entity = $this->formatOptionalId($pair->entity);
            $items[] = "({$key}, {$entity})";
        }
        return '{' . implode(', ', $items) . '}';
    }

    /**
     * Formats a list of removed keys as the PROV-N `{key, ...}` set used
     * by derivedByRemovalFrom.
     *
     * @param list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool> $keys
     */
    private function formatKeySet(array $keys): string
    {
        if ($keys === []) {
            return '{}';
        }
        $items = [];
        foreach ($keys as $key) {
            $items[] = $this->formatDictKey($key);
        }
        return '{' . implode(', ', $items) . '}';
    }

    /**
     * Formats the full `DictionaryEntry::$key` union (QN/Literal/scalar/null)
     * as a PROV-N token.
     */
    private function formatDictKey(QualifiedName|Literal|string|int|float|bool|null $key): string
    {
        if ($key === null) {
            return '-';
        }
        return $this->formatAttributeValue($key);
    }

    /**
     * Stringifies an identifier for emission, escaping the local-name punctuation
     * the grammar permits when backslash-escaped and rejecting any character that
     * remains unrepresentable. This is the single chokepoint through which every
     * qualified name reaches the output, so validation happens inline as the
     * document is written (no separate pass).
     */
    private function formatQualifiedName(QualifiedName $qn): string
    {
        $local = $this->escapeLocalPart($qn->localPart);

        // A default-namespace name is written bare; a blank-node label keeps its
        // reserved "_" prefix. Neither needs (or can take) a declaration.
        if ($qn->namespace->prefix === 'default') {
            return $local;
        }
        if ($qn->isBlank()) {
            return $qn->namespace->prefix . ':' . $local;
        }

        // Route through the minter so an otherwise-undeclared namespace gets a
        // declaration emitted in the header, keeping the output parseable.
        $prefix = $this->minter !== null ? $this->minter->prefixFor($qn) : $qn->namespace->prefix;

        // PN_PREFIX has no escape mechanism, so an unsafe prefix is unrepresentable.
        if ($this->hasUnsafeChars($prefix)) {
            throw new \InvalidArgumentException(
                "Identifier '{$qn}' has a prefix that cannot be represented in PROV-N.",
            );
        }

        return $prefix . ':' . $local;
    }

    /**
     * Backslash-escapes `PN_CHARS_ESC` punctuation in a local name, rejecting
     * any character that has no escape in the grammar.
     */
    private function escapeLocalPart(string $local): string
    {
        // QualifiedNameEscaper::escape() rejects a literal backslash (PN_CHARS_ESC
        // has no escape for it, so it would alias a different name on round trip).
        $escaped = QualifiedNameEscaper::escape($local);
        if ($this->hasUnsafeChars($escaped)) {
            throw new \InvalidArgumentException(
                "Local name '{$local}' contains a character that cannot be represented in PROV-N.",
            );
        }
        return $escaped;
    }

    /**
     * Escapes the local part of a `prefix:local` (or bare-local) attribute key,
     * leaving the prefix untouched.
     */
    private function escapeAttributeKey(string $key): string
    {
        $colon = strpos($key, ':');
        if ($colon === false) {
            return $this->escapeLocalPart($key);
        }
        return substr($key, 0, $colon + 1) . $this->escapeLocalPart(substr($key, $colon + 1));
    }

    private function formatOptionalId(?QualifiedName $id): string
    {
        return $id !== null ? $this->formatQualifiedName($id) : '-';
    }

    private function formatAttributes(Attributes $attributes, NamespaceManager $nsManager): string
    {
        if ($attributes->isEmpty()) {
            return '';
        }

        $pairs = [];
        foreach ($attributes->all() as $uri => $values) {
            $key = $this->minter !== null
                ? $this->minter->uriToPrefixed($uri, $nsManager)
                : $nsManager->uriToPrefixed($uri);
            $key = NamespaceManager::stripDefaultSentinel($key);
            $key = $this->escapeAttributeKey($key);
            $this->assertSafeAttributeKey($key);
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
            return "'" . $this->formatQualifiedName($value) . "'";
        }

        if ($value instanceof Literal) {
            $str = '"' . $this->escapeString($value->value) . '"';
            if ($value->datatype !== null) {
                $str .= ' %% ' . $this->formatQualifiedName($value->datatype);
            }
            if ($value->languageTag !== null) {
                $this->assertSafeLangTag($value->languageTag);
                $str .= "@{$value->languageTag}";
            }
            return $str;
        }

        if (is_bool($value)) {
            return $value ? '"true" %% xsd:boolean' : '"false" %% xsd:boolean';
        }

        if (is_int($value)) {
            $datatype = $value < Literal::XSD_INT_MIN || $value > Literal::XSD_INT_MAX ? 'xsd:long' : 'xsd:int';
            return "\"{$value}\" %% {$datatype}";
        }

        if (is_float($value)) {
            return '"' . Literal::formatFloat($value) . '" %% xsd:float';
        }

        return '"' . $this->escapeString($value) . '"';
    }

    /**
     * PROV-N delimiter, quoting and whitespace characters. A qualified name or prefix
     * containing any of these would let crafted input break out of its token and inject
     * records, so a document carrying one is rejected rather than serialized.
     */
    private const string PROVN_UNSAFE_PUNCTUATION = "()[]{}<>\"'=,;:|^`\\";

    private function assertSafeNamespace(\Prov\Identifier\ProvNamespace $ns): void
    {
        if ($ns->prefix !== 'default' && $this->hasUnsafeChars($ns->prefix)) {
            throw new \InvalidArgumentException("Namespace prefix '{$ns->prefix}' cannot be represented in PROV-N.");
        }
        if ($this->isUnsafeUri($ns->uri)) {
            throw new \InvalidArgumentException("Namespace URI '{$ns->uri}' cannot be represented in PROV-N.");
        }
    }

    private function assertSafeAttributeKey(string $key): void
    {
        // The prefix ":" and URI characters are fine before " = ", but list or quoting
        // delimiters and whitespace/control bytes (except NUL) would break the attribute
        // list. Built once; backslash-escaped delimiters are read back verbatim, so drop
        // "\X" pairs first when present.
        static $unsafe = '';
        if ($unsafe === '') {
            $unsafe = "()[]{}<>\"'=,;";
            for ($byte = 1; $byte <= 0x20; $byte++) {
                $unsafe .= chr($byte);
            }
            $unsafe .= "\x7f";
        }
        $bare = $key;
        if (str_contains($bare, '\\')) {
            $bare = (string) preg_replace('/\\\\./s', '', $bare);
        }
        if (strpbrk($bare, $unsafe) !== false || str_contains($bare, "\x00")) {
            throw new \InvalidArgumentException("Attribute key '{$key}' cannot be represented in PROV-N.");
        }
    }

    private function assertSafeLangTag(string $lang): void
    {
        if (preg_match('/^[a-zA-Z0-9-]+$/', $lang) !== 1) {
            throw new \InvalidArgumentException("Language tag '{$lang}' is not a valid PROV-N language tag.");
        }
    }

    private function hasUnsafeChars(string $text): bool
    {
        // Structural punctuation plus every whitespace/control byte except NUL (which a
        // C-string needle cannot carry, so str_contains() checks it separately). Built once.
        static $unsafe = '';
        if ($unsafe === '') {
            $unsafe = self::PROVN_UNSAFE_PUNCTUATION;
            for ($byte = 1; $byte <= 0x20; $byte++) {
                $unsafe .= chr($byte);
            }
            $unsafe .= "\x7f";
        }
        // PROV-N permits delimiter characters in a local name when backslash-escaped (the
        // parser reads them back verbatim); strip "\X" pairs first, but only when present.
        if (str_contains($text, '\\')) {
            $text = (string) preg_replace('/\\\\./s', '', $text);
        }
        return strpbrk($text, $unsafe) !== false || str_contains($text, "\x00");
    }

    private function isUnsafeUri(string $uri): bool
    {
        // A namespace URI sits inside "<...>", so only angle brackets, double quotes,
        // whitespace and control characters can break out; other URI punctuation is fine.
        return strpbrk($uri, '<>"') !== false || preg_match('/[\x00-\x20\x7f]/', $uri) === 1;
    }

    private function escapeString(string $s): string
    {
        return str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $s);
    }
}
