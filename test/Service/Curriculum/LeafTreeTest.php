<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\LeafTree;
use PHPUnit\Framework\TestCase;

/** TASK-064: knowledge nested by skos:broader under its block, in notation order. */
final class LeafTreeTest extends TestCase
{
    private static function node(int $id, int $parentId, string $notation): array
    {
        return ['id' => $id, 'parentId' => $parentId, 'notation' => $notation];
    }

    public function testNestsByParentAndSortsSiblingsByNaturalNotation(): void
    {
        $tree = LeafTree::build([
            self::node(3, 1, 'SBI.10'),
            self::node(2, 1, 'SBI.2'),
            self::node(1, 99, 'SBI'),
            self::node(4, 2, 'SBI.2.1'),
            self::node(5, 98, 'SBII'),
        ]);
        $this->assertSame([1, 5], array_column($tree, 'id'));
        $this->assertSame([2, 3], array_column($tree[0]['children'], 'id'));
        $this->assertSame([4], array_column($tree[0]['children'][0]['children'], 'id'));
        $this->assertSame([], $tree[1]['children']);
        $this->assertSame('SBI.2.1', $tree[0]['children'][0]['children'][0]['notation']);
    }

    public function testCyclesAndSelfParentsNeverLoopAndKeepEveryNode(): void
    {
        $tree = LeafTree::build([
            self::node(1, 2, 'A'),
            self::node(2, 1, 'B'),
            self::node(3, 3, 'C'),
            self::node(4, 0, 'D'),
        ]);
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                $ids[] = $node['id'];
                $walk($node['children']);
            }
        };
        $walk($tree);
        sort($ids);
        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testEmptyInputIsAnEmptyTree(): void
    {
        $this->assertSame([], LeafTree::build([]));
    }
}
