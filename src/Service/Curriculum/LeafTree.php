<?php

namespace OERManager\Service\Curriculum;

/**
 * Nests curriculum rows by their `skos:broader` parent (TASK-064).
 *
 * Knowledge hangs under a knowledge block at depth 1 to 3 (1.1.2). The input
 * is a flat list of one course subject's rows; a row whose parent is not in
 * the list is a root (a block, or a node whose parent was filtered out).
 * Siblings are ordered by natural notation. Bad data cannot loop: a node is
 * placed once, and any node left unplaced by a cycle becomes a root.
 */
final class LeafTree
{
    /**
     * @param list<array{id:int,parentId:int,notation:string}&array<string,mixed>> $nodes
     * @return list<array<string,mixed>> the rows with a 'children' list each
     */
    public static function build(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            $byId[(int) $node['id']] = $node;
        }
        $children = [];
        $roots = [];
        foreach ($byId as $id => $node) {
            $parentId = (int) $node['parentId'];
            if ($parentId !== $id && isset($byId[$parentId])) {
                $children[$parentId][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $placed = [];
        $tree = self::level($roots, $byId, $children, $placed);
        $unplaced = array_values(array_diff(array_keys($byId), array_keys($placed)));
        while ($unplaced) {
            // A cycle: break it at its lowest notation and keep its members.
            $sorted = self::sort($unplaced, $byId);
            $tree = array_merge($tree, self::level([$sorted[0]], $byId, $children, $placed));
            $unplaced = array_values(array_diff($unplaced, array_keys($placed)));
        }
        return $tree;
    }

    /**
     * @param list<int> $ids
     * @param array<int,array<string,mixed>> $byId
     * @param array<int,list<int>> $children
     * @param array<int,true> $placed
     * @return list<array<string,mixed>>
     */
    private static function level(array $ids, array $byId, array $children, array &$placed): array
    {
        $level = [];
        foreach (self::sort($ids, $byId) as $id) {
            if (isset($placed[$id])) {
                continue;
            }
            $placed[$id] = true;
            $node = $byId[$id];
            $node['children'] = self::level($children[$id] ?? [], $byId, $children, $placed);
            $level[] = $node;
        }
        return $level;
    }

    /**
     * @param list<int> $ids
     * @param array<int,array<string,mixed>> $byId
     * @return list<int>
     */
    private static function sort(array $ids, array $byId): array
    {
        usort($ids, static fn (int $a, int $b): int => strnatcasecmp(
            (string) $byId[$a]['notation'],
            (string) $byId[$b]['notation']
        ) ?: $a <=> $b);
        return $ids;
    }
}
