<?php

declare(strict_types=1);

namespace OERManager\Test\Support;

use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;
use Omeka\Api\Response;

/** Test fixtures keep actual service logic and isolate the Omeka API boundary. */
trait RepresentationFactory
{
    private function item(int $id, string $title = '', array $values = []): ItemRepresentation
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('id')->willReturn($id);
        $item->method('displayTitle')->willReturn($title);
        $item->method('value')->willReturnCallback(static function ($term, $options = []) use ($values) {
            $result = $values[$term] ?? [];
            return !empty($options['all']) ? $result : ($result[0] ?? ($options['default'] ?? null));
        });
        $item->method('values')->willReturn(array_map(static fn ($v) => ['values' => $v], $values));
        return $item;
    }

    private function value(
        string $literal = '',
        $resource = null,
        string $type = 'literal',
        string $uri = ''
    ): ValueRepresentation {
        $value = $this->createMock(ValueRepresentation::class);
        $value->method('value')->willReturn($literal);
        $value->method('__toString')->willReturn($literal);
        $value->method('type')->willReturn($type);
        $value->method('uri')->willReturn($uri);
        $value->method('valueResource')->willReturn($resource);
        return $value;
    }

    private function response($content, int $total = 0): Response
    {
        $response = $this->createMock(Response::class);
        $response->method('getContent')->willReturn($content);
        $response->method('getTotalResults')->willReturn($total);
        return $response;
    }
}
