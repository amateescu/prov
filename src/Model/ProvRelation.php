<?php

declare(strict_types=1);

namespace Prov\Model;

/**
 * Abstract base for every PROV-DM relation (Generation, Usage, Derivation,
 * and all the others). Each relation adds its own typed fields on top of
 * the common ProvRecord shape.
 *
 * @api
 */
abstract readonly class ProvRelation extends ProvRecord implements ProvRelationInterface {}
