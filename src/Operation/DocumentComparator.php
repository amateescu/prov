<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Activity;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Attribute\ValueIdentity;
use Prov\Document;
use Prov\Identifier\QualifiedName;
use Prov\Model\BlankNodes;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;

/**
 * Semantic equality comparison for Documents and Bundles.
 *
 * Compares records by type, identifier URI, formal attributes, and extra attributes.
 * Ignores blank node identifiers, record ordering, namespace prefix names,
 * attribute key ordering, and the UTC offset formal times are written in.
 *
 * Blank-node references are compared up to renaming: each blank label is replaced
 * by a canonical label derived from the records it occurs in (iteratively refined,
 * so distinct neighborhoods get distinct labels), and two documents that differ
 * only in their blank labels compare equal. Identical records are compared as a
 * multiset, so duplicate anonymous records still have to match in count.
 *
 * Refinement alone cannot tell apart blank nodes in a symmetric graph, and two
 * graphs whose blanks get the same labels are not necessarily the same graph
 * (a six-node cycle and two three-node cycles are the standard example). When
 * one label covers more than one blank node, the comparison goes on to search
 * for an actual renaming that makes the record multisets equal, singling out
 * one blank at a time and refining again after each choice.
 */
final class DocumentComparator
{
    /**
     * The most individualization branches one record-set comparison may explore
     * before it gives up. Refinement decides every graph whose blank nodes are
     * distinguishable, so the search only runs on symmetric graphs, and the
     * pruning below cuts a branch as soon as the two sides stop matching. A
     * budget this large is out of reach for anything but a deliberately
     * constructed input.
     */
    private const int BIJECTION_SEARCH_BUDGET = 50_000;

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
        $graphA = self::blankNodeGraph($setA);
        $graphB = self::blankNodeGraph($setB);
        $labelsA = $graphA->refine([]);
        $labelsB = $graphB->refine([]);

        $messages = [];
        $sigsA = self::signatureCounts($setA, $labelsA);
        $sigsB = self::signatureCounts($setB, $labelsB);

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

        if ($messages !== [] || !self::hasAmbiguousLinkedBlanks($labelsA, $graphA)) {
            return $messages;
        }

