<?php

namespace OERManager\Service\Governance;

use OERManager\Service\Curriculum\CurModel;
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
 * Sin consultas de grafo nuevas: la materia de un curso (`cur:MateriaCurso`)
 * declara su curso por `cur:curso` (ADR-0022, `CurModel::COURSE_EDGE`) y la
 * arista se recorre sobre la representación ya cargada, igual que
 * `RecatalogService::qualifiedTitle()`. Su etiqueta ya lleva el curso
 * («Matemáticas · 1º ESO»), así que el par muestra solo la materia.
 */
final class CurricularPairs
{
    public const SUBJECT_TERM = 'schema:about';
    public const STAGE_TERM = 'lrmi:educationalLevel';

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
            $course = CurModel::target($subject, CurModel::COURSE_EDGE);
            $courseLabel = null !== $course ? CurModel::label($course) : '';
            if (null !== $course) {
                $covered[$course->id()] = true;
            }
            $pairs[] = [
                'subject' => CurModel::courseSubjectName(CurModel::label($subject), $courseLabel),
                'course' => $courseLabel,
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
                $orphans[] = CurModel::label($level);
            }
        }

        return ['pairs' => $pairs, 'orphanCourses' => $orphans];
    }
}
