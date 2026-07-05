<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\DeserializationException;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\RelationMetadata;
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Dictionary\DictionaryInsertion;
use Prov\Relation\Dictionary\DictionaryMembership;
use Prov\Relation\Dictionary\DictionaryRemoval;
use Prov\Relation\End;
use Prov\Relation\Generation;
use Prov\Relation\Influence;
use Prov\Relation\Invalidation;
use Prov\Relation\Membership;
use Prov\Relation\Mention;
use Prov\Relation\Specialization;
use Prov\Relation\Start;
use Prov\Relation\Usage;

/**
 * Parses PROV-N documents into Document instances. Paired with
 * `ProvNSerializer` for the write direction.
 */
class ProvNDeserializer implements ProvDeserializerInterface
{
    /**
     * For each relation keyword whose grammar has a formal `time` slot, the
     * zero-based index of that slot in the args list (mirrors the `time`
     * entries in `RelationMetadata::FORMALS`). A keyword not listed here has
     * no time slot, so its arguments are never held to the dateTime shape.
     */
    private const array TIME_ARG_INDEX = [
        'wasGeneratedBy' => 2,
        'used' => 2,
        'wasInvalidatedBy' => 2,
        'wasStartedBy' => 3,
        'wasEndedBy' => 3,
    ];

    private string $input;
    private int $pos;
    private int $len;

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $data): Document
    {
        $this->input = $data;
        $this->pos = 0;
        $this->len = strlen($data);

        $this->skip();
        $this->keyword('document');

        $nsManager = new NamespaceManager();
        $records = [];
        $bundles = [];

        try {
            $this->parseBody($nsManager, $records, $bundles);
        } catch (NamespaceException|\InvalidArgumentException $e) {
            // An unresolvable or invalid identifier (undeclared prefix, missing
            // default namespace, empty local part) means the input is
            // malformed; surface it under the deserialization contract.
            throw $this->err($e->getMessage());
        }
        assert(is_array($bundles));

        $this->keyword('endDocument');

        return new Document(records: $records, bundles: $bundles, namespaces: $nsManager->getRegisteredNamespaces());
    }

    // --- Body parsing ---

    /**
     * Parses the stream of prefix declarations, records, and bundles that make up
     * a document or bundle body (between the `document`/`bundle` opener and its
     * `endDocument`/`endBundle` terminator).
     *
     * @param list<\Prov\Model\ProvRecord> $records
     * @param list<\Prov\Bundle>|null $bundles
     *   Null when inside a bundle scope (bundles cannot nest).
     */
    private function parseBody(NamespaceManager $nsManager, array &$records, ?array &$bundles): void
    {
        while ($this->pos < $this->len) {
            $this->skip();
            if ($this->pos >= $this->len) {
                break;
            }

            // Inline of peekWord(): skip() already ran above, so consume the word
            // span directly without an extra call.
            $wordLen = strspn($this->input, self::WORD_CHARS, $this->pos);
            if ($wordLen === 0) {
                throw $this->err('Expected keyword.');
            }
            $kw = substr($this->input, $this->pos, $wordLen);

            // Terminators are leaf-rare but cheap to short-circuit here.
            if ($kw === 'endDocument' || $kw === 'endBundle') {
                // Leave in stream; caller's keyword() consumes it.
                break;
            }

            // Dictionary relations use prov: prefix; parseDictionaryRelation
            // consumes the full prov:name QName itself, so don't advance here.
            if ($kw === 'prov') {
                $saved = $this->pos;
                $this->skip();
                $fullKw = $this->readQName();
                $this->pos = $saved;
                $shortKw = str_starts_with($fullKw, 'prov:') ? substr($fullKw, 5) : $fullKw;
                if (
                    $shortKw === 'hadDictionaryMember'
                    || $shortKw === 'derivedByInsertionFrom'
                    || $shortKw === 'derivedByRemovalFrom'
                ) {
                    $this->parseDictionaryRelation($nsManager, $records, $shortKw);
                    continue;
                }
            }

            // Consume the keyword; handlers skip the redundant keyword() check.
            $this->pos += $wordLen;

            // Common cases first to minimize match string compares.
            match ($kw) {
                'entity' => $this->parseEntity($nsManager, $records),
                'activity' => $this->parseActivity($nsManager, $records),
                'wasGeneratedBy',
                'used',
                'wasInformedBy',
                'wasStartedBy',
                'wasEndedBy',
                'wasInvalidatedBy',
                'wasDerivedFrom',
                'wasRevisionOf',
                'wasQuotedFrom',
                'hadPrimarySource',
                'wasAttributedTo',
                'wasAssociatedWith',
                'actedOnBehalfOf',
                'wasInfluencedBy',
                'specializationOf',
                'alternateOf',
                'hadMember',
                'mentionOf',
                    => $this->parseRelation($nsManager, $records, $kw),
                'agent' => $this->parseAgent($nsManager, $records),
                'prefix' => $this->parsePrefix($nsManager),
                'default' => $this->parseDefault($nsManager),
                'bundle' => $this->parseBundle($nsManager, $bundles),
                default => throw $this->err("Unexpected keyword '{$kw}'."),
            };
        }
    }

    // --- Namespace declarations ---

    private function parsePrefix(NamespaceManager $nsManager): void
    {
        $this->skip();
        $prefix = $this->readWord();
        $this->skip();
        $uri = $this->readIri();
        if ($prefix === '' || $uri === '') {
            throw $this->err('Malformed prefix declaration.');
        }

        $nsManager->addOrReplace(new ProvNamespace($prefix, $uri));
    }

    private function parseDefault(NamespaceManager $nsManager): void
    {
        $this->skip();
        $uri = $this->readIri();
        if ($uri === '') {
            throw $this->err('Malformed default namespace declaration.');
        }

        $nsManager->setDefault(new ProvNamespace('default', $uri));
    }

    // --- Bundle ---

    /**
     * @param list<\Prov\Bundle>|null $bundles
     */
    private function parseBundle(NamespaceManager $nsManager, ?array &$bundles): void
    {
        if ($bundles === null) {
            throw $this->err('Bundles cannot be nested.');
        }

        $this->skip();
        $id = $this->readQName();

        $childNs = new NamespaceManager($nsManager);
        $bundleRecords = [];
        $nestedBundles = null;
        $this->parseBody($childNs, $bundleRecords, $nestedBundles);
        $this->keyword('endBundle');

        $bundles[] = new Bundle(
            identifier: $this->resolveQName($id, $nsManager),
            records: $bundleRecords,
            namespaces: $childNs->getRegisteredNamespaces(),
        );
    }

    // --- Elements ---

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseEntity(NamespaceManager $nsManager, array &$records): void
    {
        $this->expect('(');
        $id = $this->readOptionalId();

        $attrs = null;
        $this->skip();
        $ch = $this->pos < $this->len ? $this->input[$this->pos] : null;
        if ($ch === ',') {
            $this->pos++;
            $this->skip();
            if ($this->pos < $this->len && $this->input[$this->pos] === '[') {
                $attrs = $this->parseAttrList($nsManager);
            }
            $this->skip();
            $ch = $this->pos < $this->len ? $this->input[$this->pos] : null;
        }
        if ($ch !== ')') {
            $this->expect(')');
        } else {
            $this->pos++;
        }
        $records[] = new Entity(
            $id !== null ? $this->resolveQName($id, $nsManager) : null,
            $attrs ?? Attributes::empty(),
        );
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseActivity(NamespaceManager $nsManager, array &$records): void
    {
        $this->expect('(');
        $id = $this->readOptionalId();

        $startTime = null;
        $endTime = null;
        $attrs = null;

        $this->skip();
        if ($this->pos < $this->len && $this->input[$this->pos] === ',') {
            $this->pos++;
            $this->skip();

            if ($this->pos < $this->len && $this->input[$this->pos] === '[') {
                $attrs = $this->parseAttrList($nsManager);
            } else {
                $startTime = $this->readOptionalTime();
                $this->expect(',');
                $endTime = $this->readOptionalTime();

                $this->skip();
                if ($this->pos < $this->len && $this->input[$this->pos] === ',') {
                    $this->pos++;
                    $this->skip();
                    if ($this->pos < $this->len && $this->input[$this->pos] === '[') {
                        $attrs = $this->parseAttrList($nsManager);
                    }
                }
            }
        }

        $this->expect(')');
        $records[] = new Activity(
            $id !== null ? $this->resolveQName($id, $nsManager) : null,
            $startTime,
            $endTime,
            $attrs ?? Attributes::empty(),
        );
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseAgent(NamespaceManager $nsManager, array &$records): void
    {
        $this->expect('(');
        $id = $this->readOptionalId();

        $attrs = null;
        $this->skip();
        $ch = $this->pos < $this->len ? $this->input[$this->pos] : null;
        if ($ch === ',') {
            $this->pos++;
            $this->skip();
            if ($this->pos < $this->len && $this->input[$this->pos] === '[') {
                $attrs = $this->parseAttrList($nsManager);
            }
            $this->skip();
            $ch = $this->pos < $this->len ? $this->input[$this->pos] : null;
        }
        if ($ch !== ')') {
            $this->expect(')');
        } else {
            $this->pos++;
        }
        $records[] = new Agent(
            $id !== null ? $this->resolveQName($id, $nsManager) : null,
            $attrs ?? Attributes::empty(),
        );
    }

    // --- Relations ---

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseRelation(NamespaceManager $nsManager, array &$records, string $kw): void
    {
        $this->expect('(');

        $parsed = $this->parseRelationArgs($nsManager, $kw);

        $this->expect(')');

        if (isset(RelationMetadata::DERIVATION_SUBTYPES[$kw])) {
            [$id, $args, $attrs] = $parsed;
            $typeValue = $nsManager->resolve('prov:' . RelationMetadata::DERIVATION_SUBTYPES[$kw]);
            $attrs = ($attrs ?? Attributes::empty())->with($nsManager->resolve('prov:type'), $typeValue);
            $parsed = [$id, $args, $attrs];
            $kw = 'wasDerivedFrom';
        }

        $this->buildRelation($nsManager, $records, $kw, $parsed);
    }

    /**
     * @param string $kw
     *   The relation keyword being parsed (e.g. 'wasGeneratedBy'). Selects the
     *   time-slot index from TIME_ARG_INDEX, so a dateTime is only expected in
     *   the argument position where the grammar has a time slot.
     *
     * @return array{0: ?string, 1: list<string|null|\DateTimeImmutable>, 2: ?\Prov\Attribute\Attributes}
     */
    private function parseRelationArgs(NamespaceManager $nsManager, string $kw): array
    {
        $id = null;
        $args = [];
        $attrs = null;
        $timeIndex = self::TIME_ARG_INDEX[$kw] ?? null;

        // No relation in TIME_ARG_INDEX has its time slot at index 0, so this
        // first read never lands on a time slot, whether it becomes $id (via
        // the optional "id;" prefix) or $args[0]. Reading it without the
        // time-shape check is safe.
        $first = $this->readArgValue();
        $this->skip();

        if ($this->pos < $this->len && $this->input[$this->pos] === ';') {
            $this->pos++;
            $id = $first instanceof \DateTimeImmutable ? null : $first;
            $this->skip();
        } else {
            $args[] = $first;
        }

        while (true) {
            if ($this->pos >= $this->len) {
                throw $this->err('Unexpected end of input while parsing relation arguments.');
            }
            $ch = $this->input[$this->pos];
            if ($ch === ')') {
                break;
            }
            if ($ch === ',') {
                $this->pos++;
                $this->skip();
                if ($this->pos >= $this->len) {
                    throw $this->err('Unexpected end of input while parsing relation arguments.');
                }
                $ch = $this->input[$this->pos];
                if ($ch === ')') {
                    break;
                }
            }
            if ($ch === '[') {
                $attrs = $this->parseAttrList($nsManager);
                $this->skip();
                break;
            }
            $before = $this->pos;
            $args[] = $this->readArgValue(count($args) === $timeIndex);
            $this->skip();
            // Progress guard: malformed inputs (e.g. "used(") can reach this branch
            // without advancing $this->pos; bail rather than spin.
            if ($this->pos === $before) {
                throw $this->err('Malformed relation arguments: no progress in parser loop.');
            }
        }

        return [$id, $args, $attrs];
    }

    // @mago-expect lint:no-boolean-flag-parameter
    private function readArgValue(bool $expectTime = false): string|\DateTimeImmutable|null
    {
        $this->skip();
        if ($this->pos >= $this->len) {
            throw $this->err('Unexpected end of input while reading an argument value.');
        }
        $ch = $this->input[$this->pos];

        // "-" followed by an argument delimiter is PROV-N's null marker.
        if ($ch === '-' && $this->isDashDelimiter($this->pos + 1)) {
            $this->pos++;
            return null;
        }

        if ($ch >= '0' && $ch <= '9') {
            $val = $this->readUntilDelim();
            $isDatePrefixed = strlen($val) >= 5 && $val[4] === '-' && preg_match('/^\d{4}-\d{2}-\d{2}/', $val) === 1;
            if ($isDatePrefixed && preg_match('/^\d{4}-\d{2}-\d{2}T/', $val) === 1) {
                return $this->parseDateTime($val);
            }
            // A date-prefixed token that is not a full dateTime is invalid in
            // a formal time slot (the caller says so via $expectTime), but a
            // default-namespace identifier like '2024-01-15-report' is legal
            // PN_LOCAL and just happens to share the shape; only the grammar
            // position, not the token shape, can tell the two apart.
            if ($isDatePrefixed && $expectTime) {
                throw $this->err(
                    "Expected an xsd:dateTime value with a time component (e.g. 2024-01-01T00:00:00) but found '{$val}'.",
                );
            }
            return $val;
        }

        return $this->readQName();
    }

    /**
     * @throws \Prov\Exception\DeserializationException
     *   When the lexical form is not a parseable datetime.
     */
    private function parseDateTime(string $val): \DateTimeImmutable
    {
        // An offset-less value (no explicit zone in $val) would otherwise parse
        // in the server's default timezone, making the document content depend
        // on server configuration. UTC is a fixed default that keeps it
        // deterministic. A value with its own offset or "Z" is unaffected; the
        // constructor uses that offset. The zone is built once and reused.
        try {
            static $utc = new \DateTimeZone('UTC');
            return new \DateTimeImmutable($val, $utc);
        } catch (\Exception) {
            throw $this->err("Invalid xsd:dateTime value '{$val}'.");
        }
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     * @param array{0: ?string, 1: list<string|null|\DateTimeImmutable>, 2: ?\Prov\Attribute\Attributes} $p
     */
    private function buildRelation(NamespaceManager $nsManager, array &$records, string $kw, array $p): void
    {
        [$id, $args, $attrs] = $p;

        $idQn = $id !== null ? $this->resolveQName($id, $nsManager) : null;
        $attrs ??= Attributes::empty();

        // Positional args are mixed: strings for identifiers, DateTimeImmutable for times.
        $resolve = fn(int $i): ?QualifiedName => isset($args[$i]) && is_string($args[$i])
            ? $this->resolveQName($args[$i], $nsManager)
            : null;
        $q0 = $resolve(0);
        $q1 = $resolve(1);
        $q2 = $resolve(2);
        $q3 = $resolve(3);
        $q4 = $resolve(4);
        $t2 = isset($args[2]) && $args[2] instanceof \DateTimeImmutable ? $args[2] : null;
        $t3 = isset($args[3]) && $args[3] instanceof \DateTimeImmutable ? $args[3] : null;

        $records[] = match ($kw) {
            'wasGeneratedBy' => new Generation($idQn, $this->requireRef($q0, 'entity', $kw), $q1, $t2, $attrs),
            'used' => new Usage($idQn, $q0, $q1, $t2, $attrs),
            'wasInformedBy' => new Communication($idQn, $q0, $q1, $attrs),
            'wasStartedBy' => new Start($idQn, $q0, $q1, $q2, $t3, $attrs),
            'wasEndedBy' => new End($idQn, $q0, $q1, $q2, $t3, $attrs),
            'wasInvalidatedBy' => new Invalidation($idQn, $this->requireRef($q0, 'entity', $kw), $q1, $t2, $attrs),
            'wasDerivedFrom' => new Derivation($idQn, $q0, $q1, $q2, $q3, $q4, $attrs),
            'wasAttributedTo' => new Attribution($idQn, $q0, $q1, $attrs),
            'wasAssociatedWith' => new Association($idQn, $q0, $q1, $q2, $attrs),
            'actedOnBehalfOf' => new Delegation($idQn, $q0, $q1, $q2, $attrs),
            'wasInfluencedBy' => new Influence($idQn, $q0, $q1, $attrs),
            'specializationOf' => new Specialization(
                $idQn,
                $this->requireRef($q0, 'specificEntity', $kw),
                $this->requireRef($q1, 'generalEntity', $kw),
                $attrs,
            ),
            'alternateOf' => new Alternate(
                $idQn,
                $this->requireRef($q0, 'alternate1', $kw),
                $this->requireRef($q1, 'alternate2', $kw),
                $attrs,
            ),
            'hadMember' => new Membership($idQn, $q0, $q1, $attrs),
            'mentionOf' => new Mention(
                $idQn,
                $this->requireRef($q0, 'specificEntity', $kw),
                $this->requireRef($q1, 'generalEntity', $kw),
                $q2,
                $attrs,
            ),
            default => throw $this->err("Unknown relation: {$kw}"),
        };
    }

    /**
     * Enforces a PROV-DM-mandatory relation endpoint. A malformed document
     * omitting it (or writing `-`) is invalid input, not an internal error.
     *
     * @throws \Prov\Exception\DeserializationException
     *   When `$value` is null.
     */
    private function requireRef(?QualifiedName $value, string $prop, string $kw): QualifiedName
    {
        if ($value === null) {
            throw $this->err("{$kw}(...) is missing a required value for '{$prop}'.");
        }
        return $value;
    }

    // --- Dictionary relations ---

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseDictionaryRelation(NamespaceManager $nsManager, array &$records, string $shortKw): void
    {
        // Consume the prov:xxx keyword.
        $this->skip();
        $this->readQName();
        $this->expect('(');

        match ($shortKw) {
            'hadDictionaryMember' => $this->parseDictMembership($nsManager, $records),
            'derivedByInsertionFrom' => $this->parseDictInsertion($nsManager, $records),
            'derivedByRemovalFrom' => $this->parseDictRemoval($nsManager, $records),
            default => throw $this->err("Unknown dictionary relation: {$shortKw}"),
        };

        $this->skip();
        $this->expect(')');
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseDictMembership(NamespaceManager $nsManager, array &$records): void
    {
        // prov:hadDictionaryMember(dictionary, entity, key)
        $this->skip();
        $dictionary = $this->readOptionalId();
        $this->skip();
        $this->expect(',');
        $this->skip();
        $entity = $this->readOptionalId();
        $entity = $entity !== null ? $this->resolveQName($entity, $nsManager) : null;
        $this->skip();
        $this->expect(',');
        $this->skip();
        $key = $this->readAttrValue($nsManager);
        $dictionaryQn = $dictionary !== null ? $this->resolveQName($dictionary, $nsManager) : null;

        $records[] = new DictionaryMembership(
            null,
            $this->requireRef($dictionaryQn, 'dictionary', 'hadDictionaryMember'),
            [new DictionaryEntry($key, $entity)],
            Attributes::empty(),
        );
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseDictInsertion(NamespaceManager $nsManager, array &$records): void
    {
        // prov:derivedByInsertionFrom([id;] after, before, {(key, entity), ...} [, [attrs]])
        $this->skip();
        $id = null;
        $first = $this->readArgValue();
        $this->skip();

        if ($this->peek() === ';') {
            $this->advance();
            $id = is_string($first) ? $first : null;
            $this->skip();
            $after = $this->readOptionalId();
        } else {
            $after = is_string($first) ? $first : null;
        }

        $this->skip();
        $this->expect(',');
        $this->skip();
        $before = $this->readOptionalId();

        $this->skip();
        $this->expect(',');
        $this->skip();

        $pairs = $this->parseKeyEntitySet($nsManager);

        $attrs = null;
        $this->skip();
        if ($this->peek() === ',') {
            $this->advance();
            $this->skip();
            if ($this->peek() === '[') {
                $attrs = $this->parseAttrList($nsManager);
            }
        }

        $afterQn = $after !== null ? $this->resolveQName($after, $nsManager) : null;
        $beforeQn = $before !== null ? $this->resolveQName($before, $nsManager) : null;
        $records[] = new DictionaryInsertion(
            $id !== null ? $this->resolveQName($id, $nsManager) : null,
            $this->requireRef($afterQn, 'after', 'derivedByInsertionFrom'),
            $this->requireRef($beforeQn, 'before', 'derivedByInsertionFrom'),
            $pairs,
            $attrs ?? Attributes::empty(),
        );
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function parseDictRemoval(NamespaceManager $nsManager, array &$records): void
    {
        // prov:derivedByRemovalFrom([id;] after, before, {key, ...} [, [attrs]])
        $this->skip();
        $id = null;
        $first = $this->readArgValue();
        $this->skip();

        if ($this->peek() === ';') {
            $this->advance();
            $id = is_string($first) ? $first : null;
            $this->skip();
            $after = $this->readOptionalId();
        } else {
            $after = is_string($first) ? $first : null;
        }

        $this->skip();
        $this->expect(',');
        $this->skip();
        $before = $this->readOptionalId();

        $this->skip();
        $this->expect(',');
        $this->skip();

        $keys = $this->parseKeySet($nsManager);

        $attrs = null;
        $this->skip();
        if ($this->peek() === ',') {
            $this->advance();
            $this->skip();
            if ($this->peek() === '[') {
                $attrs = $this->parseAttrList($nsManager);
            }
        }

        $afterQn = $after !== null ? $this->resolveQName($after, $nsManager) : null;
        $beforeQn = $before !== null ? $this->resolveQName($before, $nsManager) : null;
        $records[] = new DictionaryRemoval(
            $id !== null ? $this->resolveQName($id, $nsManager) : null,
            $this->requireRef($afterQn, 'after', 'derivedByRemovalFrom'),
            $this->requireRef($beforeQn, 'before', 'derivedByRemovalFrom'),
            $keys,
            $attrs ?? Attributes::empty(),
        );
    }

    /**
     * Parse {(key, entity), (key, entity), ...}
     * @return list<\Prov\Relation\Dictionary\DictionaryEntry>
     */
    private function parseKeyEntitySet(NamespaceManager $nsManager): array
    {
        $this->expect('{');
        $this->skip();

        $pairs = [];
        if ($this->peek() === '}') {
            $this->advance();
            return $pairs;
        }

        while (true) {
            $this->skip();
            $this->expect('(');
            $this->skip();
            $key = $this->readAttrValue($nsManager);
            $this->skip();
            $this->expect(',');
            $this->skip();
            $entity = $this->resolveQName($this->readQName(), $nsManager);
            $this->skip();
            $this->expect(')');

            $pairs[] = new DictionaryEntry($key, $entity);

            $this->skip();
            if ($this->peek() === '}') {
                $this->advance();
                break;
            }
            $this->expect(',');
        }

        return $pairs;
    }

    /**
     * Parse {key, key, ...}
     * @return list<mixed>
     */
    private function parseKeySet(NamespaceManager $nsManager): array
    {
        $this->expect('{');
        $this->skip();

        $keys = [];
        if ($this->peek() === '}') {
            $this->advance();
            return $keys;
        }

        while (true) {
            $this->skip();
            $keys[] = $this->readAttrValue($nsManager);

            $this->skip();
            if ($this->peek() === '}') {
                $this->advance();
                break;
            }
            $this->expect(',');
        }

        return $keys;
    }

    // --- Attribute list ---

    /**
     * Parse [ key = value, key = value, ... ] into an Attributes object.
     *
     * Accumulates into a raw data array and constructs Attributes once at the
     * end; Attributes::with() would copy-on-write per iteration for O(N²) cost.
     */
    private function parseAttrList(NamespaceManager $nsManager): Attributes
    {
        $this->expect('[');
        $this->skip();

        if ($this->pos < $this->len && $this->input[$this->pos] === ']') {
            $this->pos++;
            return new Attributes();
        }

        $data = [];
        $keys = [];
        while (true) {
            $this->skip();
            $keyStr = $this->readQName();
            $key = $this->resolveQName($keyStr, $nsManager);

            $this->expect('=');

            $value = $this->readAttrValue($nsManager);
            $uri = $key->getUri();
            $data[$uri][] = $value;
            $keys[$uri] ??= $key;

            $this->skip();
            if ($this->pos < $this->len && $this->input[$this->pos] === ']') {
                $this->pos++;
                break;
            }
            $this->expect(',');
        }

        return new Attributes($data, $keys);
    }

    /**
     * Read an attribute value with full namespace resolution.
     */
    private function readAttrValue(NamespaceManager $nsManager): QualifiedName|Literal|string|int|float|bool
    {
        $this->skip();

        // Qualified name literal: 'prefix:local' (may contain \-escaped chars)
        if ($this->peek() === "'") {
            $this->advance();
            $result = '';
            while ($this->pos < $this->len) {
                $run = strcspn($this->input, "'\\", $this->pos);
                $result .= substr($this->input, $this->pos, $run);
                $this->pos += $run;
                if ($this->pos >= $this->len || $this->input[$this->pos] === "'") {
                    break;
                }
                // Preserve the backslash escape verbatim; resolveQName() decodes it.
                if (($this->pos + 1) < $this->len) {
                    $result .= $this->input[$this->pos] . $this->input[$this->pos + 1];
                    $this->pos += 2;
                } else {
                    $result .= $this->input[$this->pos];
                    $this->pos++;
                }
            }
            $this->advance(); // closing '
            return $this->resolveQName($result, $nsManager);
        }

        // Triple-quoted string: """..."""
        if (
            $this->peek() === '"'
            && ($this->pos + 2) < $this->len
            && $this->input[$this->pos + 1] === '"'
            && $this->input[$this->pos + 2] === '"'
        ) {
            $str = $this->readTripleQuotedString();
            return $this->readStringSuffix($str, $nsManager);
        }

        // Regular quoted string: "..."
        if ($this->peek() === '"') {
            $str = $this->readQuotedString();
            return $this->readStringSuffix($str, $nsManager);
        }

        // Bare integer or float
        $ch = $this->peek();
        if (
            $ch !== null && ctype_digit($ch)
            || $ch === '-' && ($this->pos + 1) < $this->len && ctype_digit($this->input[$this->pos + 1])
        ) {
            $val = $this->readUntilDelim();
            if (!is_numeric($val)) {
                throw $this->err("Expected numeric literal, got '{$val}'.");
            }
            if (str_contains($val, '.') || str_contains($val, 'e') || str_contains($val, 'E')) {
                return (float) $val;
            }
            $asInt = (int) $val;
            // PHP's (int) cast silently clamps values outside its integer range; when
            // the magnitude would be lost, preserve the exact value as xsd:integer.
            if ((float) $asInt !== (float) $val) {
                return new Literal($val, ProvNamespace::xsd()->qualifiedName('integer'));
            }
            return $asInt;
        }

        // true/false
        if ($this->peekWord() === 'true') {
            $this->keyword('true');
            return true;
        }
        if ($this->peekWord() === 'false') {
            $this->keyword('false');
            return false;
        }

        throw $this->err("Unexpected character '{$this->peek()}' in attribute value.");
    }

    /**
     * After reading a string, check for @lang or %% type suffix.
     */
    private function readStringSuffix(string $str, NamespaceManager $nsManager): Literal|string
    {
        // Don't skip whitespace before @ (it's attached to the string).
        if ($this->peek() === '@') {
            $this->advance();
            $lang = $this->readWord();
            if ($lang === '') {
                throw $this->err('Empty language tag.');
            }
            return new Literal($str, languageTag: $lang);
        }

        $this->skip();

        // Check for %% type
        if ($this->peek() === '%' && ($this->pos + 1) < $this->len && $this->input[$this->pos + 1] === '%') {
            $this->pos += 2;
            $this->skip();
            $typeStr = $this->readQName();
            $datatype = $this->resolveQName($typeStr, $nsManager);
            return new Literal($str, datatype: $datatype);
        }

        // Plain string (no type, no lang) - return as-is.
        return $str;
    }

    // --- String reading ---

    private function readQuotedString(): string
    {
        $this->advance(); // opening "
        $result = '';
        while ($this->pos < $this->len) {
            // Copy the run up to the next quote or backslash in one operation.
            $run = strcspn($this->input, "\"\\", $this->pos);
            $result .= substr($this->input, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->len) {
                break;
            }
            if ($this->input[$this->pos] === '"') {
                $this->advance();
                return $result;
            }
            // Backslash escape.
            if (($this->pos + 1) < $this->len) {
                $esc = $this->input[$this->pos + 1];
                $result .= match ($esc) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0c",
                    '\\' => '\\',
                    '"' => '"',
                    "'" => "'",
                    default => '\\' . $esc,
                };
                $this->pos += 2;
            } else {
                $result .= '\\';
                $this->advance();
            }
        }
        throw $this->err('Unterminated string literal.');
    }

    private function readTripleQuotedString(): string
    {
        $this->pos += 3; // skip opening """
        $end = strpos($this->input, '"""', $this->pos);
        if ($end === false) {
            throw $this->err('Unterminated triple-quoted string.');
        }
        $result = substr($this->input, $this->pos, $end - $this->pos);
        $this->pos = $end + 3;
        return $result;
    }

    // --- Low-level tokenizer ---

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->input[$this->pos] : null;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function expect(string $char): void
    {
        $this->skip();
        if ($this->pos >= $this->len || $this->input[$this->pos] !== $char) {
            $got = $this->pos < $this->len ? "'{$this->input[$this->pos]}'" : 'EOF';
            throw $this->err("Expected '{$char}', got {$got}.");
        }
        $this->pos++;
    }

    private function keyword(string $kw): void
    {
        $this->skip();
        $n = strlen($kw);
        if (substr_compare($this->input, $kw, $this->pos, $n) === 0) {
            $after = ($this->pos + $n) < $this->len ? $this->input[$this->pos + $n] : ' ';
            if (!ctype_alnum($after) && $after !== '_') {
                $this->pos += $n;
                return;
            }
        }
        throw $this->err("Expected '{$kw}'.");
    }

    private const string WORD_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
    private const string WORD_CHARS_EXT = self::WORD_CHARS . '-.';
    // ASCII subset of the QName character class; non-ASCII bytes (>= 128) and
    // backslash-escapes are handled as a slow-path fallback after each strspn.
    // Covers the grammar's PN_CHARS_OTHERS set (`/ @ ~ & + * ? # $ !` plus
    // percent-encoding); `@` is unambiguous here because a language tag only
    // follows a string literal, never a bare qualified name.
    private const string QNAME_ASCII = self::WORD_CHARS_EXT . ':/?~#@&+*$!%';

    private function peekWord(): string
    {
        $this->skip();
        $len = strspn($this->input, self::WORD_CHARS, $this->pos);
        return substr($this->input, $this->pos, $len);
    }

    private function readWord(): string
    {
        $len = strspn($this->input, self::WORD_CHARS_EXT, $this->pos);
        $s = $this->pos;
        $this->pos += $len;
        return substr($this->input, $s, $len);
    }

    private function readQName(): string
    {
        $s = $this->pos;
        while ($this->pos < $this->len) {
            // Fast path: consume ASCII QName chars in bulk.
            $this->pos += strspn($this->input, self::QNAME_ASCII, $this->pos);
            if ($this->pos >= $this->len) {
                break;
            }
            $ch = $this->input[$this->pos];
            // Non-ASCII byte: include as-is.
            if (ord($ch) > 127) {
                $this->pos++;
                continue;
            }
            // Backslash-escape: consume the escape + the next byte.
            if ($ch === '\\' && ($this->pos + 1) < $this->len) {
                $this->pos += 2;
                continue;
            }
            break;
        }
        return substr($this->input, $s, $this->pos - $s);
    }

    /**
     * Resolves a raw qualified-name token (which may carry backslash escapes in
     * its local part) to a QualifiedName, decoding the escapes so the result
     * matches the same name parsed from a format that needs no escaping.
     */
    private function resolveQName(string $raw, NamespaceManager $nsManager): QualifiedName
    {
        $qn = $nsManager->resolve($raw);
        $decoded = QualifiedNameEscaper::decode($qn->localPart);
        if ($decoded === $qn->localPart) {
            return $qn;
        }
        return new QualifiedName($qn->namespace, $decoded);
    }

    private function readIri(): string
    {
        $this->expect('<');
        $s = $this->pos;
        while ($this->pos < $this->len && $this->input[$this->pos] !== '>') {
            $this->pos++;
        }
        $iri = substr($this->input, $s, $this->pos - $s);
        $this->expect('>');
        return $iri;
    }

    private function readOptionalId(): ?string
    {
        $this->skip();
        if ($this->pos < $this->len && $this->input[$this->pos] === '-' && $this->isDashDelimiter($this->pos + 1)) {
            $this->pos++;
            return null;
        }
        return $this->readQName();
    }

    private function readOptionalTime(): ?\DateTimeImmutable
    {
        $this->skip();
        if ($this->pos < $this->len && $this->input[$this->pos] === '-' && $this->isDashDelimiter($this->pos + 1)) {
            $this->pos++;
            return null;
        }
        $val = $this->readUntilDelim();
        return $this->parseDateTime($val);
    }

    private function isDashDelimiter(int $at): bool
    {
        if ($at >= $this->len) {
            return true;
        }
        $next = $this->input[$at];
        return $next === ',' || $next === ')' || $next === ';' || $next === ']' || ctype_space($next);
    }

    private const string ARG_DELIMITERS = ',);]' . self::WHITESPACE;

    private function readUntilDelim(): string
    {
        $len = strcspn($this->input, self::ARG_DELIMITERS, $this->pos);
        $s = $this->pos;
        $this->pos += $len;
        return substr($this->input, $s, $len);
    }

    private const string WHITESPACE = " \t\n\r\v\f";

    private function skip(): void
    {
        while ($this->pos < $this->len) {
            $advance = strspn($this->input, self::WHITESPACE, $this->pos);
            if ($advance > 0) {
                $this->pos += $advance;
                continue;
            }
            if ($this->input[$this->pos] !== '/' || ($this->pos + 1) >= $this->len) {
                return;
            }
            $next = $this->input[$this->pos + 1];
            if ($next === '/') {
                $nl = strpos($this->input, "\n", $this->pos);
                $this->pos = $nl !== false ? $nl + 1 : $this->len;
                continue;
            }
            if ($next === '*') {
                $end = strpos($this->input, '*/', $this->pos + 2);
                $this->pos = $end !== false ? $end + 2 : $this->len;
                continue;
            }
            return;
        }
    }

    private function err(string $msg): DeserializationException
    {
        $start = max(0, $this->pos - 20);
        $context = substr($this->input, $start, 40);
        $context = str_replace(["\n", "\r"], ['\n', '\r'], $context);
        return new DeserializationException("{$msg} Near: \"{$context}\" (position {$this->pos})");
    }
}
