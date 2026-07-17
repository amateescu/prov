<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Bundle;
use Prov\Document;
use Prov\Exception\NamespaceException;
use Prov\Exception\ProvException;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Mention;

/**
 * Operations on immutable Documents: flatten and merge.
 * Each operation returns a new Document without modifying the originals.
 */
final class DocumentOperations
{
    /**
     * Flatten a document by moving all bundle records into the document level.
     * Bundle namespace declarations are merged. Bundles are removed.
     *
     * Mention relations reference a bundle by identifier; flattening would leave
     * those references dangling, so flatten() throws if any Mention is present.
     * Call `self::flattenDroppingMentions()` to discard them instead.
     *
     * Known limitation: blank-node labels are container-scoped, and flattening
     * does not rename them. Documents built with DocumentBuilder are safe (a
     * document and its bundles share one label sequence), but a deserialized
     * document that reuses a label (e.g. `_:b1`) in both the document and a
     * bundle will have those unrelated records silently identified after
     * flattening.
     *
     * @throws \Prov\Exception\ProvException
     *   If any Mention record is present.
     */
    public static function flatten(Document $document): Document
    {
        if (self::anyMention($document)) {
            throw new ProvException(
                'Cannot flatten a document containing Mention relations: their bundle references '
                . 'would dangle. Call flattenDroppingMentions() to discard them.',
            );
        }
        return self::flattenInternal($document, dropMentions: false);
    }

    /**
     * Same as flatten() but discards Mention records instead of throwing.
     */
    public static function flattenDroppingMentions(Document $document): Document
    {
        return self::flattenInternal($document, dropMentions: true);
    }

    private static function anyMention(Document $document): bool
    {
        if (self::containsMention($document->records)) {
            return true;
        }
        foreach ($document->bundles as $bundle) {
            if (self::containsMention($bundle->records)) {
                return true;
            }
        }
        return false;
    }

    // @mago-expect lint:no-boolean-flag-parameter
    private static function flattenInternal(Document $document, bool $dropMentions): Document
    {
        $records = $dropMentions ? self::stripMentions($document->records) : $document->records;

        // Collect namespaces in declaration order (document first, then each
        // bundle), deduped by URI. Two declarations can disagree with the
        // canonical pick: a bundle may legally shadow a document prefix with a
        // different URI (re-mint a fresh prefix for the later one), and any
        // container may declare an alias (a second prefix for an already-seen
        // URI; only prefix conflicts are forbidden), whose declaration is
        // dropped. Both cases require rewriting the affected records onto the
        // canonical namespace below.
        /** @var array<string, \Prov\Identifier\ProvNamespace> $byUri */
        $byUri = [];
        /** @var array<string, string> $prefixToUri */
        $prefixToUri = [];
        $remapped = false;

        foreach ($document->namespaces as $ns) {
            $remapped = self::registerNamespace($ns, $byUri, $prefixToUri) || $remapped;
        }

        foreach ($document->bundles as $bundle) {
            $bundleRecords = $dropMentions ? self::stripMentions($bundle->records) : $bundle->records;
            foreach ($bundleRecords as $record) {
                $records[] = $record;
            }
            foreach ($bundle->namespaces as $ns) {
                $remapped = self::registerNamespace($ns, $byUri, $prefixToUri) || $remapped;
            }
        }

        // Records carry their own QualifiedName/namespace objects, so a re-minted
        // or dropped-alias prefix only takes effect once the records that
        // reference that URI are rebuilt to point at the canonical namespace.
        // Skip the rewrite entirely when every declaration matched its canonical
        // namespace (the common case).
        if ($remapped) {
            $records = array_map(static fn(ProvRecord $r): ProvRecord => self::remapRecord($r, $byUri), $records);
        }

        return new Document(records: $records, bundles: [], namespaces: array_values($byUri));
    }

