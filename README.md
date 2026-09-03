# prov: W3C Provenance for PHP

[![Release](https://img.shields.io/packagist/v/amateescu/prov.svg)](https://packagist.org/packages/amateescu/prov)
[![CI](https://github.com/amateescu/prov/actions/workflows/ci.yml/badge.svg)](https://github.com/amateescu/prov/actions/workflows/ci.yml)

PHP implementation of the [W3C Provenance Data Model (PROV-DM)](https://www.w3.org/TR/prov-dm/).

PROV-DM describes where things come from: **entities** (things you care about), **activities** (things that happen), and **agents** (who's responsible). Relations like `wasGeneratedBy` and `wasAttributedTo` connect them to form a provenance graph.

PROV-DM fits data lineage, audit trails, scientific-workflow provenance, attribution graphs, and any case where you need to record where information came from.

This library provides a fluent builder for assembling that graph, round-trip serializers for [PROV-JSON](https://www.w3.org/Submission/prov-json/), [PROV-N](https://www.w3.org/TR/prov-n/), and [PROV-XML](https://www.w3.org/TR/prov-xml/) (plus serialize-only [PROV-JSONLD](https://www.w3.org/Submission/prov-jsonld/)), document operations (`merge`, `flatten`, semantic equality), and a partial [PROV-CONSTRAINTS](https://www.w3.org/TR/prov-constraints/) validator.

## Requirements

- PHP 8.4+
- `ext-dom` (only if you use `XmlSerializer`)

## Installation

```
composer require amateescu/prov
```

## Quick start

```php
use Prov\Format;
use Prov\Prov;

$builder = Prov::documentBuilder();
$builder->namespace('ex', 'http://example.org/');
$builder->entity('ex:article');
$builder->activity('ex:writing', startTime: new DateTimeImmutable('2024-01-15'));
$builder->agent('ex:alice');
$builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:writing');
$builder->wasAssociatedWith(activity: 'ex:writing', agent: 'ex:alice');

$doc = $builder->build();

$json = Prov::serialize($doc, Format::Json);
echo $json;
// Other formats: Format::ProvN, Format::Xml, Format::JsonLd.

$parsed = Prov::deserialize($json, Format::Json);
```

The static `Prov::` calls are a convenience facade. Under a dependency-injection container, construct the underlying classes directly: `DocumentBuilder`, the per-format serializers (`Format::Json->createSerializer()` / `createDeserializer()`), and `ConstraintValidator`.

> **Always pass relation arguments by name.** PROV-DM fixes a per-relation positional order that does *not* follow subject-before-object. `wasGeneratedBy` takes `(entity, activity)` but `used` takes `(activity, entity)`: the two sit in opposite orders even though they connect the same two records. Positional calls silently invert the relation:
>
> ```php
> // These two lines describe DIFFERENT facts, even though both identifiers are the same:
> $builder->wasGeneratedBy('ex:article', 'ex:writing'); // article wasGeneratedBy writing ✓
> $builder->used('ex:article', 'ex:writing');           // article used writing ✗ (reversed)
>
> // Always use named arguments:
> $builder->wasGeneratedBy(entity: 'ex:article', activity: 'ex:writing');
> $builder->used(activity: 'ex:writing', entity: 'ex:article');
> ```
>
> The optional relation identifier is the *last* parameter of every relation method, so a positional call binds endpoints, never the id. Pass it by name: `wasGeneratedBy(entity: ..., activity: ..., identifier: 'ex:gen1')`.

## Format support

| Format | Serialize | Deserialize |
| --- | --- | --- |
| PROV-JSON | yes | yes |
| PROV-N | yes | yes |
| PROV-XML | yes | yes |
| PROV-JSONLD | yes | no (would require an RDF-aware parser) |

### Output ordering

PROV serializations are unordered (a document is a set of records, namespaces a set of declarations), so ordering never affects meaning. For stable, readable output every serializer always sorts namespace declarations: the `prov`/`xsd` built-ins first, then the rest alphabetically by prefix. Records keep the order you added them by default; pass `sortRecords: true` to a serializer to order them into PROV-DM concept order instead (elements first, then relations in component order, each group sorted by identifier):

```php
$json = new JsonSerializer(sortRecords: true)->serialize($doc);
```

### PROV-N notes

The PROV-N parser accepts two convenience extensions beyond the published grammar, so input that parses here is not necessarily canonical PROV-N: line (`//`) and block (`/* */`) comments, and optional commas between a relation's arguments. Output always uses the canonical form.

PROV-N has no slot for an explicit identifier on `specializationOf`, `alternateOf`, `hadMember`, or `mentionOf`, and `hadDictionaryMember` has room for neither an identifier nor attributes. When a document carries one of these relations *with* an identifier (legal in PROV-JSON/PROV-XML), the PROV-N serializer drops the identifier, since the grammar cannot express it. `DocumentComparator::equals()` will flag the difference on a JSON-to-PROV-N-to-JSON round trip; keep such relations in PROV-JSON or PROV-XML if their identifiers matter.

The `prov` and `xsd` prefixes are implicit in PROV-N and the grammar forbids redeclaring them, so the serializer never writes those declarations and refuses a document that binds either prefix to another namespace. Rename the prefix first. Every other prefix is checked against the `PN_PREFIX` production before it is written, Unicode included, so a prefix that starts with a digit or ends in a dot is refused rather than emitted as unparseable text.

### PROV-JSONLD notes

PROV-O models `specializationOf`, `alternateOf`, `hadMember`, and `mentionOf` as plain object properties, and PROV-Dictionary does the same for `hadDictionaryMember`. A statement in one of those forms is a single triple on the subject node, with no node of its own, so there is nowhere to write a relation identifier or extra attributes. Serializing a document whose record carries either throws `Prov\Exception\ProvException` rather than writing the triple without them. Every other relation has a qualified form (`prov:qualifiedGeneration`, `prov:qualifiedInsertion`, ...) that carries both.

The whole document shares one `@context`. Bundle declarations are promoted into it when their prefix is free there; a bundle prefix that rebinds a document prefix cannot be, and names under it are written with a minted prefix instead, so every compact IRI expands to the URI the model carries.

## Document operations

```php
use Prov\Operation\DocumentOperations;
use Prov\Operation\DocumentComparator;

$merged = DocumentOperations::merge($docA, $docB);
$flat = DocumentOperations::flatten($docWithBundles);            // throws if Mentions present
$flat = DocumentOperations::flattenDroppingMentions($docWithBundles);

DocumentComparator::equals($a, $b);  // structural (semantic) equality
```

## Querying a document

`ProvGraph` indexes a document (or bundle) once and answers edge queries by identifier, accepting `QualifiedName` objects, `prefix:local` shorthands, or full URIs:

```php
use Prov\Operation\ProvGraph;

$graph = new ProvGraph($document);

$graph->relationsFrom('ex:article');       // relations whose subject is ex:article
$graph->relationsTo('ex:writing');         // relations whose object is ex:writing
$graph->relationsReferencing('ex:plan');   // any endpoint, including secondary ones
$graph->generationsOf('ex:article');       // Generation records of an entity
$graph->usagesOf('ex:draft');              // Usage records of an entity
$graph->recordByIdentifier('ex:article');  // O(1) record lookup
$graph->agentsOf('ex:writing');            // agents associated with an activity

ProvGraph::referencedIdentifiers($relation);  // every endpoint of one relation
```

`agentsOf()` returns one `AgentInvolvement` per association, each carrying the agent, the plan the association named, the association's attributes (so `prov:role` survives), and `onBehalfOf`: the chain of agents this one acted on behalf of, walked from the `actedOnBehalfOf` delegations (nearest responsible first, activity-scoped delegations honored, cycles guarded). It reports identifiers and structure only; read an agent's `prov:type` with `recordByIdentifier()` to classify it.

The graph covers the container's own records; flatten a document first to query across bundle boundaries. For type-centric queries (`all Usage records`), `Document::getRecordsByType()` remains the right tool.

## Validation

```php
$result = Prov::validate($document);

if (!$result->isValid()) {
    foreach ($result->getViolations() as $violation) {
        echo "[C{$violation->constraintId}] {$violation->message}\n";
    }
}

// Or throw if the document has any violations:
Prov::validate($document)->throwIfInvalid();  // raises ConstraintViolationException
```

Coverage is partial: rules that need transitive graph reasoning over derivation chains aren't implemented, so `isValid() === true` only means no checked rule was violated. Use `ConstraintValidator::implementedConstraints()` or `::unsupportedConstraints()` to see the exact set.

## Builder tips

**Namespaces.** Register namespaces one at a time (`namespace()`, `addNamespace()`) or in bulk from an application-wide registry (`addNamespaces($iterable)`); `DocumentBuilder` also accepts an iterable to preload at construction. Re-registering a prefix with a different URI throws, including the `prov`/`xsd` built-ins, so a typo cannot silently corrupt a binding. `build()` prunes the declarations down to the namespaces your records actually reference, so registering many namespaces up front does not bloat the serialized output; call `keepUnusedNamespaces()` to keep them all. Documents obtained from `Prov::deserialize()` are not affected: they keep every namespace they declared.

**Attributes.** Pass attributes as an associative array: keys are resolved as namespace shorthands, and a list value adds one entry per element (that is how a repeated key is written, since PHP array keys are unique):

```php
$builder->entity('ex:e1', [
    'prov:label' => 'My entity',
    'prov:atLocation' => ['ex:rack1', 'ex:rack2'],  // two prov:atLocation values
]);
```

String values stay string literals, with one exception: a `prov:type` value written as a registered shorthand (`'prov:type' => 'ex:Document'`) resolves to a qualified name, because `prov:type` values name types rather than carry text. For every other key, a string like `'workspace:stage'` is stored verbatim; pass a `QualifiedName` object when you mean a reference. `Prov\Attribute\AttributesBuilder` offers the same rules imperatively, useful when attributes accumulate across code paths:

```php
$attrs = new AttributesBuilder($namespaceManager)
    ->add('prov:type', 'ex:Document')
    ->addAll('prov:atLocation', $locations)
    ->build();
```

Two `Attributes` bags combine with `$a->merge($b)`: a multimap union that keeps all values under a shared key (the way to promote a single value to several).

**Blank nodes (anonymous records):**

```php
$e = $builder->blank();          // _:b1, auto-minted
$builder->entity($e);
$builder->wasGeneratedBy(entity: $e, activity: 'ex:writing');
```

Use `QualifiedName::blankNode('b1')` instead when you control the label.

**Bundles.** `withBundle()` is the recommended form: it builds the bundle eagerly, inline, without breaking the fluent chain:

```php
$builder
    ->entity('ex:e1')
    ->withBundle('ex:b1', fn ($b) => $b
        ->entity('ex:e2')
        ->wasGeneratedBy(entity: 'ex:e2', activity: 'ex:a1'))
    ->build();
```

Two alternatives exist for other flows: `bundle()` returns a detached `BundleBuilder` that you drive directly and that is built lazily when the document's `build()` runs, and `addBundle()` attaches an already-built `Bundle` (for example one obtained by deserializing).

`DocumentBuilder::build()` and `BundleBuilder::build()` are single-use; a second call throws `LogicException`.

## Learn more

Every public class carries an inline docblock explaining what it's for. The most useful starting points:

- `Prov\Prov`: the facade used in the examples above
- `Prov\Builder\DocumentBuilder`: the full set of record and relation methods
- `Prov\Format`: supported serialization formats
- `Prov\Constraint\ConstraintValidator`: what each PROV-CONSTRAINTS rule checks

## Development

Before submitting a PR, run `composer check` (format, lint, analyze, tests).

## See also

- [`trungdong/prov`](https://github.com/trungdong/prov): Python implementation of PROV-DM.
- [`lucmoreau/ProvToolbox`](https://github.com/lucmoreau/ProvToolbox): Java toolkit for PROV.

## License

This library is made available under the MIT License. Please see [LICENSE](LICENSE) for more information.
