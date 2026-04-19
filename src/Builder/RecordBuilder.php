<?php

declare(strict_types=1);

namespace Prov\Builder;

use Prov\Activity;
use Prov\Agent;
use Prov\Attribute\Attributes;
use Prov\Entity;
use Prov\Identifier\NamespaceManager;
use Prov\Identifier\ProvNamespace;
use Prov\Identifier\QualifiedName;
use Prov\Relation\Alternate;
use Prov\Relation\Association;
use Prov\Relation\Attribution;
use Prov\Relation\Communication;
use Prov\Relation\Delegation;
use Prov\Relation\Derivation;
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
 * Base builder for PROV documents and bundles.
 *
 * All element and relation methods accept identifiers as QualifiedName objects or as string
 * shorthands resolved via the NamespaceManager (see NamespaceManager::resolve()). A null
 * identifier creates a blank node.
 *
 * Attributes can be passed as an Attributes object or as an associative array of
 * string keys (resolved as QualifiedName shorthands) to scalar/QualifiedName values.
 *
 * **Always pass relation arguments by name.** Positional order follows the PROV-N spec
 * (e.g. `wasGeneratedBy(id; entity, activity, ...)` vs `used(id; activity, entity, ...)`),
 * so positional calls quietly invert the meaning when the relation points the other way.
 * All examples and the README use named arguments:
 *
 *   $b->used(activity: 'ex:a1', entity: 'ex:e1');            // correct
 *   $b->wasGeneratedBy(entity: 'ex:e1', activity: 'ex:a1');  // correct
 *   $b->used('ex:a1', 'ex:e1');                              // DO NOT DO THIS
 */
abstract class RecordBuilder
{
    /**
     * Reserved pseudo-namespace for auto-generated blank-node identifiers.
     *
     * Blank nodes are anonymous records without a real IRI; the PROV-JSON and
     * Turtle traditions render them as `_:bN`. `BLANK_URI` is not a resolvable
     * URI; it is a sentinel that lets blank-node QualifiedNames share the
     * QualifiedName plumbing used by regular identifiers.
     */
    private const string BLANK_PREFIX = '_';
    private const string BLANK_URI = '_:';

    protected NamespaceManager $namespaceManager;

    /** @var list<\Prov\Model\ProvRecord> */
    protected array $records = [];

    protected bool $built = false;

    private int $blankNodeCounter = 0;

    /**
     * Mints a fresh blank-node identifier (e.g. `_:b1`). Capture the return
     * value when creating an anonymous record so you can refer to the same
     * node in later calls.
     */
    public function blank(): QualifiedName
    {
        return new QualifiedName(
            new ProvNamespace(self::BLANK_PREFIX, self::BLANK_URI),
            'b' . ++$this->blankNodeCounter,
        );
    }

    protected function markBuilt(): void
    {
        if ($this->built) {
            throw new \LogicException(static::class
            . '::build() was already called. Builders are single-use; create a new instance for a new document.');
        }
        $this->built = true;
    }

    /**
     * Registers a namespace.
     */
    public function addNamespace(ProvNamespace $ns): static
    {
        $this->namespaceManager->add($ns);
        return $this;
    }

    /**
     * Registers a namespace from a prefix and URI.
     */
    public function namespace(string $prefix, string $uri): static
    {
        $this->namespaceManager->add(new ProvNamespace($prefix, $uri));
        return $this;
    }

    /**
     * Sets the default namespace for unprefixed identifiers.
     */
    public function setDefaultNamespace(ProvNamespace $ns): static
    {
        $this->namespaceManager->setDefault($ns);
        return $this;
    }

    // --- Elements ---

