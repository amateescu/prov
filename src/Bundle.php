<?php

declare(strict_types=1);

namespace Prov;

use Prov\Identifier\QualifiedName;
use Prov\Model\RecordContainer;

/**
 * A named group of records inside a Document. Reach for a Bundle when you
 * want to describe provenance *about* a collection of records: who asserted
 * them, when, under what authority. Unlike a Document, a Bundle has its own
 * identifier and cannot contain further bundles.
 */
readonly class Bundle extends RecordContainer
{
    /**
     * @param list<\Prov\Model\ProvRecord> $records
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     */
    public function __construct(
        public QualifiedName $identifier,
        array $records,
        public array $namespaces,
    ) {
        parent::__construct($records);
    }
}
