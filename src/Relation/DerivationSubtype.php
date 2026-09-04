<?php

declare(strict_types=1);

namespace Prov\Relation;

use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;

/**
 * The three typed derivations PROV-DM defines: Revision, Quotation, and
 * PrimarySource. Each is a plain Derivation carrying the matching `prov:type`
 * value; PROV-N and PROV-XML also give each one a shortcut form
 * (`wasRevisionOf(...)`, a `<prov:revision>` element). The case value is the
 * local name of the `prov:type` value.
 *
 * `Derivation::subtype()` reads the subtype off a model record and
 * `ScannedRelation::$derivationSubtype` off a scanned PROV-JSON relation, so
 * a consumer never matches the type names itself.
 *
 * @see https://www.w3.org/TR/prov-dm/#term-Revision
 * @see https://www.w3.org/TR/prov-dm/#term-Quotation
 * @see https://www.w3.org/TR/prov-dm/#term-PrimarySource
 */
enum DerivationSubtype: string
{
    // The backing value is the local name of the prov:type value, so
    // `$subtype->value` gives `Revision` and `qualifiedName()` gives
    // `prov:Revision`. The PROV-N keyword is separate: see keyword().
    case Revision = 'Revision';
    case Quotation = 'Quotation';
    case PrimarySource = 'PrimarySource';

    /**
     * The PROV-N shortcut keyword: `wasRevisionOf`, `wasQuotedFrom`, or
     * `hadPrimarySource`. This is also what `EntityInvolvement::$relationType`
     * reports for a typed derivation.
     */
    public function keyword(): string
    {
        return match ($this) {
            self::Revision => 'wasRevisionOf',
            self::Quotation => 'wasQuotedFrom',
            self::PrimarySource => 'hadPrimarySource',
        };
    }

    /**
     * The `prov:type` value a Derivation of this subtype carries
     * (`prov:Revision`, `prov:Quotation`, `prov:PrimarySource`).
     */
    public function qualifiedName(): QualifiedName
    {
        return ProvNamespace::prov()->qualifiedName($this->value);
    }

    /**
     * The subtype a `prov:type` value names, or null when the value is not one
     * of the three PROV subtypes. Matches on the full URI, so the prefix the
     * value was written with does not matter.
     */
    public static function fromType(QualifiedName $type): ?self
    {
        $provUri = ProvNamespace::prov()->uri;
        if (!str_starts_with($type->uri, $provUri)) {
            return null;
        }
        return self::tryFrom(substr($type->uri, strlen($provUri)));
    }

    /**
     * The subtype a PROV-N shortcut keyword stands for, or null for any other
     * keyword (including the bare `wasDerivedFrom`).
     */
    public static function fromKeyword(string $keyword): ?self
    {
        return match ($keyword) {
            'wasRevisionOf' => self::Revision,
            'wasQuotedFrom' => self::Quotation,
            'hadPrimarySource' => self::PrimarySource,
            default => null,
        };
    }
}
