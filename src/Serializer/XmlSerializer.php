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
 * The root binds prov and xsd itself, because the output carries prov:* and
 * xsd:* terms of its own. A document that binds either prefix to another
 * namespace keeps that namespace under a minted prefix.
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

    /**
     * The prefixes the root binds itself and the exact namespace each one
     * names. The `xsd` binding has no trailing `#`, so `xsi:type="xsd:int"`
     * names the XML Schema type; a document that binds `xsd` to the `#` form
     * names another namespace, and names under it go through the minter.
     * Literal datatypes are the exception: `ValueIdentity::datatypeIn()`
     * moves them to this binding.
     */
    private const array RESERVED_NAMESPACES = ['prov' => self::PROV_NS, 'xsd' => self::XSD_NS];

    private PrefixMinter $minter;

    /**
     * Whether a QName has to be resolved at the element it occurs on. False
     * while no element below the root or a bundleContent declares a namespace:
     * the namespace managers then hold every binding, and their cached
     * resolve() gives the same answer without a DOM lookup per name.
     */
    private bool $resolveAtElement = true;

    // PROV-XML element names match PROV-JSON keys, and the child element
    // layout per relation comes from RelationMetadata::xmlChildElements().

    public function __construct(
        public readonly bool $prettyPrint = true,
        public readonly bool $sortRecords = false,
    ) {
        $this->minter = new PrefixMinter(new NamespaceManager());
    }

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
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = $this->prettyPrint;

        $root = $dom->createElementNS(self::PROV_NS, 'prov:document');
        $dom->appendChild($root);

        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', self::XSD_NS);

        $nsManager = self::reservedScope();
        foreach (OutputOrder::namespaces($document->namespaces) as $ns) {
            $reserved = self::RESERVED_NAMESPACES[$ns->prefix] ?? null;
            if ($reserved !== null) {
                // The root binds both prefixes and the body writes prov:* and
                // xsd:* terms against them. A declaration naming the same
                // namespace joins the scope; any other one is left out, so the
                // minter gives its names another prefix.
                if ($reserved === $ns->uri) {
                    $nsManager->addOrReplace($ns);
                }
                continue;
            }
            if ($ns->prefix === 'default') {
                // PROV's "default" namespace is the XML default namespace.
                $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', $ns->uri);
                $nsManager->setDefault($ns);
                continue;
            }
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $ns->prefix, $ns->uri);
            $nsManager->addOrReplace($ns);
        }
        // A minted namespace that names an attribute element is declared by
        // createElementNS on that element. One that only appears inside a
        // prov:id/prov:ref string is declared on the root once the body is
        // built, below.
        $minter = new PrefixMinter($nsManager);
        $this->minter = $minter;

        $records = $this->sortRecords ? OutputOrder::records($document->records) : $document->records;
        foreach ($records as $record) {
            $this->serializeRecord($dom, $root, $record, $nsManager);
        }

        foreach ($document->bundles as $bundle) {
            $this->serializeBundle($dom, $root, $bundle, $nsManager);
        }

        // Minted namespaces go on the root as plain attributes, not as
        // namespace nodes: a namespace node added after the body is built makes
        // libxml re-resolve every element already in that namespace, and it can
        // then pick an alias prefix a bundle has rebound to another URI.
        foreach ($minter->getMintedNamespaces() as $ns) {
            $root->setAttribute('xmlns:' . $ns->prefix, $ns->uri);
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new \RuntimeException('DOMDocument::saveXML failed on a well-formed document.');
        }
        return $xml;
    }

    /**
     * A namespace scope seeded with the root's own prov and xsd bindings. The
     * manager's built-in xsd is the `#` form, which the output does not bind.
     */
    private static function reservedScope(?NamespaceManager $parent = null): NamespaceManager
    {
        $manager = new NamespaceManager($parent);
        foreach (self::RESERVED_NAMESPACES as $prefix => $uri) {
            $manager->addOrReplace(new ProvNamespace($prefix, $uri));
        }
        return $manager;
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
        // Every element is attached before it gets namespaced attributes or
        // children: libxml then binds those to the root's declarations instead
        // of redeclaring the namespace on the element itself.
        $parent->appendChild($el);
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier, $nsManager));
        }
        $this->serializeAttributes($dom, $el, $record->attributes, $nsManager);
    }

    private function serializeActivity(
        \DOMDocument $dom,
        \DOMElement $parent,
        Activity $record,
        NamespaceManager $nsManager,
    ): void {
        $el = $dom->createElementNS(self::PROV_NS, 'prov:activity');
        $parent->appendChild($el);
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier, $nsManager));
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
        $parent->appendChild($el);
        if ($record->identifier !== null) {
            $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($record->identifier, $nsManager));
        }

        $this->serializeRelationFormals($dom, $el, $record, $nsManager);

        // Dictionary-specific child elements.
        if ($record instanceof DictionaryMembership || $record instanceof DictionaryInsertion) {
            foreach ($record->keyEntityPairs as $pair) {
                $kep = $dom->createElementNS(self::PROV_NS, 'prov:keyEntityPair');
                $el->appendChild($kep);
                $keyEl = $dom->createElementNS(self::PROV_NS, 'prov:key');
                $kep->appendChild($keyEl);
                $this->setTypedTextContent($keyEl, $pair->key, $nsManager);
                if ($pair->entity !== null) {
                    $entityEl = $dom->createElementNS(self::PROV_NS, 'prov:entity');
                    $kep->appendChild($entityEl);
                    $entityEl->setAttributeNS(
                        self::PROV_NS,
                        'prov:ref',
                        $this->xmlIdentifier($pair->entity, $nsManager),
                    );
                }
            }
        } elseif ($record instanceof DictionaryRemoval) {
            foreach ($record->removedKeys as $key) {
                $keyEl = $dom->createElementNS(self::PROV_NS, 'prov:key');
                $el->appendChild($keyEl);
                $this->setTypedTextContent($keyEl, $key, $nsManager);
            }
        }

        $this->serializeAttributes($dom, $el, $record->attributes, $nsManager);
    }

    /**
     * The QName string to write for an identifier (prov:id, prov:ref, or an
     * xsd:QName value). A prefix bound in the current scope is written
     * verbatim; an undeclared (or prefix-conflicting) namespace goes through
     * the minter so a binding xmlns lands on the root rather than emitting an
     * unbound prefix. A default-namespace identifier is written bare only when
     * it lives in the document's default namespace, the one the root xmlns
     * binds: a bundle-local default is never registered on the bundle scope
     * (see serializeBundle()), so a name in it gets a real prefix instead.
     */
    private function xmlIdentifier(QualifiedName $qn, NamespaceManager $nsManager): string
    {
        return $this->minter->token($qn, $nsManager);
    }

    /**
     * The `xsi:type` QName for a literal datatype. XSD datatypes are written
     * against the root's own xsd binding whichever spelling the model carries.
     */
    private function xmlDatatype(QualifiedName $datatype, NamespaceManager $nsManager): string
    {
        return $this->xmlIdentifier(ValueIdentity::datatypeIn($datatype, self::xsdNamespace()), $nsManager);
    }

    /**
     * The `xsd` namespace as PROV-XML binds it, without a trailing `#`.
     */
    private static function xsdNamespace(): ProvNamespace
    {
        /** @var \Prov\Identifier\ProvNamespace|null $instance */
        static $instance = null;
        return $instance ??= new ProvNamespace('xsd', self::XSD_NS);
    }

    private function setTypedTextContent(\DOMElement $el, mixed $value, NamespaceManager $nsManager): void
    {
        if ($value instanceof QualifiedName) {
            $el->setAttributeNS(self::XSI_NS, 'xsi:type', 'xsd:QName');
            // The reserved default prefix is never written; xmlIdentifier yields
            // a bare local part (document default) or a declared/minted prefix.
            // Binding an element-level default xmlns instead would make libxml
            // re-resolve the element's own prefix against every in-scope
            // declaration of that URI, and it can then pick a prefix a bundle
            // has rebound.
            $el->textContent = $this->xmlIdentifier($value, $nsManager);
        } elseif ($value instanceof Literal) {
            if ($value->datatype !== null) {
                $el->setAttributeNS(self::XSI_NS, 'xsi:type', $this->xmlDatatype($value->datatype, $nsManager));
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

    private function serializeRelationFormals(
        \DOMDocument $dom,
        \DOMElement $el,
        ProvRelation $record,
        NamespaceManager $nsManager,
    ): void {
        $refProps = $this->getRefProperties($record);
        foreach ($refProps as $childName => $value) {
            if ($value instanceof QualifiedName) {
                $child = $dom->createElementNS(self::PROV_NS, 'prov:' . $childName);
                $child->setAttributeNS(self::PROV_NS, 'prov:ref', $this->xmlIdentifier($value, $nsManager));
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
            $prefixed = $this->minter->uriToPrefixed($uri, $nsManager);
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
        $parent->appendChild($el);

        if ($value instanceof QualifiedName) {
            $this->setTypedTextContent($el, $value, $nsManager);
        } elseif ($value instanceof Literal) {
            if ($value->languageTag !== null) {
                $el->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $value->languageTag);
            } elseif ($value->datatype !== null) {
                $el->setAttributeNS(self::XSI_NS, 'xsi:type', $this->xmlDatatype($value->datatype, $nsManager));
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
    }

    private function serializeBundle(
        \DOMDocument $dom,
        \DOMElement $parent,
        Bundle $bundle,
        NamespaceManager $nsManager,
    ): void {
        $el = $dom->createElementNS(self::PROV_NS, 'prov:bundleContent');
        // Attach before declaring anything: a namespace declaration only takes
        // part in libxml's scoping once its element is in the tree, and every
        // element built below has to see the bundle's own bindings.
        $parent->appendChild($el);

        // Bundle-level declarations become xmlns declarations on the
        // bundleContent element, mirroring how the deserializer reads them back
        // via extractLocalNamespaces(). Declarations identical to a
        // document-level one are inherited and need no repeat. A bundle-local
        // default namespace is neither declared nor registered on the bundle
        // scope: the bundleContent default stays the document's, and a name in
        // the bundle default is written through a real prefix instead.
        $bundleNsManager = self::reservedScope($nsManager);
        foreach (OutputOrder::namespaces($bundle->namespaces) as $ns) {
            $existing = $nsManager->getNamespace($ns->prefix);
            if ($existing !== null && $existing->uri === $ns->uri || $ns->prefix === 'default') {
                continue;
            }
            $reserved = self::RESERVED_NAMESPACES[$ns->prefix] ?? null;
            if ($reserved !== null) {
                // Same rule as at document level: the root's bindings stand,
                // and a declaration naming another namespace stays out of the
                // scope so its names get a minted prefix.
                if ($reserved === $ns->uri) {
                    $bundleNsManager->addOrReplace($ns);
                }
                continue;
            }
            $el->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $ns->prefix, $ns->uri);
            $bundleNsManager->addOrReplace($ns);
        }

        // The prov:id sits on the element that carries those declarations, so
        // XML scopes it with them: it has to be written in the bundle's own
        // scope, which is also where the reader resolves it.
        $el->setAttributeNS(self::PROV_NS, 'prov:id', $this->xmlIdentifier($bundle->identifier, $bundleNsManager));

        $records = $this->sortRecords ? OutputOrder::records($bundle->records) : $bundle->records;
        foreach ($records as $record) {
            $this->serializeRecord($dom, $el, $record, $bundleNsManager);
        }
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
        $this->resolveAtElement = $this->hasElementLocalDeclarations($dom, $data);

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
        $id = $this->resolveProvId($el, $nsManager);
        $attrs = $this->deserializeChildAttributes($el, $nsManager) ?? Attributes::empty();
        $records[] = new Entity($id, $attrs);
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeActivity(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $id = $this->resolveProvId($el, $nsManager);
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
        $id = $this->resolveProvId($el, $nsManager);
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
        $id = $this->resolveProvId($el, $nsManager);
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
                $ref = $this->resolveProvRef($child, $nsManager);
                if ($ref !== null) {
                    $formals[$formalMap[$childLocalName]] = $ref;
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
        $rq = fn(string $k) => $this->requireRef($q($k), $k, $relName);

        $record = match ($relName) {
            'wasGeneratedBy' => new Generation($id, $rq('entity'), $q('activity'), $t('time'), $attrs),
            'used' => new Usage($id, $q('activity'), $q('entity'), $t('time'), $attrs),
            'wasInformedBy' => new Communication($id, $q('informed'), $q('informant'), $attrs),
            'wasStartedBy' => new Start($id, $q('activity'), $q('trigger'), $q('starter'), $t('time'), $attrs),
            'wasEndedBy' => new End($id, $q('activity'), $q('trigger'), $q('ender'), $t('time'), $attrs),
            'wasInvalidatedBy' => new Invalidation($id, $rq('entity'), $q('activity'), $t('time'), $attrs),
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
            'specializationOf' => new Specialization($id, $rq('specificEntity'), $rq('generalEntity'), $attrs),
            'alternateOf' => new Alternate($id, $rq('alternate1'), $rq('alternate2'), $attrs),
            'hadMember' => new Membership($id, $q('collection'), $q('entity'), $attrs),
            'mentionOf' => new Mention($id, $rq('specificEntity'), $rq('generalEntity'), $q('bundle'), $attrs),
            default => null,
        };
        if ($record !== null) {
            $records[] = $record;
        }
    }

    /**
     * Enforces a PROV-DM-mandatory relation endpoint. A malformed document
     * omitting it is invalid input, not an internal error.
     *
     * @throws \Prov\Exception\DeserializationException
     *   When `$value` is null.
     */
    private function requireRef(?QualifiedName $value, string $prop, string $relName): QualifiedName
    {
        if ($value === null) {
            throw new DeserializationException(
                "Invalid PROV-XML: '{$relName}' is missing a required value for '{$prop}'.",
            );
        }
        return $value;
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     */
    private function deserializeDictionaryRelation(\DOMElement $el, NamespaceManager $nsManager, array &$records): void
    {
        $relName = $el->localName;
        $id = $this->resolveProvId($el, $nsManager);

        $keyEntityPairs = [];
        $removedKeys = [];
        $dictQn = null;
        $newDictQn = null;
        $oldDictQn = null;

        foreach ($el->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::PROV_NS) {
                continue;
            }

            match ($child->localName) {
                'dictionary' => $dictQn = $this->resolveProvRef($child, $nsManager),
                'newDictionary' => $newDictQn = $this->resolveProvRef($child, $nsManager),
                'oldDictionary' => $oldDictQn = $this->resolveProvRef($child, $nsManager),
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

        $record = match ($relName) {
            'hadDictionaryMember' => new DictionaryMembership(
                $id,
                $this->requireRef($dictQn, 'dictionary', $relName),
                $keyEntityPairs,
                $extraAttrs,
            ),
            'derivedByInsertionFrom' => new DictionaryInsertion(
                $id,
                $this->requireRef($newDictQn, 'after', $relName),
                $this->requireRef($oldDictQn, 'before', $relName),
                $keyEntityPairs,
                $extraAttrs,
            ),
            'derivedByRemovalFrom' => new DictionaryRemoval(
                $id,
                $this->requireRef($newDictQn, 'after', $relName),
                $this->requireRef($oldDictQn, 'before', $relName),
                $removedKeys,
                $extraAttrs,
            ),
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
                $entity = $this->resolveProvRef($child, $nsManager);
            }
        }

        return new DictionaryEntry($key, $entity);
    }

    /**
     * @param list<\Prov\Bundle> $bundles
     */
    private function deserializeBundleContent(\DOMElement $el, NamespaceManager $nsManager, array &$bundles): void
    {
        // The bundle's declarations sit on this element, so XML scopes its
        // prov:id with them: the identifier resolves in the bundle's own scope.
        $bundleNsManager = new NamespaceManager($nsManager);
        $this->extractLocalNamespaces($el, $bundleNsManager);

        $id = $this->resolveProvId($el, $bundleNsManager);
        if ($id === null) {
            return;
        }

        $bundleRecords = [];
        $nestedBundles = null;
        $this->deserializeChildren($el, $bundleNsManager, $bundleRecords, $nestedBundles);

        $bundles[] = new Bundle(
            identifier: $id,
            records: $bundleRecords,
            namespaces: $bundleNsManager->getRegisteredNamespaces(),
        );
    }

    /**
     * Register namespace declarations that appear on $el but are not inherited from its parent.
     * In-scope namespaces visible on the element (via `namespace::*`) include ancestors too; we
     * filter those out by comparing against the parent element's lookup.
     */
    /**
     * Whether any element other than the root and the bundleContent elements
     * declares a namespace. Only then can a QName resolve differently at its
     * own element than through the namespace managers, which hold exactly the
     * root's and each bundle's declarations.
     *
     * Every declaration spells "xmlns" in the input, and the root's and each
     * bundleContent's own declarations show up as in-scope namespace nodes
     * their parent lacks. More "xmlns" in the input than those account for
     * means a declaration somewhere else, or the word inside a value, which
     * only costs the shortcut. A bundleContent that rebinds an inherited
     * prefix adds no node, so it keeps the per-element resolution too.
     */
    private function hasElementLocalDeclarations(\DOMDocument $dom, string $data): bool
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('prov', self::PROV_NS);
        // @mago-expect analysis:mixed-assignment
        $scopes = $xpath->query('/prov:document | //prov:bundleContent');
        if (!$scopes instanceof \DOMNodeList) {
            return true;
        }

        $known = 0;
        foreach ($scopes as $scope) {
            if (!$scope instanceof \DOMElement) {
                return true;
            }
            // @mago-expect analysis:mixed-assignment
            $own = $xpath->query('namespace::*', $scope);
            if (!$own instanceof \DOMNodeList) {
                return true;
            }
            // The root's parent is the document node, whose only in-scope
            // namespace is the implicit xml one.
            $inherited = 1;
            $parent = $scope->parentNode;
            if ($parent instanceof \DOMElement) {
                // @mago-expect analysis:mixed-assignment
                $parentNodes = $xpath->query('namespace::*', $parent);
                if (!$parentNodes instanceof \DOMNodeList) {
                    return true;
                }
                $inherited = $parentNodes->length;
            }
            $known += max(0, $own->length - $inherited);
        }
        return substr_count($data, 'xmlns') > $known;
    }

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

    /**
     * The `prov:id` of an element, resolved in that element's namespace
     * context, or null when it carries none.
     */
    private function resolveProvId(\DOMElement $el, NamespaceManager $nsManager): ?QualifiedName
    {
        $id = $el->getAttributeNS(self::PROV_NS, 'id');
        return $id !== '' ? $this->resolveQNameInContext($id, $el, $nsManager) : null;
    }

    /**
     * The `prov:ref` of an element, resolved in that element's namespace
     * context, or null when it carries none.
     */
    private function resolveProvRef(\DOMElement $el, NamespaceManager $nsManager): ?QualifiedName
    {
        $ref = $el->getAttributeNS(self::PROV_NS, 'ref');
        return $ref !== '' ? $this->resolveQNameInContext($ref, $el, $nsManager) : null;
    }

    /**
     * Resolves a lexical XML QName at the element it occurs on: a prov:id, a
     * prov:ref, an xsi:type, or the text of a QName-typed value.
     *
     * XML binds a prefix at the element that uses it, so a declaration written
     * only on that element is in scope for the value even when the document
     * root never declares it. The DOM answers that lookup; the namespace
     * manager is the fallback for a name the DOM cannot bind (a blank node, or
     * a prefix the parser registered but XML never declared).
     *
     * @throws \Prov\Exception\NamespaceException
     *   When neither the element context nor the manager can resolve the name.
     */
    private function resolveQNameInContext(string $value, \DOMElement $el, NamespaceManager $nsManager): QualifiedName
    {
        // A blank label is not a QName. Without element-local declarations the
        // managers hold every binding, and resolve() answers from its cache.
        if (!$this->resolveAtElement || str_starts_with($value, '_:')) {
            return $nsManager->resolve($value);
        }

        // The element binds the prefix (or the default) the way the manager
        // does in nearly every remaining case too, and resolve() is cached, so
        // repeated ids and refs share one QualifiedName instance.
        $colon = strpos($value, ':');
        if ($colon === false) {
            $defaultUri = $el->lookupNamespaceURI(null);
            if (
                $defaultUri === null
                || $defaultUri === ''
                || $value === ''
                || $nsManager->getDefaultNamespace()?->uri === $defaultUri
            ) {
                return $nsManager->resolve($value);
            }
            return $this->namespaceFor($defaultUri, $nsManager, 'default')->qualifiedName($value);
        }

        $prefix = substr($value, 0, $colon);
        $localPart = substr($value, $colon + 1);
        $uri = $prefix !== '' ? $el->lookupNamespaceURI($prefix) : null;
        if ($uri === null || $uri === '' || $localPart === '' || $nsManager->getNamespace($prefix)?->uri === $uri) {
            return $nsManager->resolve($value);
        }
        return $this->namespaceFor($uri, $nsManager, $prefix)->qualifiedName($localPart);
    }

    /**
     * The ProvNamespace to build a name on when the element binds `$prefix` to
     * a URI the manager does not bind it to. Reuses a visible declaration of
     * that URI, so records parsed from one document share namespace instances
     * and keep the document's own prefixes; falls back to the element-local
     * binding.
     */
    private function namespaceFor(string $uri, NamespaceManager $nsManager, string $prefix): ProvNamespace
    {
        foreach ($nsManager->getVisibleNamespaces() as $ns) {
            if ($ns->uri === $uri && $ns->prefix !== 'default') {
                return $ns;
            }
        }
        return new ProvNamespace($prefix, $uri);
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
                && ValueIdentity::normalizeDatatypeUri(
                    $this->resolveQNameInContext($xsiType, $el, $nsManager)->getUri(),
                ) === ValueIdentity::XSD_QNAME_URI
            ) {
                return $this->resolveQNameInContext($el->textContent, $el, $nsManager);
            }
            $datatype = $this->resolveQNameInContext($xsiType, $el, $nsManager);
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
