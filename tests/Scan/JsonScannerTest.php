<?php

declare(strict_types=1);

namespace Prov\Tests\Scan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prov\Attribute\Literal;
use Prov\Builder\DocumentBuilder;
use Prov\Entity;
use Prov\Exception\DeserializationException;
use Prov\Identifier\ProvNamespace;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Operation\ProvGraph;
use Prov\Relation\Derivation;
use Prov\Relation\DerivationSubtype;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Scan\JsonScanner;
use Prov\Scan\ScannedAgent;
use Prov\Scan\ScannedEndpoint;
use Prov\Scan\ScannedRelation;
use Prov\Serializer\JsonSerializer;

final class JsonScannerTest extends TestCase
{
    public function testNamespacesExposeTableIncludingBuiltins(): void
    {
        $scanner = new JsonScanner('{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{}}}');

        $namespaces = $scanner->namespaces;
        $this->assertSame('http://example.org/', $namespaces['ex']);
        $this->assertSame('http://www.w3.org/ns/prov#', $namespaces['prov']);
        $this->assertSame('http://www.w3.org/2001/XMLSchema#', $namespaces['xsd']);

        $this->assertSame('http://example.org/e1', $scanner->resolve('ex:e1')->getUri());
    }

    public function testMatchesRecordRegardlessOfPrefixSpelling(): void
    {
        // ex and ex2 bind the same URI, so a record keyed with one prefix is
        // reachable through the other, and through the full URI.
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://example.org/","ex2":"http://example.org/"},'
            . '"entity":{"ex:article":{"ex:title":"Hello","ex2:author":"Ada"}}}',
        );

