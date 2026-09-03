<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Serializer\ProvNSerializer;

/**
 * Guards the relation set against drifting out of sync across the metadata registry and
 * the serializer dispatch tables. Adding a relation class without wiring it into every
 * table fails here, rather than silently shipping a serializer that drops the relation.
 */
final class RelationDispatchConsistencyTest extends TestCase
{
    /**
     * Every concrete relation class on disk, discovered so a newly added one is covered
     * automatically.
     *
     * @return list<class-string<\Prov\Model\ProvRelation>>
     */
    private static function relationClasses(): array
    {
        $files = array_merge(
            glob(__DIR__ . '/../../src/Relation/*.php') ?: [],
            glob(__DIR__ . '/../../src/Relation/Dictionary/*.php') ?: [],
        );

        $classes = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $fqcn = str_contains($file, '/Dictionary/')
                ? 'Prov\\Relation\\Dictionary\\' . $name
                : 'Prov\\Relation\\' . $name;
            if (class_exists($fqcn) && is_subclass_of($fqcn, ProvRelation::class)) {
                $classes[] = $fqcn;
            }
        }

        sort($classes);
        return $classes;
    }

    public function testEveryRelationClassIsWiredIntoAllDispatchTables(): void
    {
        $relations = self::relationClasses();
        // Guard against a broken discovery path making the assertions vacuous.
        $this->assertNotEmpty($relations);

        $xmlChildren = RelationMetadata::xmlChildElements();

        foreach ($relations as $relation) {
            $this->assertArrayHasKey(
                $relation,
                RelationMetadata::FORMALS,
                "{$relation} missing from RelationMetadata::FORMALS",
            );
            $this->assertArrayHasKey(
                $relation,
                RelationMetadata::JSON_KEYS,
                "{$relation} missing from RelationMetadata::JSON_KEYS",
            );

            $this->assertArrayHasKey(
                $relation,
                RelationMetadata::JSONLD,
                "{$relation} missing from RelationMetadata::JSONLD",
            );

            $jsonKey = RelationMetadata::JSON_KEYS[$relation];
            $this->assertArrayHasKey(
                $jsonKey,
                $xmlChildren,
                "'{$jsonKey}' missing from RelationMetadata::xmlChildElements()",
            );
        }
    }

    public function testMetadataTablesCoverExactlyTheRelationClasses(): void
    {
        $relations = self::relationClasses();
        $this->assertSame($relations, $this->sortedKeys(RelationMetadata::FORMALS));
        $this->assertSame($relations, $this->sortedKeys(RelationMetadata::JSON_KEYS));
        $this->assertSame($relations, $this->sortedKeys(RelationMetadata::JSONLD));
    }

    public function testJsonLdPropertiesReferenceRealFormals(): void
    {
        foreach (RelationMetadata::JSONLD as $class => $spec) {
            foreach (array_keys($spec['properties']) as $prop) {
                $this->assertArrayHasKey(
                    $prop,
                    RelationMetadata::FORMALS[$class],
                    "JSONLD property '{$prop}' is not a formal of {$class}",
                );
            }
        }
    }

    public function testXmlOverridesReferenceRealFormals(): void
    {
        foreach (RelationMetadata::XML_FORMAL_OVERRIDES as $class => $overrides) {
            foreach (array_keys($overrides) as $prop) {
                $this->assertArrayHasKey(
                    $prop,
                    RelationMetadata::FORMALS[$class],
                    "XML override '{$prop}' is not a formal of {$class}",
                );
            }
        }
    }

    public function testProvNNoIdTableReferencesRealRelations(): void
    {
        /** @var array<class-string, true> $withoutId */
        $withoutId = new \ReflectionClass(ProvNSerializer::class)->getConstant('RELATIONS_WITHOUT_ID');
        $this->assertNotEmpty($withoutId);
        foreach (array_keys($withoutId) as $class) {
            $this->assertArrayHasKey(
                $class,
                RelationMetadata::FORMALS,
                "ProvNSerializer::RELATIONS_WITHOUT_ID references unknown relation {$class}",
            );
        }
    }

    /**
     * @param array<string, mixed> $table
     *
     * @return list<string>
     */
    private function sortedKeys(array $table): array
    {
        $keys = array_keys($table);
        sort($keys);
        return $keys;
    }
}
