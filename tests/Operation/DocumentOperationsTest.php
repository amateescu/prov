<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Exception\NamespaceException;
use Prov\Identifier\ProvNamespace;
use Prov\Operation\DocumentOperations;
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
}
