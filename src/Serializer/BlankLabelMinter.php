<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Document;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Relation\Dictionary\DictionaryEntry;

/**
 * Mints a synthetic `_:bN` label for a record with no identifier. A format
 * that keys every node by an identifier (PROV-JSON's maps, JSON-LD's `@id`)
 * needs one to represent the record instead of dropping it.
 *
 * Each record's label is cached, so a later reference to the same record
 * reuses it. Minting skips any `_:bN` label the document already uses,
 * wherever it appears: an identifier, a relation's formal endpoint or
 * dictionary entity, or an attribute value.
 *
 * @internal
 *
 * @mago-ignore analysis:mixed-assignment
 */
final class BlankLabelMinter
{
    /** @var \WeakMap<\Prov\Model\ProvRecord, string> */
    private \WeakMap $labels;

    private int $counter = 0;

    /** @var array<string, true>|null Blank labels the document already uses; collected lazily. */
    private ?array $usedLabels = null;

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
        $this->usedLabels ??= $this->collectUsedLabels();
        $used = $this->usedLabels;
        do {
            $label = '_:b' . ++$this->counter;
        } while (isset($used[$label]));
        return $label;
    }

    /**
     * @return array<string, true>
     */
    private function collectUsedLabels(): array
    {
        $labels = [];
        $records = $this->document->records;
        foreach ($this->document->bundles as $bundle) {
            $records = [...$records, ...$bundle->records];
        }
        foreach ($records as $record) {
            $id = $record->identifier;
            if ($id !== null && str_starts_with($id->uri, '_:')) {
                $labels[$id->uri] = true;
            }
            if ($record instanceof ProvRelation) {
                foreach (RelationMetadata::extractFormals($record) as $value) {
                    if ($value instanceof QualifiedName) {
                        if (str_starts_with($value->uri, '_:')) {
                            $labels[$value->uri] = true;
                        }
                    } elseif (is_array($value)) {
                        $this->collectDictionaryLabels($value, $labels);
                    }
                }
            }
            foreach ($record->attributes->all() as $values) {
                foreach ($values as $value) {
                    if ($value instanceof QualifiedName && str_starts_with($value->uri, '_:')) {
                        $labels[$value->getUri()] = true;
                    }
                }
            }
        }
        return $labels;
    }

    /**
     * @param array<array-key, mixed> $items
     * @param array<string, true> $labels
     */
    private function collectDictionaryLabels(array $items, array &$labels): void
    {
        foreach ($items as $item) {
            if ($item instanceof DictionaryEntry && $item->entity !== null && $item->entity->isBlank()) {
                $labels[$item->entity->getUri()] = true;
            }
        }
    }
}
