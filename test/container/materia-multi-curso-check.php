<?php

/**
 * Arnés del defecto reportado el 2026-08-25: con dos Cursos elegidos a la vez
 * (cardinalidad múltiple, PEND-007 — p. ej. 1º Primaria + 2º Bachillerato), el
 * autocompletado de Materia solo ofrecía resultados del PRIMER curso. Causa
 * partida en dos capas, arregladas juntas: el cliente (`termPicker.js
 * getContext()`) solo mandaba el primer chip de cada dimensión, y el servidor
 * (`CurriculumSearch::contextFilter()`) solo sabía filtrar por UN ancestro.
 *
 * `CurriculumSearch` depende de `Omeka\ApiManager` (core real): no se puede
 * instanciar en un test de host (ver «Limitación conocida del arnés»,
 * project-memory.md). La única pieza pura —`normalizeContextIds()`— sí se
 * prueba en host (`test/Service/CurriculumSearchTest.php`); este arnés cubre
 * lo que esa unidad no puede: que `searchDimension()` combine de verdad los
 * resultados de varios ancestros contra el catálogo real.
 *
 * SOLO LECTURA: ninguna llamada aquí escribe en el catálogo.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/materia-multi-curso-check.php
 *
 * Sale 1 si alguna comprobación falla, o si el catálogo real no tiene los dos
 * cursos y las dos materias que el caso necesita (entorno distinto al medido
 * el 2026-08-25; no es un fallo del código, es que no hay con qué probarlo).
 */

require '/var/www/html/bootstrap.php';

use OERManager\Service\CurriculumSearch;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
/** @var CurriculumSearch $curriculumSearch */
$curriculumSearch = $services->get(CurriculumSearch::class);

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    echo "  FAIL $label" . ('' !== $detail ? " — $detail" : '') . "\n";
}

/** Id del primer Curso cuyo título es exactamente $title, o 0. */
function findCursoId($api, string $title): int
{
    $items = $api->search('items', [
        'property' => [
            ['property' => 'dcterms:type', 'type' => 'eq', 'text' => 'Curso'],
            ['property' => 'dcterms:title', 'type' => 'eq', 'text' => $title],
        ],
        'page' => 1,
        'per_page' => 1,
    ])->getContent();
    foreach ($items as $item) {
        return (int) $item->id();
    }
    return 0;
}

$primaria1 = findCursoId($api, '1º Primaria');
$bachillerato2 = findCursoId($api, '2º Bachillerato');

if (0 === $primaria1 || 0 === $bachillerato2) {
    fwrite(STDERR, "No se encuentran los cursos '1º Primaria' / '2º Bachillerato' en este catálogo — arnés no aplicable aquí.\n");
    exit(1);
}

echo "1º Primaria = item $primaria1, 2º Bachillerato = item $bachillerato2\n\n";

// --- El defecto exacto: un solo curso en el contexto se queda corto ---
$soloPrimaria = $curriculumSearch->searchDimension('schema:about', 'mat', ['level' => $primaria1]);
$titulosSoloPrimaria = array_column($soloPrimaria, 'title');
check(
    'Con un solo curso (1º Primaria), "mat" encuentra su Matemáticas',
    in_array('Matemáticas', $titulosSoloPrimaria, true),
    implode(', ', $titulosSoloPrimaria)
);
check(
    'Con un solo curso (1º Primaria), "mat" NO trae Matemáticas II (es de otro curso)',
    !in_array('Matemáticas II', $titulosSoloPrimaria, true),
    implode(', ', $titulosSoloPrimaria)
);

// --- El arreglo: los dos cursos a la vez traen las materias de AMBOS ---
$dosCursos = $curriculumSearch->searchDimension(
    'schema:about',
    'mat',
    ['level' => [$primaria1, $bachillerato2]]
);
$titulosDosCursos = array_column($dosCursos, 'title');
check(
    'Con los dos cursos, "mat" SIGUE trayendo Matemáticas (1º Primaria)',
    in_array('Matemáticas', $titulosDosCursos, true),
    implode(', ', $titulosDosCursos)
);
check(
    'Con los dos cursos, "mat" AHORA TAMBIÉN trae Matemáticas II (2º Bachillerato)',
    in_array('Matemáticas II', $titulosDosCursos, true),
    implode(', ', $titulosDosCursos)
);

// --- Cada resultado lleva el linaje del curso que corresponde, no uno fijo ---
$linajesPorTitulo = [];
foreach ($dosCursos as $r) {
    $linajesPorTitulo[$r['title']] = $r['parentTitle'];
}
check(
    'Matemáticas lleva "1º Primaria" como linaje',
    ($linajesPorTitulo['Matemáticas'] ?? '') === '1º Primaria',
    $linajesPorTitulo['Matemáticas'] ?? '(ausente)'
);
check(
    'Matemáticas II lleva "2º Bachillerato" como linaje, no el de Matemáticas',
    ($linajesPorTitulo['Matemáticas II'] ?? '') === '2º Bachillerato',
    $linajesPorTitulo['Matemáticas II'] ?? '(ausente)'
);

// --- Sin duplicados aunque un id se repita en el contexto ---
$repetido = $curriculumSearch->searchDimension('schema:about', 'mat', ['level' => [$primaria1, $primaria1]]);
$idsRepetido = array_column($repetido, 'id');
check(
    'Un curso repetido en el contexto no duplica resultados',
    count($idsRepetido) === count(array_unique($idsRepetido)),
    implode(', ', $idsRepetido)
);

echo "\n$passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