    /**
     * Records `$ns` in the canonical maps unless its URI is already present.
     * When its prefix is already bound to a different URI, a fresh prefix is
     * minted for it. Returns whether records need rewriting onto the canonical
     * namespace: either a fresh prefix was minted, or `$ns` is an alias (another
     * prefix for an already-registered URI) whose declaration is dropped while
     * records may still reference it.
     *
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     * @param array<string, string> $prefixToUri
     */
    private static function registerNamespace(ProvNamespace $ns, array &$byUri, array &$prefixToUri): bool
    {
        if (isset($byUri[$ns->uri])) {
            return $byUri[$ns->uri]->prefix !== $ns->prefix;
        }
        $prefix = $ns->prefix;
        $remapped = false;
        if (isset($prefixToUri[$prefix]) && $prefixToUri[$prefix] !== $ns->uri) {
            $prefix = self::mintPrefix($ns->prefix, $prefixToUri);
            $ns = new ProvNamespace($prefix, $ns->uri);
            $remapped = true;
        }
        $byUri[$ns->uri] = $ns;
        $prefixToUri[$prefix] = $ns->uri;
        return $remapped;
    }

    /**
     * Mints a prefix derived from `$base` that is not already claimed in
     * `$prefixToUri` (e.g. `ex` -> `ex1`).
     *
     * @param array<string, string> $prefixToUri
     */
    private static function mintPrefix(string $base, array $prefixToUri): string
    {
        $i = 1;
        do {
            $candidate = $base . $i;
            $i++;
        } while (isset($prefixToUri[$candidate]));
        return $candidate;
    }

