<?php

declare(strict_types=1);

namespace Prov\Serializer;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Attribute\Literal;
use Prov\Attribute\ValueIdentity;
use Prov\Bundle;
use Prov\Document;
use Prov\Entity;
use Prov\Exception\DeserializationException;
use Prov\Exception\NamespaceException;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\ProvRecord;
use Prov\Model\ProvRelation;
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
 * Serializes Documents to and parses them from PROV-XML, the W3C's
 * XML-based interchange format for PROV.
 *
 * @mago-ignore analysis:possibly-false-argument
 * @mago-ignore analysis:invalid-method-access
 * @mago-ignore analysis:invalid-property-access
 */
class XmlSerializer implements ProvSerializerInterface, ProvDeserializerInterface
{
    private const string PROV_NS = 'http://www.w3.org/ns/prov#';
    private const string XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
    private const string XSD_NS = 'http://www.w3.org/2001/XMLSchema';

    private ?PrefixMinter $minter = null;

    /** URI of the document-level default namespace, or null if none is declared. */
    private ?string $documentDefaultUri = null;

    // PROV-XML element names match PROV-JSON keys, and the child element
    // layout per relation comes from RelationMetadata::xmlChildElements().

    public function __construct(
        public readonly bool $prettyPrint = true,
        public readonly bool $sortRecords = false,
    ) {}

    // ============================================================
    // Serialization
    // ============================================================

    /**
     * {@inheritdoc}
     *
     * @throws \RuntimeException
     *   If DOMDocument::saveXML fails after building a well-formed tree.
     */
    #[\NoDiscard]
    public function serialize(Document $document): string
    {
        // Per-document state; a reused serializer instance must not carry the
        // previous document's default namespace into one that declares none.
        $this->documentDefaultUri = null;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = $this->prettyPrint;

        $root = $dom->createElementNS(self::PROV_NS, 'prov:document');
        $dom->appendChild($root);

        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', self::XSD_NS);

        $nsManager = new NamespaceManager();
        foreach (OutputOrder::namespaces($document->namespaces) as $ns) {
            if ($ns->prefix === 'prov' || $ns->prefix === 'xsd') {
                $nsManager->addOrReplace($ns);
                continue;
            }
            if ($ns->prefix === 'default') {
                // PROV's "default" namespace is the XML default namespace.
                $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', $ns->uri);
                $nsManager->setDefault($ns);
                $this->documentDefaultUri = $ns->uri;
                continue;
            }
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $ns->prefix, $ns->uri);
            $nsManager->addOrReplace($ns);
        }
        // Minted namespaces need no root declaration: createElementNS declares
        // them on the elements that use them.
        $minter = new PrefixMinter($nsManager);
        $this->minter = $minter;

        $records = $this->sortRecords ? OutputOrder::records($document->records) : $document->records;
        foreach ($records as $record) {
            $this->serializeRecord($dom, $root, $record, $nsManager);
        }

        foreach ($document->bundles as $bundle) {
            $this->serializeBundle($dom, $root, $bundle, $nsManager);
        }

