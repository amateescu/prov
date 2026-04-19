<?php

declare(strict_types=1);

namespace Prov\Exception;

/**
 * Base class for every exception the library throws. Catching this type
 * covers deserialization errors, namespace conflicts, constraint-violation
 * failures, and the serialize-only-format case.
 */
class ProvException extends \RuntimeException {}
