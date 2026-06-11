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

## Document operations

```php
use Prov\Operation\DocumentOperations;
use Prov\Operation\DocumentComparator;

$merged = DocumentOperations::merge($docA, $docB);
$flat = DocumentOperations::flatten($docWithBundles);            // throws if Mentions present
$flat = DocumentOperations::flattenDroppingMentions($docWithBundles);

DocumentComparator::equals($a, $b);  // structural (semantic) equality
```

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

**Namespaces.** Register namespaces one at a time (`namespace()`, `addNamespace()`) or in bulk from an application-wide registry (`addNamespaces($iterable)`). `build()` prunes the declarations down to the namespaces your records actually reference, so registering many namespaces up front does not bloat the serialized output; call `keepUnusedNamespaces()` to keep them all. Documents obtained from `Prov::deserialize()` are not affected: they keep every namespace they declared.

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

**Blank nodes (anonymous records):**

```php
$e = $builder->blank();          // _:b1
$builder->entity($e);
$builder->wasGeneratedBy(entity: $e, activity: 'ex:writing');
```

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