        // Namespaces minted for an identifier written as a prov:id/prov:ref
        // string (e.g. a bundle-local default routed through a real prefix) have
        // no element to carry their declaration, so declare them on the root.
        foreach ($minter->getMintedNamespaces() as $ns) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $ns->prefix, $ns->uri);
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('DOMDocument::saveXML failed on a well-formed document.');
        }
        return $xml;
    }

    private function serializeRecord(
        \DOMDocument $dom,
        \DOMElement $parent,
        ProvRecord $record,
        NamespaceManager $nsManager,
    ): void {
        if ($record instanceof Entity) {
            $this->serializeElement($dom, $parent, 'entity', $record, $nsManager);
        } elseif ($record instanceof Activity) {
            $this->serializeActivity($dom, $parent, $record, $nsManager);
        } elseif ($record instanceof Agent) {
            $this->serializeElement($dom, $parent, 'agent', $record, $nsManager);
        } elseif ($record instanceof ProvRelation) {
            $this->serializeRelation($dom, $parent, $record, $nsManager);
        }
    }

    private function serializeElement(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $tagName,
        Entity|Agent $record,
        NamespaceManager $nsManager,
    ): void {
        $el = $dom->createElementNS(self::PROV_NS, 'prov:' . $tagName);
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier));
        }
        $this->serializeAttributes($dom, $el, $record->attributes, $nsManager);
        $parent->appendChild($el);
    }

    private function serializeActivity(
        \DOMDocument $dom,
        \DOMElement $parent,
        Activity $record,
        NamespaceManager $nsManager,
    ): void {
        $el = $dom->createElementNS(self::PROV_NS, 'prov:activity');
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier));
        }
        if ($record->startTime !== null) {
            $el->appendChild($dom->createElementNS(
                self::PROV_NS,
                'prov:startTime',
                Literal::formatDateTime($record->startTime),
            ));
        }
        if ($record->endTime !== null) {
            $el->appendChild($dom->createElementNS(
                self::PROV_NS,
                'prov:endTime',
                Literal::formatDateTime($record->endTime),
            ));
        }
        $this->serializeAttributes($dom, $el, $record->attributes, $nsManager);
        $parent->appendChild($el);
    }

    private function serializeRelation(
        \DOMDocument $dom,
        \DOMElement $parent,
        ProvRelation $record,
        NamespaceManager $nsManager,
    ): void {
        $tagName = RelationMetadata::JSON_KEYS[$record::class] ?? null;
        if ($tagName === null) {
            return;
        }

        $el = $dom->createElementNS(self::PROV_NS, 'prov:' . $tagName);
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier));
        }

        $this->serializeRelationFormals($dom, $el, $record);

        // Dictionary-specific child elements.
        if ($record instanceof DictionaryMembership || $record instanceof DictionaryInsertion) {
            foreach ($record->keyEntityPairs as $pair) {
                $kep = $dom->createElementNS(self::PROV_NS, 'prov:keyEntityPair');
                $keyEl = $dom->createElementNS(self::PROV_NS, 'prov:key');
                $this->setTypedTextContent($keyEl, $pair->key, $nsManager);
                $kep->appendChild($keyEl);
                if ($pair->entity !== null) {
                    $entityEl = $dom->createElementNS(self::PROV_NS, 'prov:entity');
                    $entityEl->setAttributeNS(self::PROV_NS, 'prov:ref', $this->xmlIdentifier($pair->entity));
                    $kep->appendChild($entityEl);
                }
                $el->appendChild($kep);
            }
        } elseif ($record instanceof DictionaryRemoval) {
            // @mago-expect analysis:mixed-assignment
            foreach ($record->removedKeys as $key) {
                $keyEl = $dom->createElementNS(self::PROV_NS, 'prov:key');
                $this->setTypedTextContent($keyEl, $key, $nsManager);
                $el->appendChild($keyEl);
            }
        }

        $this->serializeAttributes($dom, $el, $record->attributes, $nsManager);
        $parent->appendChild($el);
    }

    /**
     * The QName string to write for an identifier (prov:id, prov:ref, or an
     * xsd:QName value). A declared prefix is written verbatim; an undeclared
     * (or prefix-conflicting) namespace is routed through the minter so a
     * binding xmlns lands on the root rather than emitting an unbound prefix.
     * A default-namespace identifier is written as a bare local part only when
     * it lives in the document's default namespace (bound by the root xmlns);
     * one from another scope (e.g. a bundle-local default) is routed through a
     * real declared or minted prefix, so it never relies on an element-level
     * default xmlns, which libxml's namespace reconciliation can drop.
     */
    private function xmlIdentifier(QualifiedName $qn): string
    {
        if ($qn->namespace->prefix !== 'default') {
            if ($qn->isBlank() || $this->minter === null) {
                return (string) $qn;
            }
            return $this->minter->prefixFor($qn) . ':' . $qn->localPart;
        }
        if ($this->documentDefaultUri !== null && $qn->namespace->uri === $this->documentDefaultUri) {
            return $qn->localPart;
        }
        if ($this->minter !== null) {
            return $this->minter->prefixFor($qn) . ':' . $qn->localPart;
        }
        return $qn->localPart;
    }

    private function setTypedTextContent(\DOMElement $el, mixed $value, NamespaceManager $nsManager): void
    {
        if ($value instanceof QualifiedName) {
            $el->setAttributeNS(self::XSI_NS, 'xsi:type', 'xsd:QName');
            if ($value->namespace->prefix === 'default') {
                // The reserved default prefix is never written; xmlIdentifier
                // yields a bare local part (document default) or a real prefix.
                $el->textContent = $this->xmlIdentifier($value);
            } else {
                $declaredNs = $nsManager->getNamespace($value->namespace->prefix);
                if ($declaredNs === null || $declaredNs->uri !== $value->namespace->uri) {
                    $el->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', $value->namespace->uri);
                    $el->textContent = $value->localPart;
                } else {
                    $el->textContent = (string) $value;
                }
            }
        } elseif ($value instanceof Literal) {
            if ($value->datatype !== null) {
                $el->setAttributeNS(self::XSI_NS, 'xsi:type', $this->xmlIdentifier($value->datatype));
            }
            if ($value->languageTag !== null) {
                $el->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $value->languageTag);
            }
            $this->writeLiteralValue($el, $value);
        } else {
            $el->textContent = (string) $value;
        }
    }

    /**
     * Writes a Literal's value into an element. Regular literals use textContent;
     * rdf:XMLLiteral values are parsed as XML fragments and appended as child nodes
     * so their structure survives serialization.
     */
    private function writeLiteralValue(\DOMElement $el, Literal $value): void
    {
        $ownerDoc = $el->ownerDocument;
        if (
            $ownerDoc !== null
            && $value->datatype?->getUri() === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral'
        ) {
            $frag = $ownerDoc->createDocumentFragment();
            $previous = libxml_use_internal_errors(true);
            try {
                if ($frag->appendXML($value->value)) {
                    $el->appendChild($frag);
                    return;
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        $el->textContent = $value->value;
    }

    private function serializeRelationFormals(\DOMDocument $dom, \DOMElement $el, ProvRelation $record): void
    {
        $refProps = $this->getRefProperties($record);
        foreach ($refProps as $childName => $value) {
            if ($value instanceof QualifiedName) {
                $child = $dom->createElementNS(self::PROV_NS, 'prov:' . $childName);
                $child->setAttributeNS(self::PROV_NS, 'prov:ref', $this->xmlIdentifier($value));
                $el->appendChild($child);
            } elseif ($value instanceof \DateTimeImmutable) {
                $el->appendChild($dom->createElementNS(
                    self::PROV_NS,
                    'prov:' . $childName,
                    Literal::formatDateTime($value),
                ));
            }
        }
    }

    /**
     * Extracts the formal fields of a relation that render as child elements
     * in PROV-XML (QualifiedName references and DateTimeImmutable times),
     * keyed by their XML element local name. Read off the relation's fields
     * directly to skip the extractFormals intermediate array.
     *
     * @return array<string, \Prov\Identifier\QualifiedName|\DateTimeImmutable|null>
     */
    private function getRefProperties(ProvRelation $record): array
    {
        $meta = RelationMetadata::FORMALS[$record::class] ?? [];
        $overrides = RelationMetadata::XML_FORMAL_OVERRIDES[$record::class] ?? [];
        $vars = get_object_vars($record);

        $result = [];
        foreach ($meta as $prop => $type) {
            if ($type !== 'ref' && $type !== 'time') {
                continue;
            }
            // @mago-expect analysis:mixed-assignment
            $value = $vars[$prop] ?? null;
            if ($value === null || $value instanceof QualifiedName || $value instanceof \DateTimeImmutable) {
                $result[$overrides[$prop] ?? $prop] = $value;
            }
        }
        return $result;
    }

    private function serializeAttributes(
        \DOMDocument $dom,
        \DOMElement $parent,
        Attributes $attributes,
        NamespaceManager $nsManager,
    ): void {
        foreach ($attributes->all() as $uri => $values) {
            $prefixed = $this->minter !== null
                ? $this->minter->uriToPrefixed($uri, $nsManager)
                : $nsManager->uriToPrefixed($uri);
            foreach ($values as $value) {
                $this->serializeAttributeValue($dom, $parent, $prefixed, $value, $nsManager);
            }
        }
    }

    private function serializeAttributeValue(
        \DOMDocument $dom,
        \DOMElement $parent,
        string $prefixedKey,
        mixed $value,
        NamespaceManager $nsManager,
    ): void {
        // Resolve the key to namespace URI + local part for createElement.
        $parts = explode(':', $prefixedKey, 2);
        // XML element names can't start with a digit; PROV-XML's convention is to
        // prefix such local parts with an underscore. A name that is already an
        // underscore-run followed by a digit (e.g. "_0") is escaped too, by
        // prepending one more underscore, so the deserializer can tell an escaped
        // name from a genuine one (`_0foo` -> `__0foo`, recovered on read).
        try {
            if (count($parts) === 2) {
                $local = $parts[1];
                if (preg_match('/^_*\d/', $local) === 1) {
                    $local = '_' . $local;
                    $prefixedKey = $parts[0] . ':' . $local;
                }
                if ($parts[0] === 'default') {
                    // Default-namespace key: an unprefixed element bound to the
                    // default namespace; the reserved 'default' prefix is never written.
                    $ns = $nsManager->getNamespace('default');
                    $el = $ns !== null ? $dom->createElementNS($ns->uri, $local) : $dom->createElement($local);
                    $prefixedKey = $local;
                } else {
                    $ns = $nsManager->getNamespace($parts[0]);
                    if ($ns !== null) {
                        $el = $dom->createElementNS($ns->uri, $prefixedKey);
                    } else {
                        $el = $dom->createElement($prefixedKey);
                    }
                }
            } else {
                if (preg_match('/^_*\d/', $prefixedKey) === 1) {
                    $prefixedKey = '_' . $prefixedKey;
                }
                $el = $dom->createElement($prefixedKey);
            }
        } catch (\DOMException) {
            // PROV-XML encodes attribute keys as element names, so a local part
            // carrying characters that XML names forbid (PROV-N escapes like
            // "\=" survive in JSON and PROV-N) has no XML representation.
            throw new \InvalidArgumentException(
                "Attribute key '{$prefixedKey}' cannot be represented as a PROV-XML element name.",
            );
        }

        if ($value instanceof QualifiedName) {
            $this->setTypedTextContent($el, $value, $nsManager);
        } elseif ($value instanceof Literal) {
            if ($value->languageTag !== null) {
                $el->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $value->languageTag);
            } elseif ($value->datatype !== null) {
                $el->setAttributeNS(self::XSI_NS, 'xsi:type', $this->xmlIdentifier($value->datatype));
            }
            $this->writeLiteralValue($el, $value);
        } elseif (is_bool($value)) {
            $el->setAttributeNS(self::XSI_NS, 'xsi:type', 'xsd:boolean');
            $el->textContent = $value ? 'true' : 'false';
        } elseif (is_int($value)) {
            $type = $value < Literal::XSD_INT_MIN || $value > Literal::XSD_INT_MAX ? 'xsd:long' : 'xsd:int';
            $el->setAttributeNS(self::XSI_NS, 'xsi:type', $type);
            $el->textContent = (string) $value;
        } elseif (is_float($value)) {
            $el->setAttributeNS(self::XSI_NS, 'xsi:type', 'xsd:float');
            $el->textContent = Literal::formatFloat($value);
        } else {
            $el->textContent = (string) $value;
        }

        $parent->appendChild($el);
    }

    private function serializeBundle(
        \DOMDocument $dom,
        \DOMElement $parent,
        Bundle $bundle,
        NamespaceManager $nsManager,
    ): void {
        $el = $dom->createElementNS(self::PROV_NS, 'prov:bundleContent');
        $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($bundle->identifier));

        // Bundle-level declarations become xmlns attributes on the
        // bundleContent element, mirroring how the deserializer reads them
        // back via extractLocalNamespaces(). Declarations identical to a
        // document-level one are inherited and need no repeat. The attributes
        // are written as plain attributes (not namespace-declaration nodes):
        // libxml's namespace reconciliation drops a declaration node whose URI
        // is already in scope under another prefix, but these declarations are
        // load-bearing for the prefixed prov:id strings inside the bundle. A
        // bundle-local default namespace gets no xmlns declaration here: its
        // identifiers are written through a real prefix (xmlIdentifier), so the
        // bundleContent default stays the document's, keeping the bundle's own
        // prov:id (which lives in the parent scope) resolvable as a bare name.
        $bundleNsManager = new NamespaceManager($nsManager);
        foreach (OutputOrder::namespaces($bundle->namespaces) as $ns) {
            $existing = $nsManager->getNamespace($ns->prefix);
            if ($existing !== null && $existing->uri === $ns->uri) {
                continue;
            }
            if ($ns->prefix === 'default') {
                $bundleNsManager->setDefault($ns);
                continue;
            }
            if ($ns->prefix !== 'prov' && $ns->prefix !== 'xsd') {
                $el->setAttribute('xmlns:' . $ns->prefix, $ns->uri);
            }
            $bundleNsManager->addOrReplace($ns);
        }

        $records = $this->sortRecords ? OutputOrder::records($bundle->records) : $bundle->records;
        foreach ($records as $record) {
            $this->serializeRecord($dom, $el, $record, $bundleNsManager);
        }

        $parent->appendChild($el);
    }

    // ============================================================
    // Deserialization
    // ============================================================

    /**
     * {@inheritdoc}
     */
    public function deserialize(string $data): Document
    {
        // DOMDocument::loadXML throws ValueError on empty input (PHP 8.4+); normalize
        // to the library's own exception type so callers catching ProvException hit it.
        if ($data === '') {
            throw new DeserializationException('Invalid PROV-XML: empty input.');
        }

        $dom = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks remote DTD/entity fetches. PROV-XML has no legitimate
        // DOCTYPE use, so we reject documents that declare one to prevent internal
        // entity expansion attacks (billion-laughs, parameter-entity tricks).
        $success = $dom->loadXML($data, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($success && $dom->doctype !== null) {
            throw new DeserializationException('Invalid PROV-XML: DOCTYPE declarations are not allowed.');
        }

        if (!$success) {
            $messages = array_map(static fn(\LibXMLError $e): string => trim($e->message), $errors);
            throw new DeserializationException(
                'Invalid PROV-XML: ' . ($messages !== [] ? implode('; ', $messages) : 'could not parse XML.'),
            );
        }

        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'document' || $root->namespaceURI !== self::PROV_NS) {
            throw new DeserializationException('Invalid PROV-XML: root element must be prov:document.');
        }

        $nsManager = new NamespaceManager();

        $records = [];
        $bundles = [];
        try {
            $this->extractLocalNamespaces($root, $nsManager);
            $this->deserializeChildren($root, $nsManager, $records, $bundles);
        } catch (NamespaceException|\InvalidArgumentException $e) {
            // An unresolvable or invalid identifier (undeclared prefix, missing
            // default namespace, conflicting declarations, empty local part)
            // means the input is malformed; surface it under the
            // deserialization contract.
            throw new DeserializationException('Invalid PROV-XML: ' . $e->getMessage(), previous: $e);
        }
        assert(is_array($bundles));

        return new Document(records: $records, bundles: $bundles, namespaces: $nsManager->getRegisteredNamespaces());
    }

    /**
     * Walks the children of a `prov:document` or `prov:bundle` element,
     * dispatching each record-like element to the matching handler.
     *
     * @param list<\Prov\Model\ProvRecord> $records
     * @param list<\Prov\Bundle>|null $bundles
     *   Null when called inside a bundle scope.
     *
     * @mago-ignore analysis:conflicting-reference-constraint
     */
    private function deserializeChildren(
        \DOMElement $parent,
        NamespaceManager $nsManager,
        array &$records,
        ?array &$bundles,
    ): void {
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->namespaceURI !== self::PROV_NS) {
                continue;
            }

            match ($node->localName) {
                'entity' => $this->deserializeEntity($node, $nsManager, $records),
                'activity' => $this->deserializeActivity($node, $nsManager, $records),
                'agent' => $this->deserializeAgent($node, $nsManager, $records),
                'wasGeneratedBy',
                'used',
                'wasInformedBy',
                'wasStartedBy',
                'wasEndedBy',
                'wasInvalidatedBy',
                'wasDerivedFrom',
                'revision',
                'quotation',
                'primarySource',
                'wasAttributedTo',
                'wasAssociatedWith',
                'actedOnBehalfOf',
                'wasInfluencedBy',
                'specializationOf',
                'alternateOf',
                'hadMember',
                'mentionOf',
                    => $this->deserializeRelation($node, $nsManager, $records),
                'hadDictionaryMember',
                'derivedByInsertionFrom',
                'derivedByRemovalFrom',
                    => $this->deserializeDictionaryRelation($node, $nsManager, $records),
                'bundleContent' => $bundles !== null
                    ? $this->deserializeBundleContent($node, $nsManager, $bundles)
                    : null,
                default => null, // Ignore unknown elements (prov:other, etc.)
            };
        }
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeEntity(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $idStr = $this->resolveProvId($el, $nsManager);
        $id = $idStr !== null ? $nsManager->resolve($idStr) : null;
        $attrs = $this->deserializeChildAttributes($el, $nsManager) ?? Attributes::empty();
        $records[] = new Entity($id, $attrs);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeActivity(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $idStr = $this->resolveProvId($el, $nsManager);
        $id = $idStr !== null ? $nsManager->resolve($idStr) : null;
        $startTime = null;
        $endTime = null;

        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::PROV_NS) {
                continue;
            }
            if ($child->localName === 'startTime') {
                $startTime = $this->parseDateTime($child->textContent);
            } elseif ($child->localName === 'endTime') {
                $endTime = $this->parseDateTime($child->textContent);
            }
        }

        $attrs = $this->deserializeChildAttributes($el, $nsManager, ['startTime', 'endTime']) ?? Attributes::empty();
        $records[] = new Activity($id, $startTime, $endTime, $attrs);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeAgent(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $idStr = $this->resolveProvId($el, $nsManager);
        $id = $idStr !== null ? $nsManager->resolve($idStr) : null;
        $attrs = $this->deserializeChildAttributes($el, $nsManager) ?? Attributes::empty();
        $records[] = new Agent($id, $attrs);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeRelation(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $relName = $el->localName;
        if ($relName === null) {
            return;
        }
        // PROV-XML names the Derivation subtype shortcut elements after their
        // prov:type local name, lowercased (revision, quotation, primarySource).
        /** @var array<string, string>|null $subtypeElements */
        static $subtypeElements = null;
        if ($subtypeElements === null) {
            $subtypeElements = [];
            foreach (RelationMetadata::DERIVATION_SUBTYPES as $subtype) {
                $subtypeElements[lcfirst($subtype)] = $subtype;
            }
        }
        $injectedSubtype = $subtypeElements[$relName] ?? null;
        if ($injectedSubtype !== null) {
            $relName = 'wasDerivedFrom';
        }
        $idStr = $this->resolveProvId($el, $nsManager);
        $id = $idStr !== null ? $nsManager->resolve($idStr) : null;
        $formalMap = RelationMetadata::xmlChildElements()[$relName] ?? [];

        /** @var array<string, \Prov\Identifier\QualifiedName|\DateTimeImmutable> $formals */
        $formals = [];
        $skipChildNames = array_keys($formalMap);

        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::PROV_NS) {
                continue;
            }
            $childLocalName = $child->localName;
            if ($childLocalName !== null && isset($formalMap[$childLocalName])) {
                $ref = $child->getAttributeNS(self::PROV_NS, 'ref');
                if ($ref !== '') {
                    $formals[$formalMap[$childLocalName]] = $nsManager->resolve($this->resolveQNameInContext(
                        $ref,
                        $child,
                        $nsManager,
                    ));
                } elseif ($childLocalName === 'time') {
                    $formals[$formalMap[$childLocalName]] = $this->parseDateTime($child->textContent);
                }
            }
        }

        $attrs = $this->deserializeChildAttributes($el, $nsManager, $skipChildNames) ?? Attributes::empty();

        if ($injectedSubtype !== null) {
            $attrs = $attrs->with($nsManager->resolve('prov:type'), $nsManager->resolve('prov:' . $injectedSubtype));
        }

        $q = static fn(string $k) => isset($formals[$k]) && $formals[$k] instanceof QualifiedName ? $formals[$k] : null;
        $t = static fn(string $k) => isset($formals[$k]) && $formals[$k] instanceof \DateTimeImmutable
            ? $formals[$k]
            : null;

        $record = match ($relName) {
            'wasGeneratedBy' => new Generation($id, $q('entity'), $q('activity'), $t('time'), $attrs),
            'used' => new Usage($id, $q('activity'), $q('entity'), $t('time'), $attrs),
            'wasInformedBy' => new Communication($id, $q('informed'), $q('informant'), $attrs),
            'wasStartedBy' => new Start($id, $q('activity'), $q('trigger'), $q('starter'), $t('time'), $attrs),
            'wasEndedBy' => new End($id, $q('activity'), $q('trigger'), $q('ender'), $t('time'), $attrs),
            'wasInvalidatedBy' => new Invalidation($id, $q('entity'), $q('activity'), $t('time'), $attrs),
            'wasDerivedFrom' => new Derivation(
                $id,
                $q('generatedEntity'),
                $q('usedEntity'),
                $q('activity'),
                $q('generation'),
                $q('usage'),
                $attrs,
            ),
            'wasAttributedTo' => new Attribution($id, $q('entity'), $q('agent'), $attrs),
            'wasAssociatedWith' => new Association($id, $q('activity'), $q('agent'), $q('plan'), $attrs),
            'actedOnBehalfOf' => new Delegation($id, $q('delegate'), $q('responsible'), $q('activity'), $attrs),
            'wasInfluencedBy' => new Influence($id, $q('influencee'), $q('influencer'), $attrs),
            'specializationOf' => new Specialization($id, $q('specificEntity'), $q('generalEntity'), $attrs),
            'alternateOf' => new Alternate($id, $q('alternate1'), $q('alternate2'), $attrs),
            'hadMember' => new Membership($id, $q('collection'), $q('entity'), $attrs),
            'mentionOf' => new Mention($id, $q('specificEntity'), $q('generalEntity'), $q('bundle'), $attrs),
            default => null,
        };
        if ($record !== null) {
            $records[] = $record;
        }
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeDictionaryRelation(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $relName = $el->localName;
        $idStr = $this->resolveProvId($el, $nsManager);
        $id = $idStr !== null ? $nsManager->resolve($idStr) : null;

        $keyEntityPairs = [];
        $removedKeys = [];
        $dictionary = null;
        $newDict = null;
        $oldDict = null;

        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::PROV_NS) {
                continue;
            }

            match ($child->localName) {
                'dictionary' => $dictionary = $child->getAttributeNS(self::PROV_NS, 'ref') ?: null,
                'newDictionary' => $newDict = $child->getAttributeNS(self::PROV_NS, 'ref') ?: null,
                'oldDictionary' => $oldDict = $child->getAttributeNS(self::PROV_NS, 'ref') ?: null,
                'keyEntityPair' => $keyEntityPairs[] = $this->parseKeyEntityPair($child, $nsManager),
                'key' => $removedKeys[] = $this->deserializeAttrValue($child, $nsManager),
                default => null,
            };
        }

        $extraAttrs = $this->deserializeChildAttributes($el, $nsManager, [
            'dictionary',
            'newDictionary',
            'oldDictionary',
            'keyEntityPair',
            'key',
        ]) ?? Attributes::empty();

        $dictQn = $dictionary !== null ? $nsManager->resolve($dictionary) : null;
        $newDictQn = $newDict !== null ? $nsManager->resolve($newDict) : null;
        $oldDictQn = $oldDict !== null ? $nsManager->resolve($oldDict) : null;

        $record = match ($relName) {
            'hadDictionaryMember' => new DictionaryMembership($id, $dictQn, $keyEntityPairs, $extraAttrs),
            'derivedByInsertionFrom' => new DictionaryInsertion(
                $id,
                $newDictQn,
                $oldDictQn,
                $keyEntityPairs,
                $extraAttrs,
            ),
            'derivedByRemovalFrom' => new DictionaryRemoval($id, $newDictQn, $oldDictQn, $removedKeys, $extraAttrs),
            default => null,
        };
        if ($record !== null) {
            $records[] = $record;
        }
    }

    private function parseKeyEntityPair(\DOMElement $el, NamespaceManager $nsManager): DictionaryEntry
    {
        $key = null;
        $entity = null;

        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::PROV_NS) {
                continue;
            }

            if ($child->localName === 'key') {
                $key = $this->deserializeAttrValue($child, $nsManager);
            } elseif ($child->localName === 'entity') {
                $ref = $child->getAttributeNS(self::PROV_NS, 'ref');
                $entity = $ref !== '' ? $nsManager->resolve($ref) : null;
            }
        }

        return new DictionaryEntry($key, $entity);
    }

    /**
     * @param list<\Prov\Bundle> $bundles
     */
    private function deserializeBundleContent(\DOMElement $el, NamespaceManager $nsManager, array &$bundles): void
    {
        $id = $this->resolveProvId($el, $nsManager);
        if ($id === null) {
            return;
        }

        $bundleNsManager = new NamespaceManager($nsManager);
        $this->extractLocalNamespaces($el, $bundleNsManager);

        $bundleRecords = [];
        $nestedBundles = null;
        $this->deserializeChildren($el, $bundleNsManager, $bundleRecords, $nestedBundles);

        $bundles[] = new Bundle(
            identifier: $nsManager->resolve($id),
            records: $bundleRecords,
            namespaces: $bundleNsManager->getRegisteredNamespaces(),
        );
    }

    /**
     * Register namespace declarations that appear on $el but are not inherited from its parent.
     * In-scope namespaces visible on the element (via `namespace::*`) include ancestors too; we
     * filter those out by comparing against the parent element's lookup.
     */
    private function extractLocalNamespaces(\DOMElement $el, NamespaceManager $target): void
    {
        $ownerDoc = $el->ownerDocument;
        if ($ownerDoc === null) {
            return;
        }
        $xpath = new \DOMXPath($ownerDoc);
        $parent = $el->parentNode instanceof \DOMElement ? $el->parentNode : null;

        // @mago-expect analysis:mixed-assignment
        $nsNodes = $xpath->query('namespace::*', $el);
        if (!$nsNodes instanceof \DOMNodeList) {
            return;
        }

        foreach ($nsNodes as $nsNode) {
            if (!$nsNode instanceof \DOMNameSpaceNode && !$nsNode instanceof \DOMNode) {
                continue;
            }
            $prefix = $nsNode->localName;
            $uri = $nsNode->nodeValue;
            if (!is_string($prefix) || !is_string($uri)) {
                continue;
            }

            if ($prefix === 'xml' || $prefix === 'xsi') {
                continue;
            }

            $inheritedUri = $prefix === 'xmlns'
                ? $parent?->lookupNamespaceURI(null)
                : $parent?->lookupNamespaceURI($prefix);
            if ($inheritedUri === $uri) {
                continue;
            }

            if ($prefix === 'xmlns') {
                $target->setDefault(new ProvNamespace('default', $uri));
                continue;
            }

            $target->addOrReplace(new ProvNamespace($prefix, $uri));
        }
    }

    // --- Helpers ---

    /**
     * Parses an xsd:dateTime element value, surfacing any malformed value as a
     * DeserializationException rather than a leaked \DateMalformedStringException.
     */
    private function parseDateTime(string $value): \DateTimeImmutable
    {
        // An offset-less value would otherwise parse in the server's default
        // timezone, making the document content depend on server configuration.
        // UTC is a fixed default that keeps it deterministic. A value with its
        // own offset or "Z" is unaffected; the constructor uses that offset.
        // The zone is built once and reused across parses.
        try {
            static $utc = new \DateTimeZone('UTC');
            return new \DateTimeImmutable($value, $utc);
        } catch (\DateException $e) {
            throw new DeserializationException("Invalid PROV-XML: malformed dateTime '{$value}'.", previous: $e);
        }
    }

    private function getProvId(\DOMElement $el): ?string
    {
        $id = $el->getAttributeNS(self::PROV_NS, 'id');
        return $id !== '' ? $id : null;
    }

    private function resolveProvId(\DOMElement $el, NamespaceManager $nsManager): ?string
    {
        $id = $this->getProvId($el);
        if ($id === null) {
            return null;
        }
        return $this->resolveQNameInContext($id, $el, $nsManager);
    }

    /**
     * Resolve a prov:id or prov:ref value in the element's namespace context.
     * Unprefixed QNames use the element-local default namespace.
     */
    private function resolveQNameInContext(string $value, \DOMElement $el, NamespaceManager $nsManager): string
    {
        // Already prefixed: the caller resolves it against the namespace manager.
        if (str_contains($value, ':')) {
            return $value;
        }

        // Unprefixed: check for element-local default namespace.
        $defaultUri = $el->lookupNamespaceURI(null);
        if ($defaultUri !== null) {
            // Find a registered prefix for this URI.
            foreach ($nsManager->getRegisteredNamespaces() as $ns) {
                if ($ns->uri === $defaultUri) {
                    return $ns->prefix . ':' . $value;
                }
            }
            // No registered prefix: walk up to check parent namespace managers.
            $resolved = $nsManager->resolveUri($defaultUri . $value);
            if ($resolved !== null) {
                return (string) $resolved;
            }
            // Register a synthetic namespace so the caller can resolve it.
            $syntheticPrefix = '_ns' . crc32($defaultUri);
            $ns = new ProvNamespace($syntheticPrefix, $defaultUri);
            $nsManager->addOrReplace($ns);
            return $syntheticPrefix . ':' . $value;
        }

        return $value;
    }

    /**
     * Deserialize child elements as extra attributes, skipping formal attributes.
     *
     * @param list<string> $skipLocalNames
     */
    private function deserializeChildAttributes(
        \DOMElement $parent,
        NamespaceManager $nsManager,
        array $skipLocalNames = [],
    ): ?Attributes {
        // Accumulate into the raw URI-keyed shape and build Attributes once, rather than
        // calling with() per child (which copies the backing array, O(n^2) per record).
        $data = [];
        $keys = [];
        $hasAny = false;

        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            // Skip formal attributes (in prov namespace).
            if ($child->namespaceURI === self::PROV_NS && in_array($child->localName, $skipLocalNames, true)) {
                continue;
            }

            // PROV-XML encodes an attribute whose local part starts with a digit
            // (e.g. `0tagWithDigit`) as `_0tagWithDigit` so the XML element name
            // is valid; a name that was already an underscore-run before a digit
            // gained one extra underscore. Both escaped forms are an underscore
            // run immediately followed by a digit, so strip exactly one
            // underscore to recover the canonical name (`__0foo` -> `_0foo`,
            // `_0foo` -> `0foo`). A genuine name like `_foo` is untouched.
            $localName = $child->localName;
            if ($localName === null) {
                continue;
            }
            if (preg_match('/^_+[0-9]/', $localName) === 1) {
                $localName = substr($localName, 1);
            }
            $prefix = $child->prefix;
            $nsUri = $child->namespaceURI;

            // Prefer the element's own namespace URI: DOM applies XML scoping
            // rules, so declarations local to the element (not just the root)
            // resolve correctly. Reuse the declared ProvNamespace instance when
            // the prefix is registered with the same URI; fall back to prefix
            // resolution for non-namespaced elements.
            if ($nsUri !== null && $nsUri !== '') {
                $managerPrefix = $prefix !== '' ? $prefix : 'default';
                $declared = $nsManager->getNamespace($managerPrefix);
                $key =
                    $declared !== null && $declared->uri === $nsUri
                        ? $declared->qualifiedName($localName)
                        : new ProvNamespace($managerPrefix, $nsUri)->qualifiedName($localName);
            } else {
                $keyStr = $prefix !== '' ? $prefix . ':' . $localName : $localName;
                $key = $nsManager->resolve($keyStr);
            }
            $value = $this->deserializeAttrValue($child, $nsManager);

            $uri = $key->getUri();
            $data[$uri][] = $value;
            $keys[$uri] ??= $key;
            $hasAny = true;
        }

        return $hasAny ? new Attributes($data, $keys) : null;
    }

    private function deserializeAttrValue(\DOMElement $el, NamespaceManager $nsManager): QualifiedName|Literal|string
    {
        $xsiType = $el->getAttributeNS(self::XSI_NS, 'type');
        $lang = $el->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');

        if ($lang !== '') {
            return new Literal($el->textContent, languageTag: $lang);
        }

        if ($xsiType !== '') {
            // A QName value must be resolved in the element's namespace context,
            // which may have local xmlns overrides. Only a type whose local part
            // is "QName" can be one: the standard `xsd:QName` is matched by its
            // literal token, and a non-standard prefix bound to the XSD namespace
            // (xs:QName) is confirmed by the resolved URI. Every other type skips
            // both checks and the URI comparison.
            if (
                $xsiType === 'xsd:QName'
                || str_ends_with($xsiType, ':QName')
                && ValueIdentity::normalizeDatatypeUri($nsManager->resolve($xsiType)->getUri())
                    === ValueIdentity::XSD_QNAME_URI
            ) {
                $localNs = $nsManager;
                $defaultUri = $el->lookupNamespaceURI(null);
                if ($defaultUri !== null) {
                    $localNs = new NamespaceManager($nsManager);
                    $localNs->setDefault(new ProvNamespace('default', $defaultUri));
                }
                return $localNs->resolve($el->textContent);
            }
            $datatype = $nsManager->resolve($xsiType);
            // rdf:XMLLiteral carries literal XML content; serialize child nodes instead
            // of calling textContent (which strips element structure).
            if ($datatype->getUri() === 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral') {
                return new Literal($this->innerXml($el), datatype: $datatype);
            }
            return new Literal($el->textContent, datatype: $datatype);
        }

        return $el->textContent;
    }

    private function innerXml(\DOMElement $el): string
    {
        $doc = $el->ownerDocument;
        if ($doc === null) {
            return '';
        }
        $out = '';
        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMNode) {
                continue;
            }
            $serialized = $doc->saveXML($child);
            if ($serialized !== false) {
                $out .= $serialized;
            }
        }
        return $out;
    }
}
