<?php

namespace OERManager\Service\Governance;

use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Empareja cada materia del REA con su curso y recoge aparte los cursos que
 * ninguna materia cubre.
 *
 * Vive separada de `ColumnType\Curricular` porque la necesitan dos consumidores
 * y duplicar el recorrido sería duplicar la definición de «curso huérfano»:
 * la celda la usa para mostrar los pares, y `ColumnType\AlignmentStatus` para
 * decidir el estado (un curso huérfano degrada a `partial`, ADR-0005 §4
 * ampliado el 2026-08-10).
 *
 * Es **glue del core**, no lógica pura: recorre representaciones de Omeka, así
 * que no se puede probar en host. La decisión que alimenta sí es pura y está
 * probada — ver `AlignmentStatusValue::fromFlags()` y `CurricularSummary`.
 *
 * Sin consultas de grafo nuevas: la Asignatura declara su propio curso
 * (ADR-0009) y la arista se recorre sobre la representación ya cargada, igual
 * que `RecatalogService::qualifiedTitle()`.
 */
final class CurricularPairs
{
    public const SUBJECT_TERM = 'schema:about';
    public const STAGE_TERM = 'lrmi:educationalLevel';

    /**
     * Aristas por las que una Asignatura declara su curso, en orden de
     * preferencia. Mismo juego que `RecatalogService::QUALIFIER_TERMS`.
     */
    private const COURSE_TERMS = [
        'lrmi:educationalLevel',
        'lrmi:educationalAlignment',
        'schema:inDefinedTermSet',
    ];

    /**
     * @return array{
     *     pairs: list<array{subject:string, course:string, isLiteral:bool}>,
     *     orphanCourses: list<string>
     * }
     */
    public static function of(ItemRepresentation $item): array
    {
        $pairs = [];
        $covered = [];

        foreach ($item->value(self::SUBJECT_TERM, ['all' => true, 'default' => []]) as $value) {
            if (!str_starts_with($value->type(), 'resource')) {
                $pairs[] = ['subject' => (string) $value->value(), 'course' => '', 'isLiteral' => true];
                continue;
            }
            $subject = $value->valueResource();
            if (null === $subject) {
                continue;
            }
            $course = self::courseOf($subject);
            if (null !== $course) {
                $covered[$course->id()] = true;
            }
            $pairs[] = [
                'subject' => (string) $subject->displayTitle(''),
                'course' => null !== $course ? (string) $course->displayTitle('') : '',
                'isLiteral' => false,
            ];
        }

        $orphans = [];
        foreach ($item->value(self::STAGE_TERM, ['all' => true, 'default' => []]) as $value) {
            if (!str_starts_with($value->type(), 'resource')) {
                $orphans[] = (string) $value->value();
                continue;
            }
            $level = $value->valueResource();
            if (null !== $level && !isset($covered[$level->id()])) {
                $orphans[] = (string) $level->displayTitle('');
            }
        }

        return ['pairs' => $pairs, 'orphanCourses' => $orphans];
    }

    /** El curso al que pertenece una Asignatura, o null si no lo declara. */
    private static function courseOf(AbstractEntityRepresentation $subject): ?AbstractEntityRepresentation
    {
        foreach (self::COURSE_TERMS as $term) {
            $value = $subject->value($term);
            $course = $value ? $value->valueResource() : null;
            if (null !== $course) {
                return $course;
            }
        }
        return null;
    }
}
