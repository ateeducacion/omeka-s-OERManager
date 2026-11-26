<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\DuplicateFinder;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Response;
use PHPUnit\Framework\TestCase;

final class DuplicateFinderTest extends TestCase
{
    public function testItFindsOtherReaByTitleAndByMediaNameThroughTheApi(): void
    {
        $api = $this->createMock(ApiManager::class);
        $api->expects($this->once())->method('search')->with('items', $this->callback(function (array $q) {
            return 'dcterms:title' === $q['property'][0]['property'] && 'eq' === $q['property'][0]['type']
                && 'Fracciones' === $q['property'][0]['text'] && 5 === $q['per_page'] && 9 === $q['resource_class_id'];
        }))->willReturn(new Response([$this->item(4, 'Fracciones'), $this->item(5, 'Fracciones')]));
        $api->method('read')->willReturnCallback(function (string $resource, int $id) {
            if (66 === $id) {
                throw new \Omeka\Api\Exception\NotFoundException('private');
            }
            return new Response($this->item($id, 'Otro ' . $id));
        });
        $finder = new DuplicateFinder($api, static fn (string $name) => 'clase.elpx' === $name ? [7, 5, 66] : [], 9);
        $this->assertSame([
            ['id' => 4, 'title' => 'Fracciones', 'reason' => 'title'],
            ['id' => 7, 'title' => 'Otro 7', 'reason' => 'name'],
        ], $finder->find(' Fracciones ', 'clase.elpx', 5));
    }

    public function testNothingIsSearchedWithoutTitleOrName(): void
    {
        $api = $this->createMock(ApiManager::class);
        $api->expects($this->never())->method('search');
        $this->assertSame([], (new DuplicateFinder($api, static fn () => [], 9))->find('  ', '', null));
    }

    private function item(int $id, string $title): object
    {
        return new class ($id, $title) {
            public function __construct(private int $id, private string $title)
            {
            }
            public function id()
            {
                return $this->id;
            }
            public function displayTitle()
            {
                return $this->title;
            }
        };
    }
}
