<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;

/**
 * Minimal shape every PROV record satisfies: an optional identifier and
 * an Attributes bag. Implemented by ProvRecord and its subclasses.
 *
 * @api
 */
interface ProvRecordInterface
{
    public ?QualifiedName $identifier { get; }

    public Attributes $attributes { get; }
}
