<?php

declare(strict_types=1);

namespace Prov\Operation;

use Prov\Model\BlankNodes;

/**
 * The blank-node structure of one record set, prepared once so that label
 * refinement and the comparator's renaming search only ever touch labels.
 *
 * Each record that references a blank node is reduced to a label-free shape
 * (its signature with every blank masked) and the list of blank occurrences it
 * holds. A refinement round then signs a record as its shape plus its
 * occurrences' current labels, which costs a few string operations instead of
 * re-encoding the record.
 *
 * A record's own blank identifier is an occurrence like any other for
 * labeling, so the record's shape flows into that blank's label. It is not a
 * structural link, though: record signatures ignore blank identifiers, so
 * only the other roles decide which blanks are linked.
 *
 * @internal
 */
final class BlankNodeGraph
{
    /**
     * The label-free shape of each record that references a blank node.
     *
     * @var list<string>
     */
    private readonly array $shapes;

    /**
     * The [role, blank URI] occurrences of each such record, aligned with
     * `$shapes`.
     *
     * @var list<list<array{string, string}>>
     */
    private readonly array $occurrences;

    /**
     * The [record index, role] occurrences of each blank, the reverse of
     * `$occurrences`.
     *
     * @var array<string, list<array{int, string}>>
     */
    private readonly array $occurrencesOfBlank;

    /**
     * The blanks that share a record with a different blank. See `isLinked()`.
     *
     * @var array<string, true>
     */
    private readonly array $linked;

    /**
     * @param list<string> $shapes
     *   The label-free signature of each record that references a blank node.
     * @param list<list<array{string, string}>> $occurrences
     *   The [role, blank URI] occurrences of each of those records, in the
     *   same order.
     */
    public function __construct(array $shapes, array $occurrences)
    {
        $linked = [];
        $occurrencesOfBlank = [];
        foreach ($occurrences as $i => $recordOccurrences) {
            $referenced = [];
            foreach ($recordOccurrences as [$role, $uri]) {
                $occurrencesOfBlank[$uri][] = [$i, $role];
                if ($role !== BlankNodes::ID_ROLE) {
                    $referenced[$uri] = true;
                }
            }
            // The count of distinct referenced blanks is part of the shape: a
            // record that joins two blanks and one that references a single
            // blank twice must never share a label class.
            $shapes[$i] .= '|' . count($referenced);
            if (count($referenced) > 1) {
                $linked += $referenced;
            }
        }
        $this->shapes = $shapes;
        $this->occurrences = $occurrences;
        $this->occurrencesOfBlank = $occurrencesOfBlank;
        $this->linked = $linked;
    }

    /**
     * Whether the blank shares at least one record with a different blank.
     *
     * A blank that does not is interchangeable with every other unlinked blank
     * carrying the same label: its records depend on no other blank, and an
     * equal label means an equal multiset of record shapes around it. Only
     * linked blanks can make two same-label graphs differ, so only they are
     * worth a renaming search.
     */
    public function isLinked(string $uri): bool
    {
        return isset($this->linked[$uri]);
    }

    /**
     * Refines a labeling until it stops splitting.
     *
     * Blanks that share a label form a class. A round signs every record whose
     * blanks changed label in the previous round, rebuilds the descriptors
     * (record signature, role) of the blanks in those records, and splits each
     * class whose members now disagree on their descriptor. Every part of a
     * split class gets a new label derived from the old one and the part's
     * descriptor. The first round relabels every class this way even when it
     * does not split, so each label absorbs the shapes around its blank; from
     * then on a class that does not split keeps its label, and a stable region
     * of the graph costs nothing in later rounds.
     *
     * Labels are a function of the graph alone, never of record order or of
     * the labels the input used, so two record sets that are the same graph up
     * to blank renaming come out with the same labels. Starting from `[]`
     * yields the canonical labels.
     *
     * @param array<string, string> $labels
     *   The starting labels: `[]` for none, or a labeling with one blank
     *   singled out during the renaming search.
     *
     * @return array<string, string>
     */
    public function refine(array $labels): array
    {
        if ($this->shapes === []) {
            return [];
        }

        $classes = [];
        foreach (array_keys($this->occurrencesOfBlank) as $uri) {
            $labels[$uri] ??= '';
            $classes[$labels[$uri]][] = $uri;
        }

        $signatures = [];
        $descriptors = [];
        $dirty = array_keys($this->shapes);
        $relabelAll = true;
        while ($dirty !== []) {
            $touched = [];
            foreach ($dirty as $i) {
                $signatures[$i] = $this->signature($i, $labels);
                foreach ($this->occurrences[$i] as $occurrence) {
                    $touched[$occurrence[1]] = true;
                }
            }

            $candidates = [];
            foreach (array_keys($touched) as $uri) {
                $descs = [];
                foreach ($this->occurrencesOfBlank[$uri] as [$i, $role]) {
                    $descs[] = $signatures[$i] . '@' . $role;
                }
                sort($descs);
                $descriptors[$uri] = implode("\x1f", $descs);
                $candidates[$labels[$uri]] = true;
            }

            $dirty = [];
            foreach (array_keys($candidates) as $label) {
                $parts = [];
                foreach ($classes[$label] as $uri) {
                    $parts[$descriptors[$uri]][] = $uri;
                }
                if (count($parts) === 1 && !$relabelAll) {
                    continue;
                }
                unset($classes[$label]);
                foreach ($parts as $descriptor => $members) {
                    $newLabel = '_:' . hash('xxh128', $label . "\x1e" . $descriptor);
                    $classes[$newLabel] = $members;
                    foreach ($members as $uri) {
                        $labels[$uri] = $newLabel;
                        foreach ($this->occurrencesOfBlank[$uri] as $occurrence) {
                            $dirty[$occurrence[0]] = true;
                        }
                    }
                }
            }
            $dirty = array_keys($dirty);
            $relabelAll = false;
        }
        return $labels;
    }

    /**
     * How many records produce each signature under a labeling, keyed and
     * sorted by signature. Two record sets that are the same graph under
     * corresponding labels produce the same array, so a mismatch rules a
     * renaming out.
     *
     * @param array<string, string> $labels
     *
     * @return array<string, int>
     */
    public function signatureMultiset(array $labels): array
    {
        $counts = [];
        foreach (array_keys($this->shapes) as $i) {
            $signature = $this->signature($i, $labels);
            $counts[$signature] = ($counts[$signature] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * The signature of one record under a labeling: its shape, then each
     * blank occurrence as `role=label`, sorted so record and attribute order
     * cannot leak in.
     *
     * @param array<string, string> $labels
     */
    private function signature(int $i, array $labels): string
    {
        $parts = [];
        foreach ($this->occurrences[$i] as [$role, $uri]) {
            $parts[] = $role . '=' . ($labels[$uri] ?? '');
        }
        sort($parts);
        return $this->shapes[$i] . '|' . implode(',', $parts);
    }
}
