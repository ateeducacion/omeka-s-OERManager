<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\ItemContext;

/**
 * Orquestador de la catalogación IA-assistida (ADR-0007): extrae el contenido
 * textual seguro del item (medios), lo destila en una ficha fiel (ADR-0011) con
 * el modelo de extracción barato, y compone un ItemContext estructurado que pasa
 * a los dos clasificadores (curricular jerárquico + ejes), fusionando sus
 * propuestas en un único mapa que pre-rellena el panel de re-catalogación de 4a.
 * La IA propone; el curador confirma: aquí no se escribe nada en el catálogo.
 */
final class AiCataloguer
{
    public function __construct(
        private ContentExtractor $extractor,
        private ContextDistiller $distiller,
        private ClassifierInterface $curricular,
        private ClassifierInterface $tags
    ) {
    }

    /**
     * @param array<int,array{path:string,mediaType?:string,name?:string}> $files
     * @return array{alignment:array<string,int[]>,content:array{truncated:bool,empty:bool,sources:string[],skipped:array<string,string>},debug:array<string,mixed>}
     */
    public function propose(string $metadataText, array $files): array
    {
        if ($this->curricular instanceof TraceableInterface) {
            $this->curricular->clearTrace();
        }
        if ($this->tags instanceof TraceableInterface) {
            $this->tags->clearTrace();
        }
        $this->distiller->clearTrace();

        // Extrae SOLO el texto de los medios (sin prefijar metadatos): el contexto
        // los mantiene separados con su procedencia (ADR-0011), y el truncado por
        // presupuesto protege el contenido del medio.
        $media = $this->extractor->extract('', $files);
        $context = new ItemContext($metadataText, $media->text());

        // Destilación (ADR-0011): el modelo barato produce una ficha fiel que usan
        // los pasos gruesos; los pasos finos conservan el crudo de medios.
        $ficha = $this->distiller->distill($context);
        if ('' !== $ficha) {
            $context = $context->withFicha($ficha);
        }

        $alignment = [];
        // Sin señal no hay nada que clasificar: no se gasta ni un token.
        if (!$context->isEmpty()) {
            $alignment = $this->curricular->classify($context) + $this->tags->classify($context);
        }

        return [
            'alignment' => $alignment,
            'content' => [
                'truncated' => $media->isTruncated(),
                'empty' => $context->isEmpty(),
                'sources' => $media->sources(),
                'skipped' => $media->skipped(),
            ],
            'debug' => [
                'content_text' => $context->fineText(),
                'ficha' => $ficha,
                'distillation' => $this->distiller->getTrace(),
                'curricular' => $this->curricular instanceof TraceableInterface
                    ? $this->curricular->getTrace() : [],
                'tags' => $this->tags instanceof TraceableInterface
                    ? $this->tags->getTrace() : [],
            ],
        ];
    }
}
