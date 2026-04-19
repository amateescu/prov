<?php

declare(strict_types=1);

namespace Prov\Exception;

/**
 * Thrown by deserializers when the input isn't a valid document in their
 * format (malformed syntax, wrong root element, unexpected DOCTYPE, etc.).
 */
class DeserializationException extends ProvException {}
