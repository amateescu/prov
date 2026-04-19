<?php

declare(strict_types=1);

namespace Prov\Exception;

/**
 * Thrown on namespace-declaration problems: using an unregistered prefix,
 * conflicting prefix bindings on merge, or resolving an unprefixed name
 * with no default namespace set.
 */
class NamespaceException extends ProvException {}
