<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Content;

use OERManager\Service\Content\ImagickPdfRasterizer;
use OERManager\Test\Support\ImagickColor;
use OERManager\Test\Support\ImagickEngine;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** Tests orchestration against the engine contract, not native PDF decoding. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ImagickEngineContractTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        if (extension_loaded('imagick')) {
            self::markTestSkipped('Contract double requires a process without native Imagick.');
        }
        require_once dirname(__DIR__, 2) . '/Support/ImagickEngine.php';
        class_alias(ImagickEngine::class, 'Imagick');
        class_alias(ImagickColor::class, 'ImagickPixel');
        $this->path = tempnam(sys_get_temp_dir(), 'oer-pdf-');
        file_put_contents($this->path, 'engine contract fixture');
    }

    protected function tearDown(): void
    {
        if (isset($this->path)) {
            unlink($this->path);
        }
    }

    public function testOnlyRequestedPagesAreRenderedWithLabelsAndByteCounts(): void
    {
        $pages = (new ImagickPdfRasterizer(maxPages: 2))->rasterize($this->path, 'Lesson');
        self::assertSame([
            ['data' => 'jpeg', 'mediaType' => 'image/jpeg', 'name' => 'Lesson (p. 1)', 'size' => 4],
            ['data' => 'jpeg', 'mediaType' => 'image/jpeg', 'name' => 'Lesson (p. 2)', 'size' => 4],
        ], $pages);
        self::assertSame([[0, 150, 80], [1, 150, 80]], ImagickEngine::$renders);
    }

    public function testOversizedPageRetriesOnceAndOversizedRetryIsDiscarded(): void
    {
        ImagickEngine::$blobs = [
            0 => [150 => 'too large', 75 => 'small'],
            1 => [150 => 'too large', 75 => 'still large'],
        ];
        $pages = (new ImagickPdfRasterizer(maxPages: 2, maxPageBytes: 5))->rasterize($this->path, 'Lesson');
        self::assertCount(1, $pages);
        self::assertSame('small', $pages[0]['data']);
        self::assertSame([[0, 150, 80], [0, 75, 70], [1, 150, 80], [1, 75, 70]], ImagickEngine::$renders);
    }

    public function testFailedAndEmptyPagesDoNotPreventLaterPages(): void
    {
        ImagickEngine::$blobs = [0 => [150 => new \RuntimeException('Engine failure')], 1 => [150 => '']];
        $pages = (new ImagickPdfRasterizer())->rasterize($this->path, 'Lesson');
        self::assertCount(1, $pages);
        self::assertSame('Lesson (p. 3)', $pages[0]['name']);
    }

    public function testProbeFailureReturnsNoPages(): void
    {
        ImagickEngine::$failProbe = true;
        self::assertSame([], (new ImagickPdfRasterizer())->rasterize($this->path, 'Lesson'));
        self::assertSame([], ImagickEngine::$renders);
    }

    public function testNegativePageLimitPreventsRendering(): void
    {
        self::assertSame([], (new ImagickPdfRasterizer(maxPages: -1))->rasterize($this->path, 'Lesson'));
        self::assertSame([], ImagickEngine::$renders);
    }
}
