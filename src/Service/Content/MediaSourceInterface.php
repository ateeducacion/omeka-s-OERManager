<?php

namespace OERManager\Service\Content;

/**
 * Localiza los ficheros locales de los medios adjuntos a un item para que el
 * ContentExtractor lea su contenido. Aísla la representación de Omeka (que
 * necesita el core) del extractor puro, que opera sobre rutas locales.
 *
 * Solo rutas de ficheros YA almacenados por Omeka: nunca URLs remotas
 * (sin SSRF, spec §6).
 */
interface MediaSourceInterface
{
    /**
     * @return array<int,array{path:string,mediaType:string,name:string}>
     */
    public function filesFor(int $itemId): array;
}
