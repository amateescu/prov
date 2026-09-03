<?php

declare(strict_types=1);

namespace Prov\Builder;

use Prov\Bundle;
use Prov\Document;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;
use Prov\Model\BlankNodes;

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
    /** @var list<\Prov\Bundle> */
    private array $bundles = [];

    /** @var list<\Prov\Builder\BundleBuilder> */
    private array $bundleBuilders = [];

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
        $this->bundleBuilders[] = $bundleBuilder;
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
        if ($this->keepUnusedNamespaces) {
            $bundleBuilder->keepUnusedNamespaces();
        }
        if ($this->autoDeclareEntities) {
            $bundleBuilder->autoDeclareEntities();
        }
        $this->bundles[] = $bundleBuilder->build();
        return $this;
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
     * The bundle mints its own `_:bN` labels outside this builder's blank()
     * sequence, so advance the counter past the highest one it uses. Without
     * that, a later blank() call could mint a label the bundle already uses,
     * and flatten() would then merge two unrelated anonymous records when it
     * lifts the bundle's records to document level without renaming.
     */
    public function addBundle(Bundle $bundle): static
    {
        $this->advanceBlankNodeCounterPast($this->maxBlankNodeNumber($bundle->records));
        $this->bundles[] = $bundle;
        return $this;
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
     * keep their namespaces as built.
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

        $bundles = $this->bundles;
        foreach ($this->bundleBuilders as $bb) {
            if ($this->keepUnusedNamespaces) {
                $bb->keepUnusedNamespaces();
            }
            if ($this->autoDeclareEntities) {
                $bb->autoDeclareEntities();
            }
            $bundles[] = $bb->build();
        }

        $namespaces = $this->namespaceManager->getRegisteredNamespaces();
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
