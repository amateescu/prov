<?php

declare(strict_types=1);

namespace Prov\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Prov\Builder\DocumentBuilder;
use Prov\Document;
use Prov\Entity;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Model\AgentInvolvement;
use Prov\Operation\ProvGraph;
use Prov\Relation\Association;
use Prov\Relation\Derivation;
use Prov\Relation\Dictionary\DictionaryEntry;
use Prov\Relation\Generation;
use Prov\Relation\Usage;

final class ProvGraphTest extends TestCase
{
    private function buildDocument(): Document
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->entity('ex:article');
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
        $builder->wasAssociatedWith(activity: 'ex:writing', agent: 'ex:alice', plan: 'ex:draft');
        return $builder->build();
    }

    public function testRecordByIdentifier(): void
    {
        $graph = new ProvGraph($this->buildDocument());

        $record = $graph->recordByIdentifier('ex:article');
        $this->assertInstanceOf(Entity::class, $record);
        $this->assertSame('http://example.org/article', $record->identifier?->getUri());

        $this->assertInstanceOf(Generation::class, $graph->recordByIdentifier('ex:gen1'));
        $this->assertNull($graph->recordByIdentifier('ex:missing'));
    }

    public function testRecordByIdentifierResolvesFullUrn(): void
    {
        // A record under a URN namespace must be reachable by its full URN,
        // even though "urn:..." has no '//' authority and superficially looks
        // like a prefixed shorthand.
        $builder = new DocumentBuilder();
        $builder->addNamespace(new ProvNamespace('node', 'urn:uuid:abcdef12-3456-7890-abcd-ef1234567890#node/'));
        $builder->entity('node:42');
        $graph = new ProvGraph($builder->build());

        $uri = 'urn:uuid:abcdef12-3456-7890-abcd-ef1234567890#node/42';
        $record = $graph->recordByIdentifier($uri);
        $this->assertInstanceOf(Entity::class, $record);
        $this->assertSame($uri, $record->identifier?->getUri());
    }

    public function testRecordByIdentifierResolvesVersionedSlashLocalPart(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('node', 'http://example.org/node/');
        $builder->entity('http://example.org/node/42/rev/7');
        $graph = new ProvGraph($builder->build());

        $record = $graph->recordByIdentifier('http://example.org/node/42/rev/7');
        $this->assertInstanceOf(Entity::class, $record);
    }

    public function testRelationsFromMatchesSubject(): void
    {
        $graph = new ProvGraph($this->buildDocument());

        $fromArticle = $graph->relationsFrom('ex:article');
        $this->assertCount(2, $fromArticle);
        $this->assertInstanceOf(Generation::class, $fromArticle[0]);
        $this->assertInstanceOf(Derivation::class, $fromArticle[1]);

        $fromWriting = $graph->relationsFrom('ex:writing');
        $this->assertCount(2, $fromWriting);
        $this->assertInstanceOf(Usage::class, $fromWriting[0]);
        $this->assertInstanceOf(Association::class, $fromWriting[1]);
    }

    public function testRelationsToMatchesObject(): void
    {
        $graph = new ProvGraph($this->buildDocument());

        $toWriting = $graph->relationsTo('ex:writing');
        $this->assertCount(1, $toWriting);
        $this->assertInstanceOf(Generation::class, $toWriting[0]);

        $toAlice = $graph->relationsTo('ex:alice');
        $this->assertCount(1, $toAlice);
        $this->assertInstanceOf(Association::class, $toAlice[0]);
    }

    public function testRelationsReferencingIncludesSecondaryEndpoints(): void
    {
        $graph = new ProvGraph($this->buildDocument());

        // ex:writing is the Derivation's activity (third endpoint) and the
        // subject or object of three other relations.
        $referencing = $graph->relationsReferencing('ex:writing');
        $this->assertCount(4, $referencing);

        // ex:draft is the Association's plan (third endpoint).
        $planRefs = array_filter(
            $graph->relationsReferencing('ex:draft'),
            static fn($relation) => $relation instanceof Association,
        );
        $this->assertCount(1, $planRefs);
    }

    public function testGenerationsOfAndUsagesOf(): void
    {
        $graph = new ProvGraph($this->buildDocument());

        $generations = $graph->generationsOf('ex:article');
        $this->assertCount(1, $generations);
        $this->assertSame('http://example.org/gen1', $generations[0]->identifier?->getUri());

        $usages = $graph->usagesOf('ex:draft');
        $this->assertCount(1, $usages);
        $this->assertSame('http://example.org/use1', $usages[0]->identifier?->getUri());

        // usagesOf is entity-centric: the activity has no usages "of" it.
        $this->assertSame([], $graph->usagesOf('ex:writing'));
    }

    public function testIdentifierFormsAreEquivalent(): void
    {
        $document = $this->buildDocument();
        $graph = new ProvGraph($document);
        $qn = $document->entities[0]->identifier;
        $this->assertNotNull($qn);

        $byQn = $graph->generationsOf($qn);
        $byShorthand = $graph->generationsOf('ex:article');
        $byUri = $graph->generationsOf('http://example.org/article');

        $this->assertSame($byQn, $byShorthand);
        $this->assertSame($byQn, $byUri);
    }

    public function testUnresolvableIdentifiersMissGracefully(): void
    {
        // An identifier that resolves against no declared namespace can name
        // nothing in the index, so every lookup spelling misses the same way
        // an unknown authority-form URI does, instead of throwing.
        $graph = new ProvGraph($this->buildDocument());

        $this->assertSame([], $graph->relationsFrom('nope:article'));
        $this->assertNull($graph->recordByIdentifier('urn:uuid:00000000-0000-0000-0000-000000000000#node/1'));
        $this->assertNull($graph->recordByIdentifier('http://unknown.example/article'));

        // A declared prefix with no local part names nothing either.
        $this->assertNull($graph->recordByIdentifier('prov:'));
        $this->assertSame([], $graph->relationsReferencing('prov:'));

        // Neither does the bare name under a declared default namespace.
        $builder = new DocumentBuilder();
        $builder->namespace('default', 'http://default.org/');
        $builder->entity('article');
        $withDefault = new ProvGraph($builder->build());
        $this->assertNull($withDefault->recordByIdentifier(''));
    }

    public function testBlankNodeEndpoints(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $blank = $builder->blank();
        $builder->entity($blank);
        $builder->wasGeneratedBy(entity: $blank, activity: 'ex:a1');
        $graph = new ProvGraph($builder->build());

        $this->assertCount(1, $graph->relationsFrom($blank));
        $this->assertCount(1, $graph->relationsFrom('_:b1'));
        $this->assertInstanceOf(Entity::class, $graph->recordByIdentifier('_:b1'));
    }

    public function testWrapsBundles(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->entity('ex:e1');
        $bundleBuilder->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');
        $document = $builder->build();

        // The bundle declares no namespaces of its own ('ex' lives on the
        // document), so the lookup uses the full URI form.
        $graph = new ProvGraph($document->bundles[0]);
        $this->assertCount(1, $graph->generationsOf('http://example.org/e1'));
    }

    public function testBundleShorthandsResolveViaOwnNamespaces(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $bundleBuilder = $builder->bundle('ex:b1');
        $bundleBuilder->namespace('bx', 'http://bundle.example/');
        $bundleBuilder->entity('bx:e1');
        $bundleBuilder->wasGeneratedBy(entity: 'bx:e1', activity: 'bx:a1');
        $document = $builder->build();

        $graph = new ProvGraph($document->bundles[0]);
        $this->assertCount(1, $graph->generationsOf('bx:e1'));
        $this->assertCount(1, $graph->generationsOf('http://bundle.example/e1'));
    }

    public function testReferencedIdentifiersInPositionalOrder(): void
    {
        $document = $this->buildDocument();
        $derivation = $document->getRecordsByType(Derivation::class)[0];

        $uris = array_map(static fn($qn) => $qn->getUri(), ProvGraph::referencedIdentifiers($derivation));
        $this->assertSame(
            [
                'http://example.org/article',
                'http://example.org/draft',
                'http://example.org/writing',
            ],
            $uris,
        );
    }

    public function testReferencedIdentifiersIncludesDictionaryEntities(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->hadDictionaryMember(dictionary: 'ex:dict', keyEntityPairs: [new DictionaryEntry(
            'k1',
            $builder->blank(),
        )]);
        $document = $builder->build();
        $membership = $document->relations[0];

        $uris = array_map(static fn($qn) => $qn->getUri(), ProvGraph::referencedIdentifiers($membership));
        $this->assertSame(['http://example.org/dict', '_:b1'], $uris);

        $graph = new ProvGraph($document);
        $this->assertSame([$membership], $graph->relationsReferencing('_:b1'));
    }

    public function testRepeatedEndpointListedOnce(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasInformedBy(informed: 'ex:a1', informant: 'ex:a1');
        $graph = new ProvGraph($builder->build());

        $this->assertCount(1, $graph->relationsReferencing('ex:a1'));
        $this->assertCount(1, $graph->relationsFrom('ex:a1'));
        $this->assertCount(1, $graph->relationsTo('ex:a1'));
    }

    public function testResolvesUnprefixedIdentifiersAgainstDefaultNamespace(): void
    {
        $doc = new DocumentBuilder()
            ->setDefaultNamespace(new ProvNamespace('default', 'http://default.example/'))
            ->entity('e1')
            ->activity('a1')
            ->wasGeneratedBy(entity: 'e1', activity: 'a1')
            ->build();

        $graph = new ProvGraph($doc);

        // The default prefix in declared namespaces is treated as the default,
        // so a bare local part resolves the same as its full URI.
        $this->assertNotNull($graph->recordByIdentifier('e1'));
        $this->assertNotNull($graph->recordByIdentifier('http://default.example/e1'));
        $this->assertCount(1, $graph->relationsFrom('e1'));
        $this->assertCount(1, $graph->generationsOf('e1'));
    }

    public function testConstructsOverDocumentRedeclaringBuiltinNamespace(): void
    {
        // A document may carry a non-canonical prov/xsd URI (e.g. a deserialized
        // PROV-XML fixture that declares xsd without a trailing '#'). The graph
        // constructor must reproduce that binding, not throw on the built-in
        // "conflict" the strict NamespaceManager::add() would flag.
        $xsd = new ProvNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');
        $thing = $xsd->qualifiedName('thing');
        $doc = new Document(records: [new Entity($thing)], bundles: [], namespaces: [$xsd]);

        $graph = new ProvGraph($doc);
        $this->assertNotNull($graph->recordByIdentifier($thing->getUri()));
    }

    /**
     * @param list<\Prov\Identifier\QualifiedName> $identifiers
     *
     * @return list<string>
     */
    private static function localParts(array $identifiers): array
    {
        return array_map(static fn(QualifiedName $qn) => $qn->localPart, $identifiers);
    }

    public function testAgentsOfEmptyWhenActivityHasNoAssociations(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->activity('ex:run');
        $graph = new ProvGraph($builder->build());

        $this->assertSame([], $graph->agentsOf('ex:run'));
    }

    public function testAgentsOfSingleAgentWithoutDelegation(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertCount(1, $agents);
        $this->assertInstanceOf(AgentInvolvement::class, $agents[0]);
        $this->assertSame('bot', $agents[0]->agent->localPart);
        $this->assertNull($agents[0]->plan);
        $this->assertSame([], $agents[0]->onBehalfOf);
    }

    public function testAgentsOfFollowsSingleDelegationHop(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertCount(1, $agents);
        $this->assertSame(['alice'], self::localParts($agents[0]->onBehalfOf));
    }

    public function testAgentsOfFollowsMultiLevelChainInOrder(): void
    {
        // bot -> alice is scoped to ex:run (the queried activity), alice -> acme
        // is unscoped; both are followed, nearest responsible first.
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice', activity: 'ex:run');
        $builder->actedOnBehalfOf(delegate: 'ex:alice', responsible: 'ex:acme');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(['alice', 'acme'], self::localParts($agents[0]->onBehalfOf));
    }

    public function testAgentsOfTerminatesOnCycle(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice');
        $builder->actedOnBehalfOf(delegate: 'ex:alice', responsible: 'ex:bot');
        $graph = new ProvGraph($builder->build());

        // The walk stops once it would revisit ex:bot: alice is listed once,
        // with no repeat and no infinite loop.
        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(['alice'], self::localParts($agents[0]->onBehalfOf));
    }

    public function testAgentsOfReturnsEntryPerAssociatedAgentInOrder(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:alice');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(
            ['bot', 'alice'],
            self::localParts(array_map(static fn(AgentInvolvement $involvement) => $involvement->agent, $agents)),
        );
    }

    public function testAgentsOfSurfacesPlanAndAttributes(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot', plan: 'ex:recipe', attributes: [
            'prov:role' => 'executor',
        ]);
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame('recipe', $agents[0]->plan?->localPart);
        $this->assertCount(1, $agents[0]->attributes);
        $role = iterator_to_array($agents[0]->attributes, false);
        $this->assertSame('executor', $role[0]);
    }

    public function testAgentsOfExcludesDelegationScopedToAnotherActivity(): void
    {
        // bot -> alice holds only within ex:other, so it is absent from the
        // ex:run chain while the unscoped bot -> acme link still applies.
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice', activity: 'ex:other');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:acme');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(['acme'], self::localParts($agents[0]->onBehalfOf));
    }

    public function testAgentsOfSkipsAssociationWithNullAgent(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: null, plan: 'ex:recipe');
        $graph = new ProvGraph($builder->build());

        $this->assertSame([], $graph->agentsOf('ex:run'));
    }

    public function testAgentsOfAcceptsAllIdentifierForms(): void
    {
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $document = $builder->build();
        $graph = new ProvGraph($document);

        $byShorthand = $graph->agentsOf('ex:run');
        $byUri = $graph->agentsOf('http://example.org/run');
        $activityQn = $document->getRecordsByType(Association::class)[0]->activity;
        $this->assertNotNull($activityQn);
        $byQn = $graph->agentsOf($activityQn);

        $this->assertSame(
            self::localParts(array_map(static fn(AgentInvolvement $i) => $i->agent, $byShorthand)),
            self::localParts(array_map(static fn(AgentInvolvement $i) => $i->agent, $byUri)),
        );
        $this->assertSame(
            self::localParts(array_map(static fn(AgentInvolvement $i) => $i->agent, $byShorthand)),
            self::localParts(array_map(static fn(AgentInvolvement $i) => $i->agent, $byQn)),
        );
        $this->assertCount(1, $byQn);
    }

    public function testAgentsOfIgnoresNonAssociationSubjectRelations(): void
    {
        // ex:run is the subject of a usage and a start but no association, so
        // the instanceof filter yields no agents.
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->used(activity: 'ex:run', entity: 'ex:draft');
        $builder->wasStartedBy(activity: 'ex:run', trigger: 'ex:trigger');
        $graph = new ProvGraph($builder->build());

        $this->assertSame([], $graph->agentsOf('ex:run'));
    }

    public function testAgentsOfScansPastNonAssociationSubjectRelations(): void
    {
        // The non-association start sorts before the association in record
        // order, so a matching association is still found only because the
        // filter skips the start rather than stopping the scan at it.
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasStartedBy(activity: 'ex:run', trigger: 'ex:trigger');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(
            ['bot'],
            self::localParts(array_map(static fn(AgentInvolvement $involvement) => $involvement->agent, $agents)),
        );
    }

    public function testAgentsOfFollowsFirstDelegationWhenHopHasSeveral(): void
    {
        // bot delegates to both alice and carol; the chain follows the first in
        // record order and does not collapse the hop into the last one.
        $builder = new DocumentBuilder();
        $builder->namespace('ex', 'http://example.org/');
        $builder->wasAssociatedWith(activity: 'ex:run', agent: 'ex:bot');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:alice');
        $builder->actedOnBehalfOf(delegate: 'ex:bot', responsible: 'ex:carol');
        $graph = new ProvGraph($builder->build());

        $agents = $graph->agentsOf('ex:run');
        $this->assertSame(['alice'], self::localParts($agents[0]->onBehalfOf));
    }
}
