<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Agrupamiento curricular del panel de detalle (TASK-032).
 *
 * El drawer mostraba listas planas por dimensión, así que con dos cursos
 * mezclados el curador no podía saber qué saber pertenece a cuál: el REA #40437
 * del catálogo real lista «Educación Física» cuatro veces seguidas. Aquí los
 * valores se reagrupan por curso.
 *
 * Pura a propósito: resolver el curso ancestro de cada valor exige leer el
 * currículo, y eso lo hace `RecatalogService`, que sí depende del core. Esta
 * clase recibe los ancestros ya resueltos y solo decide la forma.
 *
 * **Nada se pierde.** Todo valor que no entre en un grupo aparece en `orphans`
 * con su razón: esconderlos haría que la pantalla dejara de reflejar lo que el
 * REA dice de verdad, y taparía justo el defecto que el módulo ya penaliza en
 * la columna de anclaje.
 */
final class CurricularGrouping
{
    public const COURSE_TERM = 'lrmi:educationalLevel';
    public const SUBJECT_TERM = 'schema:about';
    public const TEACHES_TERM = 'lrmi:teaches';
    public const ASSESSES_TERM = 'lrmi:assesses';

    /** Vocabulario plano: se lista aparte, nunca se agrupa. */
    public const AXIS_TERM = 'dcterms:relation';

    /** Un curso que ninguna materia sostiene (ADR-0016: degrada a parcial). */
    public const REASON_UNSUPPORTED_COURSE = 'course-without-subject';

    /**
     * Un valor cuyo curso no está declarado en el item, cuyo curso SÍ está
     * declarado pero ninguna materia lo sostiene (por eso no llegó a formar
     * grupo: cayó primero a REASON_UNSUPPORTED_COURSE), cuyo curso no se pudo
     * resolver, o el mismo curso declarado una segunda vez (I3, revisión final
     * de rama: el molde de salida es un grupo por curso, así que la repetición
     * no tiene dónde ir más que aquí).
     */
    public const REASON_UNDECLARED_COURSE = 'course-not-declared';

    /**
     * @param array<string, list<array{id:int, title:string, courseId:?int}>> $alignment
     * @return array{
     *     groups: list<array{courseId:int, courseTitle:string, subjects:list<string>,
     *         teaches:list<string>, assesses:list<string>}>,
     *     axes: list<string>,
     *     orphans: list<array{term:string, title:string, reason:string}>
     * }
     */
    public static function build(array $alignment): array
    {
        $courses = $alignment[self::COURSE_TERM] ?? [];
        $subjects = $alignment[self::SUBJECT_TERM] ?? [];

        // Un curso solo sostiene grupo si alguna materia cuelga de él.
        $supported = [];
        foreach ($subjects as $subject) {
            if (null !== $subject['courseId']) {
                $supported[$subject['courseId']] = true;
            }
        }

        $groups = [];
        $orphans = [];
        foreach ($courses as $course) {
            // El curso ya tiene grupo: es la MISMA declaración repetida (dos
            // resource values de lrmi:educationalLevel al mismo item-término).
            // Reescribir $groups[$course['id']] aquí vaciaría el grupo que ya se
            // formó (o se va a formar con lo que cuelgue de él más abajo), y el
            // valor repetido desaparecería sin dejar rastro — ni grupo, ni
            // huérfano — rompiendo la promesa «nada se pierde» del docblock de
            // la clase (I3, revisión final de rama). El molde de salida es un
            // grupo por curso, así que la repetición cae a huérfanos.
            if (isset($groups[$course['id']])) {
                $orphans[] = [
                    'term' => self::COURSE_TERM,
                    'title' => $course['title'],
                    'reason' => self::REASON_UNDECLARED_COURSE,
                ];
                continue;
            }
            if (isset($supported[$course['id']])) {
                $groups[$course['id']] = [
                    'courseId' => $course['id'],
                    'courseTitle' => $course['title'],
                    'subjects' => [],
                    'teaches' => [],
                    'assesses' => [],
                ];
                continue;
            }
            $orphans[] = [
                'term' => self::COURSE_TERM,
                'title' => $course['title'],
                'reason' => self::REASON_UNSUPPORTED_COURSE,
            ];
        }

        foreach (
            [self::SUBJECT_TERM => 'subjects',
                  self::TEACHES_TERM => 'teaches',
                  self::ASSESSES_TERM => 'assesses'] as $term => $bucket
        ) {
            foreach ($alignment[$term] ?? [] as $value) {
                $courseId = $value['courseId'];
                if (null !== $courseId && isset($groups[$courseId])) {
                    $groups[$courseId][$bucket][] = $value['title'];
                    continue;
                }
                $orphans[] = [
                    'term' => $term,
                    'title' => $value['title'],
                    'reason' => self::REASON_UNDECLARED_COURSE,
                ];
            }
        }

        return [
            'groups' => array_values($groups),
            'axes' => array_column($alignment[self::AXIS_TERM] ?? [], 'title'),
            'orphans' => $orphans,
        ];
    }
}
