<?php

declare(strict_types=1);

namespace Prov;

use Prov\Builder\DocumentBuilder;
use Prov\Constraint\ConstraintValidator;
use Prov\Constraint\ConstraintViolationList;

/**
 * Convenience facade for the library's common operations: build a new
 * document, serialize to or deserialize from a PROV format, and run the
 * PROV-CONSTRAINTS validator. Reach for the underlying classes directly for
 * anything richer.
 *
 * @see \Prov\Builder\DocumentBuilder
 * @see Format
 * @see \Prov\Constraint\ConstraintValidator
 */
final class Prov
{
    /**
     * Starts a fresh DocumentBuilder. Equivalent to `new DocumentBuilder()`;
     * lets callers that already have `Prov\Prov` in scope skip a second
     * `use` import.
     */
    public static function documentBuilder(): DocumentBuilder
    {
        return new DocumentBuilder();
    }

    /**
     * Serializes a Document into the given PROV format (defaults to PROV-JSON).
     */
    #[\NoDiscard]
    public static function serialize(Document $document, Format $format = Format::Json): string
    {
        return $format->createSerializer()->serialize($document);
    }

    /**
     * Parses a serialized PROV document into a Document using the given format.
     *
     * @throws \Prov\Exception\ProvException
     *   When deserializing a serialize-only format (PROV-JSONLD).
     */
    public static function deserialize(string $data, Format $format = Format::Json): Document
    {
        return $format->createDeserializer()->deserialize($data);
    }

    /**
     * Runs the PROV-CONSTRAINTS checks against a document and returns the
     * violation list. Chain `->throwIfInvalid()` on the result to get an
     * exception-based flow.
     */
    public static function validate(Document $document): ConstraintViolationList
    {
        return new ConstraintValidator()->validate($document);
    }
}
