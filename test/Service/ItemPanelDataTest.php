<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ItemPanelData;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Representation\MediaRepresentation;
use PHPUnit\Framework\TestCase;

final class ItemPanelDataTest extends TestCase
{
    use RepresentationFactory;

    public function testPanelIncludesPrivateIdentityMetadataGroupedAlignmentAndNativeMedia(): void
    {
        $course = $this->item(2, 'Course');
        $subject = $this->item(3, 'Math', ['lrmi:educationalAlignment' => [$this->value(
            '',
            $course,
            'resource:item'
        )]]);
        $axis = $this->item(4, 'Axis');
        $item = $this->item(1, 'Private resource', [
            'dcterms:description' => [$this->value('Description'), $this->value('')],
            'schema:isPartOf' => [$this->value('', $axis, 'resource:item')],
            'lrmi:educationalLevel' => [$this->value('', $course, 'resource:item')],
            'schema:about' => [$this->value('', $subject, 'resource:item'), $this->value('legacy')],
            'lrmi:teaches' => [$this->value('', $this->item(5, 'Unassigned'), 'resource:item')],
            'dcterms:relation' => [$this->value('', $axis, 'resource:item')],
        ]);
        $item->method('thumbnailDisplayUrls')->willReturn(['square' => '/thumb.jpg']);
        $item->method('url')->with('edit')->willReturn('/admin/item/1/edit');
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('displayTitle')->willReturn('File');
        $media->method('mediaType')->willReturn('image/jpeg');
        $media->method('size')->willReturn(123);
        $media->method('originalUrl')->willReturn('/original.jpg');
        $media->expects($this->once())->method('render')->with(['thumbnailType' => 'medium',
            'link' => 'original'])->willReturn('<img>');
        $item->method('media')->willReturn([$media]);
        $result = (new ItemPanelData())->forItem($item);
        $this->assertSame('Private resource', $result['identity']['title']);
        $this->assertFalse($result['identity']['isPublic']);
        $this->assertSame('/thumb.jpg', $result['identity']['thumbnail']);
        $this->assertSame('Description', $result['record']['dcterms:description']);
        $this->assertSame('Axis', $result['record']['schema:isPartOf']);
        $this->assertSame('<img>', $result['media'][0]['renderedHtml']);
        $this->assertStringContainsString('Math', json_encode($result['alignment']));
        $this->assertStringContainsString('Unassigned', json_encode($result['alignment']));
    }
}
