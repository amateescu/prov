<?php

declare(strict_types=1);

namespace Prov\Identifier;

use Prov\Exception\NamespaceException;

/**
 * Collects and resolves namespace declarations for builders, serializers, and deserializers.
 *
 * The prov/xsd namespaces are preregistered as library built-ins so callers can use
 * prefixed names without explicit declaration. `add()` is strict: redeclaring any
 * prefix (built-in or caller-declared) with a different URI throws, so a typo cannot
 * silently corrupt a binding. Deserializers and serializers, which faithfully reproduce
 * whatever a foreign document declares (including a non-canonical prov/xsd URI), use
 * `addOrReplace()` to opt out of that strictness.
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
     * Builds a manager preloaded from a container's declared namespaces (a
     * Document's, a Bundle's, or a foreign document's parsed prefix map),
     * routing the reserved "default" prefix to `setDefault()` and everything
     * else to `addOrReplace()`. This is the shape every serializer,
     * deserializer, and `ProvGraph` needs: the source document is the
     * authority on its own declarations, so a redeclared or non-canonical
     * prov/xsd binding replaces the built-in rather than throwing.
     *
     * @param iterable<\Prov\Identifier\ProvNamespace> $namespaces
     */
    public static function forContainer(iterable $namespaces, ?NamespaceManager $parent = null): self
    {
        $manager = new self($parent);
        foreach ($namespaces as $ns) {
            if ($ns->prefix === 'default') {
                $manager->setDefault($ns);
            } else {
                $manager->addOrReplace($ns);
            }
        }
        return $manager;
    }

    /**
     * Strips the reserved "default:" sentinel a prefixed key resolves to when
     * its URI belongs to the default namespace, leaving the bare local name
     * to resolve against the format's own default-namespace mechanism
     * (PROV-N's `default <uri>` declaration, PROV-JSON's `default` prefix
     * entry, JSON-LD's `@vocab`). The sentinel itself must never be written.
     */
    public static function stripDefaultSentinel(string $key): string
    {
        return str_starts_with($key, 'default:') ? substr($key, strlen('default:')) : $key;
    }

    /**
     * Registers a namespace, throwing on any conflict.
     *
     * Re-registering a prefix with the same URI is a no-op (the existing
     * instance is kept so cached QualifiedNames stay valid). Re-registering it
     * with a different URI throws, whether the prefix is a library built-in
     * (prov/xsd) or a previous caller declaration. Use `addOrReplace()` to
     * deliberately rebind.
     *
     * @throws \Prov\Exception\NamespaceException
     *   When the prefix is already bound to a different URI.
     */
    public function add(ProvNamespace $ns): void
    {
        $existing = $this->namespaces[$ns->prefix] ?? null;
        if ($existing !== null) {
            if ($existing->uri === $ns->uri) {
                // Same binding; no structural change. Clear the built-in marker
                // because the prefix is now a caller declaration.
                unset($this->builtinPrefixes[$ns->prefix]);
                return;
            }
            if (isset($this->builtinPrefixes[$ns->prefix])) {
                throw new NamespaceException(
                    "Prefix '{$ns->prefix}' is a library built-in bound to '{$existing->uri}'; "
                    . "use addOrReplace() to rebind it to '{$ns->uri}'.",
                );
            }
            throw new NamespaceException(
                "Prefix '{$ns->prefix}' is already registered with URI '{$existing->uri}', cannot re-register with URI '{$ns->uri}'.",
            );
        }
        $this->namespaces[$ns->prefix] = $ns;
        $this->invalidateCaches();
    }

    /**
     * Registers a namespace, rebinding the prefix if it is already bound to a
     * different URI. The deserializer/serializer counterpart to `add()`: a
     * foreign document is the authority on its own declarations, so a
     * non-canonical prov/xsd URI (or any redeclared prefix) replaces the prior
     * binding rather than throwing.
     */
    public function addOrReplace(ProvNamespace $ns): void
    {
        $existing = $this->namespaces[$ns->prefix] ?? null;
        if ($existing !== null && $existing->uri === $ns->uri) {
            unset($this->builtinPrefixes[$ns->prefix]);
            return;
        }
        $this->namespaces[$ns->prefix] = $ns;
        unset($this->builtinPrefixes[$ns->prefix]);
        $this->invalidateCaches();
    }

    /**
     * A new-URI entry invalidates any cached resolutions that used the old
     * binding.
     */
    private function invalidateCaches(): void
    {
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
            // Without this guard the prefix branch below would report the URI
            // scheme as an unregistered prefix (e.g. "Prefix 'http' is not registered").
            throw new NamespaceException("No registered namespace matches URI '{$shorthand}'.");
        }

        if (str_contains($shorthand, ':')) {
            [$prefix, $localPart] = explode(':', $shorthand, 2);
            $ns = $this->getNamespace($prefix);
            if ($ns !== null) {
                return $this->resolveCache[$shorthand] = $ns->qualifiedName($localPart);
            }
            // The leading segment is not a registered prefix, so the input may
            // itself be a full URI in a scheme without '//' (urn:, tag:,
            // mailto:, ...). Match it against registered namespace URIs before
            // giving up.
            $resolved = $this->resolveUri($shorthand);
            if ($resolved !== null) {
                return $this->resolveCache[$shorthand] = $resolved;
            }
            throw new NamespaceException(
                "Prefix '{$prefix}' is not registered and no namespace URI matches '{$shorthand}'.",
            );
        }

        $default = $this->getDefault();
        if ($default === null) {
            throw new NamespaceException("No default namespace set for unprefixed identifier '{$shorthand}'.");
        }

        return $this->resolveCache[$shorthand] = $default->qualifiedName($shorthand);
    }

    /**
     * Attempts to resolve a full URI against registered namespaces.
     *
     * The most specific (longest URI) namespace wins, so a URI under a nested
     * namespace (`http://e/sub/`) is not mis-split against its parent
     * (`http://e/`). Once the matching namespace is fixed, the remaining local
     * part is accepted even when it contains `/` or `#`, so versioned
     * identifiers (`42/rev/7`) and fragment-relative names resolve.
     */
    public function resolveUri(string $uri): ?QualifiedName
    {
        foreach ($this->namespacesByUriLengthDesc() as $ns) {
            if ($uri !== $ns->uri && str_starts_with($uri, $ns->uri)) {
                $localPart = substr($uri, strlen($ns->uri));
                if ($localPart !== '') {
                    return $ns->qualifiedName($localPart);
                }
            }
        }
        return null;
    }

    /**
     * Converts a full URI to a prefixed string (e.g. "prov:type"), or returns
     * the URI as-is. Uses the same longest-match-first semantics as
     * `resolveUri()`, so the most specific namespace supplies the prefix and
     * the remaining local part may contain `/` or `#`.
     */
    public function uriToPrefixed(string $uri): string
    {
        if (isset($this->uriToPrefixedCache[$uri])) {
            return $this->uriToPrefixedCache[$uri];
        }

        foreach ($this->namespacesByUriLengthDesc() as $ns) {
            if ($uri !== $ns->uri && str_starts_with($uri, $ns->uri)) {
                $localPart = substr($uri, strlen($ns->uri));
                if ($localPart !== '') {
                    return $this->uriToPrefixedCache[$uri] = $ns->prefix . ':' . $localPart;
                }
            }
        }
        return $this->uriToPrefixedCache[$uri] = $uri;
    }

    /**
     * Every visible namespace (this manager's own plus any inherited from the
     * parent), ordered by URI length descending so the most specific namespace
     * is tried first when matching a URI.
     *
     * @return list<\Prov\Identifier\ProvNamespace>
     */
    private function namespacesByUriLengthDesc(): array
    {
        $namespaces = array_values($this->getAllNamespaces());
        usort($namespaces, static fn(ProvNamespace $a, ProvNamespace $b): int => strlen($b->uri) <=> strlen($a->uri));
        return $namespaces;
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
