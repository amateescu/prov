<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Attribute\Attributes;
use Prov\Identifier\QualifiedName;

/**
 * Abstract root of every PROV-DM record: the elements (Entity, Activity,
 * Agent) and the relations (Generation, Usage, etc.). Carries an optional
 * identifier and an Attributes bag.
 *
 * @api
 */
abstract readonly class ProvRecord implements ProvRecordInterface
{
    public function __construct(
        public ?QualifiedName $identifier,
        public Attributes $attributes = new Attributes(),
    ) {}
}
