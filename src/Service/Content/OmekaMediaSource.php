<?php

namespace OERManager\Service\Content;

use Omeka\Api\Manager as ApiManager;
use Omeka\File\Store\StoreInterface;

/**
 * Localiza las rutas locales de los medios de un item para el ContentExtractor.
 * Solo ficheros YA almacenados por Omeka (nunca URLs remotas → sin SSRF, spec §6)
 * y solo si el store es local: con un store remoto (S3, etc.) no hay ruta local y
 * se omite (no se descarga nada).
 *
 * Verificado en el contenedor (usa el ApiManager y el File\Store del core).
 */
final class OmekaMediaSource implements MediaSourceInterface
{
    /** Extensiones que el extractor sabe tratar (resto se omite ya aquí). */
    private const WHITELIST = ['txt', 'html', 'htm', 'xml', 'pdf', 'zip', 'json'];

    /** Extensiones de imagen candidatas a visión (ADR-0011). */
    private const IMAGE_WHITELIST = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(private ApiManager $api, private StoreInterface $store)
    {
    }

    public function filesFor(int $itemId): array
    {
        try {
            $item = $this->api->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return [];
        }

        $files = [];
        foreach ($item->media() as $media) {
            $filename = (string) $media->filename();
            if ('' === $filename) {
                continue;
            }
            if (!in_array(strtolower((string) $media->extension()), self::WHITELIST, true)) {
                continue;
            }
            $path = $this->localPath('original/' . $filename);
            if (null === $path || !is_file($path)) {
                continue;
            }
            $files[] = [
                'path' => $path,
                'mediaType' => (string) $media->mediaType(),
                'name' => (string) ($media->source() ?: $filename),
            ];
        }
        return $files;
    }

    public function imagesFor(int $itemId): array
    {
        try {
            $item = $this->api->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return [];
        }

        $images = [];
        foreach ($item->media() as $media) {
            $filename = (string) $media->filename();
            if ('' === $filename) {
                continue;
            }
            if (!in_array(strtolower((string) $media->extension()), self::IMAGE_WHITELIST, true)) {
                continue;
            }
            $path = $this->localPath('original/' . $filename);
            if (null === $path || !is_file($path)) {
                continue;
            }
            $size = filesize($path);
            $images[] = [
                'path' => $path,
                'mediaType' => (string) $media->mediaType(),
                'name' => (string) ($media->source() ?: $filename),
                'size' => false === $size ? 0 : $size,
            ];
        }
        return $images;
    }

    /**
     * Ruta local del fichero en el store, o null si el store no es local
     * (getLocalPath solo existe en Omeka\File\Store\Local).
     */
    private function localPath(string $storagePath): ?string
    {
        if (!method_exists($this->store, 'getLocalPath')) {
            return null;
        }
        return (string) $this->store->getLocalPath($storagePath);
    }
}
