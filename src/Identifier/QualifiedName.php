<?php

declare(strict_types=1);

namespace Prov\Identifier;

/**
 * A PROV identifier: a namespace plus a local part that together resolve
 * to a full URI. Callers typically obtain one via
 * `NamespaceManager::resolve()` or `ProvNamespace::qualifiedName()` rather
 * than constructing directly, so prefix bindings stay consistent.
 */
readonly class QualifiedName implements \Stringable
{
    public string $uri;
    private string $stringForm;

    public function __construct(
        public ProvNamespace $namespace,
        public string $localPart,
    ) {
        $this->uri = $this->namespace->uri . $this->localPart;
        $this->stringForm = $this->namespace->prefix . ':' . $this->localPart;
    }

    /**
     * The full URI form (namespace URI concatenated with the local part).
     * Shorthand for the public `$uri` property.
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    public function __toString(): string
    {
        return $this->stringForm;
    }
}
