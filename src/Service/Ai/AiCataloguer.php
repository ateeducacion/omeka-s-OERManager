<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Content\ContentExtractor;
use OERManager\Service\Content\ExtractedContent;
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
     * @param array<int,array{path:string,mediaType?:string,name?:string,size?:int}> $files
     * @param array<int,array{path:string,mediaType?:string,name?:string,size?:int}> $images
     * @return array<string,mixed>
     */
    public function propose(
        string $metadataText,
        array $files,
        array $images = [],
        ?ProgressReporter $progress = null
    ): array {
        $progress ??= new NullProgressReporter();
        if ($this->curricular instanceof TraceableInterface) {
            $this->curricular->clearTrace();
        }
        if ($this->tags instanceof TraceableInterface) {
            $this->tags->clearTrace();
        }
        $this->distiller->clearTrace();
        $this->vision->clearTrace();

        // Progreso por fases (TASK-020): total aproximado; las etiquetas son la
        // señal principal, el número es orientativo (algunas fases son condicionales).
        $total = 5;
        $progress->report('Extrayendo contenido', 1, $total);

        // Extrae SOLO el texto de los medios (sin prefijar metadatos): el contexto
        // los mantiene separados con su procedencia (ADR-0011), y el truncado por
        // presupuesto protege el contenido del medio.
        $media = $this->extractor->extract('', $files);

        // Visión (ADR-0011): rescata las imágenes (top-N) y los PDF escaneados que el
        // ContentExtractor no pudo leer. Gobernada por el toggle/proveedor dentro del
        // extractor; off-by-default => no-op sin red. El PDF sin capa de texto se
        // detecta por su motivo de salto.
        $rescuable = $this->rescuablePdfs($files, $media->skipped());
        $this->stopIfRequested($progress);
        if ([] !== $images || [] !== $rescuable) {
            $progress->report('Analizando imágenes y PDF', 2, $total);
        }
        $visionDescriptions = $this->vision->describe($images, $rescuable);
        $context = new ItemContext($metadataText, $media->text(), '', $visionDescriptions);

        // Destilación (ADR-0011): el modelo barato produce una ficha fiel que usan
        // los pasos gruesos; los pasos finos conservan el crudo de medios.
        $this->stopIfRequested($progress);
        $progress->report('Destilando ficha', 3, $total);
        $ficha = $this->distiller->distill($context);
        if ('' !== $ficha) {
            $context = $context->withFicha($ficha);
        }

        $alignment = [];
        // Sin señal no hay nada que clasificar: no se gasta ni un token.
        if (!$context->isEmpty()) {
            $this->stopIfRequested($progress);
            $progress->report('Clasificación curricular', 4, $total);
            $curricular = $this->curricular->classify($context);

            $this->stopIfRequested($progress);
            $progress->report('Ejes temáticos', 5, $total);
            $tags = $this->tags->classify($context);

            $alignment = $curricular + $tags;
        }

        return [
            'alignment' => $alignment,
            // Justificación por saber/criterio (TASK-023): el clasificador
            // curricular la expone si la soporta; los ejes no la llevan.
            'justifications' => method_exists($this->curricular, 'getJustifications')
                ? $this->curricular->getJustifications()
                : [],
            'content' => $this->contentBlock($media, $context->isEmpty()),
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
     * Aborta el propose si el curador pidió cancelar (TASK-020). Se comprueba en
     * los límites de fase; corta al terminar la fase en curso, sin propuesta parcial.
     */
    private function stopIfRequested(ProgressReporter $progress): void
    {
        if ($progress->shouldStop()) {
            throw new JobStoppedException();
        }
    }

    /**
     * PDF cuyo contenido no pudo leerse como texto (escaneado, sin capa de texto,
     * o perdido por una plataforma sin `iconv //TRANSLIT` — TASK-024b):
     * candidatos a rescate por visión. Se identifican por su motivo de salto y se
     * cruzan con los ficheros locales para recuperar la ruta del binario; las
     * entradas internas de un ZIP no tienen ruta y se omiten.
     *
     * `pdf_too_large` entra en la lista SIN confirmación del curador (TASK-026): el
     * tope de parseo es una guarda de memoria del parseo local, y la visión ya no
     * sube el binario —rasteriza las primeras páginas—, así que un PDF de 28 MB
     * cuesta lo mismo que cualquier item con imágenes. La confirmación de TASK-025
     * existía por el coste de subir el original y deja de tener motivo.
     *
     * @param array<int,array{path?:string,mediaType?:string,name?:string,size?:int}> $files
     * @param array<string,string> $skipped nombre => motivo
     * @return array<int,array{path:string,mediaType:string,name:string}>
     */
    private function rescuablePdfs(array $files, array $skipped): array
    {
        $reasons = ['pdf_unreadable', 'pdf_empty', 'pdf_iconv_unsupported', 'pdf_too_large'];
        $rescue = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ('' === $path) {
                continue;
            }
            $name = (string) ($file['name'] ?? basename($path));
            if (!in_array($skipped[$name] ?? '', $reasons, true)) {
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

    /**
     * Bloque `content` común a todos los retornos. `skipped` (nombre => motivo) ya
     * da al panel el detalle de qué medio no aportó y por qué.
     *
     * @return array<string,mixed>
     */
    private function contentBlock(ExtractedContent $media, bool $empty): array
    {
        return [
            'truncated' => $media->isTruncated(),
            'empty' => $empty,
            'sources' => $media->sources(),
            'skipped' => $media->skipped(),
        ];
    }
}