        // Refinement could not tell some linked blank nodes apart, so equal
        // signature multisets are not yet proof: a six-node cycle and two
        // three-node cycles get identical labels. Look for a renaming that
        // makes the exact record multisets match.
        $budget = self::BIJECTION_SEARCH_BUDGET;
        if (self::blankBijectionExists($setA, $setB, $graphA, $graphB, $labelsA, $labelsB, $budget)) {
            return [];
        }
        if ($budget < 0) {
            return [$scope . 'anonymous records are too symmetric to compare; the search for a renaming gave up.'];
        }
        return [$scope . 'no renaming of the anonymous records makes the two record sets equal.'];
    }

    /**
     * Signs every record (with blank labels canonicalized) and counts how many
     * records share each signature, so duplicate anonymous records compare as
     * a multiset instead of collapsing into one.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     * @param array<string, string> $labels
     *
     * @return array<string, array{\Prov\Model\ProvRecord, int}>
     */
    private static function signatureCounts(array $records, array $labels): array
    {
        $out = [];
        foreach ($records as $record) {
            $sig = self::recordSignature($record, $labels);
            $existing = $out[$sig] ?? null;
            $out[$sig] = $existing === null ? [$record, 1] : [$existing[0], $existing[1] + 1];
        }
        return $out;
    }

    /**
     * Prepares the blank-node structure of a record set: the label-free shape
     * of every record that references a blank, and the blank occurrences it
     * holds. Refinement then reads only this, never the records.
     *
     * A blank identifier that no record references is the same thing as a null
     * identifier (a serializer mints one for every anonymous record, and
     * recordSignature() signs both as empty), so it is left out. A referenced
     * one stays: its record's shape then flows into the label the references
     * are compared by.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private static function blankNodeGraph(array $records): BlankNodeGraph
    {
        $occurrencesByRecord = [];
        $referenced = [];
        foreach ($records as $i => $record) {
            $found = self::collectBlankOccurrences($record);
            if ($found === []) {
                continue;
            }
            $occurrencesByRecord[$i] = $found;
            foreach ($found as [$role, $uri]) {
                if ($role !== BlankNodes::ID_ROLE) {
                    $referenced[$uri] = true;
                }
            }
        }

        $shapes = [];
        $occurrences = [];
        foreach ($occurrencesByRecord as $i => $found) {
            $kept = [];
            foreach ($found as $occurrence) {
                if ($occurrence[0] !== BlankNodes::ID_ROLE || isset($referenced[$occurrence[1]])) {
                    $kept[] = $occurrence;
                }
            }
            if ($kept === []) {
                continue;
            }
            $shapes[] = self::recordSignature($records[$i], []);
            $occurrences[] = $kept;
        }
        return new BlankNodeGraph($shapes, $occurrences);
    }

    /**
     * Whether one label covers more than one linked blank node, which is what
     * makes the signature comparison inconclusive. Unlinked blanks that share
     * a label are interchangeable, so they never call for a search.
     *
     * @param array<string, string> $labels
     */
    private static function hasAmbiguousLinkedBlanks(array $labels, BlankNodeGraph $graph): bool
    {
        $seen = [];
        foreach ($labels as $uri => $label) {
            if (!$graph->isLinked($uri)) {
                continue;
            }
            if (isset($seen[$label])) {
                return true;
            }
            $seen[$label] = true;
        }
        return false;
    }

    /**
     * Whether some renaming of the first set's blank nodes onto the second
     * set's makes their record multisets equal.
     *
     * Refinement partitions the blanks; where it leaves a class of linked
     * blanks with more than one member, one blank of that class is singled out
     * and tried against each candidate on the other side, refining again after
     * each choice. A branch whose refined signature multisets no longer match
     * is cut immediately, so the search only widens where the graph really is
     * symmetric. Once every linked class holds one blank the renaming is fixed
     * (unlinked blanks pair off within their class in any order) and the exact
     * record multisets are compared under it.
     *
     * @param list<\Prov\Model\ProvRecord> $setA
     * @param list<\Prov\Model\ProvRecord> $setB
     * @param array<string, string> $labelsA
     * @param array<string, string> $labelsB
     * @param int $budget
     *   Branches left to explore. Drops below zero when the search gives up.
     */
    private static function blankBijectionExists(
        array $setA,
        array $setB,
        BlankNodeGraph $graphA,
        BlankNodeGraph $graphB,
        array $labelsA,
        array $labelsB,
        int &$budget,
    ): bool {
        $classesA = self::groupByLabel($labelsA);
        $classesB = self::groupByLabel($labelsB);
        if (count($classesA) !== count($classesB)) {
            return false;
        }

        // A label never covers both linked and unlinked blanks (the shape
        // records how many distinct blanks a record holds), so one member
        // speaks for its class.
        $ambiguous = null;
        foreach ($classesA as $label => $members) {
            $other = $classesB[$label] ?? null;
            if ($other === null || count($other) !== count($members)) {
                return false;
            }
            if (
                count($members) > 1
                && $graphA->isLinked($members[0])
                && ($ambiguous === null || count($members) < count($classesA[$ambiguous]))
            ) {
                $ambiguous = $label;
            }
        }

        if ($ambiguous === null) {
            $renaming = [];
            foreach ($classesA as $label => $members) {
                foreach ($members as $i => $uri) {
                    $renaming[$uri] = $classesB[$label][$i];
                }
            }
            return self::matchesUnderRenaming($setA, $setB, $renaming);
        }

        $fixed = $classesA[$ambiguous][0];
        foreach ($classesB[$ambiguous] as $candidate) {
            if (--$budget < 0) {
                return false;
            }
            $nextA = $graphA->refine(self::individualize($labelsA, $fixed));
            $nextB = $graphB->refine(self::individualize($labelsB, $candidate));
            if ($graphA->signatureMultiset($nextA) !== $graphB->signatureMultiset($nextB)) {
                continue;
            }
            if (self::blankBijectionExists($setA, $setB, $graphA, $graphB, $nextA, $nextB, $budget)) {
                return true;
            }
            if ($budget < 0) {
                return false;
            }
        }
        return false;
    }

    /**
     * Gives one blank node a label of its own, so the next refinement round
     * can propagate the choice through its neighborhood.
     *
     * @param array<string, string> $labels
     *
     * @return array<string, string>
     */
    private static function individualize(array $labels, string $uri): array
    {
        $labels[$uri] = ($labels[$uri] ?? '') . "\x00fixed";
        return $labels;
    }

    /**
     * Groups blank-node URIs by label, each group in a stable order.
     *
     * @param array<string, string> $labels
     *
     * @return array<string, list<string>>
     */
    private static function groupByLabel(array $labels): array
    {
        $groups = [];
        foreach ($labels as $uri => $label) {
            $groups[$label][] = $uri;
        }
        foreach ($groups as &$members) {
            sort($members);
        }
        unset($members);
        ksort($groups);
        return $groups;
    }

    /**
     * Whether the two record multisets are equal once the first set's blank
     * labels are rewritten to the second set's under `$renaming`.
     *
     * @param list<\Prov\Model\ProvRecord> $setA
     * @param list<\Prov\Model\ProvRecord> $setB
     * @param array<string, string> $renaming
     */
    private static function matchesUnderRenaming(array $setA, array $setB, array $renaming): bool
    {
        $identity = [];
        foreach ($renaming as $target) {
            $identity[$target] = $target;
        }
        return self::signatureMultiset($setA, $renaming) === self::signatureMultiset($setB, $identity);
    }

    /**
     * The record signatures under a blank labelling, with how often each one
     * occurs, sorted by signature. Two record sets that hold the same records
     * in any order produce the same array, so callers compare multisets and
     * never record sequences.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     * @param array<string, string> $labels
     *
     * @return array<string, int>
     */
    private static function signatureMultiset(array $records, array $labels): array
    {
        $counts = [];
        foreach (self::signatureCounts($records, $labels) as $sig => [$_, $count]) {
            $counts[$sig] = $count;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * Lists each blank-node occurrence in a record as a [role, blank URI] pair,
     * from the shared traversal every blank-node consumer uses. `alternateOf`
     * is symmetric in PROV-DM, so its two endpoints report one shared role;
     * `formalSignature()` sorts that record's endpoints for the same reason,
     * and the two have to agree or a blank's label would depend on which side
     * of the relation it was written on.
     *
     * @return list<array{string, string}>
     */
    private static function collectBlankOccurrences(ProvRecord $record): array
    {
        $symmetric = $record instanceof \Prov\Relation\Alternate;
        $out = [];
        foreach (BlankNodes::occurrences($record) as $occurrence) {
            $role = $occurrence['role'];
            if ($symmetric && ($role === 'alternate1' || $role === 'alternate2')) {
                $role = 'alternate';
            }
            $out[] = [$role, $occurrence['name']->getUri()];
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
                $record->startTime !== null ? self::dateTimeSignature($record->startTime) : '',
                $record->endTime !== null ? self::dateTimeSignature($record->endTime) : '',
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
                $parts[] = self::dateTimeSignature($value);
            } elseif (is_array($value) && $prop === 'keyEntityPairs') {
                /** @var list<\Prov\Relation\Dictionary\DictionaryEntry> $value */
                $parts[] = self::keyEntityPairsSignature($value, $blankLabels);
            } elseif (is_array($value) && $prop === 'removedKeys') {
                /** @var list<QualifiedName|Literal|string|int|float|bool> $value */
                $parts[] = self::removedKeysSignature($value, $blankLabels);
            } else {
                $parts[] = '';
            }
        }

        return $parts;
    }

    /**
     * Signs a formal time by the instant it denotes, not by its lexical form.
     * In the xsd:dateTime value space two times for the same instant are equal
     * whatever UTC offset they are written in, so sign by "U.u" (Unix timestamp
     * plus microseconds), the offset-independent key the constraint validator
     * compares event times with too. Serializers keep using
     * Literal::formatDateTime(), so output still carries the offset the caller
     * supplied.
     */
    private static function dateTimeSignature(\DateTimeImmutable $value): string
    {
        return $value->format('U.u');
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
            $sigs[] = [self::keySignature($pair->key, $blankLabels), $entitySig];
        }
        sort($sigs);
        return $sigs;
    }

    /**
     * @param list<\Prov\Identifier\QualifiedName|\Prov\Attribute\Literal|string|int|float|bool> $keys
     * @param array<string, string> $blankLabels
     *
     * @return list<string>
     */
    private static function removedKeysSignature(array $keys, array $blankLabels): array
    {
        $sigs = [];
        foreach ($keys as $key) {
            $sigs[] = self::keySignature($key, $blankLabels);
        }
        sort($sigs);
        return $sigs;
    }

    /**
     * @param array<string, string> $blankLabels
     */
    private static function keySignature(
        QualifiedName|Literal|string|int|float|bool|null $key,
        array $blankLabels = [],
    ): string {
        if ($key instanceof QualifiedName || $key instanceof Literal) {
            return ValueIdentity::signature($key, $blankLabels);
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
