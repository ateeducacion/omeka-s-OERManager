<?php

declare(strict_types=1);

namespace OERManager\Service\Content;

/**
 * Rasterizador de PDF con ext-imagick (TASK-026), el mismo motor con el que Omeka
 * genera las derivadas de los medios, así que no añade dependencia ninguna.
 *
 * Medido en el contenedor sobre `IA_alcaravan.pdf` (28,4 MB, 1 página, sin capa de
 * texto): `pingImage` 0,58 s y la página a 150 dpi en 0,91 s → JPEG de 659 KB. El
 * camino anterior (subir el binario) tardaba 10 min y devolvía vacío.
 *
 * Todo el trabajo va acotado: tope de páginas (coste — cada página es un bloque de
 * imagen que se paga), tope de bytes por página (los proveedores acotan ~5 MB por
 * imagen) y captura de cualquier fallo del motor, porque un PDF es dato NO confiable
 * y el render no debe poder abortar el propose.
 */
final class ImagickPdfRasterizer implements PdfRasterizerInterface
{
    /** Páginas enviadas como mucho: la portada y las primeras concentran la señal. */
    public const DEFAULT_MAX_PAGES = 4;
    /** Resolución de render: legible para el modelo sin disparar el tamaño. */
    public const DEFAULT_RESOLUTION = 150;
    public const DEFAULT_QUALITY = 80;
    /** Tope por imagen enviada al proveedor (Anthropic acota ~5 MB por imagen). */
    public const DEFAULT_MAX_PAGE_BYTES = 5242880;

    public function __construct(
        private int $maxPages = self::DEFAULT_MAX_PAGES,
        private int $resolution = self::DEFAULT_RESOLUTION,
        private int $quality = self::DEFAULT_QUALITY,
        private int $maxPageBytes = self::DEFAULT_MAX_PAGE_BYTES
    ) {
    }

    public function rasterize(string $path, string $name): array
    {
        if ('' === $path || !is_file($path) || !is_readable($path) || !class_exists(\Imagick::class)) {
            return [];
        }
        $limit = min($this->countPages($path), max(0, $this->maxPages));

        $pages = [];
        for ($i = 0; $i < $limit; $i++) {
            $blob = $this->renderPage($path, $i);
            if (null === $blob) {
                continue;
            }
            $pages[] = [
                'data' => $blob,
                'mediaType' => 'image/jpeg',
                'name' => sprintf('%s (p. %d)', $name, $i + 1),
                'size' => strlen($blob),
            ];
        }
        return $pages;
    }

    /**
     * Nº de páginas por `pingImage`, que lee la estructura sin rasterizar nada (y sin
     * cargar los 28 MB en memoria). 0 si el motor no sabe abrir el fichero.
     */
    private function countPages(string $path): int
    {
        try {
            $probe = new \Imagick();
            $probe->pingImage($path);
            $pages = $probe->getNumberImages();
            $probe->clear();
            return max(0, $pages);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Rasteriza UNA página a JPEG. Si se pasa del tope de bytes se reintenta una vez
     * a media resolución y menor calidad antes de rendirse: mejor una página algo
     * más basta que perderla.
     */
    private function renderPage(string $path, int $index): ?string
    {
        $blob = $this->renderAt($path, $index, $this->resolution, $this->quality);
        if (null !== $blob && strlen($blob) > $this->maxPageBytes) {
            $blob = $this->renderAt($path, $index, max(72, intdiv($this->resolution, 2)), 70);
        }
        return null !== $blob && strlen($blob) <= $this->maxPageBytes ? $blob : null;
    }

    private function renderAt(string $path, int $index, int $resolution, int $quality): ?string
    {
        try {
            $page = new \Imagick();
            // La resolución se fija ANTES de leer: es lo que decide a qué tamaño
            // rasteriza el motor la página vectorial.
            $page->setResolution($resolution, $resolution);
            $page->readImage($path . '[' . $index . ']');
            // JPEG no tiene canal alfa: sin aplanar sobre blanco, las zonas
            // transparentes salen negras y tapan el contenido.
            $page->setImageBackgroundColor(new \ImagickPixel('white'));
            $flat = $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $flat->setImageFormat('jpeg');
            $flat->setImageCompressionQuality($quality);
            $blob = $flat->getImageBlob();
            $flat->clear();
            $page->clear();
            return '' === $blob ? null : $blob;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
