<?php

namespace OERManager\Service\Ai;

use OERManager\Service\Llm\LlmUsage;
use OERManager\Service\Content\ItemContext;
use OERManager\Service\Stats\CurriculumOrder;
use OERManager\Service\Llm\LlmClientInterface;

/**
 * Clasificador curricular bottom-up (ADR-0010). Etapa(s) y materia(s) delimitan
 * el contexto (Fase A) en multi-select, sin fijar curso; el LLM selecciona
 * saberes y criterios por su descripción cruzando etapas/materias/cursos (Fases
 * B/C); los criterios se acotan a los cursos derivados de los saberes elegidos
 * (más precisos; fallback sin saberes → criterios de la materia). Curso
 * (lrmi:educationalLevel) y materia (schema:about) se DERIVAN de las hojas
 * elegidas (Fase D) → subgrafo coherente por construcción. La etapa solo acota:
 * nunca se escribe (ADR-0009).
 */
final class CurricularClassifier implements ClassifierInterface, TraceableInterface
{
    use IndexSelection;

    private const TEACHES = 'lrmi:teaches';
    private const ASSESSES = 'lrmi:assesses';

    /** Si los saberes superan este número, pre-filtrar por bloque temático (E2). */
    private const BLOCK_THRESHOLD = 30;

    /** Tope de hojas mergeadas presentadas al LLM (coste de tokens, NFR-004/NFR-008). */
    private const LEAF_CAP = 200;

    /**
     * Guía de la etapa acotadora (Fase A.1). Desde TASK-056 la inclusividad es
     * condicional: si el contenido indica la etapa, se elige esa. Con el tope
     * LEAF_CAP una etapa de más NO era inocua (ADR-0010, addendum TASK-056):
     * sus hojas desplazaban a las de la etapa correcta (0/57 en el conjunto de
     * evaluación de TASK-057). Se mantiene el recall solo cuando el contenido no
     * dice el nivel. No se aplica a materia ni a hojas.
     */
    private const ETAPA_GUIDANCE = 'Si el contenido indica la etapa o el curso de forma explícita (por ejemplo '
        . '«4º ESO», «2º ciclo de Educación Primaria», «Educación Infantil»), elige SOLO esa etapa. '
        . 'Abreviaturas: EI = Educación Infantil, EP = Educación Primaria. '
        . 'Solo si el contenido no indica la etapa, sé INCLUSIVO: si el recurso podría encajar en varias '
        . 'etapas, selecciónalas todas, porque los saberes y criterios de una etapa no elegida no podrán '
        . 'proponerse después.';

    /**
     * Guía del paso de cursos plausibles (Fase A.3, TASK-056). Acota la búsqueda
     * de hojas; el curso escrito se sigue derivando de las hojas elegidas.
     *
     * Lleva la equivalencia ciclo → cursos de la LOMLOE (RD 95/2022 Infantil,
     * RD 157/2022 Primaria): medido el 2026-10-06, el modelo leía «2.º ciclo de
     * EP» como 2º y 3º de Primaria en vez de 3º y 4º. Es conocimiento del
     * currículo para interpretar el texto, no detección por reglas.
     */
    private const COURSE_GUIDANCE = 'Si el contenido indica el curso o el ciclo, elige esos cursos. '
        . 'Equivalencias de ciclos: primer ciclo de Educación Infantil = 1º, 2º y 3º Infantil (0, 1 y 2 años); '
        . 'segundo ciclo de Educación Infantil = 4º, 5º y 6º Infantil (3, 4 y 5 años); '
        . 'primer ciclo de Educación Primaria = 1º y 2º; segundo ciclo de Educación Primaria = 3º y 4º; '
        . 'tercer ciclo de Educación Primaria = 5º y 6º. EI = Educación Infantil, EP = Educación Primaria. '
        . 'Añade un curso vecino solo si hay duda real; si el contenido no da ninguna pista de curso, '
        . 'elige todos los que encajen con su nivel de dificultad.';

    /** @var array<int,array<string,mixed>> */
    private array $trace = [];

