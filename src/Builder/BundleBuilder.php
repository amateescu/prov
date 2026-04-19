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
    ) {
        $this->namespaceManager = $namespaceManager ?? new NamespaceManager();
    }

    /**
     * Finalizes the builder and returns the immutable Bundle.
     *
     * @throws \LogicException
     *   On a second call: builders are single-use.
     */
    public function build(): Bundle
    {
        $this->markBuilt();

        return new Bundle(
            identifier: $this->identifier,
            records: $this->records,
            namespaces: $this->namespaceManager->getRegisteredNamespaces(),
        );
    }
}
