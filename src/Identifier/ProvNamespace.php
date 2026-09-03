<?php

declare(strict_types=1);

namespace Prov\Identifier;

/**
 * A namespace declaration: a prefix (the short form used in serialized
 * output) and the URI it expands to. Turns prefixed shorthands like
 * `ex:article` into full-URI QualifiedName values.
 */
readonly class ProvNamespace implements \Stringable
{
    /**
     * The shared `prov:` namespace (`http://www.w3.org/ns/prov#`).
     */
    public static function prov(): self
    {
        static $instance = new self('prov', 'http://www.w3.org/ns/prov#');
        return $instance;
    }

    /**
     * The shared `xsd:` namespace (`http://www.w3.org/2001/XMLSchema#`).
     */
    public static function xsd(): self
    {
        static $instance = new self('xsd', 'http://www.w3.org/2001/XMLSchema#');
        return $instance;
    }

    /**
     * @param string $prefix
     *   The short form used in serialized output. Must be non-empty; the
     *   library represents a document's default namespace with the reserved
     *   prefix "default".
     * @param string $uri
     *   The URI the prefix expands to. Must be non-empty. The blank-node
     *   sentinel namespace ('_', '_:') is the one special form, minted
     *   internally for anonymous records.
     *
     * @throws \InvalidArgumentException
     *   When the prefix or URI is empty.
     */
    public function __construct(
        public string $prefix,
        public string $uri,
    ) {
        if ($this->prefix === '') {
            throw new \InvalidArgumentException(
                "Namespace prefix cannot be empty (URI '{$this->uri}'); use the reserved prefix 'default' for a default namespace.",
            );
        }
        if ($this->uri === '') {
            throw new \InvalidArgumentException("Namespace URI cannot be empty (prefix '{$this->prefix}').");
        }
    }

    /**
     * Builds a QualifiedName in this namespace for the given local part.
     */
    public function qualifiedName(string $localPart): QualifiedName
    {
        return new QualifiedName($this, $localPart);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->uri;
    }
}