    /**
     * Justificación por hoja elegida (TASK-023): dimensión => {itemId => texto}.
     * Solo lrmi:teaches/lrmi:assesses; se resetea al inicio de cada classify().
     *
     * @var array<string,array<int,string>>
     */
    private array $justifications = [];

    /**
     * Ids de hojas reunidas por dimensión ANTES del cupo y de los filtros
     * (TASK-059): con ellos el conjunto de evaluación distingue una hoja que
     * nunca se reunió de una que se recortó o de una que el modelo no eligió.
     *
     * @var array<string,list<int>>
     */
    private array $gathered = [];

    private int $maxTokens;
    private ?float $temperature;

    public function __construct(
        private LlmClientInterface $llm,
        private TermResolverInterface $resolver,
        private PromptBuilder $prompts,
        private ResponseParser $parser,
        int $maxTokens = 1024,
        ?float $temperature = null,
        private ?JevLeafSelector $leafSelector = null
    ) {
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
    }

    public function classify(ItemContext $context): array
    {
        $this->justifications = [];
        $this->gathered = [];

        // Pasos gruesos (etapa/materia/bloque) con la ficha; pasos finos
        // (saberes/criterios) con ficha + crudo de medios (ADR-0011).
        $coarse = $context->coarseText();
        $fine = $context->fineText();

        // Fase A.1 — Etapas (multi; acotan, no se escriben, ADR-0009).
        $etapaIds = $this->pickEtapaIds($this->resolver->listCandidates('etapa'), $coarse);
        if (!$etapaIds) {
            return [];
        }

        // Fase A.2 — Materias (multi; NO fijan curso; no se escriben). Se
        // presentan con sus cursos (TASK-056).
        $families = $this->gatherFamilies($etapaIds);
        $subjectNames = $this->pickSubjectNames($families, $coarse);
        if (!$subjectNames) {
            return [];
        }

        // Fase A.3 — Cursos plausibles (TASK-056): acotan las hojas, no se
        // escriben; el curso escrito se deriva en la Fase D.
        $courseIds = $this->pickCourseIds($families, $subjectNames, $coarse);

        $result = [];
        /** @var array<int,bool> $derivedCourses */
        $derivedCourses = [];
        /** @var array<int,bool> $subjectIds */
        $subjectIds = [];

        // Fase B — Saberes por descripción, cruzando etapas/materias/cursos.
        $teaches = $this->gatherLeaves(self::TEACHES, $etapaIds, $subjectNames, $courseIds);
        if (count($teaches) > self::BLOCK_THRESHOLD) {
            $teaches = $this->prefilterByBlock($teaches, implode(', ', $subjectNames), $coarse);
        }
        $teachesRows = $this->selectRows('Saberes básicos', $teaches, $fine, self::TEACHES);
        if ($teachesRows) {
            $result[self::TEACHES] = array_map(static fn (array $c): int => (int) $c['id'], $teachesRows);
            $this->collectLineage($teachesRows, $derivedCourses, $subjectIds);
        }

        // Fase C — Criterios; acotados a los cursos de los saberes elegidos (si los hay).
        $assesses = $this->gatherLeaves(self::ASSESSES, $etapaIds, $subjectNames, $courseIds);
        if ($derivedCourses) {
            $assesses = array_values(array_filter(
                $assesses,
                static fn (array $c): bool => isset($derivedCourses[(int) ($c['courseId'] ?? 0)])
            ));
        }
        $assessesRows = $this->selectRows('Criterios de evaluación', $assesses, $fine, self::ASSESSES);
        if ($assessesRows) {
            $result[self::ASSESSES] = array_map(static fn (array $c): int => (int) $c['id'], $assessesRows);
            $this->collectLineage($assessesRows, $derivedCourses, $subjectIds);
        }

        // Fase D — Derivación: curso y materia = padres reales de las hojas.
        if ($derivedCourses) {
            $result['lrmi:educationalLevel'] = array_keys($derivedCourses);
        }
        if ($subjectIds) {
            $result['schema:about'] = array_keys($subjectIds);
        }

        return $result;
    }

    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * Justificación por saber/criterio elegido (TASK-023). Solo lrmi:teaches/
     * lrmi:assesses; poblado durante classify().
     *
     * @return array<string,array<int,string>> dimensión => {itemId => texto}
     */
    public function getJustifications(): array
    {
        return $this->justifications;
    }

