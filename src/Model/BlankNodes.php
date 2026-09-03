<?php

declare(strict_types=1);

namespace Prov\Model;

use Prov\Identifier\QualifiedName;
use Prov\Relation\Dictionary\DictionaryEntry;

/**
 * Finds the blank-node references a record holds.
 *
 * Blank labels are container-scoped names, so anything that composes or
 * compares containers has to see every position a label can occur in: a
 * record identifier, a relation's formal endpoints, a dictionary entry's
 * entity or key, a removed dictionary key, and a qualified-name attribute
 * value. Missing one position lets two independent anonymous records collapse
 * into one. This is the single walk the builder, the label minter, the
 * document operations, and the comparator all use.
 *
 * @internal
 */
final class BlankNodes
{
    /**
     * The role of a record's own blank identifier. Every other role is a
     * reference to some record.
     */
    public const string ID_ROLE = 'id';

    /**
     * Every blank-node reference in a record, as `role`/`name` pairs.
     *
     * The role names the position the reference sits in: `id` for the record's
     * own identifier, the formal property name for a relation endpoint,
     * `<property>.entity` and `<property>.key` for the parts of a dictionary
     * entry, and `attr:<key URI>` for an attribute value. Roles are
     * order-independent, so record and attribute ordering cannot influence a
     * caller that derives names from them.
     *
     * @return list<array{role: string, name: \Prov\Identifier\QualifiedName}>
     */
    public static function occurrences(ProvRecord $record): array
    {
        $out = [];

        $id = $record->identifier;
        if ($id !== null && $id->isBlank()) {
            $out[] = ['role' => self::ID_ROLE, 'name' => $id];
        }

        if ($record instanceof ProvRelation) {
            // @mago-expect analysis:mixed-assignment
            foreach (RelationMetadata::extractFormals($record) as $prop => $value) {
                if ($value instanceof QualifiedName) {
                    if ($value->isBlank()) {
                        $out[] = ['role' => $prop, 'name' => $value];
                    }
                } elseif (is_array($value)) {
                    // @mago-expect analysis:mixed-assignment
                    foreach ($value as $item) {
                        // A key-entity pair holds two positions; a removed key
                        // is the key on its own.
                        // @mago-expect analysis:mixed-assignment
                        $key = $item;
                        if ($item instanceof DictionaryEntry) {
                            if ($item->entity !== null && $item->entity->isBlank()) {
                                $out[] = ['role' => $prop . '.entity', 'name' => $item->entity];
                            }
                            $key = $item->key;
                        }
                        if ($key instanceof QualifiedName && $key->isBlank()) {
                            $out[] = ['role' => $prop . '.key', 'name' => $key];
                        }
                    }
                }
            }
        }

        foreach ($record->attributes->all() as $keyUri => $values) {
            foreach ($values as $value) {
                if ($value instanceof QualifiedName && $value->isBlank()) {
                    $out[] = ['role' => 'attr:' . $keyUri, 'name' => $value];
                }
            }
        }

        return $out;
    }

    /**
     * The set of blank-node label URIs (`_:b1`, ...) the records use, in any
     * position.
     *
     * @param iterable<\Prov\Model\ProvRecord> $records
     *
     * @return array<string, true>
     */
    public static function labels(iterable $records): array
    {
        $labels = [];
        foreach ($records as $record) {
            foreach (self::occurrences($record) as $occurrence) {
                $labels[$occurrence['name']->getUri()] = true;
            }
        }
        return $labels;
    }
}
