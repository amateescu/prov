<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Document;
use Prov\Model\BlankNodes;
use Prov\Model\ProvRecord;

/**
 * Mints a synthetic `_:bN` label for a record with no identifier. A format
 * that keys every node by an identifier (PROV-JSON's maps, JSON-LD's `@id`)
 * needs one to represent the record instead of dropping it.
 *
 * Each record's label is cached, so a later reference to the same record
 * reuses it. Minting skips any `_:bN` label the document already uses,
 * wherever it appears.
 *
 * @internal
 */
final class BlankLabelMinter
{
    /** @var \WeakMap<\Prov\Model\ProvRecord, string> */
    private \WeakMap $labels;

    private int $counter = 0;

    /** @var array<string, bool> Blank labels the document already uses; collected on first read. */
    private array $usedLabels {
        get => $this->usedLabels ??= $this->collectUsedLabels();
    }

    public function __construct(
        private readonly Document $document,
    ) {
        $this->labels = new \WeakMap();
    }

    /**
     * Returns the blank label for `$record`, minting and caching one on the
     * first request.
     */
    public function labelFor(ProvRecord $record): string
    {
        return $this->labels[$record] ??= $this->mint();
    }

    private function mint(): string
    {
        $used = $this->usedLabels;
        do {
            $label = '_:b' . ++$this->counter;
        } while (isset($used[$label]));
        return $label;
    }

    /**
     * @return array<string, bool>
     */
    private function collectUsedLabels(): array
    {
        $records = $this->document->records;
        foreach ($this->document->bundles as $bundle) {
            $records = [...$records, ...$bundle->records];
        }
        return BlankNodes::labels($records);
    }
}