    public function clearTrace(): void
    {
        $this->trace = [];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return int[] índices 1-based devueltos por el LLM
     */
    private function ask(
        array $candidates,
        string $label,
        string $content,
        int $maxSelections,
        string $guidance = ''
    ): array {
        $prompt = $this->prompts->buildSelectionPrompt($label, $candidates, $content, $maxSelections, $guidance);
        // Perfil de inferencia compartido: temperatura solo si está configurada
        // (los Opus 4.6+ la rechazan); se traza para comparar entre proveedores.
        $options = ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $startedAt = microtime(true);
        $response = $this->llm->chat([['role' => 'user', 'content' => $prompt['user']]], $options);
        $indices = $this->parser->parseIndices($response->text());
        $this->trace[] = [
            'step' => $label,
            'candidates' => count($candidates),
            'usage' => LlmUsage::of($response, $startedAt),
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'llm_options' => array_diff_key($options, ['system' => '']),
            'response' => $response->text(),
            'selected_indices' => $indices,
        ];
        return $indices;
    }

    /**
     * Etapas elegidas (multi): acotan el contexto, no se escriben.
     *
     * @param array<int,array{id:int,title:string}> $candidates
     * @return int[]
     */
    private function pickEtapaIds(array $candidates, string $content): array
    {
        if (!$candidates) {
            return [];
        }
        return $this->mapIndicesToIds(
            $this->ask($candidates, 'Etapa educativa', $content, 0, self::ETAPA_GUIDANCE),
            $candidates
        );
    }

    /**
     * Une las familias de materia de todas las etapas elegidas, dedup por nombre,
     * con la unión de sus cursos (TASK-056).
     *
     * @param int[] $etapaIds
     * @return array<string,array{name:string,courses:array<int,string>}> por nombre; cursos id => título
     */
    private function gatherFamilies(array $etapaIds): array
    {
        $families = [];
        foreach ($etapaIds as $etapaId) {
            foreach ($this->resolver->listSubjectFamilies($etapaId) as $family) {
                $name = trim((string) ($family['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $families[$name] ??= ['name' => $name, 'courses' => []];
                foreach ((array) ($family['courses'] ?? []) as $course) {
                    $id = (int) ($course['id'] ?? 0);
                    if ($id > 0) {
                        $families[$name]['courses'][$id] = trim((string) ($course['title'] ?? ''));
                    }
                }
            }
        }
        return $families;
    }

    /**
     * Materias elegidas (multi). Cada candidata lleva sus cursos (TASK-056):
     * «Tecnología (4º ESO)» frente a «Tecnología e Ingeniería I (1º Bachillerato)».
     *
     * @param array<string,array{name:string,courses:array<int,string>}> $families
     * @return string[]
     */
    private function pickSubjectNames(array $families, string $content): array
    {
        if (!$families) {
            return [];
        }
        $families = array_values($families);
        $candidates = array_map(static function (array $f): array {
            $courses = self::courseRange($f['courses']);
            return ['title' => '' === $courses ? $f['name'] : $f['name'] . ' (' . $courses . ')'];
        }, $families);
        $names = [];
        foreach ($this->ask($candidates, 'Materia (asignatura)', $content, 0) as $idx) {
            $pos = $idx - 1;
            if (isset($families[$pos])) {
                $names[(string) $families[$pos]['name']] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * Cursos plausibles (Fase A.3, TASK-056): entre los cursos de las materias
     * elegidas. Con uno o ninguno no hay nada que elegir y no se llama al LLM.
     * Si el LLM no elige ninguno, se usan todos (sin pérdida de cobertura).
     *
     * @param array<string,array{name:string,courses:array<int,string>}> $families
     * @param string[] $subjectNames
     * @return int[] cursos que acotan las hojas; vacío = sin acotar
     */
    private function pickCourseIds(array $families, array $subjectNames, string $content): array
    {
        $courses = [];
        foreach ($subjectNames as $name) {
            $courses += $families[$name]['courses'] ?? [];
        }
        $ordered = CurriculumOrder::sortCourses(array_map(
            static fn (string $title): array => ['label' => $title],
            $courses
        ));
        if (count($ordered) <= 1) {
            return $ordered;
        }
        $candidates = array_map(static fn (int $id): array => ['id' => $id, 'title' => $courses[$id]], $ordered);
        $chosen = $this->mapIndicesToIds(
            $this->ask($candidates, 'Curso', $content, 0, self::COURSE_GUIDANCE),
            $candidates
        );
        return $chosen ?: $ordered;
    }

    /**
     * Cursos de una materia en forma compacta y en orden curricular:
     * «1º–3º ESO», «1º, 3º ESO», «4º Infantil de 3 años, 5º Infantil de 4 años».
     *
     * @param array<int,string> $courses id => título
     */
    private static function courseRange(array $courses): string
    {
        $titles = [];
        $labels = array_map(static fn (string $t): array => ['label' => $t], $courses);
        foreach (CurriculumOrder::sortCourses($labels) as $id) {
            $titles[] = $courses[$id];
        }
        $groups = [];
        foreach ($titles as $title) {
            if (1 === preg_match('/^\s*(\d+)º\s+(.+)$/u', $title, $m)) {
                $groups[$m[2]][] = (int) $m[1];
            } else {
                $groups[$title] = $groups[$title] ?? [];
            }
        }
        $parts = [];
        foreach ($groups as $suffix => $ordinals) {
            if (!$ordinals) {
                $parts[] = $suffix;
                continue;
            }
            $runs = [];
            foreach ($ordinals as $n) {
                $last = count($runs) - 1;
                if ($last >= 0 && $runs[$last][1] === $n - 1) {
                    $runs[$last][1] = $n;
                } else {
                    $runs[] = [$n, $n];
                }
            }
            $parts[] = implode(', ', array_map(
                static fn (array $r): string => $r[0] === $r[1] ? $r[0] . 'º' : $r[0] . 'º–' . $r[1] . 'º',
                $runs
            )) . ' ' . $suffix;
        }
        return implode(', ', $parts);
    }

    /**
     * Reúne las hojas de una dimensión cruzando etapas×materias, acotadas a los
     * cursos de la Fase A.3; dedup por id. Si superan LEAF_CAP (coste de tokens),
     * el cupo se reparte a partes iguales entre los pares (materia, curso) en vez
     * de cortar en orden (TASK-056): antes las primeras etapas llenaban el cupo y
     * la materia correcta no llegaba al LLM. El recorte queda en la traza.
     *
     * @param int[] $etapaIds
     * @param string[] $subjectNames
     * @param int[] $courseIds
     * @return array<int,array<string,mixed>>
     */
    private function gatherLeaves(string $dimension, array $etapaIds, array $subjectNames, array $courseIds): array
    {
        $merged = [];
        foreach ($etapaIds as $etapaId) {
            foreach ($subjectNames as $subjectName) {
                foreach ($this->resolver->listLeaves($dimension, $etapaId, $subjectName, $courseIds) as $leaf) {
                    $id = (int) ($leaf['id'] ?? 0);
                    if ($id > 0 && !isset($merged[$id])) {
                        $merged[$id] = $leaf + ['subjectName' => $subjectName];
                    }
                }
            }
        }
        $this->gathered[$dimension] = array_keys($merged);
        if (count($merged) <= self::LEAF_CAP) {
            return array_values($merged);
        }

        $groups = [];
        foreach ($merged as $id => $leaf) {
            $groups[$leaf['subjectName'] . "\u{1F}" . (int) ($leaf['courseId'] ?? 0)][] = $id;
        }
        $kept = [];
        while (count($kept) < self::LEAF_CAP) {
            foreach ($groups as $key => &$ids) {
                if (count($kept) >= self::LEAF_CAP) {
                    break;
                }
                if ($ids) {
                    $kept[array_shift($ids)] = true;
                }
            }
            unset($ids);
        }
        $this->trace[] = [
            'step' => 'leaf_cap', 'dimension' => $dimension, 'available' => count($merged), 'kept' => self::LEAF_CAP,
        ];
        // Orden original de las hojas: el reparto decide QUÉ entra, no el orden.
        return array_values(array_intersect_key($merged, $kept));
    }

    /**
     * Acumula el linaje (curso/materia) de las filas elegidas como conjuntos.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,bool> $courseIds
     * @param array<int,bool> $subjectIds
     */
    private function collectLineage(array $rows, array &$courseIds, array &$subjectIds): void
    {
        foreach ($rows as $c) {
            $cId = (int) ($c['courseId'] ?? 0);
            $sId = (int) ($c['subjectId'] ?? 0);
            if ($cId > 0) {
                $courseIds[$cId] = true;
            }
            if ($sId > 0) {
                $subjectIds[$sId] = true;
            }
        }
    }

    /**
     * Selección de hojas (saberes/criterios) CON justificación (TASK-023): pide al
     * LLM el porqué de cada elección y lo guarda por itemId. Devuelve las filas
     * elegidas (igual que antes); la justificación queda en $this->justifications.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>> filas elegidas
     */
    private function selectRows(string $label, array $candidates, string $content, string $dimension): array
    {
        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $candidates = array_values($candidates);
        $extra = [];
        if (!$candidates) {
            $this->trace[] = ['step' => $label, 'candidates' => 0];
            $rows = [];
        } else {
            $rows = null;
            if (null !== $this->leafSelector) {
                // TASK-062: Jev elige con una pregunta sí/no por candidato. Si falla,
                // el paso cae a la selección con el LLM: la propuesta no se pierde.
                try {
                    $rows = $this->selectWithDecisionModel($label, $candidates, $content, $dimension);
                    $extra = ['strategy' => 'jev'];
                } catch (\Throwable $e) {
                    $extra = ['decision_error' => mb_substr($e->getMessage(), 0, 200)];
                }
            }
            if (null === $rows) {
                $map = $this->askWithReasons($candidates, $label, $content);
                $this->captureJustifications($dimension, $map, $candidates);
                $rows = $this->mapIndicesToRows(array_keys($map), $candidates);
                $extra += ['strategy' => 'llm'];
            }
        }
        // TASK-059: qué hojas se reunieron, cuáles vio el modelo y cuáles eligió.
        $last = array_key_last($this->trace);
        $this->trace[$last] += $extra + [
            'gathered_ids' => $this->gathered[$dimension] ?? [],
            'candidate_ids' => $ids($candidates),
            'selected_ids' => $ids($rows),
        ];
        return $rows;
    }

    /**
     * Selección por modelo de decisiones (TASK-062). Una entrada de traza por
     * petición (con su uso, para coste y latencia) y, al final, la del paso con
     * la P(sí) de TODOS los candidatos, que permite barrer umbrales sin volver
     * a llamar. La justificación de cada elegido es su probabilidad.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function selectWithDecisionModel(
        string $label,
        array $candidates,
        string $content,
        string $dimension
    ): array {
        $out = $this->leafSelector->select($content, $candidates, $dimension);
        foreach ($out['usage'] as $usage) {
            $this->trace[] = ['step' => 'jev_request', 'dimension' => $dimension, 'usage' => $usage];
        }
        $this->trace[] = [
            'step' => $label,
            'candidates' => count($candidates),
            'threshold' => $this->leafSelector->threshold(),
            'probabilities' => $out['probabilities'],
        ];
        foreach ($out['selected'] as $row) {
            $id = (int) $row['id'];
            $this->justifications[$dimension][$id] ??= sprintf('Jev P = %.2f', $out['probabilities'][$id]);
        }
        return $out['selected'];
    }

    /**
     * Como ask() pero pidiendo justificación por candidato (contrato con "why").
     * Devuelve el mapa índice 1-based => justificación; traza igual que ask().
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,string>
     */
    private function askWithReasons(array $candidates, string $label, string $content): array
    {
        $prompt = $this->prompts->buildSelectionPrompt($label, $candidates, $content, 0, '', true);
        $options = ['system' => $prompt['system'], 'json' => true, 'max_tokens' => $this->maxTokens];
        if (null !== $this->temperature) {
            $options['temperature'] = $this->temperature;
        }
        $startedAt = microtime(true);
        $response = $this->llm->chat([['role' => 'user', 'content' => $prompt['user']]], $options);
        $map = $this->parser->parseSelections($response->text());
        $this->trace[] = [
            'step' => $label,
            'candidates' => count($candidates),
            'usage' => LlmUsage::of($response, $startedAt),
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'llm_options' => array_diff_key($options, ['system' => '']),
            'response' => $response->text(),
            'selected_indices' => array_keys($map),
        ];
        return $map;
    }

    /**
     * Guarda la justificación por itemId de la hoja elegida (TASK-023). Respeta el
     * dedup por id de mapIndicesToRows (el primer índice de un id gana) e ignora las
     * justificaciones vacías.
     *
     * @param array<int,string> $map índice 1-based => justificación
     * @param array<int,array<string,mixed>> $candidates
     */
    private function captureJustifications(string $dimension, array $map, array $candidates): void
    {
        $candidates = array_values($candidates);
        foreach ($map as $index => $why) {
            $position = $index - 1;
            if ('' === $why || !isset($candidates[$position])) {
                continue;
            }
            $id = (int) ($candidates[$position]['id'] ?? 0);
            if ($id > 0 && !isset($this->justifications[$dimension][$id])) {
                $this->justifications[$dimension][$id] = $why;
            }
        }
    }

    /**
     * E2: si hay muchos saberes, el LLM elige bloques temáticos y se filtran los
     * candidatos. Fallback: sin bloque elegido → todos (no se pierde cobertura).
     * Desde TASK-056 cada bloque es un par (materia, bloque) etiquetado con sus
     * cursos: dos materias con un bloque del mismo título ya no se funden, y el
     * LLM ve a qué materia y curso pertenece cada uno.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function prefilterByBlock(array $candidates, string $subjectLabel, string $content): array
    {
        $blocks = [];
        foreach ($candidates as $c) {
            $block = trim((string) ($c['block'] ?? ''));
            if ('' === $block) {
                continue;
            }
            $key = self::blockKey($c);
            $blocks[$key] ??= ['subject' => (string) ($c['subjectName'] ?? ''), 'block' => $block, 'courses' => []];
            $courseId = (int) ($c['courseId'] ?? 0);
            if ($courseId > 0) {
                $blocks[$key]['courses'][$courseId] = (string) ($c['courseTitle'] ?? '');
            }
        }
        if (!$blocks) {
            return $candidates;
        }
        $keys = array_keys($blocks);
        $blockCandidates = array_map(static function (array $b): array {
            $where = trim($b['subject'] . ' · ' . self::courseRange($b['courses']), ' ·');
            return ['title' => ('' === $where ? '' : '[' . $where . '] ') . $b['block']];
        }, array_values($blocks));
        $selected = [];
        foreach ($this->ask($blockCandidates, 'Bloques temáticos de ' . $subjectLabel, $content, 0) as $idx) {
            $pos = $idx - 1;
            if (isset($keys[$pos])) {
                $selected[$keys[$pos]] = true;
            }
        }
        if (!$selected) {
            return $candidates;
        }
        return array_values(array_filter(
            $candidates,
            static fn (array $c): bool => isset($selected[self::blockKey($c)])
        ));
    }

    /** @param array<string,mixed> $candidate */
    private static function blockKey(array $candidate): string
    {
        return (string) ($candidate['subjectName'] ?? '') . "\u{1F}" . trim((string) ($candidate['block'] ?? ''));
    }
}
