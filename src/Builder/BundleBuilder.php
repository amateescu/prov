<?php

declare(strict_types=1);

namespace Prov\Builder;

use Prov\Bundle;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\QualifiedName;

/**
 * Fluent builder for a Bundle inside a Document. Created via
 * `DocumentBuilder::bundle()` (detached builder) or `withBundle()` (inline
 * callback); call `build()` to finalize the Bundle. Single-use like its
 * parent.
 */
class BundleBuilder extends RecordBuilder
{
    public function __construct(
        private QualifiedName $identifier,
        ?NamespaceManager $namespaceManager = null,
        ?RecordBuilder $blankNodeScope = null,
    ) {
        $this->namespaceManager = $namespaceManager ?? new NamespaceManager();
        if ($blankNodeScope !== null) {
            $this->shareBlankNodeScope($blankNodeScope);
        }
    }

    /**
     * Finalizes the builder and returns the immutable Bundle.
     *
     * The bundle's own namespace declarations are pruned to the ones its
     * records actually reference, unless `keepUnusedNamespaces()` was called;
     * declarations inherited from the parent document are unaffected.
     *
     * @throws \LogicException
     *   On a second call: builders are single-use.
     */
    public function build(): Bundle
    {
        $this->markBuilt();

        $records = $this->records;
        if ($this->autoDeclareEntities) {
            $records = [...$records, ...self::autoDeclaredEntities($records)];
        }

        $namespaces = $this->namespaceManager->registeredNamespaces;
        if (!$this->keepUnusedNamespaces) {
            $usedUris = self::collectReferencedUris($records);
            $usedUris[$this->identifier->getUri()] = true;
            $namespaces = self::pruneNamespaces($namespaces, $usedUris);
        }

        return new Bundle(identifier: $this->identifier, records: $records, namespaces: $namespaces);
    }
}
