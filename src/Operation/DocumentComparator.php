<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Activity;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Attribute\ValueIdentity;
use Prov\Document;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;

/**
 * Semantic equality comparison for Documents and Bundles.
 *
 * Compares records by type, identifier URI, formal attributes, and extra attributes.
 * Ignores blank node identifiers, record ordering, namespace prefix names, and
 * attribute key ordering.
 *
 * Blank-node references are compared up to renaming: each blank label is replaced
 * by a canonical label derived from the records it occurs in (iteratively refined,
 * so distinct neighborhoods get distinct labels), and two documents that differ
 * only in their blank labels compare equal. Structurally indistinguishable blank
 * nodes share a canonical label; their records are then distinguished by count,
 * since identical records are compared as a multiset.
 */
final class DocumentComparator
{
    /**
     * Returns true if two Documents are semantically equivalent.
     */
    public static function equals(Document $a, Document $b): bool
    {
        return self::diff($a, $b) === [];
    }

    /**
     * Returns a list of human-readable differences between two Documents, or
     * an empty list if they are semantically equivalent. Use this for
     * diagnostics when `equals()` returns false.
     *
     * @return list<string>
     */
    public static function diff(Document $a, Document $b): array
    {
        $messages = self::recordSetDiff($a->records, $b->records, '');

        $bundlesA = [];
        foreach ($a->bundles as $bundle) {
            $bundlesA[$bundle->identifier->getUri()] = $bundle;
        }
        $bundlesB = [];
        foreach ($b->bundles as $bundle) {
            $bundlesB[$bundle->identifier->getUri()] = $bundle;
        }

        foreach (array_diff_key($bundlesA, $bundlesB) as $uri => $_) {
            $messages[] = "Bundle '{$uri}' only in first document.";
        }
        foreach (array_diff_key($bundlesB, $bundlesA) as $uri => $_) {
            $messages[] = "Bundle '{$uri}' only in second document.";
        }

        foreach ($bundlesA as $uri => $bundleA) {
            if (!isset($bundlesB[$uri])) {
                continue;
            }
            $messages = array_merge($messages, self::recordSetDiff(
                $bundleA->records,
                $bundlesB[$uri]->records,
                "bundle '{$uri}' ",
            ));
        }

        return $messages;
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $setA
     * @param list<\Prov\Model\ProvRecord> $setB
     *
     * @return list<string>
     */
    private static function recordSetDiff(array $setA, array $setB, string $scope): array
    {
        $messages = [];
        $sigsA = self::signatureCounts($setA);
        $sigsB = self::signatureCounts($setB);

        foreach ($sigsA as $sig => [$record, $countA]) {
            if (!isset($sigsB[$sig])) {
                $messages[] = $scope . 'record only in first: ' . self::describe($record);
            } elseif ($sigsB[$sig][1] !== $countA) {
                $countB = $sigsB[$sig][1];
                $messages[] =
                    $scope
                    . "record appears {$countA} times in first but {$countB} times in second: "
                    . self::describe($record);
            }
        }
        foreach ($sigsB as $sig => [$record, $countB]) {
            if (!isset($sigsA[$sig])) {
                $messages[] = $scope . 'record only in second: ' . self::describe($record);
            }
        }

        return $messages;
    }

    /**
     * Signs every record (with blank labels canonicalized) and counts how many
     * records share each signature, so duplicate anonymous records compare as
     * a multiset instead of collapsing into one.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return array<string, array{\Prov\Model\ProvRecord, int}>
     */
    private static function signatureCounts(array $records): array
    {
        $labels = self::canonicalBlankLabels($records);
        $out = [];
        foreach ($records as $record) {
            $sig = self::recordSignature($record, $labels);
            $existing = $out[$sig] ?? null;
            $out[$sig] = $existing === null ? [$record, 1] : [$existing[0], $existing[1] + 1];
        }
        return $out;
    }

    /**
     * Computes a canonical label for every blank node in the record set, so two
     * documents identical up to blank renaming sign identically. Labels start
     * from the multiset of (record signature, role) occurrences of each blank
     * (other blanks masked) and are refined by re-signing with the current
     * labels until they stabilize.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return array<string, string>
     */
    private static function canonicalBlankLabels(array $records): array
    {
        $blankRecords = [];
        foreach ($records as $record) {
            if (self::collectBlankOccurrences($record) !== []) {
                $blankRecords[] = $record;
            }
        }
        if ($blankRecords === []) {
            return [];
        }

        $labels = [];
        // Two extra rounds propagate a blank's neighborhood through shared
        // records; further refinement changes labels only in contrived graphs.
        for ($round = 0; $round < 3; $round++) {
            $descriptors = [];
            foreach ($blankRecords as $record) {
                $sig = self::recordSignature($record, $labels);
                foreach (self::collectBlankOccurrences($record) as [$role, $uri]) {
                    $descriptors[$uri][] = $sig . '@' . $role;
                }
            }
            $newLabels = [];
            foreach ($descriptors as $uri => $descs) {
                sort($descs);
                $newLabels[$uri] = '_:' . md5(implode("\x1f", $descs));
            }
            if ($newLabels === $labels) {
                break;
            }
            $labels = $newLabels;
        }

        return $labels;
    }

    /**
     * Lists each blank-node occurrence in a record as a [role, blank URI] pair.
     * Roles are order-independent (property names, attribute key URIs) because
     * record and attribute ordering must not influence canonical labels.
     *
     * @return list<array{string, string}>
     */
    private static function collectBlankOccurrences(ProvRecord $record): array
    {
        $out = [];
        $id = $record->identifier;
        if ($id !== null && $id->isBlank()) {
            $out[] = ['id', $id->getUri()];
        }

        if ($record instanceof ProvRelation) {
            // @mago-expect analysis:mixed-assignment
            foreach (RelationMetadata::extractFormals($record) as $prop => $value) {
                if ($value instanceof QualifiedName && $value->isBlank()) {
                    $out[] = [$prop, $value->getUri()];
                } elseif (is_array($value)) {
                    // @mago-expect analysis:mixed-assignment
                    foreach ($value as $item) {
                        if (
                            $item instanceof \Prov\Relation\Dictionary\DictionaryEntry
                            && $item->entity !== null
                            && $item->entity->isBlank()
                        ) {
                            $out[] = ['dict', $item->entity->getUri()];
                        }
                    }
                }
            }
        }

        foreach ($record->attributes->all() as $keyUri => $values) {
            foreach ($values as $value) {
                if ($value instanceof QualifiedName && $value->isBlank()) {
                    $out[] = ['attr:' . $keyUri, $value->getUri()];
                }
            }
        }

        return $out;
    }

    private static function describe(ProvRecord $record): string
    {
        $class = $record::class;
        $lastSlash = strrpos($class, '\\');
        $shortClass = $lastSlash === false ? $class : substr($class, $lastSlash + 1);
        $id = $record->identifier?->getUri() ?? '(blank)';
        return "{$shortClass}({$id})";
    }

    /**
     * @param array<string, string> $blankLabels
     */
    private static function recordSignature(ProvRecord $record, array $blankLabels = []): string
    {
        // Blank node identifiers (null, or the "_:" sentinel) normalize to empty, so a
        // blank record signs the same regardless of how its anonymity is represented.
        $id = $record->identifier;
        $idSig = $id === null || $id->isBlank() ? '' : $id->getUri();

        // json_encode gives an unambiguous, injection-proof signature in one pass: a crafted
        // attribute value cannot forge a component boundary because every string is JSON-escaped.
        // JSON_INVALID_UTF8_SUBSTITUTE keeps non-UTF-8 byte sequences from failing the encode.
        return (string) json_encode([
            $record::class,
            $idSig,
            self::formalSignature($record, $blankLabels),
            self::attributesSignature($record->attributes, $blankLabels),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * A reference URI with blank labels replaced by their canonical form (or a
     * mask while canonical labels are still being computed).
     *
     * @param array<string, string> $blankLabels
     */
    private static function referenceUri(QualifiedName $qn, array $blankLabels): string
    {
        $uri = $qn->getUri();
        if (str_starts_with($uri, '_:')) {
            return $blankLabels[$uri] ?? '_:?';
        }
        return $uri;
    }

    /**
     * @param array<string, string> $blankLabels
     *
     * @return list<string|list<mixed>>
     */
    private static function formalSignature(ProvRecord $record, array $blankLabels): array
    {
        if ($record instanceof Activity) {
            return [
                $record->startTime !== null ? Literal::formatDateTime($record->startTime) : '',
                $record->endTime !== null ? Literal::formatDateTime($record->endTime) : '',
            ];
        }

        if (!$record instanceof ProvRelation) {
            return [];
        }

        /** @var array<string, \Prov\Identifier\QualifiedName|\DateTimeImmutable|list<mixed>|null> $formals */
        $formals = RelationMetadata::extractFormals($record);

        // alternateOf is a symmetric PROV-DM relation: (a, b) and (b, a) denote
        // the same fact. Sort the two endpoints so either ordering signs the same.
        if ($record instanceof \Prov\Relation\Alternate) {
            $uris = [
                $formals['alternate1'] instanceof QualifiedName
                    ? self::referenceUri($formals['alternate1'], $blankLabels)
                    : '',
                $formals['alternate2'] instanceof QualifiedName
                    ? self::referenceUri($formals['alternate2'], $blankLabels)
                    : '',
            ];
            sort($uris);
            return $uris;
        }

        $parts = [];
        foreach ($formals as $prop => $value) {
            if ($value instanceof QualifiedName) {
                $parts[] = self::referenceUri($value, $blankLabels);
            } elseif ($value instanceof \DateTimeImmutable) {
                $parts[] = Literal::formatDateTime($value);
            } elseif (is_array($value) && $prop === 'keyEntityPairs') {
                /** @var list<\Prov\Relation\Dictionary\DictionaryEntry> $value */
                $parts[] = self::keyEntityPairsSignature($value, $blankLabels);
            } elseif (is_array($value) && $prop === 'removedKeys') {
                /** @var list<QualifiedName|Literal|string|int|float|bool> $value */
                $parts[] = self::removedKeysSignature($value);
            } else {
                $parts[] = '';
            }
        }

        return $parts;
    }

    /**
     * @param array<string, string> $blankLabels
     *
     * @return list<array{string, string}>
     */
    private static function attributesSignature(Attributes $attrs, array $blankLabels): array
    {
        $pairs = [];
        foreach ($attrs->all() as $uri => $values) {
            foreach ($values as $value) {
                $pairs[] = [$uri, ValueIdentity::signature($value, $blankLabels)];
            }
        }

        sort($pairs);
        return $pairs;
    }

    /**
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $pairs
     * @param array<string, string> $blankLabels
     *
     * @return list<array{string, string}>
     */
    private static function keyEntityPairsSignature(array $pairs, array $blankLabels): array
    {
        $sigs = [];
        foreach ($pairs as $pair) {
            $entitySig = $pair->entity !== null ? self::referenceUri($pair->entity, $blankLabels) : '';
            $sigs[] = [self::keySignature($pair->key), $entitySig];
        }
        sort($sigs);
        return $sigs;
    }

    /**
     * @param list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool> $keys
     *
     * @return list<string>
     */
    private static function removedKeysSignature(array $keys): array
    {
        $sigs = array_map(self::keySignature(...), $keys);
        sort($sigs);
        return $sigs;
    }

    private static function keySignature(QualifiedName|Literal|string|int|float|bool|null $key): string
    {
        if ($key instanceof QualifiedName || $key instanceof Literal) {
            return ValueIdentity::signature($key);
        }
        if (is_string($key)) {
            // Bare string keys default to xsd:string in PROV-DM. Sign them like a Literal
            // so `"foo"` and `Literal("foo", xsd:string)` compare equal.
            return ValueIdentity::signature($key);
        }
        if (is_scalar($key)) {
            return gettype($key) . ':' . var_export($key, true);
        }
        return '';
    }
}
