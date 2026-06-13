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
     * The inclusive bounds of the 32-bit `xsd:int` value space. A PHP int
     * outside this range cannot be represented as `xsd:int`; use `long()`.
     */
    public const int XSD_INT_MIN = -2_147_483_648;
    public const int XSD_INT_MAX = 2_147_483_647;

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

    /**
     * Literal typed as `xsd:int`.
     *
     * @throws \InvalidArgumentException
     *   When the value falls outside the 32-bit xsd:int range; use `long()`
     *   for 64-bit values.
     */
    public static function int(int $value): self
    {
        if ($value < self::XSD_INT_MIN || $value > self::XSD_INT_MAX) {
            throw new \InvalidArgumentException(
                "Value {$value} is outside the 32-bit xsd:int range; use Literal::long() for 64-bit values.",
            );
        }
        return new self((string) $value, ProvNamespace::xsd()->qualifiedName('int'));
    }

    /** Literal typed as `xsd:boolean`. */
    // @mago-expect lint:no-boolean-flag-parameter
    public static function boolean(bool $value): self
    {
        return new self($value ? 'true' : 'false', ProvNamespace::xsd()->qualifiedName('boolean'));
    }

    /** Literal typed as `xsd:dateTime`, including sub-second precision when present. */
    public static function dateTime(\DateTimeImmutable $value): self
    {
        return new self(self::formatDateTime($value), ProvNamespace::xsd()->qualifiedName('dateTime'));
    }

    /** Literal typed as `xsd:float`. */
    public static function float(float $value): self
    {
        return new self(self::formatFloat($value), ProvNamespace::xsd()->qualifiedName('float'));
    }

    /** Literal typed as `xsd:double`. */
    public static function double(float $value): self
    {
        return new self(self::formatFloat($value), ProvNamespace::xsd()->qualifiedName('double'));
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

    /**
     * Formats a float as a round-trippable, locale-independent lexical string.
     *
     * Uses the shortest representation that parses back to the same value
     * (driven by serialize_precision), unlike a plain `(string)` cast which is
     * bound by the `precision` ini setting and can drop significant digits.
     */
    public static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }
        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        return $encoded === false ? (string) $value : $encoded;
    }

    /**
     * Formats a datetime as an xsd:dateTime lexical string, including sub-second
     * precision only when the value actually carries microseconds (so whole-second
     * timestamps keep their canonical ISO 8601 / ATOM form).
     */
    public static function formatDateTime(\DateTimeImmutable $value): string
    {
        $pattern = $value->format('u') === '000000' ? \DateTimeInterface::ATOM : 'Y-m-d\TH:i:s.uP';
        return $value->format($pattern);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
