<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Model\ProvRecord;
use Prov\Model\RelationMetadata;

/**
 * Output ordering shared by the serializers.
 *
 * PROV serializations are unordered (a document is a set of records, namespaces
 * a set of declarations), so reordering never changes meaning. These helpers
 * make output deterministic and readable: namespace declarations are always
 * sorted (the prov and xsd built-ins first, then alphabetically by prefix), and
 * records can optionally be sorted into PROV-DM concept order.
 *
 * @internal
 */
final class OutputOrder
{
    private const string PROV_URI = 'http://www.w3.org/ns/prov#';
    private const string XSD_URI = 'http://www.w3.org/2001/XMLSchema#';

    /**
     * Sorts namespace declarations: the prov and xsd built-ins first (in that
     * order), then the rest alphabetically by prefix.
     *
     * @param list<\Prov\Identifier\ProvNamespace> $namespaces
     *
     * @return list<\Prov\Identifier\ProvNamespace>
     */
    public static function namespaces(array $namespaces): array
    {
        usort(
            $namespaces,
            static fn(ProvNamespace $a, ProvNamespace $b): int => (
                self::namespaceRank($a->uri) <=> self::namespaceRank($b->uri) ?: strcmp($a->prefix, $b->prefix)
            ),
        );
        return $namespaces;
    }

    /**
     * Sorts a `prefix => URI` map by the same rule as `namespaces()`: built-ins
     * first, then alphabetically by prefix.
     *
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    public static function prefixMap(array $map): array
    {
        uksort(
            $map,
            static fn(string $a, string $b): int => (
                self::namespaceRank($map[$a]) <=> self::namespaceRank($map[$b]) ?: strcmp($a, $b)
            ),
        );
        return $map;
    }

    /**
     * Orders records into PROV-DM concept order: elements first (entity,
     * activity, agent), then relations in PROV-DM component order (the
     * RelationMetadata declaration order). Within one construct, records with an
     * identifier sort by its URI; anonymous records keep their relative order
     * (the sort is stable) and follow the identified ones.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return list<\Prov\Model\ProvRecord>
     */
    public static function records(array $records): array
    {
        /** @var array<class-string, int> $relationRank */
        $relationRank = array_flip(array_keys(RelationMetadata::FORMALS));
        usort($records, static function (ProvRecord $a, ProvRecord $b) use ($relationRank): int {
            $rank = self::recordRank($a, $relationRank) <=> self::recordRank($b, $relationRank);
            if ($rank !== 0) {
                return $rank;
            }
            $uriA = $a->identifier?->getUri();
            $uriB = $b->identifier?->getUri();
            if ($uriA === null || $uriB === null) {
                // Anonymous records follow identified ones; two anonymous records
                // keep their original order.
                return ($uriA === null ? 1 : 0) <=> ($uriB === null ? 1 : 0);
            }
            return strcmp($uriA, $uriB);
        });
        return $records;
    }

    private static function namespaceRank(string $uri): int
    {
        return match ($uri) {
            self::PROV_URI => 0,
            self::XSD_URI => 1,
            default => 2,
        };
    }

    /**
     * @param array<class-string, int> $relationRank
     */
    private static function recordRank(ProvRecord $record, array $relationRank): int
    {
        return match (true) {
            $record instanceof Entity => 0,
            $record instanceof Activity => 1,
            $record instanceof Agent => 2,
            default => 3 + ($relationRank[$record::class] ?? count($relationRank)),
        };
    }
}
