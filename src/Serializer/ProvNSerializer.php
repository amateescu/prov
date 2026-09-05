<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Attribute\ValueIdentity;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
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

    /**
     * The prefixes PROV-N binds implicitly and the exact namespace each one
     * names. The `xsd` binding carries the trailing `#`; a document that binds
     * `xsd` to the form without it names another namespace, and names under
     * it go through the minter. Literal datatypes are the exception:
     * `ValueIdentity::datatypeIn()` moves them to this binding.
     */
    private const array RESERVED_NAMESPACES = [
        'prov' => 'http://www.w3.org/ns/prov#',
        'xsd' => 'http://www.w3.org/2001/XMLSchema#',
    ];

    private readonly string $indentPrefix;

    private PrefixMinter $minter;

    /**
     * @param int $indentation
     *   Number of spaces per nesting level.
     * @param bool $includeDefaultNamespace
     *   Whether to write the `default <uri>` declaration. With it off, names in
     *   the default namespace are written through a minted prefix that the
     *   header declares, so the output still parses back to the same URIs. The
     *   output is never bare local names without a declaration.
     * @param bool $sortRecords
     *   Whether to order records into PROV-DM concept order instead of keeping
     *   the document's own order.
     */
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
    #[\Override]
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        $nsManager = $this->scopeManager($document->namespaces);
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

        $namespaces = OutputOrder::namespaces([...$document->namespaces, ...$minter->mintedNamespaces]);
        $declarations = $this->namespaceDeclarations($namespaces, $indent);
        if ($declarations !== []) {
            $lines = array_merge($lines, $declarations);
            $lines[] = '';
        }

        $lines = array_merge($lines, $bodyLines);
        $lines[] = 'endDocument';

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines */
    private function serializeBundle(Bundle $bundle, array &$lines, NamespaceManager $parentNsManager): void
    {
        $nsManager = $this->scopeManager($bundle->namespaces, $parentNsManager);

        $indent = $this->indentPrefix;
        $indent2 = $indent . $indent;
        $lines[] = $indent . 'bundle ' . $this->formatQualifiedName($bundle->identifier, $parentNsManager);

        $declarations = $this->namespaceDeclarations(OutputOrder::namespaces($bundle->namespaces), $indent2);
        if ($declarations !== []) {
            $lines = array_merge($lines, $declarations);
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

    /**
     * The namespace scope a container's names are written against.
     *
     * A declaration that binds `prov` or `xsd` to a namespace other than the
     * one in `RESERVED_NAMESPACES` is dropped, so `PrefixMinter` gives its
     * names a prefix of their own and the header declares it.
     *
     * With `includeDefaultNamespace` off no `default` declaration is written,
     * so the scope drops the default namespace too. `PrefixMinter` then finds
     * no scope default, mints a real prefix for names in that namespace, and
     * the header declares it.
     *
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     */
    private function scopeManager(array $namespaces, ?NamespaceManager $parent = null): NamespaceManager
    {
        $namespaces = array_values(array_filter($namespaces, function (ProvNamespace $ns): bool {
            if ($ns->prefix === 'default') {
                return $this->includeDefaultNamespace;
            }
            $reserved = self::RESERVED_NAMESPACES[$ns->prefix] ?? null;
            return $reserved === null || $reserved === $ns->uri;
        }));
        return NamespaceManager::forContainer($namespaces, $parent);
    }

    /**
     * The `prefix`/`default` declaration lines for a document or bundle block.
     *
     * PROV-N declares `prov` and `xsd` itself and forbids redeclaring them, so
     * neither prefix is ever written: a matching binding is implicit and a
     * foreign one is out of the scope already (see `scopeManager()`). Every
     * other declaration is validated and written.
     *
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     *
     * @return list<string>
     */
    private function namespaceDeclarations(array $namespaces, string $indent): array
    {
        $lines = [];
        foreach ($namespaces as $ns) {
            if (isset(self::RESERVED_NAMESPACES[$ns->prefix])) {
                continue;
            }
            $this->assertSafeNamespace($ns);
            if ($ns->prefix === 'default') {
                if ($this->includeDefaultNamespace) {
                    $lines[] = $indent . "default <{$ns->uri}>";
                }
            } else {
                $lines[] = $indent . "prefix {$ns->prefix} <{$ns->uri}>";
            }
        }
        return $lines;
    }

    private function serializeRecord(ProvRecord $record, NamespaceManager $nsManager): ?string
    {
        return match (true) {
            $record instanceof Entity => $this->serializeEntity($record, $nsManager),
            $record instanceof Activity => $this->serializeActivity($record, $nsManager),
            $record instanceof Agent => $this->serializeAgent($record, $nsManager),
            $record instanceof DictionaryMembership => $this->serializeDictMembership($record, $nsManager),
            $record instanceof DictionaryInsertion => $this->serializeDictInsertion($record, $nsManager),
            $record instanceof DictionaryRemoval => $this->serializeDictRemoval($record, $nsManager),
            $record instanceof ProvRelation && isset(RelationMetadata::FORMALS[$record::class])
                => $this->serializeRelation($record, $nsManager),
            default => null,
        };
    }

    private function serializeEntity(Entity $entity, NamespaceManager $nsManager): string
    {
        $id = $this->formatOptionalId($entity->identifier, $nsManager);
        if ($entity->attributes->isEmpty()) {
            return "entity({$id})";
        }
        return "entity({$id}" . $this->formatAttributes($entity->attributes, $nsManager) . ')';
    }

    private function serializeActivity(Activity $activity, NamespaceManager $nsManager): string
    {
        $id = $this->formatOptionalId($activity->identifier, $nsManager);
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
        $id = $this->formatOptionalId($agent->identifier, $nsManager);
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
                $parts[] = $this->formatOptionalId($value instanceof QualifiedName ? $value : null, $nsManager);
            } elseif ($type === 'time') {
                $parts[] = $value instanceof \DateTimeImmutable ? Literal::formatDateTime($value) : '-';
            }
        }
        $args = implode(', ', $parts);

        $identifier = isset(self::RELATIONS_WITHOUT_ID[$record::class]) ? null : $record->identifier;
        $prefix = $identifier !== null
            ? $keyword . '(' . $this->formatQualifiedName($identifier, $nsManager) . '; ' . $args
            : $keyword . '(' . $args;

        return $record->attributes->isEmpty()
            ? $prefix . ')'
            : $prefix . $this->formatAttributes($record->attributes, $nsManager) . ')';
    }

    private function serializeDictMembership(DictionaryMembership $dm, NamespaceManager $nsManager): string
    {
        $lines = [];
        foreach ($dm->keyEntityPairs as $pair) {
            $dict = $this->formatOptionalId($dm->dictionary, $nsManager);
            $entity = $this->formatOptionalId($pair->entity, $nsManager);
            $key = $this->formatDictKey($pair->key, $nsManager);
            $lines[] = "prov:hadDictionaryMember({$dict}, {$entity}, {$key})";
        }
        return implode("\n" . $this->indentPrefix, $lines);
    }

    private function serializeDictInsertion(DictionaryInsertion $di, NamespaceManager $nsManager): string
    {
        $after = $this->formatOptionalId($di->after, $nsManager);
        $before = $this->formatOptionalId($di->before, $nsManager);
        $set = $this->formatKeyEntitySet($di->keyEntityPairs, $nsManager);
        $attrs = $this->formatAttributes($di->attributes, $nsManager);

        if ($di->identifier !== null) {
            return (
                'prov:derivedByInsertionFrom('
                . $this->formatQualifiedName($di->identifier, $nsManager)
                . "; {$after}, {$before}, {$set}{$attrs})"
            );
        }
        return "prov:derivedByInsertionFrom({$after}, {$before}, {$set}{$attrs})";
    }

    private function serializeDictRemoval(DictionaryRemoval $dr, NamespaceManager $nsManager): string
    {
        $after = $this->formatOptionalId($dr->after, $nsManager);
        $before = $this->formatOptionalId($dr->before, $nsManager);
        $set = $this->formatKeySet($dr->removedKeys, $nsManager);
        $attrs = $this->formatAttributes($dr->attributes, $nsManager);

        if ($dr->identifier !== null) {
            return (
                'prov:derivedByRemovalFrom('
                . $this->formatQualifiedName($dr->identifier, $nsManager)
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
    private function formatKeyEntitySet(array $pairs, NamespaceManager $nsManager): string
    {
        if ($pairs === []) {
            return '{}';
        }
        $items = [];
        foreach ($pairs as $pair) {
            $key = $this->formatDictKey($pair->key, $nsManager);
            $entity = $this->formatOptionalId($pair->entity, $nsManager);
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
    private function formatKeySet(array $keys, NamespaceManager $nsManager): string
    {
        if ($keys === []) {
            return '{}';
        }
        $items = [];
        foreach ($keys as $key) {
            $items[] = $this->formatDictKey($key, $nsManager);
        }
        return '{' . implode(', ', $items) . '}';
    }

    /**
     * Formats the full `DictionaryEntry::$key` union (QN/Literal/scalar/null)
     * as a PROV-N token.
     */
    private function formatDictKey(
        QualifiedName|Literal|string|int|float|bool|null $key,
        NamespaceManager $nsManager,
    ): string {
        if ($key === null) {
            return '-';
        }
        return $this->formatAttributeValue($key, $nsManager);
    }

    /**
     * Stringifies an identifier for emission, escaping the local-name punctuation
     * the grammar permits when backslash-escaped and rejecting any character that
     * remains unrepresentable. This is the single chokepoint through which every
     * qualified name reaches the output, so local-name validation happens inline
     * as the document is written (no separate pass). The prefix needs no check
     * here: every prefix the minter hands out is declared in a header block,
     * and namespaceDeclarations() validates each declaration before the
     * document is returned.
     */
    private function formatQualifiedName(QualifiedName $qn, NamespaceManager $nsManager): string
    {
        return $this->minter->token($qn, $nsManager, $this->escapeLocalPart($qn->localPart));
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

    private function formatOptionalId(?QualifiedName $id, NamespaceManager $nsManager): string
    {
        return $id !== null ? $this->formatQualifiedName($id, $nsManager) : '-';
    }

    private function formatAttributes(Attributes $attributes, NamespaceManager $nsManager): string
    {
        if ($attributes->isEmpty()) {
            return '';
        }

        $pairs = [];
        foreach ($attributes->all() as $uri => $values) {
            $key = $this->minter->uriToPrefixed($uri, $nsManager);
            $key = NamespaceManager::stripDefaultSentinel($key);
            $key = $this->escapeAttributeKey($key);
            $this->assertSafeAttributeKey($key);
            foreach ($values as $value) {
                $formattedValue = $this->formatAttributeValue($value, $nsManager);
                $pairs[] = "{$key} = {$formattedValue}";
            }
        }

        return ', [' . implode(', ', $pairs) . ']';
    }

    private function formatAttributeValue(
        QualifiedName|Literal|string|int|float|bool $value,
        NamespaceManager $nsManager,
    ): string {
        if ($value instanceof QualifiedName) {
            return "'" . $this->formatQualifiedName($value, $nsManager) . "'";
        }

        if ($value instanceof Literal) {
            $str = '"' . $this->escapeString($value->value) . '"';
            if ($value->datatype !== null) {
                // XSD datatypes are written against the implicit xsd binding
                // whichever spelling the model carries.
                $datatype = ValueIdentity::datatypeIn($value->datatype, ProvNamespace::xsd());
                $str .= ' %% ' . $this->formatQualifiedName($datatype, $nsManager);
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

    private function assertSafeNamespace(ProvNamespace $ns): void
    {
        if ($ns->prefix !== 'default') {
            if (!self::isValidPrefix($ns->prefix)) {
                throw new \InvalidArgumentException(
                    "Namespace prefix '{$ns->prefix}' cannot be represented in PROV-N.",
                );
            }
        }
        if ($this->isUnsafeUri($ns->uri)) {
            throw new \InvalidArgumentException("Namespace URI '{$ns->uri}' cannot be represented in PROV-N.");
        }
    }

    /**
     * Whether a string matches the PROV-N `PN_PREFIX` production: a name
     * character that is not a digit, hyphen, or underscore, then any run of
     * name characters and dots that does not end on a dot. The character
     * classes are the Unicode ranges the grammar lists, so a non-ASCII prefix
     * is accepted exactly where the grammar accepts it. Checked once per
     * declaration; every prefix written in the body is declared, so that
     * covers the body too.
     */
    private static function isValidPrefix(string $prefix): bool
    {
        static $pattern = null;
        if ($pattern === null) {
            $base =
                'A-Za-z'
                . '\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}'
                . '\x{0370}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}'
                . '\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}'
                . '\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
            $chars = $base . '_\\-0-9\x{00B7}\x{0300}-\x{036F}\x{203F}-\x{2040}';
            $pattern = '/^[' . $base . '](?:[' . $chars . '.]*[' . $chars . '])?$/u';
        }
        // preg_match returns false on invalid UTF-8, which is not a valid
        // prefix either.
        return preg_match($pattern, $prefix) === 1;
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
