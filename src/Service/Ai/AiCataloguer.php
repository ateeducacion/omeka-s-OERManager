<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ContentExtractor;

/**
 * Orquestador de la catalogación IA-assistida (ADR-0007): extrae el contenido
 * textual seguro del item (metadatos + medios) y lo pasa a los dos clasificadores
 * (curricular jerárquico + ejes), fusionando sus propuestas en un único mapa que
 * pre-rellena el panel de re-catalogación de 4a. La IA propone; el curador
 * confirma: aquí no se escribe nada en el catálogo.
 */
final class AiCataloguer
{
    public function __construct(
        private ContentExtractor $extractor,
        private ClassifierInterface $curricular,
        private ClassifierInterface $tags
    ) {
    }

    /**
     * @param array<int,array{path:string,mediaType?:string,name?:string}> $files
     * @return array{alignment:array<string,int[]>,content:array{truncated:bool,empty:bool,sources:string[],skipped:array<string,string>}}
     */
    public function propose(string $metadataText, array $files): array
    {
        $content = $this->extractor->extract($metadataText, $files);

        $alignment = [];
        // Sin contenido no hay nada que clasificar: no se gasta ni un token.
        if (!$content->isEmpty()) {
            $text = $content->text();
            $alignment = $this->curricular->classify($text) + $this->tags->classify($text);
        }

        return [
            'alignment' => $alignment,
            'content' => [
                'truncated' => $content->isTruncated(),
                'empty' => $content->isEmpty(),
                'sources' => $content->sources(),
                'skipped' => $content->skipped(),
            ],
        ];
    }
}