        $this->assertSame('Hello', $scanner->attributeValue('entity', 'ex:article', 'ex:title'));
        $this->assertSame('Hello', $scanner->attributeValue('entity', 'ex2:article', 'ex:title'));
        $this->assertSame('Hello', $scanner->attributeValue('entity', 'ex:article', 'ex2:title'));
        $this->assertSame('Hello', $scanner->attributeValue(
            'entity',
            'http://example.org/article',
            'http://example.org/title',
        ));
        $this->assertSame('Ada', $scanner->attributeValue('entity', 'ex2:article', 'ex:author'));
    }

    public function testDecodesEscapedNamesLikeTheDeserializer(): void
    {
        // The serializer backslash-escapes PN_CHARS_ESC punctuation in ids and
        // attribute keys. The scanner reads those names back in the same
        // canonical form the deserializer reports, or the two disagree about
        // what the document says.
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $builder = new DocumentBuilder();
        $builder->namespace($ex->prefix, $ex->uri);
        $builder->entity($ex->qualifiedName('e(1),x'), ['ex:a,b' => 'v']);
        $builder->wasGeneratedBy(entity: $ex->qualifiedName('e(1),x'), activity: 'ex:a1');
        $json = new JsonSerializer()->serialize($builder->build());
        $scanner = new JsonScanner($json);

        // ids() reports the document's own spelling, escapes and all.
        $rawId = 'ex:e\\(1\\)\\,x';
        $this->assertSame([$rawId], $scanner->ids('entity'));

        // Everything that resolves reports the decoded name.
        $this->assertSame('http://example.org/e(1),x', $scanner->resolve($rawId)->getUri());
        $this->assertSame(['http://example.org/a,b' => ['v']], $scanner->attributesOf('entity', $rawId));
        $this->assertSame('http://example.org/e(1),x', $scanner->relationEndpoints()[0]->identifier->getUri());

        // So a caller holding the plain name reaches the record with it.
        $this->assertSame('v', $scanner->attributeValue('entity', 'ex:e(1),x', 'ex:a,b'));

        $entity = new JsonSerializer()->deserialize($json)->records[0];
        $this->assertSame($entity->identifier?->getUri(), $scanner->resolve($rawId)->getUri());
    }

    public function testNormalizesTypedAndMultiValuedAttributes(): void
    {
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://example.org/","xsd":"http://www.w3.org/2001/XMLSchema#"},'
            . '"entity":{"ex:e1":{'
            . '"ex:title":"plain",'
            . '"ex:count":{"$":"42","type":"xsd:int"},'
            . '"ex:ratio":{"$":"3.5","type":"xsd:float"},'
            . '"ex:flag":{"$":"true","type":"xsd:boolean"},'
            . '"ex:when":{"$":"2024-01-15T10:00:00Z","type":"xsd:dateTime"},'
            . '"ex:ref":{"$":"ex:other","type":"prov:QUALIFIED_NAME"},'
            . '"ex:label":{"$":"bonjour","lang":"fr"},'
            . '"ex:padded":{"$":"+007","type":"xsd:int"},'
            . '"ex:huge":{"$":"99999999999999999999","type":"xsd:integer"},'
            . '"ex:tags":["a","b",{"$":"7","type":"xsd:int"}]'
            . '}}}',
        );

        // The common xsd types collapse to PHP scalars.
        $this->assertSame('plain', $scanner->attributeValue('entity', 'ex:e1', 'ex:title'));
        $this->assertSame(42, $scanner->attributeValue('entity', 'ex:e1', 'ex:count'));
        $this->assertSame(7, $scanner->attributeValue('entity', 'ex:e1', 'ex:padded'));
        $this->assertSame(3.5, $scanner->attributeValue('entity', 'ex:e1', 'ex:ratio'));
        $this->assertTrue($scanner->attributeValue('entity', 'ex:e1', 'ex:flag'));

        // dateTime, QualifiedName references, and language-tagged literals stay raw.
        $this->assertSame(
            ['$' => '2024-01-15T10:00:00Z', 'type' => 'xsd:dateTime'],
            $scanner->attributeValue('entity', 'ex:e1', 'ex:when'),
        );
        $this->assertSame(
            ['$' => 'ex:other', 'type' => 'prov:QUALIFIED_NAME'],
            $scanner->attributeValue('entity', 'ex:e1', 'ex:ref'),
        );
        $this->assertSame(['$' => 'bonjour', 'lang' => 'fr'], $scanner->attributeValue('entity', 'ex:e1', 'ex:label'));

        // xsd integers are unbounded, so a value a PHP int cannot hold stays
        // raw instead of being reported as the saturated PHP_INT_MAX.
        $this->assertSame(
            ['$' => '99999999999999999999', 'type' => 'xsd:integer'],
            $scanner->attributeValue('entity', 'ex:e1', 'ex:huge'),
        );

        // Multi-valued attributes normalize element by element.
        $attributes = $scanner->attributesOf('entity', 'ex:e1');
        $this->assertSame(['a', 'b', 7], $attributes['http://example.org/tags']);
        $this->assertSame(['plain'], $attributes['http://example.org/title']);
    }

    public function testIdsIterateSectionInDocumentOrder(): void
    {
        $scanner = new JsonScanner($this->serializeBuiltDocument());

        $this->assertSame(['ex:article', 'ex:draft'], $scanner->ids('entity'));
        $this->assertSame(['ex:writing'], $scanner->ids('activity'));
        $this->assertSame(['ex:alice'], $scanner->ids('agent'));
        $this->assertSame(['ex:gen1'], $scanner->ids('wasGeneratedBy'));
        $this->assertSame([], $scanner->ids('wasInvalidatedBy'));
    }

    public function testRelationsYieldEndpointsAndAttributes(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasGeneratedBy(
            entity: 'ex:e1',
            activity: 'ex:a1',
            time: new \DateTimeImmutable('2024-01-15T10:00:00Z'),
            attributes: ['ex:note' => 'done'],
            identifier: 'ex:gen1',
        );
        $scanner = new JsonScanner(new JsonSerializer()->serialize($builder->build()));

        $relations = $scanner->relations('wasGeneratedBy');
        $this->assertCount(1, $relations);
        $relation = $relations[0];
        $this->assertInstanceOf(ScannedRelation::class, $relation);
        $this->assertSame('wasGeneratedBy', $relation->section);
        $this->assertSame('ex:gen1', $relation->id);
        $this->assertSame('ex:e1', $relation->endpoints['prov:entity']);
        $this->assertSame('ex:a1', $relation->endpoints['prov:activity']);
        $this->assertArrayHasKey('prov:time', $relation->endpoints);
        $this->assertSame(['done'], $relation->attributes['http://example.org/note']);
        // A formal endpoint is not reported as an attribute.
        $this->assertArrayNotHasKey('http://www.w3.org/ns/prov#entity', $relation->attributes);
    }

    public function testDerivationSubtypeMatchesDeserializedRecord(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasRevisionOf(generatedEntity: 'ex:e2', usedEntity: 'ex:e1', identifier: 'ex:rev');
        $builder->wasDerivedFrom(generatedEntity: 'ex:e3', usedEntity: 'ex:e1', identifier: 'ex:plain');
        // A string literal spelled like the type name is not a qualified name.
        $builder->wasDerivedFrom(generatedEntity: 'ex:e4', usedEntity: 'ex:e1', identifier: 'ex:literal', attributes: [
            'prov:type' => new Literal('prov:Quotation'),
        ]);
        $builder->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1', identifier: 'ex:gen');
        $json = new JsonSerializer()->serialize($builder->build());
        $scanner = new JsonScanner($json);

        $scanned = [];
        foreach ($scanner->relations('wasDerivedFrom') as $relation) {
            $scanned[$relation->id] = $relation->derivationSubtype;
        }
        $this->assertSame(
            ['ex:rev' => DerivationSubtype::Revision, 'ex:plain' => null, 'ex:literal' => null],
            $scanned,
        );
        $this->assertNull($scanner->relations('wasGeneratedBy')[0]->derivationSubtype);

        // The scan-side reader agrees with the model-side one.
        foreach (new JsonSerializer()
            ->deserialize($json)
            ->getRecordsByType(Derivation::class) as $derivation) {
            $this->assertSame($scanned[(string) $derivation->identifier], $derivation->subtype());
        }
    }

    public function testDerivationSubtypeIsNamespaceAware(): void
    {
        $json = json_encode([
            'prefix' => ['p' => 'http://www.w3.org/ns/prov#', 'ex' => 'http://example.org/'],
            'wasDerivedFrom' => [
                'ex:d1' => [
                    'p:generatedEntity' => 'ex:e2',
                    'p:usedEntity' => 'ex:e1',
                    'p:type' => ['$' => 'p:PrimarySource', 'type' => 'p:QUALIFIED_NAME'],
                ],
            ],
        ]);
        $this->assertIsString($json);

        $relation = new JsonScanner($json)->relations('wasDerivedFrom')[0];
        $this->assertSame(DerivationSubtype::PrimarySource, $relation->derivationSubtype);
    }

    public function testRelationsReferencingSpansSections(): void
    {
        $scanner = new JsonScanner($this->serializeBuiltDocument());

        // ex:draft is a usage's entity, a derivation's used entity, and an
        // association's plan: three relations in three sections.
        $sections = array_map(
            static fn(ScannedRelation $relation): string => $relation->section,
            $scanner->relationsReferencing('ex:draft'),
        );
        sort($sections);
        $this->assertSame(['used', 'wasAssociatedWith', 'wasDerivedFrom'], $sections);

        // A relation referencing an id in two roles is listed once.
        $this->assertCount(4, $scanner->relationsReferencing('ex:writing'));
    }

    public function testRelationEndpointsYieldsRoleKindedReferences(): void
    {
        $ex = new ProvNamespace('ex', 'http://example.org/');
        $builder = new DocumentBuilder();
        $builder->namespace($ex->prefix, $ex->uri);
        $builder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $builder->used(activity: 'ex:a1', entity: 'ex:e2');
        $builder->wasAttributedTo(entity: 'ex:e1', agent: 'ex:alice');
        $builder->wasAssociatedWith(activity: 'ex:a1', agent: 'ex:bot', plan: 'ex:recipe');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice', activity: 'ex:a1');
        $builder->hadDictionaryMember('ex:dict1', keyEntityPairs: [new DictionaryEntry(
            'k1',
            $ex->qualifiedName('e3'),
        )]);
        $scanner = new JsonScanner(new JsonSerializer()->serialize($builder->build()));

        $endpoints = $scanner->relationEndpoints();
        $summary = array_map(static fn(ScannedEndpoint $endpoint): string => sprintf(
            '%s|%s|%s|%s',
            $endpoint->section,
            $endpoint->role,
            $endpoint->kind,
            $endpoint->identifier->getUri(),
        ), $endpoints);
        sort($summary);

        // Every reference of every relation is reported, role-kinded: an
        // association's agent and a delegation's delegate/responsible are
        // 'agent'-kind (not entity), an association's plan is entity-kind
        // despite being the plan the activity ran under, and a dictionary
        // member's key-entity contributes an entity-kind 'keyEntity' role
        // alongside the dictionary's own entity-kind reference.
        $this->assertSame(
            [
                'actedOnBehalfOf|activity|activity|http://example.org/a1',
                'actedOnBehalfOf|delegate|agent|http://example.org/bot',
                'actedOnBehalfOf|responsible|agent|http://example.org/alice',
                'hadDictionaryMember|dictionary|entity|http://example.org/dict1',
                'hadDictionaryMember|keyEntity|entity|http://example.org/e3',
                'used|activity|activity|http://example.org/a1',
                'used|entity|entity|http://example.org/e2',
                'wasAssociatedWith|activity|activity|http://example.org/a1',
                'wasAssociatedWith|agent|agent|http://example.org/bot',
                'wasAssociatedWith|plan|entity|http://example.org/recipe',
                'wasAttributedTo|agent|agent|http://example.org/alice',
                'wasAttributedTo|entity|entity|http://example.org/e1',
                'wasGeneratedBy|activity|activity|http://example.org/a1',
                'wasGeneratedBy|entity|entity|http://example.org/e1',
            ],
            $summary,
        );
    }

    public function testRelationEndpointsSkipsUnresolvableEndpoint(): void
    {
        // 'unknown:e1' has no registered namespace, so it cannot resolve; the
        // relation's other, resolvable endpoint is still reported.
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://example.org/"},'
            . '"wasGeneratedBy":{"ex:gen1":{"prov:entity":"unknown:e1","prov:activity":"ex:a1"}}}',
        );

        $endpoints = $scanner->relationEndpoints();
        $this->assertCount(1, $endpoints);
        $this->assertSame('activity', $endpoints[0]->role);
        $this->assertSame('http://example.org/a1', $endpoints[0]->identifier->getUri());
    }

    public function testFormalKindsClassifyEveryFormalOfASection(): void
    {
        // The formal keys come back in PROV-N positional order, each kinded by
        // what it holds: a reference naming another record, a time, or a
        // dictionary key set. A consumer rewriting stored PROV-JSON follows the
        // references and leaves the rest alone.
        $this->assertSame(
            ['prov:entity' => 'ref', 'prov:activity' => 'ref', 'prov:time' => 'time'],
            JsonScanner::formalKinds('wasGeneratedBy'),
        );
        $this->assertSame(
            ['prov:dictionary' => 'ref', 'prov:key-entity-set' => 'array'],
            JsonScanner::formalKinds('hadDictionaryMember'),
        );
        $this->assertSame(
            ['prov:after' => 'ref', 'prov:before' => 'ref', 'prov:key-set' => 'array'],
            JsonScanner::formalKinds('derivedByRemovalFrom'),
        );

        // An element section and an unknown section have no formals at all, so
        // every entry of such a record body is an attribute.
        $this->assertSame([], JsonScanner::formalKinds('entity'));
        $this->assertSame([], JsonScanner::formalKinds('nosuchsection'));

        // Every relation section is classified, and those are the only kinds.
        foreach (RelationMetadata::JSON_KEYS as $section) {
            $kinds = JsonScanner::formalKinds($section);
            $this->assertNotSame([], $kinds, $section);
            foreach ($kinds as $key => $kind) {
                $this->assertStringStartsWith('prov:', $key);
                $this->assertContains($kind, ['ref', 'time', 'array'], $section . ' ' . $key);
            }
        }
    }

    public function testIsQualifiedNameValueSeparatesNamesFromLiterals(): void
    {
        // xs binds the XSD namespace without the trailing hash, the spelling
        // PROV-XML fixtures use, and p binds the PROV namespace under another
        // prefix. Both resolve to the tags a qualified-name value carries.
        $scanner = new JsonScanner(
            '{"prefix":{"xs":"http://www.w3.org/2001/XMLSchema","p":"http://www.w3.org/ns/prov#"},"entity":{"prov:e1":{}}}',
        );

        foreach (['prov:QUALIFIED_NAME', 'xsd:QName', 'xs:QName', 'p:QUALIFIED_NAME'] as $tag) {
            $this->assertTrue($scanner->isQualifiedNameValue(['$' => 'prov:e1', 'type' => $tag]), $tag);
        }

        // A literal is data whatever its text reads like, and so is a typed
        // value under any other datatype or a language-tagged one.
        $this->assertFalse($scanner->isQualifiedNameValue('prov:e1'));
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'prov:e1', 'type' => 'xsd:string']));
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'prov:e1', 'lang' => 'en']));
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'prov:e1', 'type' => 'nosuch:QName']));

        // A damaged value is not a name either: the lexical form has to be
        // there and it has to be a string, and so does the tag.
        $this->assertFalse($scanner->isQualifiedNameValue(['type' => 'prov:QUALIFIED_NAME']));
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 5, 'type' => 'prov:QUALIFIED_NAME']));
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'prov:e1', 'type' => 5]));

        // The deserializer reads the same value as a QualifiedName, so a
        // consumer following this predicate follows the same references the
        // model does.
        $json =
            '{"prefix":{"ex":"http://example.org/"},'
            . '"entity":{"ex:e1":{"ex:cites":{"$":"ex:e2","type":"prov:QUALIFIED_NAME"}}}}';
        $cites = new ProvNamespace('ex', 'http://example.org/')->qualifiedName('cites');
        $entity = new JsonSerializer()
            ->deserialize($json)
            ->getRecordsByType(Entity::class)[0];
        $reference = ['$' => 'ex:e2', 'type' => 'prov:QUALIFIED_NAME'];
        $this->assertSame('http://example.org/e2', $entity->attributes->getQualifiedNames($cites)[0]->getUri());
        $this->assertTrue(new JsonScanner($json)->isQualifiedNameValue($reference));
    }

    public function testResolveReferenceAcceptsBothWrittenForms(): void
    {
        $scanner = new JsonScanner('{"prefix":{"ex":"http://example.org/"},"entity":{"ex:e1":{}}}');

        // The bare string and the typed map name the same thing.
        $this->assertSame('http://example.org/e1', $scanner->resolveReference('ex:e1')?->getUri());
        $this->assertSame(
            'http://example.org/e1',
            $scanner->resolveReference(['$' => 'ex:e1', 'type' => 'prov:QUALIFIED_NAME'])?->getUri(),
        );

        // The tag is not checked, so a literal whose text reads like an
        // identifier resolves too. Callers at positions where a literal is
        // allowed ask isQualifiedNameValue() first.
        $this->assertSame(
            'http://example.org/e1',
            $scanner->resolveReference(['$' => 'ex:e1', 'type' => 'xsd:string'])?->getUri(),
        );
        $this->assertFalse($scanner->isQualifiedNameValue(['$' => 'ex:e1', 'type' => 'xsd:string']));

        // A value that is not a reference, and one the document cannot resolve.
        $this->assertNull($scanner->resolveReference(['type' => 'prov:QUALIFIED_NAME']));
        $this->assertNull($scanner->resolveReference(['$' => 5]));
        $this->assertNull($scanner->resolveReference(5));
        $this->assertNull($scanner->resolveReference(null));
        $this->assertNull($scanner->resolveReference('nosuch:e1'));
    }

    public function testHadMemberEntityListReportsEveryMember(): void
    {
        // PROV-JSON lets hadMember carry several entities under one prov:entity
        // key. The deserializer expands that into one Membership per entity, so
        // the scanner reports one endpoint per entity too.
        $json =
            '{"prefix":{"ex":"http://example.org/"},'
            . '"hadMember":{"ex:m1":{"prov:collection":"ex:c","prov:entity":["ex:e1","ex:e2"]}}}';
        $scanner = new JsonScanner($json);

        $summary = array_map(
            static fn(ScannedEndpoint $endpoint): string => $endpoint->role . '|' . $endpoint->identifier->getUri(),
            $scanner->relationEndpoints(),
        );
        sort($summary);
        $this->assertSame(
            [
                'collection|http://example.org/c',
                'entity|http://example.org/e1',
                'entity|http://example.org/e2',
            ],
            $summary,
        );

        // The endpoint index covers them too, so each member finds its membership.
        $this->assertCount(1, $scanner->relationsReferencing('ex:e1'));
        $this->assertCount(1, $scanner->relationsReferencing('ex:e2'));

        $this->assertCount(2, new JsonSerializer()->deserialize($json)->records);
    }

    public function testEmptyLocalPartIsSkippedRatherThanThrown(): void
    {
        // 'prov:', '_:' and the bare name under a declared default namespace all
        // lack a local part, so they name nothing. The queries have to skip them
        // the same way they skip an undeclared prefix, not throw.
        $scanner = new JsonScanner(
            '{"prefix":{"default":"http://default.org/","ex":"http://example.org/"},'
            . '"entity":{"ex:e1":{"prov:":"unreadable","ex:t":"ok"}},'
            . '"wasGeneratedBy":{"ex:gen1":{"prov:entity":"","prov:activity":"prov:"}},'
            . '"used":{"ex:use1":{"prov:entity":"_:","prov:activity":"ex:a1"}}}',
        );

        $this->assertSame(['http://example.org/t' => ['ok']], $scanner->attributesOf('entity', 'ex:e1'));

        $endpoints = $scanner->relationEndpoints();
        $this->assertCount(1, $endpoints);
        $this->assertSame('http://example.org/a1', $endpoints[0]->identifier->getUri());

        // Building the endpoint index walks the empty endpoints too; a lookup on
        // a sound identifier still answers.
        $referencing = $scanner->relationsReferencing('ex:a1');
        $this->assertCount(1, $referencing);
        $this->assertSame('used', $referencing[0]->section);
    }

    public function testAgentsOfFollowsDelegationChain(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(
            activity: 'ex:run',
            agent: 'ex:bot',
            plan: 'ex:recipe',
            attributes: ['prov:role' => 'executor'],
            identifier: 'ex:assoc1',
        );
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice', activity: 'ex:run');
        $builder->actedOnBehalfOf(delegate: 'ex:alice', responsible: 'ex:acme');
        $scanner = new JsonScanner(new JsonSerializer()->serialize($builder->build()));

        $agents = $scanner->agentsOf('ex:run');
        $this->assertCount(1, $agents);
        $this->assertInstanceOf(ScannedAgent::class, $agents[0]);
        $this->assertSame('ex:bot', $agents[0]->agent);
        $this->assertSame('ex:recipe', $agents[0]->plan);
        $this->assertSame(['ex:alice', 'ex:acme'], $agents[0]->onBehalfOf);
        $this->assertSame(['executor'], $agents[0]->attributes['http://www.w3.org/ns/prov#role']);
    }

    public function testAgentsOfExcludesDelegationScopedToAnotherActivity(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot', identifier: 'ex:assoc1');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice', activity: 'ex:other');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:acme');
        $scanner = new JsonScanner(new JsonSerializer()->serialize($builder->build()));

        $agents = $scanner->agentsOf('ex:run');
        $this->assertCount(1, $agents);
        $this->assertSame(['ex:acme'], $agents[0]->onBehalfOf);
    }

    public function testAgentsOfIsNamespaceAware(): void
    {
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://example.org/","ex2":"http://example.org/"},'
            . '"wasAssociatedWith":{"ex:assoc1":{"prov:activity":"ex:run","prov:agent":"ex:bot"}}}',
        );

        // The activity is queried through a different prefix than the document used.
        $agents = $scanner->agentsOf('ex2:run');
        $this->assertCount(1, $agents);
        $this->assertSame('ex:bot', $agents[0]->agent);
    }

    public function testSkipsMalformedRecordBody(): void
    {
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://e/"},"entity":{"ex:good":{"ex:t":"ok"},"ex:bad":"notamap"}}',
        );

        // ids lists every declared key; reading the damaged body yields nothing.
        $this->assertSame(['ex:good', 'ex:bad'], $scanner->ids('entity'));
        $this->assertSame(['http://e/t' => ['ok']], $scanner->attributesOf('entity', 'ex:good'));
        $this->assertSame([], $scanner->attributesOf('entity', 'ex:bad'));
        $this->assertNull($scanner->attributeValue('entity', 'ex:bad', 'ex:t'));
    }

    public function testSkipsMalformedTypedValue(): void
    {
        // ex:a is a typed object with no "$" member: unreadable, so it drops out
        // while the sound attribute on the same record survives.
        $scanner = new JsonScanner(
            '{"prefix":{"ex":"http://e/"},"entity":{"ex:e":{"ex:a":{"type":"xsd:int"},"ex:b":"ok"}}}',
        );

        $this->assertSame(['http://e/b' => ['ok']], $scanner->attributesOf('entity', 'ex:e'));
        $this->assertNull($scanner->attributeValue('entity', 'ex:e', 'ex:a'));
        $this->assertSame('ok', $scanner->attributeValue('entity', 'ex:e', 'ex:b'));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function structuralDamageProvider(): array
    {
        return [
            'not json' => ['this is not json'],
            'json scalar' => ['42'],
            'non-empty list root' => ['[1,2,3]'],
            'prefix section is a scalar' => ['{"prefix":"notamap"}'],
            'non-string prefix uri' => ['{"prefix":{"ex":123}}'],
            'entity section is a scalar' => ['{"prefix":{"ex":"http://e/"},"entity":"notamap"}'],
            'relation section is a scalar' => ['{"wasGeneratedBy":"notamap"}'],
        ];
    }

    #[DataProvider('structuralDamageProvider')]
    public function testStructuralDamageThrowsAtConstruction(string $input): void
    {
        $this->expectException(DeserializationException::class);
        new JsonScanner($input);
    }

    public function testEquivalenceWithDeserializeAndProvGraph(): void
    {
        $json = $this->serializeBuiltDocument();
        $document = new JsonSerializer()->deserialize($json);
        $graph = new ProvGraph($document);
        $scanner = new JsonScanner($json);

        // Record ids per section match the deserialized document.
        $this->assertSame($this->sortedUris($document->entities), $this->sortedResolvedIds($scanner, 'entity'));
        $this->assertSame($this->sortedUris($document->activities), $this->sortedResolvedIds($scanner, 'activity'));
        $this->assertSame($this->sortedUris($document->agents), $this->sortedResolvedIds($scanner, 'agent'));

        // The attribute map of one record matches.
        $article = $document->getRecordByIdentifier($scanner->resolve('ex:article'));
        $this->assertNotNull($article);
        $scannerAttrs = $scanner->attributesOf('entity', 'ex:article');
        $this->assertSame(array_keys($article->attributes->all()), array_keys($scannerAttrs));
        foreach ($article->attributes->all() as $uri => $values) {
            $this->assertSame((string) $values[0], (string) $scannerAttrs[$uri][0]);
        }

        // The relations touching one record match ProvGraph's view.
        $this->assertSame(
            $this->graphReferencing($graph, 'http://example.org/writing'),
            $this->scannerReferencing($scanner, 'ex:writing'),
        );

        // The agents behind one activity match ProvGraph::agentsOf.
        $graphAgents = $graph->agentsOf('ex:writing');
        $scannerAgents = $scanner->agentsOf('ex:writing');
        $this->assertCount(count($graphAgents), $scannerAgents);
        $this->assertSame($graphAgents[0]->agent->getUri(), $scanner->resolve($scannerAgents[0]->agent)->getUri());
        $this->assertSame(
            $graphAgents[0]->plan?->getUri(),
            $scannerAgents[0]->plan !== null ? $scanner->resolve($scannerAgents[0]->plan)->getUri() : null,
        );
        $this->assertSame([], $scannerAgents[0]->onBehalfOf);
    }

    private function serializeBuiltDocument(): string
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->entity('ex:article', ['ex:title' => 'My Article']);
        $builder->entity('ex:draft');
        $builder->activity('ex:writing');
        $builder->agent('ex:alice');
        $builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:writing', identifier: 'ex:gen1');
        $builder->used(activity: 'ex:writing', entity: 'ex:draft', identifier: 'ex:use1');
        $builder->wasDerivedFrom(
            generatedEntity: 'ex:article',
            usedEntity: 'ex:draft',
            activity: 'ex:writing',
            identifier: 'ex:der1',
        );
        $builder->wasAssociatedWith(
            activity: 'ex:writing',
            agent: 'ex:alice',
            plan: 'ex:draft',
            identifier: 'ex:assoc1',
        );
        return new JsonSerializer()->serialize($builder->build());
    }

    /**
     * @param list<\Prov\Model\ProvRecord> $records
     *
     * @return list<string>
     */
    private function sortedUris(array $records): array
    {
        $uris = [];
        foreach ($records as $record) {
            $this->assertNotNull($record->identifier);
            $uris[] = $record->identifier->getUri();
        }
        sort($uris);
        return $uris;
    }

    /**
     * @return list<string>
     */
    private function sortedResolvedIds(JsonScanner $scanner, string $section): array
    {
        $uris = array_map(static fn(string $id): string => $scanner->resolve($id)->getUri(), $scanner->ids($section));
        sort($uris);
        return $uris;
    }

    /**
     * @return list<string>
     */
    private function graphReferencing(ProvGraph $graph, string $identifier): array
    {
        $out = [];
        foreach ($graph->relationsReferencing($identifier) as $relation) {
            $this->assertInstanceOf(ProvRelation::class, $relation);
            $this->assertNotNull($relation->identifier);
            $out[] = RelationMetadata::JSON_KEYS[$relation::class] . '|' . $relation->identifier->getUri();
        }
        sort($out);
        return $out;
    }

    /**
     * @return list<string>
     */
    private function scannerReferencing(JsonScanner $scanner, string $identifier): array
    {
        $out = [];
        foreach ($scanner->relationsReferencing($identifier) as $relation) {
            $out[] = $relation->section . '|' . $scanner->resolve($relation->id)->getUri();
        }
        sort($out);
        return $out;
    }
}
