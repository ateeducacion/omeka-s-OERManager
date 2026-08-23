<?php

declare(strict_types=1);

namespace OERManager\Service;

/**
 * Áreas del panel de detalle y el estado de cada una.
 *
 * Portado de `asset/js/core/detailAreas.js` en TASK-034 (ADR-0017 §2). Antes
 * esta lógica estaba partida entre dos lenguajes: el controlador decidía
 * `panel: null` / `integrity: null` y el modelo JS volvía a derivar de ahí los
 * tres estados. Al servirse el panel como plantilla, la derivación se hace
 * donde ya viven los datos y deja de poder discrepar consigo misma.
 *
 * Codifica el principio que la rebanada 3a de TASK-028 pagó caro: **«no se pudo
 * leer» y «no hay nada» NUNCA comparten pantalla**. De ahí tres estados y no
 * dos — sin `UNKNOWN`, un id inválido o un fetch que el ACL deniega acaban
 * pintando lo mismo que un REA sano.
 *
 * Pura: no toca la API, ni el contenedor, ni traduce. Los literales viajan sin
 * traducir y los rotula la plantilla, igual que hacía el núcleo JS.
 */
final class PanelAreas
{
    /**
     * Orden de presentación. Anclaje primero: es la decisión que el panel
     * habilita (ADR-0014 §1), y la única área con acciones.
     */
    public const ORDER = ['alignment', 'media', 'record', 'integrity'];

    public const LABELS = [
        'alignment' => 'Anclaje curricular', // @translate
        'media' => 'Medios', // @translate
        'record' => 'Información', // @translate
        'integrity' => 'Integridad', // @translate
    ];

    public const PANEL_ERROR_TEXT = 'No se ha podido cargar el detalle de este REA.'; // @translate
    public const MEDIA_EMPTY_TEXT = 'Este REA no tiene ningún medio.'; // @translate

    /**
     * El vacío es una invitación a actuar, no un parte: desde TASK-033 el botón
     * de re-catalogar está en esta misma área, a un renglón de este texto.
     */
    // El `=` baja de línea solo para no pasar de 120 columnas. `extract-tagged-strings`
    // retrocede por tokens desde la marca hasta la cadena más cercana, no por líneas,
    // así que la sigue extrayendo; comprobado en `language/template.pot`.
    public const ALIGNMENT_EMPTY_TEXT
        = 'Sin anclaje curricular. Re-catalógalo para asignarle curso, asignatura y saberes.'; // @translate

    public const STATE_READY = 'ready';
    public const STATE_EMPTY = 'empty';
    public const STATE_UNKNOWN = 'unknown';

    /**
     * @param array<string,mixed>|null $panel Lo que devuelve `ItemPanelData::forItem()`,
     *   o `null` si el item no se pudo leer.
     * @param array<string,mixed>|null $integrity Resultado del comprobador, o
     *   `null` si no llegó a comprobarse.
     * @return list<array{id:string, state:string}> En el orden de `ORDER`.
     */
    public static function build(?array $panel, ?array $integrity): array
    {
        if (null === $panel) {
            return array_map(
                static fn (string $id): array => ['id' => $id, 'state' => self::STATE_UNKNOWN],
                self::ORDER
            );
        }

        $alignment = $panel['alignment'] ?? ['groups' => [], 'axes' => [], 'orphans' => []];
        $media = $panel['media'] ?? [];
        $record = $panel['record'] ?? [];

        $byId = [
            'alignment' => [
                'id' => 'alignment',
                'state' => self::alignmentState($alignment),
                'alignment' => $alignment,
            ],
            'media' => [
                'id' => 'media',
                'state' => $media ? self::STATE_READY : self::STATE_EMPTY,
                'media' => $media,
            ],
            'record' => [
                'id' => 'record',
                'state' => $record ? self::STATE_READY : self::STATE_EMPTY,
                'record' => $record,
            ],
            'integrity' => [
                'id' => 'integrity',
                // `null` es «no se pudo comprobar», no «está sano»: un array de
                // integridad sin incidencias sigue siendo una comprobación hecha.
                'state' => null === $integrity ? self::STATE_UNKNOWN : self::STATE_READY,
                'integrity' => $integrity,
            ],
        ];

        return array_map(static fn (string $id): array => $byId[$id], self::ORDER);
    }

    /**
     * Cualquiera de las tres colecciones cuenta como contenido: un anclaje que
     * SOLO tiene huérfanos no está vacío —hay algo que enseñar, y además es
     * justo lo que hay que mirar—, y unos ejes sueltos también son anclaje.
     *
     * @param array<string,mixed> $alignment
     */
    private static function alignmentState(array $alignment): string
    {
        $hasSomething = ($alignment['groups'] ?? [])
            || ($alignment['axes'] ?? [])
            || ($alignment['orphans'] ?? []);

        return $hasSomething ? self::STATE_READY : self::STATE_EMPTY;
    }
}
