<?php

declare(strict_types=1);

namespace Prov;

use Prov\Identifier\QualifiedName;
use Prov\Model\RecordContainer;

/**
 * The top-level PROV-DM container. Holds a canonical list of records
 * (entities, activities, agents, and the relations between them) plus any
 * bundles that scope further records under their own identifier.
 *
 * Build one with a `DocumentBuilder` or parse one via `Prov::deserialize()`;
 * pass to `Prov::serialize()` to write it back out, or `Prov::validate()` to
 * check against PROV-CONSTRAINTS.
 *
 * Most reads go through the typed views: `$entities`, `$activities`,
 * `$agents`, and `$relations`, all precomputed at construction. `$records`
 * has the full list in declaration order if you need it. Sub-graphs live on
 * `$bundles`, and namespace declarations on `$namespaces`.
 */
readonly class Document extends RecordContainer
{
    /**
     * @param list<\Prov\Model\ProvRecord> $records
     * @param list<\Prov\Bundle> $bundles
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     */
    public function __construct(
        array $records,
        public array $bundles,
        public array $namespaces,
    ) {
        parent::__construct($records);
    }

    /**
     * Linear scan over `$bundles`; O(n). Documents rarely carry more than a
     * handful of bundles, so a dedicated index is not maintained.
     */
    public function getBundleByIdentifier(QualifiedName $identifier): ?Bundle
    {
        $target = $identifier->getUri();
        foreach ($this->bundles as $bundle) {
            if ($bundle->identifier->getUri() === $target) {
                return $bundle;
            }
        }
        return null;
    }
}
