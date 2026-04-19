<?php

declare(strict_types=1);

namespace Prov\Attribute;

use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

/**
 * A typed-literal value (RDF-style): a lexical form plus either an XSD
 * datatype or a BCP 47 language tag (mutually exclusive). Prefer the
 * static factories (`string()`, `int()`, `boolean()`, `dateTime()`, etc.)
 * over the raw constructor.
 */
readonly class Literal implements \Stringable
{
    /**
     * @param string $value
     *   The lexical value of the literal.
     * @param ?\Prov\Identifier\QualifiedName $datatype
     *   XSD datatype (e.g. xsd:string). Mutually exclusive with $languageTag.
     * @param ?string $languageTag
     *   BCP 47 language tag (e.g. "en"). Mutually exclusive with $datatype.
     */
    public function __construct(
        public string $value,
        public ?QualifiedName $datatype = null,
        public ?string $languageTag = null,
    ) {
        if ($this->datatype !== null && $this->languageTag !== null) {
            throw new \InvalidArgumentException('Literal cannot have both a datatype and a language tag.');
        }
    }

    // Convenience factories: each wraps the value as a Literal tagged with
    // the matching xsd: datatype.

    /** Literal typed as `xsd:string`. */
    public static function string(string $value): self
    {
        return new self($value, ProvNamespace::xsd()->qualifiedName('string'));
    }

    /** Literal typed as `xsd:int`. */
    public static function int(int $value): self
    {
        return new self((string) $value, ProvNamespace::xsd()->qualifiedName('int'));
    }

    /** Literal typed as `xsd:boolean`. */
    // @mago-expect lint:no-boolean-flag-parameter
    public static function boolean(bool $value): self
    {
        return new self($value ? 'true' : 'false', ProvNamespace::xsd()->qualifiedName('boolean'));
    }

    /** Literal typed as `xsd:dateTime`, formatted in ISO 8601 / ATOM form. */
    public static function dateTime(\DateTimeImmutable $value): self
    {
        return new self($value->format(\DateTimeInterface::ATOM), ProvNamespace::xsd()->qualifiedName('dateTime'));
    }

    /** Literal typed as `xsd:float`. */
    public static function float(float $value): self
    {
        return new self((string) $value, ProvNamespace::xsd()->qualifiedName('float'));
    }

    /** Literal typed as `xsd:double`. */
    public static function double(float $value): self
    {
        return new self((string) $value, ProvNamespace::xsd()->qualifiedName('double'));
    }

    /** Literal typed as `xsd:long`. */
    public static function long(int $value): self
    {
        return new self((string) $value, ProvNamespace::xsd()->qualifiedName('long'));
    }

    /** Literal typed as `xsd:decimal`. The value is passed as a string to preserve precision. */
    public static function decimal(string $value): self
    {
        return new self($value, ProvNamespace::xsd()->qualifiedName('decimal'));
    }

    /** Literal typed as `xsd:anyURI`. */
    public static function anyURI(string $value): self
    {
        return new self($value, ProvNamespace::xsd()->qualifiedName('anyURI'));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
