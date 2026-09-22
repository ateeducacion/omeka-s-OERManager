<?php

declare(strict_types=1);

namespace OERManager\Test\Support;

/** Records calls to the optional image engine without loading native code. */
final class ImagickEngine
{
    public const LAYERMETHOD_FLATTEN = 14;
    public static int $pageCount = 3;
    public static bool $failProbe = false;
    public static array $blobs = [];
    public static array $renders = [];
    private int $resolution = 0;
    private int $index = 0;
    private int $quality = 0;

    public function pingImage(string $path): void
    {
        if (self::$failProbe) {
            throw new \RuntimeException('Invalid PDF');
        }
    }

    public function getNumberImages(): int
    {
        return self::$pageCount;
    }

    public function setResolution(int $x, int $y): void
    {
        $this->resolution = $x;
    }

    public function readImage(string $path): void
    {
        preg_match('/\[(\d+)\]$/', $path, $matches);
        $this->index = (int) $matches[1];
    }

    public function setImageBackgroundColor(ImagickColor $color): void
    {
        if ($color->color !== 'white') {
            throw new \LogicException('Transparent pages need a white background');
        }
    }

    public function mergeImageLayers(int $mode): self
    {
        if ($mode !== self::LAYERMETHOD_FLATTEN) {
            throw new \LogicException('Pages must be flattened');
        }
        return $this;
    }

    public function setImageFormat(string $format): void
    {
        if ($format !== 'jpeg') {
            throw new \LogicException('Expected JPEG output');
        }
    }

    public function setImageCompressionQuality(int $quality): void
    {
        $this->quality = $quality;
    }

    public function getImageBlob(): string
    {
        self::$renders[] = [$this->index, $this->resolution, $this->quality];
        $blob = self::$blobs[$this->index][$this->resolution] ?? 'jpeg';
        if ($blob instanceof \Throwable) {
            throw $blob;
        }
        return $blob;
    }

    public function clear(): void
    {
    }
}
