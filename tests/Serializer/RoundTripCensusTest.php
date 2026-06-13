<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Activity;
use Prov\Attribute\Literal;
use Prov\Document;
use Prov\Format;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Prov;
use Prov\Serializer\JsonSerializer;

/**
 * Round-trip census over the Southampton Provenance Suite fixtures: every field
 * of the original parsed document (identifiers, endpoints, times, attribute
 * keys, values, datatypes, language tags, namespace declarations) must survive
 * serialize + deserialize in every round-trip format.
 *
 * Unlike the fixture round-trip tests, the census walks the original document
 * field by field instead of relying on DocumentComparator::equals(), so a
 * comparator blind spot cannot mask a loss, and failures name the exact field
 * that disappeared.
 */
final class RoundTripCensusTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../vendor/openprov/testcases';

    /**
     * @return array<string, list<string>>
     */
    public static function fixtureProvider(): array
    {
        $dir = realpath(self::FIXTURES_DIR);
        if ($dir === false) {
            return [];
        }

        $files = glob($dir . '/test-*/*.json');
        if ($files === false) {
            return [];
        }

        $fixtures = [];
        foreach ($files as $file) {
            $fixtures[substr(basename(dirname($file)), 5)] = [$file];
        }

        ksort($fixtures);
        return $fixtures;
    }

    #[DataProvider('fixtureProvider')]
    public function testEveryFieldSurvivesRoundTrip(string $fixturePath): void
    {
        $json = file_get_contents($fixturePath);
        $this->assertNotFalse($json);
        $original = new JsonSerializer()->deserialize($json);

        foreach ([Format::Json, Format::ProvN, Format::Xml] as $format) {
            try {
                $serialized = Prov::serialize($original, $format);
            } catch (\InvalidArgumentException $e) {
                // Only PROV-XML may legitimately reject a representable document:
                // it encodes attribute keys as XML element names, so a key that
                // is not a valid NCName has no representation. PROV-N and PROV-JSON
                // escape every representable input (review items 2.2/2.5), so a
                // rejection there is a regression, not an allowed loss.
                if ($format !== Format::Xml) {
                    $this->fail("[{$format->name}] rejected a representable fixture: {$e->getMessage()}");
                }
                $this->addToAssertionCount(1);
                continue;
            }
            $this->assertCensus($original, Prov::deserialize($serialized, $format), $format->name);
        }
    }

    private function assertCensus(Document $original, Document $roundTripped, string $format): void
    {
        $this->assertNamespacesSurvive($original, $roundTripped, $format);
        $this->assertRecordsSurvive($original->records, $roundTripped->records, $format, '');

        foreach ($original->bundles as $bundle) {
            $rtBundle = $roundTripped->getBundleByIdentifier($bundle->identifier);
            $this->assertNotNull($rtBundle, "[{$format}] bundle '{$bundle->identifier}' lost");
            $this->assertRecordsSurvive(
                $bundle->records,
                $rtBundle->records,
                $format,
                "bundle '{$bundle->identifier}' ",
            );
        }
    }

    /**
     * Every declared (prefix, URI) pair must survive: deserialized documents
     * keep their declarations verbatim (namespace pruning applies only to
     * builder-built documents), and the serializers may add declarations
     * (synthetic prefixes) but never drop one.
     */
    private function assertNamespacesSurvive(Document $original, Document $roundTripped, string $format): void
    {
        $rt = [];
        foreach ($roundTripped->namespaces as $ns) {
            // PROV-XML declares the XSD namespace without the trailing '#'
            // while PROV-JSON declares it with one; both denote the same
            // namespace, so compare in normalized form.
            $rt[$ns->prefix] = self::normalizeDatatypeUri($ns->uri);
        }
        foreach ($original->namespaces as $ns) {
            $this->assertSame(
                self::normalizeDatatypeUri($ns->uri),
                $rt[$ns->prefix] ?? null,
                "[{$format}] namespace declaration '{$ns->prefix}' => '{$ns->uri}' lost",
            );
        }
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $original
     * @param list<\Prov\Model\ProvRecord> $roundTripped
     */
    private function assertRecordsSurvive(array $original, array $roundTripped, string $format, string $scope): void
    {
        // Index candidates: identified records by class + identifier URI,
        // anonymous (or blank-labelled) ones per class.
        $byId = [];
        $byClass = [];
        foreach ($roundTripped as $record) {
            $id = $record->identifier;
            if ($id !== null && !str_starts_with($id->getUri(), '_:')) {
                $byId[$record::class . '|' . $id->getUri()][] = $record;
            } else {
                $byClass[$record::class][] = $record;
            }
        }

        foreach ($original as $record) {
            $id = $record->identifier;
            $identified = $id !== null && !str_starts_with($id->getUri(), '_:');
            $candidates = $identified
                ? $byId[$record::class . '|' . $id->getUri()] ?? []
                : $byClass[$record::class] ?? [];

            $label = $identified ? (string) $id : 'anonymous';
            $this->assertNotEmpty(
                $candidates,
                "[{$format}] {$scope}record " . basename(str_replace('\\', '/', $record::class)) . "({$label}) lost",
            );

            $mismatch = null;
            foreach ($candidates as $candidate) {
                $mismatch = $this->fieldMismatch($record, $candidate);
                if ($mismatch === null) {
                    continue 2;
                }
            }
            $this->fail(
                "[{$format}] {$scope}no round-tripped record carries every field of "
                . basename(str_replace('\\', '/', $record::class))
                . "({$label}); closest mismatch: {$mismatch}",
            );
        }
    }

    /**
     * Returns a description of the first field of $a that $b does not carry,
     * or null when every field survives.
     */
    private function fieldMismatch(ProvRecord $a, ProvRecord $b): ?string
    {
        if ($a instanceof Activity && $b instanceof Activity) {
            foreach (['startTime', 'endTime'] as $field) {
                $timeA = $a->{$field};
                $timeB = $b->{$field};
                if (self::timeForm($timeA) !== self::timeForm($timeB)) {
                    return "{$field} '" . self::timeForm($timeA) . "'";
                }
            }
        }

        if ($a instanceof ProvRelation && $b instanceof ProvRelation) {
            $formalsA = RelationMetadata::extractFormals($a);
            $formalsB = RelationMetadata::extractFormals($b);
            foreach ($formalsA as $prop => $valueA) {
                $valueB = $formalsB[$prop] ?? null;
                if ($valueA instanceof QualifiedName) {
                    $uriA = self::referenceForm($valueA);
                    $uriB = $valueB instanceof QualifiedName ? self::referenceForm($valueB) : null;
                    if ($uriA !== $uriB) {
                        return "endpoint {$prop} '{$uriA}'";
                    }
                } elseif ($valueA instanceof \DateTimeImmutable) {
                    if (!$valueB instanceof \DateTimeImmutable || self::timeForm($valueA) !== self::timeForm($valueB)) {
                        return "time {$prop} '" . self::timeForm($valueA) . "'";
                    }
                } elseif (is_array($valueA)) {
                    if (!is_array($valueB) || count($valueA) !== count($valueB)) {
                        return "{$prop} entry count " . count($valueA);
                    }
                }
            }
        }

        // Attributes: every (key, value) of $a must appear in $b, as a multiset.
        $formsB = [];
        foreach ($b->attributes->all() as $keyUri => $values) {
            foreach ($values as $value) {
                $formsB[$keyUri][self::valueForm($value)] ??= 0;
                $formsB[$keyUri][self::valueForm($value)]++;
            }
        }
        foreach ($a->attributes->all() as $keyUri => $values) {
            foreach ($values as $value) {
                $form = self::valueForm($value);
                if (($formsB[$keyUri][$form] ?? 0) < 1) {
                    return "attribute <{$keyUri}> value {$form}";
                }
                $formsB[$keyUri][$form]--;
            }
        }

        return null;
    }

    private static function timeForm(?\DateTimeImmutable $time): string
    {
        return $time !== null ? $time->format('U.u') : '';
    }

    private static function referenceForm(QualifiedName $qn): string
    {
        // Blank labels are serialization-scoped; presence is what must survive.
        return str_starts_with($qn->getUri(), '_:') ? '_:' : $qn->getUri();
    }

    /**
     * A comparison token mirroring PROV-DM value equivalences: a bare string
     * equals an xsd:string literal, native scalars equal their canonical typed
     * literals, the xsd namespace matches with or without the trailing '#',
     * and rdf:XMLLiteral values compare by datatype only (their lexical form
     * is legitimately reformatted by XML pretty-printing).
     */
    private static function valueForm(mixed $value): string
    {
        if ($value instanceof QualifiedName) {
            return 'qn:' . self::referenceForm($value);
        }
        if ($value instanceof Literal) {
            $datatype = $value->datatype !== null ? self::normalizeDatatypeUri($value->datatype->getUri()) : null;
            if ($datatype === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral') {
                return 'lit:<xml>^^' . $datatype;
            }
            if ($datatype === null && $value->languageTag === null) {
                return 'lit:' . $value->value . '^^http://www.w3.org/2001/XMLSchema#string';
            }
            $form = 'lit:' . $value->value;
            if ($datatype !== null) {
                $form .= '^^' . $datatype;
            }
            if ($value->languageTag !== null) {
                $form .= '@' . strtolower($value->languageTag);
            }
            return $form;
        }
        if (is_string($value)) {
            return 'lit:' . $value . '^^http://www.w3.org/2001/XMLSchema#string';
        }
        if (is_bool($value)) {
            return 'lit:' . ($value ? 'true' : 'false') . '^^http://www.w3.org/2001/XMLSchema#boolean';
        }
        if (is_int($value)) {
            return 'lit:' . $value . '^^http://www.w3.org/2001/XMLSchema#int';
        }
        if (is_float($value)) {
            return 'lit:' . Literal::formatFloat($value) . '^^http://www.w3.org/2001/XMLSchema#float';
        }
        return 'other:' . get_debug_type($value);
    }

    private static function normalizeDatatypeUri(string $uri): string
    {
        $withoutHash = 'http://www.w3.org/2001/XMLSchema';
        if (str_starts_with($uri, $withoutHash) && !str_starts_with($uri, $withoutHash . '#')) {
            return $withoutHash . '#' . substr($uri, strlen($withoutHash));
        }
        return $uri;
    }
}
