<?php

declare(strict_types=1);

namespace OERManager\Service\Content;

/**
 * Convierte las primeras páginas de un PDF en imágenes para la visión (TASK-026).
 *
 * Existe para que el rescate de un PDF escaneado NO consista en subir el binario
 * original al proveedor: un PDF de 28 MB son ~38 MB en base64 y ~10 minutos de
 * proceso remoto que devuelven una descripción vacía, mientras que sus páginas
 * rasterizadas son unos cientos de KB que el proveedor lee como cualquier imagen.
 *
 * Aísla ext-imagick tras una interfaz para poder probar en host (sin la extensión)
 * todo el camino de visión con un doble.
 */
interface PdfRasterizerInterface
{
    /**
     * Rasteriza las primeras páginas del PDF. Devuelve `[]` —nunca lanza— cuando no
     * se puede rasterizar (sin ext-imagick, ruta ilegible, PDF que el motor no
     * abre); quien llama lo interpreta como «usa el camino nativo del proveedor».
     *
     * @param string $name nombre visible del medio, para etiquetar cada página
     * @return array<int,array{data:string,mediaType:string,name:string,size:int}>
     */
    public function rasterize(string $path, string $name): array;
}
