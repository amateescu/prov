<?php

declare(strict_types=1);

namespace Prov\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Prov\Model\ProvRelation;
use Prov\Model\RelationMetadata;
use Prov\Serializer\ProvNSerializer;
use Prov\Serializer\XmlSerializer;

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

        /** @var array<string, string> $recordDispatch */
        $recordDispatch = new \ReflectionClass(ProvNSerializer::class)->getConstant('RECORD_DISPATCH');
        /** @var array<string, array<string, string>> $xmlChildren */
        $xmlChildren = new \ReflectionClass(XmlSerializer::class)->getConstant('FORMAL_CHILD_ELEMENTS');

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
                $recordDispatch,
                "{$relation} missing from ProvNSerializer::RECORD_DISPATCH",
            );

            $jsonKey = RelationMetadata::JSON_KEYS[$relation];
            $this->assertArrayHasKey(
                $jsonKey,
                $xmlChildren,
                "'{$jsonKey}' missing from XmlSerializer::FORMAL_CHILD_ELEMENTS",
            );
        }
    }

    public function testMetadataTablesCoverExactlyTheRelationClasses(): void
    {
        $relations = self::relationClasses();
        $this->assertSame($relations, $this->sortedKeys(RelationMetadata::FORMALS));
        $this->assertSame($relations, $this->sortedKeys(RelationMetadata::JSON_KEYS));
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
