<?php

declare(strict_types=1);

namespace OERManager\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use OERManager\Service\Governance\CurricularGrouping;

/**
 * Reúne lo que el panel de detalle necesita y el JSON-LD público NO puede dar
 * (TASK-032 §5): la miniatura no viaja en el JSON del item, `o:media` solo trae
 * ids sin nombre ni tipo ni tamaño, y el drawer cargaba sin autenticar — el
 * primer REA que se pusiera en privado habría dejado de abrir su panel.
 *
 * Solo lectura. No escribe nada.
 */
class ItemPanelData
{
    /** Aristas al curso ancestro, en orden de preferencia (ADR-0009). */
    private const COURSE_EDGES = ['lrmi:educationalLevel', 'lrmi:educationalAlignment'];

    /**
     * @return array{
     *     identity: array{id:int, title:string, isPublic:bool, thumbnail:?string, editUrl:string},
     *     record: array<string,string>,
     *     alignment: array<string,mixed>,
     *     media: list<array{title:string, type:string, size:int, url:?string}>
     * }
     */
    public function forItem(ItemRepresentation $item): array
    {
        $thumbnails = $item->thumbnailDisplayUrls();

        return [
            'identity' => [
                'id' => (int) $item->id(),
                'title' => (string) $item->displayTitle(''),
                'isPublic' => (bool) $item->isPublic(),
                'thumbnail' => $thumbnails['square'] ?? null,
                'editUrl' => (string) $item->url('edit'),
            ],
            'record' => $this->record($item),
            'alignment' => CurricularGrouping::build($this->alignment($item)),
            'media' => $this->media($item),
        ];
    }

    /** Campos de ficha que el panel muestra en su área de información. */
    private function record(ItemRepresentation $item): array
    {
        $fields = [
            'dcterms:description',
            'dcterms:rights',
            'schema:isPartOf',
            'lrmi:learningResourceType',
        ];
        $record = [];
        foreach ($fields as $term) {
            $values = $item->value($term, ['all' => true, 'default' => []]);
            $texts = [];
            foreach ($values as $value) {
                $resource = $value->valueResource();
                $texts[] = $resource ? (string) $resource->displayTitle() : trim((string) $value->value());
            }
            $texts = array_values(array_filter($texts, static fn (string $t): bool => '' !== $t));
            if ($texts) {
                $record[$term] = implode(', ', $texts);
            }
        }
        return $record;
    }

    /**
     * Valores de alineamiento con su CURSO ancestro ya resuelto, que es lo que
     * `CurricularGrouping` necesita y no puede averiguar por sí sola.
     *
     * Sin lecturas nuevas por id: la arista se recorre sobre la representación
     * que el propio valor ya trae, igual que hace `qualifiedTitle()`.
     *
     * @return array<string, list<array{id:int, title:string, courseId:?int}>>
     */
    private function alignment(ItemRepresentation $item): array
    {
        $alignment = [];
        foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
            $alignment[$term] = [];
            foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
                $target = $value->valueResource();
                if (null === $target) {
                    continue;
                }
                $id = (int) $target->id();
                $alignment[$term][] = [
                    'id' => $id,
                    'title' => (string) $target->displayTitle(),
                    'courseId' => $this->courseIdFor($term, $id, $target),
                ];
            }
        }
        return $alignment;
    }

    /**
     * Curso ancestro de un valor de alineamiento, según su dimensión.
     *
     * El propio curso ES su curso (`$id`); un eje (`dcterms:relation`) no
     * tiene estructuralmente esa arista —es vocabulario plano, todo cuelga del
     * mismo `DefinedTermSet` (`RecatalogService::UNQUALIFIED_TERM`)— así que
     * resolverlo con `courseIdOf()` serían dos hidrataciones de Doctrine por
     * eje para un valor que `CurricularGrouping::AXIS_TERM` nunca agrupa: se
     * evita, no se calcula un `null` que nadie iba a leer.
     */
    private function courseIdFor(string $term, int $id, AbstractResourceEntityRepresentation $target): ?int
    {
        if (CurricularGrouping::COURSE_TERM === $term) {
            return $id;
        }
        if (CurricularGrouping::AXIS_TERM === $term) {
            return null;
        }
        return $this->courseIdOf($target);
    }

    /** Id del curso del que cuelga un item-término, o null si no se resuelve. */
    private function courseIdOf(AbstractResourceEntityRepresentation $target): ?int
    {
        foreach (self::COURSE_EDGES as $edge) {
            $value = $target->value($edge);
            $ancestor = $value ? $value->valueResource() : null;
            if (null !== $ancestor) {
                return (int) $ancestor->id();
            }
        }
        return null;
    }

    /**
     * Metadatos de los medios. `o:media` en el JSON-LD solo trae `{@id, o:id}`,
     * así que nombre, tipo y tamaño solo pueden salir de aquí.
     *
     * `renderedHtml` es el renderer NATIVO de Omeka (`MediaRepresentation::render()`,
     * el mismo que usa el propio core en `file.phtml`/`media-render.phtml`):
     * resuelve por tipo —imagen inline, `<audio>`/`<video controls>`, o la
     * miniatura/icono genérico de `ThumbnailRenderer` para lo demás (pdf, zip)—
     * en vez de reinventar la presentación por tipo de fichero en este módulo.
     * `render()` resuelve su propia vista internamente (`getViewHelper()`), sin
     * dependencia nueva aquí.
     *
     * @return list<array{title:string, type:string, size:int, url:?string, renderedHtml:string}>
     */
    private function media(ItemRepresentation $item): array
    {
        $media = [];
        foreach ($item->media() as $one) {
            $media[] = [
                'title' => (string) $one->displayTitle(),
                'type' => (string) $one->mediaType(),
                'size' => (int) $one->size(),
                'url' => $one->originalUrl(),
                'renderedHtml' => (string) $one->render(['thumbnailType' => 'medium', 'link' => 'original']),
            ];
        }
        return $media;
    }
}
