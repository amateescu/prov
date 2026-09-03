<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Attributes;
use Prov\Builder\DocumentBuilder;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\BlankNodes;
use Prov\Model\ProvRecord;
use Prov\Operation\DocumentOperations;
use Prov\Relation\Alternate;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Dictionary\DictionaryRemoval;
use Prov\Relation\Specialization;

/**
 * Blank labels name a record only inside its own container. Composing two
 * containers has to keep independent labels independent: merging two documents
 * that both start at `_:b1`, and attaching a bundle whose labels the document
 * builder did not mint.
 */
final class BlankNodeCompositionTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    /**
     * The named entities each blank node is linked to, keyed by blank label.
     *
     * @return array<string, list<string>>
     */
    private function neighboursByBlank(Document $document): array
    {
        $out = [];
        foreach ($document->records as $record) {
            if (!$record instanceof Specialization) {
                continue;
            }
            $out[$record->specificEntity->getUri()][] = $record->generalEntity->getUri();
        }
        ksort($out);
        return $out;
    }

    public function testMergeKeepsIndependentBlankNodesApart(): void
    {
        $blank = QualifiedName::blankNode('b1');

        $a = new Document(
            [
                new Entity($blank),
                new Specialization(null, $blank, $this->ex->qualifiedName('a')),
            ],
            [],
            [$this->ex],
        );
        $b = new Document(
            [
                new Entity($blank),
                new Specialization(null, $blank, $this->ex->qualifiedName('b')),
            ],
            [],
            [$this->ex],
        );

        $merged = DocumentOperations::merge($a, $b);

        $neighbours = $this->neighboursByBlank($merged);
        $this->assertCount(2, $neighbours, 'The two independent blank nodes were merged into one.');
        $lists = array_values($neighbours);
        sort($lists);
        $this->assertSame(
            [
                ['http://example.org/a'],
                ['http://example.org/b'],
            ],
            $lists,
        );
    }

    public function testMergeKeepsBlankNodeLinksWithinEachInput(): void
    {
        $b1 = QualifiedName::blankNode('b1');
        $b2 = QualifiedName::blankNode('b2');

        $a = new Document([new Entity($b1), new Alternate(null, $b1, $b2), new Entity($b2)], [], [$this->ex]);
        $b = new Document([new Entity($b1), new Alternate(null, $b1, $b2), new Entity($b2)], [], [$this->ex]);

        $merged = DocumentOperations::merge($a, $b);

        $alternates = [];
        foreach ($merged->records as $record) {
            if ($record instanceof Alternate) {
                $alternates[] = [$record->alternate1->getUri(), $record->alternate2->getUri()];
            }
        }
        $this->assertCount(2, $alternates);
        $this->assertNotSame($alternates[0], $alternates[1], 'Both inputs kept the same pair of labels.');
        // Each alternateOf must still join two entities declared in the same input.
        foreach ($alternates as [$first, $second]) {
            $this->assertNotSame($first, $second);
            $this->assertTrue($this->declaresEntity($merged->records, $first));
            $this->assertTrue($this->declaresEntity($merged->records, $second));
        }
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function declaresEntity(array $records, string $uri): bool
    {
        foreach ($records as $record) {
            if ($record instanceof Entity && $record->identifier?->getUri() === $uri) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return iterable<string, list<callable(\Prov\Identifier\QualifiedName): \Prov\Model\ProvRecord>>
     */
    public static function blankReferencePositions(): iterable
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');

        yield 'identifier' => [static fn(QualifiedName $b): ProvRecord => new Entity($b)];
        yield 'formal endpoint' => [
            static fn(QualifiedName $b): ProvRecord => new Specialization(null, $b, $ex->qualifiedName('g')),
        ];
        yield 'attribute value' => [
            static fn(QualifiedName $b): ProvRecord => new Entity(
                $ex->qualifiedName('e'),
                Attributes::single($ex->qualifiedName('ref'), $b),
            ),
        ];
        yield 'dictionary entity' => [
            static fn(QualifiedName $b): ProvRecord => new DictionaryMembership(
                null,
                $ex->qualifiedName('d'),
                [new DictionaryEntry('k', $b)],
            ),
        ];
        yield 'dictionary key' => [
            static fn(QualifiedName $b): ProvRecord => new DictionaryMembership(
                null,
                $ex->qualifiedName('d'),
                [new DictionaryEntry($b, $ex->qualifiedName('v'))],
            ),
        ];
        yield 'removed dictionary key' => [
            static fn(QualifiedName $b): ProvRecord => new DictionaryRemoval(
                null,
                $ex->qualifiedName('d2'),
                $ex->qualifiedName('d1'),
                [$b],
            ),
        ];
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Model\ProvRecord $make
     */
    #[DataProvider('blankReferencePositions')]
    public function testBlankLabelsAreFoundInEveryPosition(callable $make): void
    {
        $record = $make(QualifiedName::blankNode('b1'));
        $this->assertSame(['_:b1' => true], BlankNodes::labels([$record]));
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Model\ProvRecord $make
     */
    #[DataProvider('blankReferencePositions')]
    public function testAddBundleAdvancesThePastEveryBlankPosition(callable $make): void
    {
        $bundle = new Bundle(
            identifier: $this->ex->qualifiedName('b1'),
            records: [$make(QualifiedName::blankNode('b1'))],
            namespaces: [],
        );

        $builder = new DocumentBuilder([$this->ex]);
        $builder->addBundle($bundle);

        $this->assertNotSame('_:b1', $builder->blank()->getUri());
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Model\ProvRecord $make
     */
    #[DataProvider('blankReferencePositions')]
    public function testMergeRenamesBlankLabelsInEveryPosition(callable $make): void
    {
        $blank = QualifiedName::blankNode('b1');

        $a = new Document([new Entity($blank)], [], [$this->ex]);
        $b = new Document([$make($blank)], [], [$this->ex]);

        $merged = DocumentOperations::merge($a, $b);
        $labels = BlankNodes::labels($merged->records);

        $this->assertCount(2, $labels, 'The two independent blank nodes kept a single label.');
    }

    public function testMergeStandardizesBundleBlankNodesApart(): void
    {
        $blank = QualifiedName::blankNode('b1');

        $a = new Document(
            [],
            [
                new Bundle(
                    $this->ex->qualifiedName('bundle'),
                    [new Entity($blank), new Specialization(null, $blank, $this->ex->qualifiedName('a'))],
                    [],
                ),
            ],
            [$this->ex],
        );
        $b = new Document(
            [],
            [
                new Bundle(
                    $this->ex->qualifiedName('bundle'),
                    [new Entity($blank), new Specialization(null, $blank, $this->ex->qualifiedName('b'))],
                    [],
                ),
            ],
            [$this->ex],
        );

        $merged = DocumentOperations::merge($a, $b);
        $this->assertCount(1, $merged->bundles);
        $labels = BlankNodes::labels($merged->bundles[0]->records);
        $this->assertCount(2, $labels, 'Two bundles sharing an identifier merged their independent blank nodes.');
    }

    public function testMergeLeavesNonCollidingBlankLabelsAlone(): void
    {
        $a = new Document([new Entity(QualifiedName::blankNode('b1'))], [], [$this->ex]);
        $b = new Document([new Entity(QualifiedName::blankNode('b9'))], [], [$this->ex]);

        $merged = DocumentOperations::merge($a, $b);

        $this->assertSame(['_:b1' => true, '_:b9' => true], BlankNodes::labels($merged->records));
    }

    public function testAddBundleKeepsALabelTheDocumentAlreadyUsesApart(): void
    {
        $builder = new DocumentBuilder([$this->ex]);
        $blank = $builder->blank();
        $builder->entity($blank);
        $builder->specializationOf(specificEntity: $blank, generalEntity: $this->ex->qualifiedName('a'));

        $shared = QualifiedName::blankNode('b1');
        $builder->addBundle(new Bundle(
            identifier: $this->ex->qualifiedName('bundle'),
            records: [
                new Entity($shared),
                new Specialization(null, $shared, $this->ex->qualifiedName('b')),
            ],
            namespaces: [],
        ));

        $flat = DocumentOperations::flatten($builder->build());

        $neighbours = $this->neighboursByBlank($flat);
        $this->assertCount(2, $neighbours, 'The document and the bundle node were merged into one.');
        $lists = array_values($neighbours);
        sort($lists);
        $this->assertSame(
            [
                ['http://example.org/a'],
                ['http://example.org/b'],
            ],
            $lists,
        );
    }

    /**
     * @param callable(\Prov\Identifier\QualifiedName): \Prov\Model\ProvRecord $make
     */
    #[DataProvider('blankReferencePositions')]
    public function testAddBundleRenamesBlankLabelsInEveryPosition(callable $make): void
    {
        $builder = new DocumentBuilder([$this->ex]);
        $builder->entity($builder->blank());
        $builder->addBundle(new Bundle(
            identifier: $this->ex->qualifiedName('bundle'),
            records: [$make(QualifiedName::blankNode('b1'))],
            namespaces: [],
        ));

        $flat = DocumentOperations::flatten($builder->build());

        $this->assertCount(2, BlankNodes::labels($flat->records), 'Two independent nodes kept a single label.');
    }

    public function testAddBundleRenamesNonNumericBlankLabels(): void
    {
        $builder = new DocumentBuilder([$this->ex]);
        $builder->entity(QualifiedName::blankNode('shared'));
        $builder->addBundle(new Bundle(
            identifier: $this->ex->qualifiedName('bundle'),
            records: [new Entity(QualifiedName::blankNode('shared'))],
            namespaces: [],
        ));

        $flat = DocumentOperations::flatten($builder->build());

        $this->assertCount(2, BlankNodes::labels($flat->records), 'Two independent nodes kept a single label.');
    }

    public function testAddBundleKeepsTwoAttachedBundlesApart(): void
    {
        $builder = new DocumentBuilder([$this->ex]);
        foreach (['one', 'two'] as $name) {
            $builder->addBundle(new Bundle(
                identifier: $this->ex->qualifiedName($name),
                records: [new Entity(QualifiedName::blankNode('b1'))],
                namespaces: [],
            ));
        }

        $flat = DocumentOperations::flatten($builder->build());

        $this->assertCount(2, BlankNodes::labels($flat->records), 'Two independent nodes kept a single label.');
    }

    public function testAddBundleLeavesNonCollidingBlankLabelsAlone(): void
    {
        $builder = new DocumentBuilder([$this->ex]);
        $builder->entity($builder->blank());
        $builder->addBundle(new Bundle(
            identifier: $this->ex->qualifiedName('bundle'),
            records: [new Entity(QualifiedName::blankNode('b9'))],
            namespaces: [],
        ));

        $flat = DocumentOperations::flatten($builder->build());

        $this->assertSame(['_:b1' => true, '_:b9' => true], BlankNodes::labels($flat->records));
    }

    public function testFlattenAfterAddBundleKeepsBlankValuesIndependent(): void
    {
        $bundle = new Bundle(
            identifier: $this->ex->qualifiedName('b1'),
            records: [
                new Entity(
                    $this->ex->qualifiedName('inBundle'),
                    Attributes::single($this->ex->qualifiedName('ref'), QualifiedName::blankNode('b1')),
                ),
            ],
            namespaces: [],
        );

        $builder = new DocumentBuilder([$this->ex]);
        $builder->addBundle($bundle);
        $blank = $builder->blank();
        $builder->wasDerivedFrom(generatedEntity: $this->ex->qualifiedName('g'), usedEntity: $blank);
        $document = $builder->build();

        $flat = DocumentOperations::flatten($document);

        $referenced = [];
        foreach ($flat->records as $record) {
            if ($record instanceof Derivation) {
                $referenced[] = $record->usedEntity?->getUri();
            }
        }
        $this->assertSame(['_:b2'], $referenced);
        $this->assertNotSame('_:b1', $referenced[0]);
    }
}
