<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;

/**
 * Converts attribute-key URIs to prefixed form during serialization, minting
 * a synthetic prefix when no declared namespace covers the URI.
 *
 * A QualifiedName whose namespace was never declared on the document would
 * otherwise serialize as a bare URI, producing unparseable PROV-N/PROV-JSON
 * output and broken XML element names. Minted namespaces are registered on
 * the document-level NamespaceManager (so bundle-level lookups chain to
 * them) and reported via `getMintedNamespaces()` so serializers can emit
 * the matching declarations.
 *
 * @internal
 */
final class PrefixMinter
{
    /** @var array<string, \Prov\Identifier\ProvNamespace> Minted namespaces by namespace URI. */
    private array $minted = [];

    /**
     * @param \Prov\Identifier\NamespaceManager $documentManager
     *   The document-level manager that minted namespaces are registered on.
     */
    public function __construct(
        private readonly NamespaceManager $documentManager,
    ) {}

    /**
     * Converts a full URI to a prefixed string, minting a synthetic prefix
     * when neither `$manager` nor a previous mint covers it. A URI with no
     * splittable local part (nothing after the last '#' or '/') is returned
     * as-is.
     */
    public function uriToPrefixed(string $uri, NamespaceManager $manager): string
    {
        $prefixed = $manager->uriToPrefixed($uri);
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

        $ns = $this->minted[$nsUri] ?? null;
        if ($ns === null) {
            $ns = new ProvNamespace($this->mintPrefix($nsUri), $nsUri);
            $this->documentManager->add($ns);
            $this->minted[$nsUri] = $ns;
        }

        return $ns->prefix . ':' . $localPart;
    }

    /**
     * The namespaces minted so far, for emission as prefix declarations.
     *
     * @return list<\Prov\Identifier\ProvNamespace>
     */
    public function getMintedNamespaces(): array
    {
        return array_values($this->minted);
    }

    /**
     * Derives a deterministic prefix from the namespace URI, stepping around
     * the unlikely case of a declared prefix with the same name.
     */
    private function mintPrefix(string $nsUri): string
    {
        $base = sprintf('ns%u', crc32($nsUri));
        $candidate = $base;
        $suffix = 2;
        while ($this->documentManager->getNamespace($candidate) !== null) {
            $candidate = $base . '_' . $suffix++;
        }
        return $candidate;
    }
}
