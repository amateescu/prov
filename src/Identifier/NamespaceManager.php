<?php

declare(strict_types=1);

namespace Prov\Identifier;

use Prov\Exception\NamespaceException;

/**
 * Collects and resolves namespace declarations for builders, serializers, and deserializers.
 *
 * The prov/xsd namespaces are preregistered as library built-ins so callers can use
 * prefixed names without explicit declaration. Documents that authoritatively redeclare
 * a built-in (e.g. PROV-XML fixtures that use a non-canonical URI) silently override
 * the built-in. Redeclaring any other prefix with a different URI throws, since that
 * indicates a caller mistake.
 */
class NamespaceManager
{
    /** @var array<string, \Prov\Identifier\ProvNamespace> */
    private array $namespaces = [];

    /** @var array<string, true> Prefixes that were installed as library built-ins. */
    private array $builtinPrefixes = [];

    /** @var array<string, \Prov\Identifier\QualifiedName> Memoized resolve() results for repeated shorthands. */
    private array $resolveCache = [];

    /** @var array<string, string> Memoized uriToPrefixed() results (full URI => prefix:local or URI passthrough). */
    private array $uriToPrefixedCache = [];

    private ?ProvNamespace $default = null;

    public function __construct(
        private ?NamespaceManager $parent = null,
    ) {
        foreach ([ProvNamespace::prov(), ProvNamespace::xsd()] as $ns) {
            $this->namespaces[$ns->prefix] = $ns;
            $this->builtinPrefixes[$ns->prefix] = true;
        }
    }

    /**
     * Registers a namespace. Silently overrides a built-in (prov/xsd) if the caller
     * declares it with a different URI. Throws on conflict with any previously
     * user-registered prefix.
     */
    public function add(ProvNamespace $ns): void
    {
        $existing = $this->namespaces[$ns->prefix] ?? null;
        if ($existing !== null) {
            if ($existing->uri === $ns->uri) {
                // Same URI; no structural change. Keep the existing instance so
                // any cached QualifiedNames referencing it stay valid. Clear the
                // built-in marker because the prefix has now been declared by
                // the caller.
                unset($this->builtinPrefixes[$ns->prefix]);
                return;
            }
            if (!isset($this->builtinPrefixes[$ns->prefix])) {
                throw new NamespaceException(
                    "Prefix '{$ns->prefix}' is already registered with URI '{$existing->uri}', cannot re-register with URI '{$ns->uri}'.",
                );
            }
        }
        $this->namespaces[$ns->prefix] = $ns;
        unset($this->builtinPrefixes[$ns->prefix]);
        // A new-URI entry invalidates any cached resolutions that may have
        // used the old binding.
        $this->resolveCache = [];
        $this->uriToPrefixedCache = [];
    }

    /**
     * Declares the default namespace used to expand unprefixed identifiers.
     * Also registers the namespace so its prefix is available.
     */
    public function setDefault(ProvNamespace $ns): void
    {
        $this->default = $ns;
        $this->add($ns);
    }

    /**
     * Resolves a string identifier to a QualifiedName. Accepts three formats:
     *  - Full URI: "http://example.org/foo" (matched against registered namespace URIs)
     *  - Prefixed: "ex:foo" (prefix must be registered)
     *  - Unprefixed: "foo" (requires a default namespace)
     *
     * @throws \Prov\Exception\NamespaceException
     *   If the prefix is not registered or no default namespace is set.
     */
    public function resolve(string $shorthand): QualifiedName
    {
        if (isset($this->resolveCache[$shorthand])) {
            return $this->resolveCache[$shorthand];
        }

        // Blank-node sentinel (e.g. "_:b1"): an anonymous record identifier, not a
        // prefixed name to resolve against a namespace. Mirrors RecordBuilder::blank()
        // so a blank node round-trips back to the same QualifiedName it serialized from.
        if (str_starts_with($shorthand, '_:')) {
            return $this->resolveCache[$shorthand] = new QualifiedName(
                new ProvNamespace('_', '_:'),
                substr($shorthand, 2),
            );
        }

        // Try the full-URI path only if the input actually looks like one.
        // Prefixed shorthands like "ex:e1" contain ':' but would waste a linear
        // scan of all namespaces in resolveUri().
        if (str_contains($shorthand, '://')) {
            $resolved = $this->resolveUri($shorthand);
            if ($resolved !== null) {
                return $this->resolveCache[$shorthand] = $resolved;
            }
        }

        if (str_contains($shorthand, ':')) {
            [$prefix, $localPart] = explode(':', $shorthand, 2);
            $ns = $this->getNamespace($prefix);
            if ($ns === null) {
                throw new NamespaceException("Prefix '{$prefix}' is not registered.");
            }
            return $this->resolveCache[$shorthand] = $ns->qualifiedName($localPart);
        }

        $default = $this->getDefault();
        if ($default === null) {
            throw new NamespaceException("No default namespace set for unprefixed identifier '{$shorthand}'.");
        }

        return $this->resolveCache[$shorthand] = $default->qualifiedName($shorthand);
    }

    /**
     * Attempts to resolve a full URI against registered namespaces.
     */
    public function resolveUri(string $uri): ?QualifiedName
    {
        foreach ($this->getAllNamespaces() as $ns) {
            if (str_starts_with($uri, $ns->uri) && $uri !== $ns->uri) {
                $localPart = substr($uri, strlen($ns->uri));
                if ($localPart !== '' && !str_contains($localPart, '/') && !str_contains($localPart, '#')) {
                    return $ns->qualifiedName($localPart);
                }
            }
        }
        return $this->parent?->resolveUri($uri);
    }

    /**
     * Converts a full URI to a prefixed string (e.g. "prov:type"), or returns the URI as-is.
     */
    public function uriToPrefixed(string $uri): string
    {
        if (isset($this->uriToPrefixedCache[$uri])) {
            return $this->uriToPrefixedCache[$uri];
        }

        foreach ($this->getAllNamespaces() as $ns) {
            if (str_starts_with($uri, $ns->uri) && $uri !== $ns->uri) {
                $localPart = substr($uri, strlen($ns->uri));
                return $this->uriToPrefixedCache[$uri] = $ns->prefix . ':' . $localPart;
            }
        }
        return $this->uriToPrefixedCache[$uri] = $uri;
    }

    /**
     * @return array<string, \Prov\Identifier\ProvNamespace>
     */
    private function getAllNamespaces(): array
    {
        $all = $this->namespaces;
        if ($this->parent !== null) {
            $all += $this->parent->getAllNamespaces();
        }
        return $all;
    }

    /**
     * Returns the namespace bound to `$prefix`, falling back to the parent
     * manager if any; null if nothing is bound at this level or above.
     */
    public function getNamespace(string $prefix): ?ProvNamespace
    {
        return $this->namespaces[$prefix] ?? $this->parent?->getNamespace($prefix);
    }

    /**
     * @return list<\Prov\Identifier\ProvNamespace>
     */
    public function getRegisteredNamespaces(): array
    {
        return array_values($this->namespaces);
    }

    private function getDefault(): ?ProvNamespace
    {
        return $this->default ?? $this->parent?->getDefault();
    }
}
