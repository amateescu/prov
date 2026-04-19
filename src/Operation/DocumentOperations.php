<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Bundle;
use Prov\Document;
use Prov\Exception\NamespaceException;
use Prov\Exception\ProvException;
use Prov\Model\ProvRecord;
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
        $namespaces = $document->namespaces;
        $seenUris = [];

        foreach ($namespaces as $ns) {
            $seenUris[$ns->uri] = true;
        }

        foreach ($document->bundles as $bundle) {
            $bundleRecords = $dropMentions ? self::stripMentions($bundle->records) : $bundle->records;
            foreach ($bundleRecords as $record) {
                $records[] = $record;
            }
            foreach ($bundle->namespaces as $ns) {
                if (!isset($seenUris[$ns->uri])) {
                    $namespaces[] = $ns;
                    $seenUris[$ns->uri] = true;
                }
            }
        }

        return new Document(records: $records, bundles: [], namespaces: $namespaces);
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
