<?php

declare(strict_types=1);

namespace Prov\Builder;

use Prov\Bundle;
use Prov\Document;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\BlankNodes;
use Prov\Model\ProvRecord;
use Prov\Model\RecordRewriter;

/**
 * Fluent builder for assembling a Document: add records (entities,
 * activities, agents, relations), declare namespaces, create bundles, then
 * obtain the immutable Document with `build()`.
 *
 * Single-use: `build()` can only be called once per builder. See the README
 * for the full set of relation methods and the conventions around named
 * arguments.
 */
class DocumentBuilder extends RecordBuilder
{
    /**
     * Attached bundles and pending bundle builders, in the order they were
     * added. `build()` materializes the builders in place, so the document's
     * bundles come out in that same order.
     *
     * @var list<\Prov\Bundle|\Prov\Builder\BundleBuilder>
     */
    private array $bundles = [];

    /**
     * @param \Prov\Identifier\NamespaceManager|iterable<\Prov\Identifier\ProvNamespace> $namespaces
     *   A shared NamespaceManager to build against, or namespaces to register up
     *   front in a fresh manager (equivalent to calling `addNamespaces()` right
     *   after construction). A passed manager is used directly, not copied: the
     *   caller owns its lifetime, and any namespace this build declares (a
     *   `namespace()` call, a prefix minted while resolving) stays in that shared
     *   manager, so a later builder over the same manager reuses it. Pass an
     *   iterable when each document should start from its own registry.
     */
    public function __construct(NamespaceManager|iterable $namespaces = [])
    {
        if ($namespaces instanceof NamespaceManager) {
            $this->namespaceManager = $namespaces;
            return;
        }
        $this->namespaceManager = new NamespaceManager();
        $this->addNamespaces($namespaces);
    }

    /**
     * Opens a detached BundleBuilder for the given identifier. The caller
     * drives it directly; the resulting Bundle is included when this
     * builder's `build()` runs.
     */
    public function bundle(QualifiedName|string $identifier): BundleBuilder
    {
        $bundleBuilder = new BundleBuilder(
            $this->resolveBundleIdentifier($identifier),
            new NamespaceManager($this->namespaceManager),
            $this,
        );
        $this->bundles[] = $bundleBuilder;
        return $bundleBuilder;
    }

    /**
     * Create a bundle using a callback, without breaking the fluent chain.
     *
     * @param callable(\Prov\Builder\BundleBuilder): void $callback
     */
    public function withBundle(QualifiedName|string $identifier, callable $callback): static
    {
        $bundleBuilder = new BundleBuilder(
            $this->resolveBundleIdentifier($identifier),
            new NamespaceManager($this->namespaceManager),
            $this,
        );
        $callback($bundleBuilder);
        $this->bundles[] = $this->buildNestedBundle($bundleBuilder);
        return $this;
    }

    /**
     * Builds a bundle from its builder, passing the document-level output
     * flags down first.
     */
    private function buildNestedBundle(BundleBuilder $bundleBuilder): Bundle
    {
        if ($this->keepUnusedNamespaces) {
            $bundleBuilder->keepUnusedNamespaces();
        }
        if ($this->autoDeclareEntities) {
            $bundleBuilder->autoDeclareEntities();
        }
        return $bundleBuilder->build();
    }

    /**
     * Narrower resolver for bundle identifiers: unlike records, a bundle's identifier
     * cannot be null, so we always produce a concrete QualifiedName.
     */
    private function resolveBundleIdentifier(QualifiedName|string $identifier): QualifiedName
    {
        return $identifier instanceof QualifiedName ? $identifier : $this->namespaceManager->resolve($identifier);
    }

    /**
     * Attaches an already-built Bundle to the document. Useful when a
     * Bundle is constructed outside the fluent flow (e.g. deserialized).
     *
     * The bundle labels its blank nodes outside this builder's blank()
     * sequence, so the counter moves past every label the bundle keeps and a
     * later blank() cannot mint one of them. A label the bundle shares with
     * another container is renamed when the document is built, see
     * standardizeBlankNodesApart().
     */
    public function addBundle(Bundle $bundle): static
    {
        $this->advanceBlankNodeCounterPast($this->maxBlankNodeNumber($bundle->records));
        $this->bundles[] = $bundle;
        return $this;
    }

