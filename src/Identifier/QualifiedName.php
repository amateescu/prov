<?php

declare(strict_types=1);

namespace Prov\Identifier;

/**
 * A PROV identifier: a namespace plus a local part that together resolve
 * to a full URI. Callers typically obtain one via
 * `NamespaceManager::resolve()` or `ProvNamespace::qualifiedName()` rather
 * than constructing directly, so prefix bindings stay consistent.
 *
 * The full URI is the identity of a QualifiedName: two instances with the
 * same URI but different prefixes name the same thing and serialize to the
 * same resource, while PHP's `==` compares the prefix too. Compare via
 * `getUri()` rather than object comparison.
 */
readonly class QualifiedName implements \Stringable
{
    public string $uri;
    private string $stringForm;

    /**
     * @param \Prov\Identifier\ProvNamespace $namespace
     *   The namespace the identifier lives in.
     * @param string $localPart
     *   The name within the namespace. Must be non-empty.
     *
     * @throws \InvalidArgumentException
     *   When the local part is empty.
     */
    public function __construct(
        public ProvNamespace $namespace,
        public string $localPart,
    ) {
        if ($this->localPart === '') {
            throw new \InvalidArgumentException(
                "Qualified name local part cannot be empty (namespace '{$this->namespace->uri}').",
            );
        }
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

    /**
     * The form to write in prefixed serialization formats (PROV-N, PROV-JSON,
     * PROV-XML): the `prefix:local` short form, except for an identifier in the
     * default namespace, where the bare local part is written. The reserved
     * `default` prefix is an internal sentinel and must never appear literally
     * in output; the bare local part resolves against the format's default
     * namespace declaration instead.
     */
    public function getSerializedForm(): string
    {
        return $this->namespace->prefix === 'default' ? $this->localPart : $this->stringForm;
    }

    /**
     * Whether this identifier is a blank node: an anonymous record label in
     * the reserved `_:` pseudo-namespace rather than a resolvable URI. Blank
     * labels are document-scoped; only their links matter, not their names.
     */
    public function isBlank(): bool
    {
        return str_starts_with($this->uri, '_:');
    }

    public function __toString(): string
    {
        return $this->stringForm;
    }
}
