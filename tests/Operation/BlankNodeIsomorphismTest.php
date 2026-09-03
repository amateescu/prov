<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Operation\DocumentComparator;
use Prov\Relation\Alternate;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryMembership;

/**
 * `DocumentComparator::equals()` compares blank nodes up to renaming, which
 * means it has to decide graph isomorphism, not just neighbourhood similarity.
 * Two graphs with the same degrees and the same record counts can still be
 * different graphs.
 */
final class BlankNodeIsomorphismTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    /**
     * Builds a document of blank entities joined by `alternateOf` edges.
     * `$labels` renames the nodes, so the same graph can be expressed with any
     * labelling.
     *
     * @param list<array{int, int}> $edges
     * @param array<int, string>|null $labels
     */
    private function graph(array $edges, ?array $labels = null): Document
    {
        $nodes = [];
        foreach ($edges as [$from, $to]) {
            $nodes[$from] = true;
            $nodes[$to] = true;
        }
        ksort($nodes);

        $name = static fn(int $node): QualifiedName => QualifiedName::blankNode($labels[$node] ?? 'b' . $node);

        $records = [];
        foreach (array_keys($nodes) as $node) {
            $records[] = new Entity($name($node));
        }
        foreach ($edges as [$from, $to]) {
            $records[] = new Alternate(null, $name($from), $name($to));
        }

        return new Document($records, [], [$this->ex]);
    }

    /**
     * @return list<array{int, int}>
     */
    private static function sixCycle(): array
    {
        return [[1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 1]];
    }

    /**
     * @return list<array{int, int}>
     */
    private static function twoTriangles(): array
    {
        return [[1, 2], [2, 3], [3, 1], [4, 5], [5, 6], [6, 4]];
    }

    public function testSixCycleIsNotEqualToTwoTriangles(): void
    {
        $cycle = $this->graph(self::sixCycle());
        $triangles = $this->graph(self::twoTriangles());

        $this->assertFalse(DocumentComparator::equals($cycle, $triangles));
        $this->assertNotSame([], DocumentComparator::diff($cycle, $triangles));
    }

    public function testSixCycleEqualsItsRelabelledCopy(): void
    {
        $labels = [1 => 'x', 2 => 'q7', 3 => 'alpha', 4 => 'b99', 5 => 'zz', 6 => 'n'];
        $this->assertTrue(DocumentComparator::equals(
            $this->graph(self::sixCycle()),
            $this->graph(self::sixCycle(), $labels),
        ));
    }

    public function testTwoTrianglesEqualTheirRelabelledCopy(): void
    {
        $labels = [1 => 'p', 2 => 'q', 3 => 'r', 4 => 's', 5 => 't', 6 => 'u'];
        $this->assertTrue(DocumentComparator::equals(
            $this->graph(self::twoTriangles()),
            $this->graph(self::twoTriangles(), $labels),
        ));
    }

    public function testEdgeOrientationDoesNotChangeTheGraph(): void
    {
        // alternateOf is symmetric, so writing an edge as (4, 6) instead of
        // (6, 4) still describes two triangles.
        $flippedOne = [[1, 2], [2, 3], [3, 1], [4, 5], [5, 6], [4, 6]];
        $this->assertTrue(DocumentComparator::equals($this->graph(self::twoTriangles()), $this->graph($flippedOne)));

        $flippedTwo = [[1, 2], [2, 3], [3, 1], [4, 5], [4, 6], [5, 6]];
        $this->assertTrue(DocumentComparator::equals($this->graph(self::twoTriangles()), $this->graph($flippedTwo)));
    }

    public function testRecordOrderDoesNotChangeTheGraph(): void
    {
        // The search for a renaming has to compare record multisets, never
        // record sequences: the same symmetric graph listed in another order
        // is still the same graph.
        $cycle = self::sixCycle();
        $shuffled = [$cycle[3], $cycle[0], $cycle[5], $cycle[1], $cycle[4], $cycle[2]];
        $this->assertTrue(DocumentComparator::equals($this->graph($cycle), $this->graph($shuffled)));

        $triangles = self::twoTriangles();
        $this->assertTrue(DocumentComparator::equals(
            $this->graph($triangles),
            $this->graph(array_reverse($triangles)),
        ));
    }

    public function testDisconnectedFourCycleAndTwoEdgesDifferFromEightCycle(): void
    {
        $eightCycle = [[1, 2], [2, 3], [3, 4], [4, 5], [5, 6], [6, 7], [7, 8], [8, 1]];
        $twoFourCycles = [[1, 2], [2, 3], [3, 4], [4, 1], [5, 6], [6, 7], [7, 8], [8, 5]];

        $this->assertFalse(DocumentComparator::equals($this->graph($eightCycle), $this->graph($twoFourCycles)));
        $this->assertTrue(DocumentComparator::equals($this->graph($twoFourCycles), $this->graph($twoFourCycles)));
    }

    public function testBlankAttributeValuesFormTheSameKindOfGraph(): void
    {
        $ref = $this->ex->qualifiedName('ref');

        $chain = static fn(string $a, string $b, string $c): Document => new Document(
            [
                new Entity(QualifiedName::blankNode($a), Attributes::single($ref, QualifiedName::blankNode($b))),
                new Entity(QualifiedName::blankNode($b), Attributes::single($ref, QualifiedName::blankNode($c))),
                new Entity(QualifiedName::blankNode($c)),
            ],
            [],
            [new ProvNamespace('ex', 'http://example.org/')],
        );

        $this->assertTrue(DocumentComparator::equals($chain('a', 'b', 'c'), $chain('x', 'y', 'z')));

        $loop = new Document(
            [
                new Entity(QualifiedName::blankNode('a'), Attributes::single($ref, QualifiedName::blankNode('b'))),
                new Entity(QualifiedName::blankNode('b'), Attributes::single($ref, QualifiedName::blankNode('a'))),
                new Entity(QualifiedName::blankNode('c')),
            ],
            [],
            [$this->ex],
        );
        $this->assertFalse(DocumentComparator::equals($chain('a', 'b', 'c'), $loop));
    }

    public function testDictionaryEntryEntitiesParticipateInTheComparison(): void
    {
        $dictionary = fn(string $first, string $second): Document => new Document(
            [
                new Entity(QualifiedName::blankNode($first)),
                new Entity(QualifiedName::blankNode($second)),
                new DictionaryMembership(null, $this->ex->qualifiedName('d1'), [
                    new DictionaryEntry('k1', QualifiedName::blankNode($first)),
                    new DictionaryEntry('k2', QualifiedName::blankNode($second)),
                ]),
            ],
            [],
            [$this->ex],
        );

        $this->assertTrue(DocumentComparator::equals($dictionary('a', 'b'), $dictionary('p', 'q')));

        $swapped = new Document(
            [
                new Entity(QualifiedName::blankNode('a')),
                new Entity(QualifiedName::blankNode('b')),
                new DictionaryMembership(null, $this->ex->qualifiedName('d1'), [
                    new DictionaryEntry('k1', QualifiedName::blankNode('a')),
                    new DictionaryEntry('k1', QualifiedName::blankNode('b')),
                ]),
            ],
            [],
            [$this->ex],
        );
        $this->assertFalse(DocumentComparator::equals($dictionary('a', 'b'), $swapped));
    }

    public function testManyDistinguishableBlankNodesCompareQuickly(): void
    {
        $build = function (int $count, ?int $swapAt = null): Document {
            $records = [];
            for ($i = 1; $i <= $count; $i++) {
                $blank = QualifiedName::blankNode('b' . $i);
                $records[] = new Entity($blank);
                $target = $swapAt === $i ? 'n' . ($i + 1) : 'n' . $i;
                $records[] = new Alternate(null, $blank, $this->ex->qualifiedName($target));
            }
            return new Document($records, [], [$this->ex]);
        };

        $start = microtime(true);
        $this->assertTrue(DocumentComparator::equals($build(400), $build(400)));
        $this->assertFalse(DocumentComparator::equals($build(400), $build(400, 7)));
        $this->assertLessThan(5.0, microtime(true) - $start, 'Comparing distinguishable blank nodes got slow.');
    }

    public function testChainedAndInterchangeableBlankNodesCompareQuickly(): void
    {
        // A chain takes as many refinement rounds as it is long, so a round has
        // to be cheap. Interchangeable blanks (never in a record with another
        // blank) share a label but need no renaming search at all.
        $chain = [];
        for ($i = 1; $i < 300; $i++) {
            $chain[] = [$i, $i + 1];
        }
        $reversed = array_reverse($chain);

        $twins = static function (int $count): Document {
            $records = [];
            for ($i = 1; $i <= $count; $i++) {
                $records[] = new Entity(
                    QualifiedName::blankNode('b' . $i),
                    Attributes::single(ProvNamespace::prov()->qualifiedName('type'), 'sample'),
                );
            }
            return new Document($records, [], []);
        };

        $start = microtime(true);
        $this->assertTrue(DocumentComparator::equals($this->graph($chain), $this->graph($reversed)));
        $this->assertTrue(DocumentComparator::equals($twins(300), $twins(300)));
        $this->assertFalse(DocumentComparator::equals($twins(300), $twins(299)));
        $this->assertLessThan(2.0, microtime(true) - $start, 'Comparing chained or interchangeable blanks got slow.');
    }

    public function testAnonymousDuplicateRecordsStillCompareAsAMultiset(): void
    {
        $records = static function (int $copies): Document {
            /** @var list<\Prov\Model\ProvRecord> $out */
            $out = [];
            for ($i = 0; $i < $copies; $i++) {
                $out[] = new Entity(null);
            }
            return new Document($out, [], []);
        };

        $this->assertTrue(DocumentComparator::equals($records(3), $records(3)));
        $this->assertFalse(DocumentComparator::equals($records(3), $records(2)));
    }

    public function testRecordsWithoutBlankNodesAreUnaffected(): void
    {
        $named = new Document([new Entity($this->ex->qualifiedName('e1'))], [], [$this->ex]);
        $other = new Document([new Entity($this->ex->qualifiedName('e2'))], [], [$this->ex]);

        $this->assertTrue(DocumentComparator::equals($named, $named));
        $this->assertFalse(DocumentComparator::equals($named, $other));
    }
}