    /**
     * Rebuilds a record so every QualifiedName it references uses the canonical
     * namespace for that URI. Reconstructed via the constructor using named
     * arguments (public readonly property names match the constructor
     * parameters), so each record type is handled without a per-type branch.
     *
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapRecord(ProvRecord $record, array $byUri): ProvRecord
    {
        /** @var array<string, mixed> $args */
        $args = [];
        // @mago-expect analysis:mixed-assignment
        foreach (get_object_vars($record) as $name => $value) {
            $args[$name] = self::remapValue($value, $byUri);
        }
        try {
            $new = new \ReflectionClass($record)->newInstanceArgs($args);
        } catch (\ReflectionException $e) {
            // Every record's public property names match its constructor
            // parameters, so reconstruction by named argument cannot fail here.
            throw new \LogicException('Could not rebuild record while flattening.', previous: $e);
        }
        assert($new instanceof ProvRecord);
        return $new;
    }

    /**
     * Remaps any QualifiedName reachable from a record property value
     * (identifiers, formal endpoints, attribute bags, dictionary entries).
     *
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapValue(mixed $value, array $byUri): mixed
    {
        if ($value instanceof QualifiedName) {
            return self::remapQn($value, $byUri);
        }
        if ($value instanceof Attributes) {
            return self::remapAttributes($value, $byUri);
        }
        if ($value instanceof DictionaryEntry) {
            return new DictionaryEntry(
                self::remapDictKey($value->key, $byUri),
                $value->entity !== null ? self::remapQn($value->entity, $byUri) : null,
            );
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::remapValue($item, $byUri), $value);
        }
        return $value;
    }

    /**
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapQn(QualifiedName $qn, array $byUri): QualifiedName
    {
        $canonical = $byUri[$qn->namespace->uri] ?? null;
        if ($canonical === null || $canonical->prefix === $qn->namespace->prefix) {
            return $qn;
        }
        return new QualifiedName($canonical, $qn->localPart);
    }

    /**
     * Remaps a dictionary entry key, preserving its declared value union.
     *
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapDictKey(
        QualifiedName|Literal|string|int|float|bool|null $key,
        array $byUri,
    ): QualifiedName|Literal|string|int|float|bool|null {
        if ($key instanceof QualifiedName) {
            return self::remapQn($key, $byUri);
        }
        if ($key instanceof Literal) {
            return self::remapLiteral($key, $byUri);
        }
        return $key;
    }

    /**
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapAttributes(Attributes $attributes, array $byUri): Attributes
    {
        if ($attributes->isEmpty()) {
            return $attributes;
        }
        $pairs = [];
        foreach ($attributes as $key => $value) {
            $pairs[] = [self::remapQn($key, $byUri), self::remapAttrValue($value, $byUri)];
        }
        return Attributes::from($pairs);
    }

    /**
     * Remaps an attribute value, preserving its declared value union.
     *
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapAttrValue(
        QualifiedName|Literal|string|int|float|bool $value,
        array $byUri,
    ): QualifiedName|Literal|string|int|float|bool {
        if ($value instanceof QualifiedName) {
            return self::remapQn($value, $byUri);
        }
        if ($value instanceof Literal) {
            return self::remapLiteral($value, $byUri);
        }
        return $value;
    }

    /**
     * @param array<string, \Prov\Identifier\ProvNamespace> $byUri
     */
    private static function remapLiteral(Literal $literal, array $byUri): Literal
    {
        if ($literal->datatype === null) {
            return $literal;
        }
        return new Literal($literal->value, self::remapQn($literal->datatype, $byUri), $literal->languageTag);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private static function containsMention(array $records): bool
    {
        foreach ($records as $record) {
            if ($record instanceof Mention) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return list<\Prov\Model\ProvRecord>
     */
    private static function stripMentions(array $records): array
    {
        return array_values(array_filter($records, static fn(ProvRecord $record): bool => !$record instanceof Mention));
    }

    /**
     * Merge two documents. Records and bundles from both documents are combined.
     * Namespace declarations are deduplicated by URI.
     *
     * This is concatenation, not set union: the combined record list keeps both
     * documents' records verbatim, so a record present in both appears twice.
     * Equal records are not collapsed (PROV-DM has no record identity beyond an
     * optional identifier, and even identically-shaped records may be distinct
     * assertions). Deduplicate afterward via `DocumentComparator` signatures if
     * set semantics are wanted. Bundles, by contrast, are merged by identifier:
     * two bundles sharing a URI have their records concatenated and namespaces
     * reconciled.
     *
     * @throws \Prov\Exception\NamespaceException
     *   If the two documents (or two bundles sharing an identifier) declare the
     *   same prefix with different URIs. Reconcile the prefixes before merging.
     */
    public static function merge(Document $a, Document $b): Document
    {
        $records = array_merge($a->records, $b->records);

        $bundlesByUri = [];

        foreach (array_merge($a->bundles, $b->bundles) as $bundle) {
            $uri = $bundle->identifier->getUri();
            if (isset($bundlesByUri[$uri])) {
                // Merge bundle records.
                $existing = $bundlesByUri[$uri];
                $mergedRecords = array_merge($existing->records, $bundle->records);
                $mergedNs = self::mergeNamespaces($existing->namespaces, $bundle->namespaces);
                $bundlesByUri[$uri] = new Bundle($existing->identifier, $mergedRecords, $mergedNs);
            } else {
                $bundlesByUri[$uri] = $bundle;
            }
        }

        $bundles = array_values($bundlesByUri);
        $namespaces = self::mergeNamespaces($a->namespaces, $b->namespaces);

        return new Document(records: $records, bundles: $bundles, namespaces: $namespaces);
    }

    /**
     * @param list<\Prov\Identifier\ProvNamespace> $a
     * @param list<\Prov\Identifier\ProvNamespace> $b
     *
     * @return list<\Prov\Identifier\ProvNamespace>
     *
     * @throws \Prov\Exception\NamespaceException
     *   If the same prefix is bound to different URIs across the two inputs.
     */
    private static function mergeNamespaces(array $a, array $b): array
    {
        $byUri = [];
        $byPrefix = [];
        foreach (array_merge($a, $b) as $ns) {
            $existingForPrefix = $byPrefix[$ns->prefix] ?? null;
            if ($existingForPrefix !== null && $existingForPrefix->uri !== $ns->uri) {
                throw new NamespaceException(
                    "Cannot merge: prefix '{$ns->prefix}' is bound to conflicting URIs "
                    . "'{$existingForPrefix->uri}' and '{$ns->uri}'.",
                );
            }
            if (!isset($byUri[$ns->uri])) {
                $byUri[$ns->uri] = $ns;
                $byPrefix[$ns->prefix] = $ns;
            }
        }
        return array_values($byUri);
    }
}