    /**
     * Appends an `Entity` record.
     */
    public function entity(
        QualifiedName|string|null $identifier = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Entity(
            identifier: $this->resolveIdentifier($identifier),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Activity` record.
     */
    public function activity(
        QualifiedName|string|null $identifier = null,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Activity(
            identifier: $this->resolveIdentifier($identifier),
            startTime: $startTime,
            endTime: $endTime,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Agent` record.
     */
    public function agent(
        QualifiedName|string|null $identifier = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Agent(
            identifier: $this->resolveIdentifier($identifier),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    // --- Relations ---

    /**
     * Appends a `Generation` relation: an entity was generated by an activity.
     */
    public function wasGeneratedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $entity = null,
        QualifiedName|string|null $activity = null,
        ?\DateTimeImmutable $time = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Generation(
            identifier: $this->resolveIdentifier($identifier),
            entity: $this->resolveIdentifier($entity),
            activity: $this->resolveIdentifier($activity),
            time: $time,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Usage` relation: an activity used an entity.
     */
    public function used(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $activity = null,
        QualifiedName|string|null $entity = null,
        ?\DateTimeImmutable $time = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Usage(
            identifier: $this->resolveIdentifier($identifier),
            activity: $this->resolveIdentifier($activity),
            entity: $this->resolveIdentifier($entity),
            time: $time,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Communication` relation: one activity was informed by another.
     */
    public function wasInformedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $informed = null,
        QualifiedName|string|null $informant = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Communication(
            identifier: $this->resolveIdentifier($identifier),
            informed: $this->resolveIdentifier($informed),
            informant: $this->resolveIdentifier($informant),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Start` relation: an activity was started at a given time.
     */
    public function wasStartedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $activity = null,
        QualifiedName|string|null $trigger = null,
        QualifiedName|string|null $starter = null,
        ?\DateTimeImmutable $time = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Start(
            identifier: $this->resolveIdentifier($identifier),
            activity: $this->resolveIdentifier($activity),
            trigger: $this->resolveIdentifier($trigger),
            starter: $this->resolveIdentifier($starter),
            time: $time,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `End` relation: an activity was ended at a given time.
     */
    public function wasEndedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $activity = null,
        QualifiedName|string|null $trigger = null,
        QualifiedName|string|null $ender = null,
        ?\DateTimeImmutable $time = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new End(
            identifier: $this->resolveIdentifier($identifier),
            activity: $this->resolveIdentifier($activity),
            trigger: $this->resolveIdentifier($trigger),
            ender: $this->resolveIdentifier($ender),
            time: $time,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Invalidation` relation: an entity was invalidated by an activity.
     */
    public function wasInvalidatedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $entity = null,
        QualifiedName|string|null $activity = null,
        ?\DateTimeImmutable $time = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Invalidation(
            identifier: $this->resolveIdentifier($identifier),
            entity: $this->resolveIdentifier($entity),
            activity: $this->resolveIdentifier($activity),
            time: $time,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Derivation` relation: one entity was derived from another.
     *
     * @param \Prov\Identifier\QualifiedName|string|null $generation
     *   Identifier of the Generation record linking $generatedEntity to $activity.
     * @param \Prov\Identifier\QualifiedName|string|null $usage
     *   Identifier of the Usage record linking $activity to $usedEntity.
     */
    public function wasDerivedFrom(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $generatedEntity = null,
        QualifiedName|string|null $usedEntity = null,
        QualifiedName|string|null $activity = null,
        QualifiedName|string|null $generation = null,
        QualifiedName|string|null $usage = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Derivation(
            identifier: $this->resolveIdentifier($identifier),
            generatedEntity: $this->resolveIdentifier($generatedEntity),
            usedEntity: $this->resolveIdentifier($usedEntity),
            activity: $this->resolveIdentifier($activity),
            generation: $this->resolveIdentifier($generation),
            usage: $this->resolveIdentifier($usage),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * PROV-N shortcut for a Derivation typed as `prov:Revision`.
     *
     * @see https://www.w3.org/TR/prov-dm/#term-Revision
     */
    public function wasRevisionOf(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $generatedEntity = null,
        QualifiedName|string|null $usedEntity = null,
        Attributes|array|null $attributes = null,
    ): static {
        return $this->wasDerivedFrom(
            identifier: $identifier,
            generatedEntity: $generatedEntity,
            usedEntity: $usedEntity,
            attributes: $this->injectProvType($attributes, 'Revision'),
        );
    }

    /**
     * PROV-N shortcut for a Derivation typed as `prov:Quotation`.
     *
     * @see https://www.w3.org/TR/prov-dm/#term-Quotation
     */
    public function wasQuotedFrom(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $generatedEntity = null,
        QualifiedName|string|null $usedEntity = null,
        Attributes|array|null $attributes = null,
    ): static {
        return $this->wasDerivedFrom(
            identifier: $identifier,
            generatedEntity: $generatedEntity,
            usedEntity: $usedEntity,
            attributes: $this->injectProvType($attributes, 'Quotation'),
        );
    }

    /**
     * PROV-N shortcut for a Derivation typed as `prov:PrimarySource`.
     *
     * @see https://www.w3.org/TR/prov-dm/#term-PrimarySource
     */
    public function hadPrimarySource(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $generatedEntity = null,
        QualifiedName|string|null $usedEntity = null,
        Attributes|array|null $attributes = null,
    ): static {
        return $this->wasDerivedFrom(
            identifier: $identifier,
            generatedEntity: $generatedEntity,
            usedEntity: $usedEntity,
            attributes: $this->injectProvType($attributes, 'PrimarySource'),
        );
    }

    /**
     * Appends an `Attribution` relation: an entity is credited to an agent.
     */
    public function wasAttributedTo(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $entity = null,
        QualifiedName|string|null $agent = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Attribution(
            identifier: $this->resolveIdentifier($identifier),
            entity: $this->resolveIdentifier($entity),
            agent: $this->resolveIdentifier($agent),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Association` relation: an activity was carried out
     * in association with an agent.
     */
    public function wasAssociatedWith(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $activity = null,
        QualifiedName|string|null $agent = null,
        QualifiedName|string|null $plan = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Association(
            identifier: $this->resolveIdentifier($identifier),
            activity: $this->resolveIdentifier($activity),
            agent: $this->resolveIdentifier($agent),
            plan: $this->resolveIdentifier($plan),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Delegation` relation: one agent acted on behalf of
     * another agent.
     */
    public function actedOnBehalfOf(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $delegate = null,
        QualifiedName|string|null $responsible = null,
        QualifiedName|string|null $activity = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Delegation(
            identifier: $this->resolveIdentifier($identifier),
            delegate: $this->resolveIdentifier($delegate),
            responsible: $this->resolveIdentifier($responsible),
            activity: $this->resolveIdentifier($activity),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Influence` relation: the generic "was influenced by"
     * link, used when no more specific relation fits.
     */
    public function wasInfluencedBy(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $influencee = null,
        QualifiedName|string|null $influencer = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Influence(
            identifier: $this->resolveIdentifier($identifier),
            influencee: $this->resolveIdentifier($influencee),
            influencer: $this->resolveIdentifier($influencer),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Specialization` relation: one entity is a more specific
     * view of another.
     */
    public function specializationOf(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $specificEntity = null,
        QualifiedName|string|null $generalEntity = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Specialization(
            identifier: $this->resolveIdentifier($identifier),
            specificEntity: $this->resolveIdentifier($specificEntity),
            generalEntity: $this->resolveIdentifier($generalEntity),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends an `Alternate` relation: two entities refer to the same
     * real-world thing from different viewpoints.
     */
    public function alternateOf(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $alternate1 = null,
        QualifiedName|string|null $alternate2 = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Alternate(
            identifier: $this->resolveIdentifier($identifier),
            alternate1: $this->resolveIdentifier($alternate1),
            alternate2: $this->resolveIdentifier($alternate2),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Membership` relation: an entity is a member of a
     * collection entity.
     */
    public function hadMember(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $collection = null,
        QualifiedName|string|null $entity = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Membership(
            identifier: $this->resolveIdentifier($identifier),
            collection: $this->resolveIdentifier($collection),
            entity: $this->resolveIdentifier($entity),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `Mention` relation: a cross-bundle reference from a
     * specific entity to the general entity it stands for.
     */
    public function mentionOf(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $specificEntity = null,
        QualifiedName|string|null $generalEntity = null,
        QualifiedName|string|null $bundle = null,
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new Mention(
            identifier: $this->resolveIdentifier($identifier),
            specificEntity: $this->resolveIdentifier($specificEntity),
            generalEntity: $this->resolveIdentifier($generalEntity),
            bundle: $this->resolveIdentifier($bundle),
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    // --- Dictionary extension ---

    /**
     * Appends a `DictionaryMembership` relation: the listed key/entity
     * pairs are members of a dictionary entity.
     *
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $keyEntityPairs
     */
    public function hadDictionaryMember(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $dictionary = null,
        array $keyEntityPairs = [],
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new DictionaryMembership(
            identifier: $this->resolveIdentifier($identifier),
            dictionary: $this->resolveIdentifier($dictionary),
            keyEntityPairs: $keyEntityPairs,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `DictionaryInsertion` relation: one dictionary was
     * derived from another by inserting key/entity pairs.
     *
     * @param \Prov\Identifier\QualifiedName|string|null $after
     *   The resulting dictionary (after insertion).
     * @param \Prov\Identifier\QualifiedName|string|null $before
     *   The source dictionary (before insertion).
     * @param list<\Prov\Relation\Dictionary\DictionaryEntry> $keyEntityPairs
     */
    public function derivedByInsertionFrom(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $after = null,
        QualifiedName|string|null $before = null,
        array $keyEntityPairs = [],
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new DictionaryInsertion(
            identifier: $this->resolveIdentifier($identifier),
            after: $this->resolveIdentifier($after),
            before: $this->resolveIdentifier($before),
            keyEntityPairs: $keyEntityPairs,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    /**
     * Appends a `DictionaryRemoval` relation: one dictionary was
     * derived from another by removing keys.
     *
     * @param \Prov\Identifier\QualifiedName|string|null $after
     *   The resulting dictionary (after removal).
     * @param \Prov\Identifier\QualifiedName|string|null $before
     *   The source dictionary (before removal).
     * @param list<mixed> $removedKeys
     */
    public function derivedByRemovalFrom(
        QualifiedName|string|null $identifier = null,
        QualifiedName|string|null $after = null,
        QualifiedName|string|null $before = null,
        array $removedKeys = [],
        Attributes|array|null $attributes = null,
    ): static {
        $this->records[] = new DictionaryRemoval(
            identifier: $this->resolveIdentifier($identifier),
            after: $this->resolveIdentifier($after),
            before: $this->resolveIdentifier($before),
            removedKeys: $removedKeys,
            attributes: $this->resolveAttributes($attributes),
        );
        return $this;
    }

    // --- Internal helpers ---

    protected function resolveIdentifier(QualifiedName|string|null $id): ?QualifiedName
    {
        if ($id === null || $id instanceof QualifiedName) {
            return $id;
        }

        return $this->namespaceManager->resolve($id);
    }

    /**
     * Adds a `prov:type = prov:<subtype>` entry to `$attrs`, resolving the caller's
     * input (null, array, or Attributes) to an Attributes instance first.
     */
    private function injectProvType(Attributes|array|null $attrs, string $subtype): Attributes
    {
        return $this->resolveAttributes($attrs)->with(
            $this->namespaceManager->resolve('prov:type'),
            $this->namespaceManager->resolve('prov:' . $subtype),
        );
    }

    protected function resolveAttributes(Attributes|array|null $attrs): Attributes
    {
        if ($attrs === null) {
            // Hot-path shared empty: avoids an Attributes::empty() call per record
            // (~40ns saved, measurable across large builds). Kept in sync with
            // Attributes::empty() because both return a plain `new Attributes()`;
            // if Attributes::empty() ever grows state, update this site too.
            /** @var \Prov\Attribute\Attributes|null $empty */
            static $empty = null;
            return $empty ??= new Attributes();
        }

        if ($attrs instanceof Attributes) {
            return $attrs;
        }

        /** @var array<string, \Prov\Attribute\Literal|\Prov\Identifier\QualifiedName|scalar> $attrs */
        $data = [];
        foreach ($attrs as $key => $value) {
            $resolvedKey = $this->namespaceManager->resolve($key);
            $data[$resolvedKey->getUri()][] = $value;
        }

        return new Attributes($data);
    }
}
