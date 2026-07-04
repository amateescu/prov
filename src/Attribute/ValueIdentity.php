<?php

declare(strict_types=1);

namespace Prov\Attribute;

use Prov\Identifier\QualifiedName;

/**
 * Canonical identity string for an attribute value.
 *
 * Maps each value to a token such that two values denoting the same PROV-DM
 * value collapse: a bare scalar and the canonical xsd:* Literal it round-trips
 * to, and an untyped Literal and an explicit xsd:string Literal, all sign
 * identically. `Attributes` uses this to dedup identical attribute-value pairs
 * (PROV-DM models a record's attributes as a set of pairs), and
 * `Prov\Operation\DocumentComparator` uses it for semantic equality, so the two
 * never disagree about whether two values are the same.
 *
 * @internal
 */
final class ValueIdentity
{
    public const string XSD_STRING_URI = 'http://www.w3.org/2001/XMLSchema#string';
    public const string XML_LITERAL_URI = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral';
    public const string XSD_QNAME_URI = 'http://www.w3.org/2001/XMLSchema#QName';

    /**
     * @param ?array<string, string> $blankLabels
     *   When null, blank-node references sign by their raw `_:bN` URI, so two
     *   distinct anonymous references stay distinct (the dedup semantics used at
     *   construction). When an array, a blank reference signs by its canonical
     *   label (or a `_:?` mask while labels are still being computed), so blank
     *   nodes compare up to renaming (the equality semantics used by the
     *   comparator).
     */
    public static function signature(
        QualifiedName|Literal|string|int|float|bool $value,
        ?array $blankLabels = null,
    ): string {
        if ($value instanceof QualifiedName) {
            $uri = $value->getUri();
            if (str_starts_with($uri, '_:')) {
                $uri = $blankLabels === null ? $uri : $blankLabels[$uri] ?? '_:?';
            }
            return 'qn:' . $uri;
        }
        if ($value instanceof Literal) {
            $datatype = $value->datatype !== null ? self::normalizeDatatypeUri($value->datatype->getUri()) : null;
            // PROV-DM default: a literal without an explicit datatype and without a language
            // tag is an xsd:string. Normalize so bare strings and xsd:string-typed literals
            // sign identically regardless of format.
            if ($datatype === null && $value->languageTag === null) {
                return 'lit:' . $value->value . '^^' . self::XSD_STRING_URI;
            }
            $literalValue = $datatype === self::XML_LITERAL_URI
                ? self::normalizeXmlLiteral($value->value)
                : $value->value;
            $sig = 'lit:' . $literalValue;
            if ($datatype !== null) {
                $sig .= '^^' . $datatype;
            }
            if ($value->languageTag !== null) {
                $sig .= '@' . $value->languageTag;
            }
            return $sig;
        }
        if (is_string($value)) {
            return 'lit:' . $value . '^^' . self::XSD_STRING_URI;
        }
        // Native scalars sign identically to the canonical xsd:* Literal a round-trip
        // through PROV-N/XML produces, so a value stays equal across formats. The token is
        // built inline to avoid allocating a Literal and QualifiedName on this hot path.
        if (is_bool($value)) {
            return 'lit:' . ($value ? 'true' : 'false') . '^^http://www.w3.org/2001/XMLSchema#boolean';
        }
        if (is_int($value)) {
            // ProvNSerializer and XmlSerializer type a bare int outside the
            // 32-bit xsd:int range as xsd:long. Match that here. Signing every
            // int as xsd:int would make such a value compare unequal to its
            // own round trip and to the equal Literal::long() value.
            $datatype = $value < Literal::XSD_INT_MIN || $value > Literal::XSD_INT_MAX ? 'long' : 'int';
            return 'lit:' . $value . '^^http://www.w3.org/2001/XMLSchema#' . $datatype;
        }
        return 'lit:' . Literal::formatFloat($value) . '^^http://www.w3.org/2001/XMLSchema#float';
    }

    /**
     * The PROV-XML fixtures declare xsd: without a trailing `#` while PROV-JSON fixtures
     * declare it with one. Both point at the same W3C XSD namespace. Normalize so
     * `.../XMLSchemastring` and `.../XMLSchema#string` compare equal.
     */
    public static function normalizeDatatypeUri(string $uri): string
    {
        $withoutHash = 'http://www.w3.org/2001/XMLSchema';
        if (str_starts_with($uri, $withoutHash) && !str_starts_with($uri, $withoutHash . '#')) {
            return $withoutHash . '#' . substr($uri, strlen($withoutHash));
        }
        return $uri;
    }

    /**
     * Strips inter-element whitespace from an rdf:XMLLiteral value so that the same
     * XML fragment serialized compactly (PROV-JSON) or pretty-printed (PROV-XML)
     * signs identically. Returns the input unchanged if it doesn't parse as XML.
     */
    private static function normalizeXmlLiteral(string $value): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $doc->preserveWhiteSpace = false;
            if (!$doc->loadXML('<r xmlns:_="_">' . $value . '</r>', LIBXML_NONET)) {
                return $value;
            }
            $root = $doc->documentElement;
            if ($root === null) {
                return $value;
            }
            $out = '';
            foreach ($root->childNodes as $child) {
                if ($child instanceof \DOMNode) {
                    $out .= $doc->saveXML($child) ?: '';
                }
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
