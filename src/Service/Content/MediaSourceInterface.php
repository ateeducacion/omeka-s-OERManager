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

    /**
     * Medios de imagen del item, candidatos a la visión (ADR-0011): se localizan
     * aparte de la cascada de texto y se acompañan del tamaño en bytes para que el
     * filtro heurístico (descarta ruido + top-N por tamaño) decida cuáles enviar.
     *
     * @return array<int,array{path:string,mediaType:string,name:string,size:int}>
     */
    public function imagesFor(int $itemId): array;
}
