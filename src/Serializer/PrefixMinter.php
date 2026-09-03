<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

/**
 * Decides how a qualified name or an attribute-key URI is written in
 * serialized output, minting a synthetic prefix when no declared namespace
 * covers it.
 *
 * A QualifiedName whose namespace was never declared on the document would
 * otherwise serialize as a bare URI, producing unparseable PROV-N/PROV-JSON
 * output and broken XML element names. Minted namespaces are registered on
 * the document-level NamespaceManager (so bundle-level lookups chain to
 * them) and reported via `getMintedNamespaces()` so serializers can emit
 * the matching declarations.
 *
 * Every method takes the namespace scope the name is written in: the document
 * manager for a document-level record, the bundle manager for a record inside
 * a bundle. A bundle may rebind a prefix the document already binds to another
 * URI, so a prefix that is correct at document level can name a different
 * namespace inside that bundle. The scope decides which prefixes are visible,
 * and every cached decision is kept per scope.
 *
 * @internal
 */
final class PrefixMinter
{
    /**
     * Namespaces this minter declared, grouped by namespace URI. One URI can
     * hold more than one entry: a prefix declared for a document-level name can
     * be shadowed inside a bundle, and the name then needs a second prefix that
     * is visible there.
     *
     * @var array<string, list<\Prov\Identifier\ProvNamespace>>
     */
    private array $minted = [];

    /**
     * Resolved prefixFor() results per scope, keyed by "prefix\0uri". A scope
     * that is garbage collected takes its entries with it.
     *
     * @var \WeakMap<\Prov\Identifier\NamespaceManager, array<string, string>>
     */
    private \WeakMap $prefixForCache;

    /**
     * @param \Prov\Identifier\NamespaceManager $documentManager
     *   The document-level manager that minted namespaces are registered on.
     */
    public function __construct(
        private readonly NamespaceManager $documentManager,
    ) {
        $this->prefixForCache = new \WeakMap();
    }

    /**
     * Writes a qualified name as the `prefix:local` token for `$scope`.
     * `$localPart` is the local part as the format escapes it; it defaults to
     * the name's own.
     *
     * A name in the scope's own default namespace is written bare, and a blank
     * node keeps its reserved `_` prefix; neither needs (or can take) a
     * declaration. A default-namespace name from another scope (a
     * document-level name inside a bundle that rebinds the default) would read
     * back as the bundle's default, so it gets a real prefix through
     * `prefixFor()` like every other name.
     */
    public function token(QualifiedName $qn, NamespaceManager $scope, ?string $localPart = null): string
    {
        $ns = $qn->namespace;
        $localPart ??= $qn->localPart;

        if ($ns->prefix === 'default') {
            if ($scope->getDefaultNamespace()?->uri === $ns->uri) {
                return $localPart;
            }
            return $this->prefixFor($qn, $scope) . ':' . $localPart;
        }

        if ($qn->isBlank()) {
            return $ns->prefix . ':' . $localPart;
        }

        $prefix = $this->prefixFor($qn, $scope);

        // The name's precomputed string form is exactly "prefix:local" when
        // neither part changed; reuse it rather than allocate an identical
        // string for every record and reference.
        if ($prefix === $ns->prefix && $localPart === $qn->localPart) {
            return (string) $qn;
        }
        return $prefix . ':' . $localPart;
    }

    /**
     * Converts a full URI to a prefixed string, minting a synthetic prefix
     * when neither `$scope` nor a previous mint covers it. A URI with no
     * splittable local part (nothing after the last '#' or '/') is returned
     * as-is.
     */
    public function uriToPrefixed(string $uri, NamespaceManager $scope): string
    {
        $prefixed = $scope->uriToPrefixed($uri);
        if ($prefixed !== $uri) {
            return $prefixed;
        }

        $hashPos = strrpos($uri, '#');
        $slashPos = strrpos($uri, '/');
        $pos = max($hashPos === false ? -1 : $hashPos, $slashPos === false ? -1 : $slashPos);
        $localPart = substr($uri, $pos + 1);
        if ($pos < 0 || $localPart === '') {
            return $uri;
        }
        $nsUri = substr($uri, 0, $pos + 1);

        $ns = $this->visibleMint($nsUri, $scope);
        if ($ns === null) {
            $ns = $this->declare(new ProvNamespace($this->mintPrefix($nsUri, $scope), $nsUri));
        }

        return $ns->prefix . ':' . $localPart;
    }

