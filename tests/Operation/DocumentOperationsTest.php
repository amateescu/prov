<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Entity;
use Prov\Exception\NamespaceException;
use Prov\Format;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Operation\DocumentComparator;
use Prov\Operation\DocumentOperations;
use Prov\Operation\ProvGraph;
use Prov\Prov;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Generation;

final class DocumentOperationsTest extends TestCase
{
    private ProvNamespace $ex;

    protected function setUp(): void
    {
        $this->ex = new ProvNamespace('ex', 'http://example.org/');
    }

    private function buildDoc(): DocumentBuilder
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace($this->ex);
        return $builder;
    }

    // --- Flatten ---

    public function testFlattenEmptyDocument(): void
    {
        $doc = new DocumentBuilder()->build();
        $flat = DocumentOperations::flatten($doc);

        $this->assertCount(0, $flat->records);
        $this->assertCount(0, $flat->bundles);
    }

    public function testFlattenDocumentWithoutBundles(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $doc = $builder->build();

        $flat = DocumentOperations::flatten($doc);
        $this->assertCount(1, $flat->entities);
        $this->assertCount(0, $flat->bundles);
    }

    public function testFlattenMovesBundleRecordsToDocument(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $bb = $builder->bundle('ex:b1');
        $bb->entity('ex:e2');
        $bb->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1');

        $doc = $builder->build();
        $flat = DocumentOperations::flatten($doc);

        $this->assertCount(0, $flat->bundles);
        $this->assertCount(2, $flat->entities); // e1 + e2
        $this->assertCount(1, $flat->getRecordsByType(Generation::class));
    }

    public function testFlattenMultipleBundles(): void
    {
        $builder = $this->buildDoc();
        $builder->bundle('ex:b1')->entity('ex:e1');
        $builder->bundle('ex:b2')->entity('ex:e2');
        $builder->bundle('ex:b3')->entity('ex:e3');

        $doc = $builder->build();
        $flat = DocumentOperations::flatten($doc);

        $this->assertCount(0, $flat->bundles);
        $this->assertCount(3, $flat->entities);
    }

    public function testFlattenMergesBundleNamespaces(): void
    {
        $other = new ProvNamespace('other', 'http://other.org/');
        $builder = $this->buildDoc();
        $bb = $builder->bundle('ex:b1');
        $bb->addNamespace($other);
        $bb->entity('other:e1');

        $doc = $builder->build();
        $flat = DocumentOperations::flatten($doc);

        $uris = array_map(static fn($ns) => $ns->uri, $flat->namespaces);
        $this->assertContains('http://other.org/', $uris);
    }

    public function testFlattenDoesNotMutateOriginal(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->bundle('ex:b1')->entity('ex:e2');

        $doc = $builder->build();
        $flat = DocumentOperations::flatten($doc);

        $this->assertCount(1, $doc->bundles); // Original unchanged.
        $this->assertCount(0, $flat->bundles);
    }

    public function testFlattenThrowsWhenMentionPresent(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $bb = $builder->bundle('ex:b1');
        $bb->entity('ex:e2');
        $bb->mentionOf(specificEntity: 'ex:e2', generalEntity: 'ex:e1', bundle: 'ex:b1');

        $this->expectException(\Prov\Exception\ProvException::class);
        $this->expectExceptionMessage('Mention');
        DocumentOperations::flatten($builder->build());
    }

    public function testFlattenDropsMentionsWhenOptedIn(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $bb = $builder->bundle('ex:b1');
        $bb->entity('ex:e2');
        $bb->mentionOf(specificEntity: 'ex:e2', generalEntity: 'ex:e1', bundle: 'ex:b1');

        $flat = DocumentOperations::flattenDroppingMentions($builder->build());

        $this->assertCount(0, $flat->bundles);
        $this->assertCount(2, $flat->entities);
        foreach ($flat->records as $record) {
            $this->assertNotInstanceOf(\Prov\Relation\Mention::class, $record);
        }
    }

    public function testFlattenDetectsMentionAtDocumentLevel(): void
    {
        $builder = $this->buildDoc();
        $builder->entity('ex:e1');
        $builder->entity('ex:e2');
        $builder->mentionOf(specificEntity: 'ex:e2', generalEntity: 'ex:e1', bundle: 'ex:b1');
        $builder->bundle('ex:b1')->entity('ex:e3');

        $this->expectException(\Prov\Exception\ProvException::class);
        DocumentOperations::flatten($builder->build());
    }

    // --- Merge ---

    public function testMergeEmptyDocuments(): void
    {
        $a = new DocumentBuilder()->build();
        $b = new DocumentBuilder()->build();
        $merged = DocumentOperations::merge($a, $b);

        $this->assertCount(0, $merged->records);
    }

    public function testMergeCombinesRecords(): void
    {
        $a = $this->buildDoc();
        $a->entity('ex:e1');

        $b = $this->buildDoc();
        $b->entity('ex:e2');
        $b->agent('ex:ag1');

        $merged = DocumentOperations::merge($a->build(), $b->build());
        $this->assertCount(2, $merged->entities);
        $this->assertCount(1, $merged->agents);
    }

    public function testMergeCombinesBundles(): void
    {
        $a = $this->buildDoc();
        $a->bundle('ex:b1')->entity('ex:e1');

        $b = $this->buildDoc();
        $b->bundle('ex:b2')->entity('ex:e2');

        $merged = DocumentOperations::merge($a->build(), $b->build());
        $this->assertCount(2, $merged->bundles);
    }

    public function testMergeBundlesWithSameId(): void
    {
        $a = $this->buildDoc();
        $a->bundle('ex:b1')->entity('ex:e1');

        $b = $this->buildDoc();
        $b->bundle('ex:b1')->entity('ex:e2');

        $merged = DocumentOperations::merge($a->build(), $b->build());
        $this->assertCount(1, $merged->bundles);
        $this->assertCount(2, $merged->bundles[0]->entities);
    }

    public function testMergeDeduplicatesNamespaces(): void
    {
        $a = $this->buildDoc()->keepUnusedNamespaces();
        $b = $this->buildDoc()->keepUnusedNamespaces();

        $merged = DocumentOperations::merge($a->build(), $b->build());
        $exCount = count(array_filter($merged->namespaces, static fn($ns) => $ns->uri === 'http://example.org/'));
        $this->assertSame(1, $exCount);
    }

    public function testMergeDoesNotMutateOriginals(): void
    {
        $docA = $this->buildDoc()->entity('ex:e1')->build();
        $docB = $this->buildDoc()->entity('ex:e2')->build();

        $merged = DocumentOperations::merge($docA, $docB);

        $this->assertCount(1, $docA->entities);
        $this->assertCount(1, $docB->entities);
        $this->assertCount(2, $merged->entities);
    }

    public function testMergeThrowsOnConflictingPrefixAtDocumentLevel(): void
    {
        $docA = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('ex', 'http://example.org/a/'))
            ->entity('ex:e1')
            ->build();
        $docB = new DocumentBuilder()
            ->addNamespace(new ProvNamespace('ex', 'http://example.org/b/'))
            ->entity('ex:e1')
            ->build();

        $this->expectException(NamespaceException::class);
        $this->expectExceptionMessage("prefix 'ex'");
        DocumentOperations::merge($docA, $docB);
    }

    public function testMergeThrowsOnConflictingPrefixInSameBundle(): void
    {
        $bundleId = $this->ex->qualifiedName('b1');

        $builderA = new DocumentBuilder();
        $builderA->bundle($bundleId)->addNamespace(new ProvNamespace('foo', 'http://example.org/a/'))->entity('foo:e1');
        $docA = $builderA->build();

        $builderB = new DocumentBuilder();
        $builderB->bundle($bundleId)->addNamespace(new ProvNamespace('foo', 'http://example.org/b/'))->entity('foo:e1');
        $docB = $builderB->build();

        $this->expectException(NamespaceException::class);
        DocumentOperations::merge($docA, $docB);
    }

    // --- Flatten: prefix conflicts and blank nodes (review items 1.3, 1.4) ---

    public function testFlattenReMintsConflictingBundlePrefix(): void
    {
        $exB = new ProvNamespace('ex', 'http://example.org/b/');

        // The document already uses both `ex` and `ex1`, so re-minting the
        // bundle's conflicting `ex` has to step past `ex1` to `ex2`.
        $builder = new DocumentBuilder();
        $builder->addNamespace(new ProvNamespace('ex', 'http://example.org/a/'));
        $builder->addNamespace(new ProvNamespace('ex1', 'http://example.org/c/'));
        $builder->entity('ex:e1');
        $bb = $builder->bundle('ex:b1');
        $bb->addNamespace($exB);
        // Exercise every QualifiedName-bearing slot in the conflicting namespace
        // so the rewrite covers identifiers, attribute keys, QualifiedName and
        // typed-literal values, and dictionary keys.
        $bb->entity('ex:e2', [
            'ex:tag' => $exB->qualifiedName('ref'),
            'ex:typed' => new Literal('v', $exB->qualifiedName('myType')),
        ]);
        $bb->derivedByInsertionFrom(after: 'ex:e2', before: 'ex:e1', keyEntityPairs: [new DictionaryEntry(
            $exB->qualifiedName('k'),
            $exB->qualifiedName('e2'),
        )]);
        // A blank-node reference: its namespace is never in the canonical map,
        // so the rewrite must leave it untouched.
        $bb->wasGeneratedBy(entity: $bb->blank(), activity: 'ex:e2');

        $flat = DocumentOperations::flatten($builder->build());

        // No prefix is declared twice with conflicting URIs.
        $byPrefix = [];
        foreach ($flat->namespaces as $ns) {
            $this->assertArrayNotHasKey(
                $ns->prefix,
                $byPrefix,
                "Prefix '{$ns->prefix}' is declared more than once after flatten",
            );
            $byPrefix[$ns->prefix] = $ns->uri;
        }

        // The rewritten records keep their original URIs across every slot: the
        // bundle entity's identifier, its attribute key and QualifiedName value,
        // its typed-literal datatype, and the dictionary entry's key and entity.
        $graph = new ProvGraph($flat);
        $this->assertNotNull($graph->recordByIdentifier('http://example.org/a/e1'));
        $entity = $graph->recordByIdentifier('http://example.org/b/e2');
        $this->assertInstanceOf(Entity::class, $entity);

        $tagKey = new ProvNamespace('ex', 'http://example.org/b/')->qualifiedName('tag');
        $this->assertSame('http://example.org/b/ref', $entity->attributes->getQualifiedNames($tagKey)[0]?->getUri());
        $typedKey = new ProvNamespace('ex', 'http://example.org/b/')->qualifiedName('typed');
        $this->assertSame(
            'http://example.org/b/myType',
            $entity->attributes->getLiterals($typedKey)[0]?->datatype?->getUri(),
        );

        $insertion = null;
        foreach ($flat->records as $record) {
            if ($record instanceof DictionaryInsertion) {
                $insertion = $record;
            }
        }
        $this->assertInstanceOf(DictionaryInsertion::class, $insertion);
        $entry = $insertion->keyEntityPairs[0];
        $this->assertInstanceOf(QualifiedName::class, $entry->key);
        $this->assertSame('http://example.org/b/k', $entry->key->getUri());
        $this->assertSame('http://example.org/b/e2', $entry->entity?->getUri());

        // The flattened document is graph-constructible and serializable.
        new ProvGraph($flat);
        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            $roundTripped = Prov::deserialize(Prov::serialize($flat, $format), $format);
            $this->assertTrue(
                DocumentComparator::equals($flat, $roundTripped),
                "Flattened document with a re-minted prefix drifted via {$format->name}",
            );
        }
    }

    public function testFlattenNormalizesAliasPrefixesOntoTheCanonicalNamespace(): void
    {
        // `a2` (document level) and `b` (bundle level) are aliases: extra
        // prefixes for the URI canonically declared as `a`. Flatten keeps one
        // declaration per URI, so the records referencing an alias must be
        // rewritten onto the canonical prefix or serialization would emit
        // prefixed names with no matching declaration.
        $builder = new DocumentBuilder();
        $builder->addNamespace(new ProvNamespace('a', 'http://example.org/'));
        $builder->addNamespace(new ProvNamespace('a2', 'http://example.org/'));
        $builder->entity('a2:e1');
        $bb = $builder->bundle('a:b1');
        $bb->addNamespace(new ProvNamespace('b', 'http://example.org/'));
        $bb->entity('b:e2');

        $flat = DocumentOperations::flatten($builder->build());

        $declared = [];
        foreach ($flat->namespaces as $ns) {
            if ($ns->uri === 'http://example.org/') {
                $declared[] = $ns->prefix;
            }
        }
        $this->assertSame(['a'], $declared, 'Expected a single canonical declaration for the aliased URI');

        foreach ($flat->records as $record) {
            $id = $record->identifier;
            if ($id !== null && !$id->isBlank()) {
                $this->assertSame(
                    'a',
                    $id->namespace->prefix,
                    "Record '{$id}' was not rewritten onto the canonical prefix",
                );
            }
        }

        $roundTripped = Prov::deserialize(Prov::serialize($flat, Format::ProvN), Format::ProvN);
        $this->assertTrue(
            DocumentComparator::equals($flat, $roundTripped),
            'Flattened document with normalized alias prefixes drifted via PROV-N',
        );
    }

    public function testFlattenKeepsDocumentAndBundleBlankNodesDistinct(): void
    {
        $builder = new DocumentBuilder();
        $builder->addNamespace(new ProvNamespace('ex', 'http://example.org/'));
        $builder->entity($builder->blank());
        $builder->withBundle('ex:b1', static function ($bundle): void {
            $bundle->entity($bundle->blank());
        });

        $flat = DocumentOperations::flatten($builder->build());

        $blankUris = [];
        foreach ($flat->records as $record) {
            if ($record->identifier !== null && $record->identifier->isBlank()) {
                $blankUris[] = $record->identifier->getUri();
            }
        }

        $this->assertCount(2, $blankUris, 'Expected one blank node from the document and one from the bundle');
        $this->assertCount(2, array_unique($blankUris), 'Document and bundle blank-node labels collided after flatten');
    }
}
