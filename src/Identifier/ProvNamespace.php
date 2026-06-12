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
        /** @var self|null $instance */
        static $instance = null;
        return $instance ??= new self('prov', 'http://www.w3.org/ns/prov#');
    }

    /**
     * The shared `xsd:` namespace (`http://www.w3.org/2001/XMLSchema#`).
     */
    public static function xsd(): self
    {
        /** @var self|null $instance */
        static $instance = null;
        return $instance ??= new self('xsd', 'http://www.w3.org/2001/XMLSchema#');
    }

    /**
     * Mints a namespace under an RFC 8141 `urn:uuid:` URN with an optional
     * f-component (fragment) path.
     *
     * The URI is `urn:uuid:{uuid}`, followed by `#{fragmentPath}` when a
     * fragment path is given. The UUID is validated up front so callers cannot
     * silently produce a garbage URN such as `urn:uuid:#node/` from an empty
     * or malformed identifier.
     *
     * @param string $prefix
     *   The short prefix bound to the minted namespace.
     * @param string $uuid
     *   A canonical RFC 4122 UUID (8-4-4-4-12 hexadecimal digits),
     *   case-insensitive.
     * @param string $fragmentPath
     *   The f-component appended after '#'. A single leading '#' is tolerated
     *   and not duplicated. Empty yields a bare `urn:uuid:` namespace. End the
     *   path with a delimiter (e.g. `node/`): qualifiedName() concatenates
     *   local parts directly onto it, so `node` would mint `#node42`.
     *
     * @throws \InvalidArgumentException
     *   When the UUID is empty or not a well-formed RFC 4122 UUID.
     */
    public static function urnUuid(string $prefix, string $uuid, string $fragmentPath = ''): self
    {
        if (\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new \InvalidArgumentException(
                $uuid === ''
                    ? 'A urn:uuid namespace requires a non-empty UUID.'
                    : "Malformed UUID '{$uuid}'; expected the RFC 4122 8-4-4-4-12 hexadecimal form.",
            );
        }

        $uri = 'urn:uuid:' . $uuid;
        if ($fragmentPath !== '') {
            $uri .= '#' . \ltrim($fragmentPath, '#');
        }

        return new self($prefix, $uri);
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

    /**
     * Whether the given QualifiedName's URI begins with this namespace's URI.
     *
     * This is a raw prefix test with no longest-match or local-part guard: an
     * identifier under a nested namespace (`http://e/sub/x`) also reports true
     * for the parent (`http://e/`). It is not safe for resolving a URI to its
     * most specific namespace; use `NamespaceManager::resolveUri()` or
     * `uriToPrefixed()` for that.
     */
    public function contains(QualifiedName $identifier): bool
    {
        return str_starts_with($identifier->getUri(), $this->uri);
    }

    public function __toString(): string
    {
        return $this->uri;
    }
}