    /**
     * Returns the prefix to write for a qualified name in `$scope`, ensuring
     * its namespace is declared: minting a synthetic prefix when no visible
     * declaration covers it, and preferring the name's own prefix when that
     * prefix is free. Unlike `uriToPrefixed()`, the name's own namespace
     * boundary is preserved, so a local part containing `/` or `#` is never
     * re-split.
     *
     * The reserved `default` prefix is a sentinel and is never written or
     * declared; a name carrying it resolves to an existing declaration of the
     * same URI or to a minted real prefix.
     */
    public function prefixFor(QualifiedName $qn, NamespaceManager $scope): string
    {
        $ns = $qn->namespace;

        // Once resolved, a given (scope, prefix, uri) triple always reproduces
        // the same answer: the resolution guarantees a matching declaration
        // visible in that scope, and this minter only ever declares prefixes
        // that were free there. The same names recur across every record, so
        // cache by that triple.
        $cacheKey = $ns->prefix . "\0" . $ns->uri;
        $scopeCache = $this->prefixForCache[$scope] ?? [];
        $cached = $scopeCache[$cacheKey] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $prefix = $this->resolvePrefix($ns, $scope);
        $scopeCache[$cacheKey] = $prefix;
        $this->prefixForCache[$scope] = $scopeCache;
        return $prefix;
    }

    /**
     * Computes the prefix for a namespace in a scope, declaring or minting as
     * needed. The uncached body of prefixFor().
     */
    private function resolvePrefix(ProvNamespace $ns, NamespaceManager $scope): string
    {
        // The name's own prefix, when the scope binds it to the name's URI.
        if ($ns->prefix !== 'default' && $scope->getNamespace($ns->prefix)?->uri === $ns->uri) {
            return $ns->prefix;
        }

        // A prefix this minter already declared for the URI, when the scope
        // still shows it (a bundle can rebind it to something else).
        $minted = $this->visibleMint($ns->uri, $scope);
        if ($minted !== null) {
            return $minted->prefix;
        }

        // Any other visible declaration of the URI beats declaring an alias.
        foreach ($scope->getVisibleNamespaces() as $visible) {
            if ($visible->uri === $ns->uri && $visible->prefix !== 'default') {
                return $visible->prefix;
            }
        }

        $prefix = $this->isFree($ns->prefix, $scope) ? $ns->prefix : $this->mintPrefix($ns->uri, $scope);
        return $this->declare(new ProvNamespace($prefix, $ns->uri))->prefix;
    }

    /**
     * The namespaces minted so far, for emission as prefix declarations.
     *
     * @return list<\Prov\Identifier\ProvNamespace>
     */
    public function getMintedNamespaces(): array
    {
        $out = [];
        foreach ($this->minted as $namespaces) {
            foreach ($namespaces as $ns) {
                $out[] = $ns;
            }
        }
        return $out;
    }

    /**
     * Registers a namespace on the document manager and records it for
     * emission.
     */
    private function declare(ProvNamespace $ns): ProvNamespace
    {
        $this->documentManager->add($ns);
        $this->minted[$ns->uri][] = $ns;
        return $ns;
    }

    /**
     * A namespace this minter already declared for `$uri` whose prefix is still
     * bound to that URI in `$scope`, or null when none is.
     */
    private function visibleMint(string $uri, NamespaceManager $scope): ?ProvNamespace
    {
        foreach ($this->minted[$uri] ?? [] as $ns) {
            if ($scope->getNamespace($ns->prefix)?->uri === $ns->uri) {
                return $ns;
            }
        }
        return null;
    }

    /**
     * Whether a prefix can be declared for a new URI: it must be a real prefix
     * and bound nowhere, both in the scope the name is written in and on the
     * document manager the declaration lands on.
     */
    private function isFree(string $prefix, NamespaceManager $scope): bool
    {
        return (
            $prefix !== ''
            && $prefix !== 'default'
            && $scope->getNamespace($prefix) === null
            && $this->documentManager->getNamespace($prefix) === null
        );
    }

    /**
     * Derives a deterministic prefix from the namespace URI, stepping around
     * the unlikely case of a prefix with the same name already in use.
     */
    private function mintPrefix(string $nsUri, NamespaceManager $scope): string
    {
        $base = sprintf('ns%u', crc32($nsUri));
        $candidate = $base;
        $suffix = 2;
        while (!$this->isFree($candidate, $scope)) {
            $candidate = $base . '_' . $suffix++;
        }
        return $candidate;
    }
}
