<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\ItemContext;
use OERManager\Service\Content\MediaVisionExtractor;

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
        private MediaVisionExtractor $vision,
        private ContextDistiller $distiller,
        private ClassifierInterface $curricular,
        private ClassifierInterface $tags
    ) {
    }

    /**
     * @param array<int,array{path:string,mediaType?:string,name?:string}> $files
     * @param array<int,array{path:string,mediaType?:string,name?:string,size?:int}> $images
     * @return array{alignment:array<string,int[]>,justifications:array<string,array<int,string>>,content:array{truncated:bool,empty:bool,sources:string[],skipped:array<string,string>},debug:array<string,mixed>}
     */
    public function propose(string $metadataText, array $files, array $images = []): array
    {
        if ($this->curricular instanceof TraceableInterface) {
            $this->curricular->clearTrace();
        }
        if ($this->tags instanceof TraceableInterface) {
            $this->tags->clearTrace();
        }
        $this->distiller->clearTrace();
        $this->vision->clearTrace();

        // Extrae SOLO el texto de los medios (sin prefijar metadatos): el contexto
        // los mantiene separados con su procedencia (ADR-0011), y el truncado por
        // presupuesto protege el contenido del medio.
        $media = $this->extractor->extract('', $files);

        // Visión (ADR-0011): rescata las imágenes (top-N) y los PDF escaneados que el
        // ContentExtractor no pudo leer. Gobernada por el toggle/proveedor dentro del
        // extractor; off-by-default => no-op sin red. El PDF sin capa de texto se
        // detecta por su motivo de salto.
        $visionDescriptions = $this->vision->describe($images, $this->rescuablePdfs($files, $media->skipped()));
        $context = new ItemContext($metadataText, $media->text(), '', $visionDescriptions);

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
            // Justificación por saber/criterio (TASK-023): el clasificador
            // curricular la expone si la soporta; los ejes no la llevan.
            'justifications' => method_exists($this->curricular, 'getJustifications')
                ? $this->curricular->getJustifications()
                : [],
            'content' => [
                'truncated' => $media->isTruncated(),
                'empty' => $context->isEmpty(),
                'sources' => $media->sources(),
                'skipped' => $media->skipped(),
            ],
            'debug' => [
                'content_text' => $context->fineText(),
                'ficha' => $ficha,
                'vision' => $this->vision->getTrace(),
                'distillation' => $this->distiller->getTrace(),
                'curricular' => $this->curricular instanceof TraceableInterface
                    ? $this->curricular->getTrace() : [],
                'tags' => $this->tags instanceof TraceableInterface
                    ? $this->tags->getTrace() : [],
            ],
        ];
    }

    /**
     * PDF cuyo contenido no pudo leerse como texto (escaneado, sin capa de texto):
     * candidatos a rescate por visión. Se identifican por su motivo de salto
     * (pdf_unreadable/pdf_empty) y se cruzan con los ficheros locales para recuperar
     * la ruta del binario; las entradas internas de un ZIP no tienen ruta y se omiten.
     *
     * @param array<int,array{path:string,mediaType?:string,name?:string}> $files
     * @param array<string,string> $skipped nombre => motivo
     * @return array<int,array{path:string,mediaType:string,name:string}>
     */
    private function rescuablePdfs(array $files, array $skipped): array
    {
        $rescue = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ('' === $path) {
                continue;
            }
            $name = (string) ($file['name'] ?? basename($path));
            $reason = $skipped[$name] ?? '';
            if (!in_array($reason, ['pdf_unreadable', 'pdf_empty'], true)) {
                continue;
            }
            $rescue[] = [
                'path' => $path,
                'mediaType' => (string) ($file['mediaType'] ?? 'application/pdf'),
                'name' => $name,
            ];
        }
        return $rescue;
    }
}
