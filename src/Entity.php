<?php

declare(strict_types=1);

namespace Prov;

use Prov\Model\ProvElement;

/**
 * A PROV-DM Entity: a thing whose provenance is being described. Entities
 * can be physical, digital, or conceptual; what matters is that you're
 * referring to a stable, identifiable version of it (a specific revision of
 * a document, a snapshot of a dataset).
 */
readonly class Entity extends ProvElement {}