    /**
     * Renames the blank labels a bundle shares with a container that came
     * before it, so two independent anonymous records never end up under one
     * name.
     *
     * A blank label only names a record inside its own container, and
     * flatten() lifts bundle records to document level without renaming them.
     * Containers are handled in the order they were added, the document's own
     * records first, and each bundle is renamed against every label seen
     * before it. The document keeps its labels, so a QualifiedName the caller
     * still holds stays valid. Labels that do not collide are left alone, and
     * a bundle without a collision comes back as it went in.
     *
     * This runs at build time because a collision can show up late: an
     * explicit QualifiedName::blankNode() or `_:` string never passes through
     * blank(), and a bundle builder holds its records until build().
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *   The document's own records.
     * @param list<\Prov\Bundle> $bundles
     *   The bundles, in the order they were added.
     *
     * @return list<\Prov\Bundle>
     */
    private static function standardizeBlankNodesApart(array $records, array $bundles): array
    {
        // Only a bundle can collide with the document, so a document without
        // bundles skips the label walk over its own records.
        if ($bundles === []) {
            return [];
        }
        $taken = BlankNodes::labels($records);
        foreach ($bundles as $index => $bundle) {
            $labels = BlankNodes::labels($bundle->records);
            $colliding = array_intersect_key($labels, $taken);
            $taken += $labels;
            if ($colliding === []) {
                continue;
            }

            $renames = BlankNodes::renames($colliding, $taken);
            foreach ($renames as $fresh) {
                $taken[$fresh->getUri()] = true;
            }
            $mapName = static fn(QualifiedName $qn): QualifiedName => $renames[$qn->getUri()] ?? $qn;
            $rebuild = static fn(ProvRecord $record): ProvRecord => RecordRewriter::rebuild($record, $mapName);

            $bundles[$index] = new Bundle(
                identifier: $bundle->identifier,
                records: array_map($rebuild, $bundle->records),
                namespaces: $bundle->namespaces,
            );
        }
        return $bundles;
    }

    /**
     * Returns the highest numeric suffix among the `_:bN` labels these records
     * use, in any position a blank reference can occupy. Zero if none use one.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function maxBlankNodeNumber(array $records): int
    {
        $max = 0;
        foreach (array_keys(BlankNodes::labels($records)) as $label) {
            $max = max($max, $this->blankNodeNumber($label));
        }
        return $max;
    }

    /**
     * The numeric suffix of a `_:bN` blank-node label, or 0 for anything else
     * (a non-numeric blank label).
     */
    private function blankNodeNumber(string $label): int
    {
        if (!str_starts_with($label, '_:b')) {
            return 0;
        }
        $suffix = substr($label, strlen('_:b'));
        return ctype_digit($suffix) ? (int) $suffix : 0;
    }

    /**
     * Finalizes the builder and returns the immutable Document.
     *
     * Namespace declarations are pruned to the ones the document's records
     * (including bundle contents) actually reference, unless
     * `keepUnusedNamespaces()` was called. Bundles attached with `addBundle()`
     * keep their namespaces as built. Blank labels a bundle shares with the
     * document or with an earlier bundle are renamed apart, see
     * standardizeBlankNodesApart().
     *
     * @throws \LogicException
     *   On a second call: builders are single-use.
     */
    public function build(): Document
    {
        $this->markBuilt();

        $records = $this->records;
        if ($this->autoDeclareEntities) {
            $records = [...$records, ...self::autoDeclaredEntities($records)];
        }

        $bundles = [];
        foreach ($this->bundles as $bundle) {
            $bundles[] = $bundle instanceof BundleBuilder ? $this->buildNestedBundle($bundle) : $bundle;
        }
        $bundles = self::standardizeBlankNodesApart($records, $bundles);

        $namespaces = $this->namespaceManager->registeredNamespaces;
        if (!$this->keepUnusedNamespaces) {
            $usedUris = self::collectReferencedUris($records);
            foreach ($bundles as $bundle) {
                $usedUris[$bundle->identifier->namespace->uri] = true;
                $usedUris = self::collectReferencedUris($bundle->records, $usedUris);
            }
            $namespaces = self::pruneNamespaces($namespaces, $usedUris);
        }

        return new Document(records: $records, bundles: $bundles, namespaces: $namespaces);
    }
}
