<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\OmekaMediaSource;
use Omeka\Api\Manager;
use Omeka\Api\Response;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\Local;
use Omeka\File\Store\StoreInterface;
use PHPUnit\Framework\TestCase;

final class OmekaMediaSourceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'oer-media-');
        file_put_contents($this->path, 'local content');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testFilesFilterUnsupportedMissingAndUnnamedMedia(): void
    {
        $source = $this->source([
            $this->media('', 'txt'),
            $this->media('video.mp4', 'mp4'),
            $this->media('missing.txt', 'txt'),
            $this->media('doc.txt', 'TXT', 'text/plain', 'original.txt'),
            $this->media('fallback.json', 'json', 'application/json'),
        ]);
        self::assertSame([
            ['path' => $this->path, 'mediaType' => 'text/plain', 'name' => 'original.txt', 'size' => 13],
            ['path' => $this->path, 'mediaType' => 'application/json', 'name' => 'fallback.json', 'size' => 13],
        ], $source->filesFor(12));
    }

    public function testImagesUseTheirOwnWhitelistAndSourceFallback(): void
    {
        $source = $this->source([
            $this->media('', 'jpg'),
            $this->media('doc.txt', 'txt'),
            $this->media('missing.txt', 'png'),
            $this->media('photo.jpg', 'JPG', 'image/jpeg', 'Photo'),
            $this->media('photo.png', 'png', 'image/png'),
        ]);
        self::assertSame([
            ['path' => $this->path, 'mediaType' => 'image/jpeg', 'name' => 'Photo', 'size' => 13],
            ['path' => $this->path, 'mediaType' => 'image/png', 'name' => 'photo.png', 'size' => 13],
        ], $source->imagesFor(12));
    }

    public function testRemoteStorageDoesNotProvideFilesOrDownloadImages(): void
    {
        $source = $this->source([
            $this->media('doc.pdf', 'pdf'),
            $this->media('photo.jpg', 'jpg'),
        ], $this->createMock(StoreInterface::class));
        self::assertSame([], $source->filesFor(12));
        self::assertSame([], $source->imagesFor(12));
    }

    public function testUnavailableItemReturnsNoMedia(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('read')->willThrowException(new \RuntimeException('Not found'));
        $source = new OmekaMediaSource($api, $this->createMock(StoreInterface::class));
        self::assertSame([], $source->filesFor(99));
        self::assertSame([], $source->imagesFor(99));
    }

    private function source(array $media, ?StoreInterface $store = null): OmekaMediaSource
    {
        $item = $this->createMock(ItemRepresentation::class);
        $item->method('media')->willReturn($media);
        $api = $this->createMock(Manager::class);
        $api->method('read')->with('items', 12)->willReturn(new Response($item));
        if ($store === null) {
            $store = $this->createMock(Local::class);
            $store->method('getLocalPath')->willReturnCallback(
                fn ($path) => $path === 'original/missing.txt' ? $this->path . '-missing' : $this->path
            );
        }
        return new OmekaMediaSource($api, $store);
    }

    private function media(
        string $filename,
        string $extension,
        string $type = '',
        string $source = ''
    ): MediaRepresentation {
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('filename')->willReturn($filename);
        $media->method('extension')->willReturn($extension);
        $media->method('mediaType')->willReturn($type);
        $media->method('source')->willReturn($source);
        return $media;
    }
}
