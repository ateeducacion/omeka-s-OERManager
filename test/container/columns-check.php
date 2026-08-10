<?php

/**
 * Arnés de contenedor de la rebanada 2 de TASK-028.
 *
 * Comprueba sobre el catálogo REAL lo que ningún test de host puede: que las
 * columnas nuevas rindan lo que el dato manda. En particular los 4 valores
 * literales (schema:about ×3, lrmi:educationalLevel ×1) que hoy pasan por «ok»
 * y deben pasar a aviso (D2/D-3), y el recuento de dead_link que quedó sin
 * medir al escribir el spec.
 *
 * SOLO LECTURA: no escribe nada en el catálogo. Solo lee items vía la API y
 * ejercita IntegrityChecker y ColumnType\Curricular sobre ellos.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/columns-check.php
 *
 * Sale 1 si alguna comprobación falla, para poder encadenarlo en un smoke test.
 */

require '/var/www/html/bootstrap.php';

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$checker = $services->get(OERManager\Service\IntegrityChecker::class);

$passed = 0;
$failed = 0;
$skipped = 0;

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

// A diferencia de check(true), skip() NO puede aportar un OK: existe para las
// comprobaciones que el catálogo actual no permite ejercitar (p. ej. D-6 sin
// ningún REA con plantilla). Un consumidor automatizado que mire «N OK, 0
// FAIL» tiene que poder distinguir «se verificó y pasó» de «no se pudo
// verificar con este dato».
function skip(string $label, string $motivo): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $motivo\n";
}

$classes = $api->search('resource_classes', ['term' => 'lrmi:LearningResource'])->getContent();
if (!$classes) {
    echo "No existe la clase lrmi:LearningResource en esta instalación.\n";
    exit(1);
}

$items = $api->search('items', [
    'resource_class_id' => $classes[0]->id(),
    'per_page' => 500,
])->getContent();

echo 'REA en el catálogo: ' . count($items) . "\n\n";

echo "1. Reglas de integridad\n";

$deadLinks = 0;
$literals = 0;
$missingLicence = 0;
$statuses = ['ok' => 0, 'warning' => 0, 'error' => 0];

foreach ($items as $item) {
    // Con enlaces ENCENDIDOS: es la pasada que mide el dead_link real.
    $result = $checker->check($item, true);
    $statuses[$result->getStatus()]++;
    foreach ($result->getIssues() as $issue) {
        if ('dead_link' === $issue['code']) {
            $deadLinks++;
        }
        if ('literal_in_link_property' === $issue['code']) {
            $literals++;
        }
        if ('missing_license' === $issue['code']) {
            $missingLicence++;
        }
    }
}

echo "   estados: ok={$statuses['ok']} warning={$statuses['warning']} error={$statuses['error']}\n";
echo "   dead_link=$deadLinks  literal_in_link_property=$literals  missing_license=$missingLicence\n";

// D2: el estudio contó 4 valores literales en properties de enlace. Si el dato
// no ha cambiado deben aflorar ahora, cuando antes pasaban por «ok».
check('los valores literales en properties de enlace afloran', $literals > 0,
    "se esperaban ~4 y se han encontrado $literals");

// D-5: el check anterior aquí comparaba $deadLinks con 0, y $deadLinks SIEMPRE
// es 0 por el mismo motivo por el que esta comprobación existe — es el mismo
// defecto que ya se corrigió en la sección 2 de este arnés (comprobación
// tautológica, no podía fallar jamás para ningún catálogo). dead_link es
// inalcanzable POR CONSTRUCCIÓN, no por la FK en cascada del core:
// AbstractResourceEntityRepresentation::values() descarta los valores ocultos
// (enlace con destino colgante) antes de devolverlos, así que ninguno llega
// nunca a IntegrityChecker. Lo que sí se puede verificar es la premisa misma:
// que todo valor de tipo resource que values() devuelve tiene destino vivo.
$resourceValues = 0;
$liveResourceValues = 0;
foreach ($items as $item) {
    foreach ($item->values() as $info) {
        foreach ($info['values'] as $value) {
            if (!str_starts_with($value->type(), 'resource')) {
                continue;
            }
            $resourceValues++;
            if ($value->valueResource()) {
                $liveResourceValues++;
            }
        }
    }
}
check(
    'todo valor de tipo resource que devuelve values() tiene destino vivo (D-5, estructural)',
    $resourceValues === $liveResourceValues,
    "resource_values=$resourceValues vivos=$liveResourceValues"
);
echo "   NOTA: la ausencia de dead_link es ESTRUCTURAL (filtrado de values()), no\n"
    . "   una propiedad de este catálogo ni de la FK en cascada del core. El único\n"
    . "   hueco honesto sería un data type de terceros con nombre 'resource:*' que\n"
    . "   no extendiera AbstractResource.\n";

echo "\n2. D-7: la comprobación de enlaces se puede apagar\n";

// OJO: comparar recuentos de severidad 'warning' con y sin enlaces es
// tautológico — en IntegrityPolicy, $checkLinks solo controla la emisión de
// dead_link, y dead_link es SIEMPRE 'error', nunca 'warning'. Esa comparación
// no podía fallar jamás, para ningún item ni catálogo. Lo que de verdad debe
// verificarse es (a) que los avisos que NO son dead_link son idénticos con y
// sin la comprobación, y (b) que la pasada con enlaces es un superconjunto de
// la pasada sin enlaces —solo puede añadir dead_link, nunca quitar ni añadir
// nada más.
$sample = $items[0];
$withLinks = $checker->check($sample, true);
$withoutLinks = $checker->check($sample, false);

$codesWithLinks = array_column($withLinks->getIssues(), 'code');
$codesWithoutLinks = array_column($withoutLinks->getIssues(), 'code');

$nonDeadWithLinks = array_values(array_filter(
    $codesWithLinks,
    static fn (string $code): bool => 'dead_link' !== $code
));
sort($nonDeadWithLinks);
$sortedWithoutLinks = $codesWithoutLinks;
sort($sortedWithoutLinks);

check('los avisos que no son dead_link son idénticos con y sin comprobar enlaces',
    $nonDeadWithLinks === $sortedWithoutLinks,
    'con=' . json_encode($nonDeadWithLinks) . ' sin=' . json_encode($sortedWithoutLinks));

$deadLinkCountSample = count(array_filter(
    $codesWithLinks,
    static fn (string $code): bool => 'dead_link' === $code
));
check('con enlaces se obtiene un superconjunto de sin enlaces (solo puede añadir dead_link)',
    count($codesWithLinks) === count($codesWithoutLinks) + $deadLinkCountSample,
    'con=' . count($codesWithLinks) . ' sin=' . count($codesWithoutLinks)
    . ' dead_link_de_mas=' . $deadLinkCountSample);

if (0 === $deadLinkCountSample) {
    echo "   NOTA: el item de muestra no tiene dead_link, así que con y sin enlaces\n"
        . "   dan el MISMO conjunto de avisos en esta pasada — no se ha verificado una\n"
        . "   diferencia real entre las dos ramas, solo su ausencia en este dato.\n";
}

echo "\n2b. Coste del interruptor D-7 (dato informativo)\n";

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, false);
}
$cheap = microtime(true) - $start;

$start = microtime(true);
foreach ($items as $item) {
    $checker->check($item, true);
}
$expensive = microtime(true) - $start;

printf("   sin enlaces: %.3f s   con enlaces: %.3f s (n=%d items)\n", $cheap, $expensive, count($items));
// Con 19 items y una tolerancia de 0.01 s, el margen es diez veces mayor que
// la señal medida: esto NO demuestra el ahorro de D-7, solo descarta una
// regresión grosera (p. ej. que apagar los enlaces saliera más caro).
check('sin regresión grosera al encender los enlaces (NO valida el ahorro real de D-7 a esta escala)',
    $cheap <= $expensive + 0.01);
echo "   NOTA: el ahorro real de D-7 se verificó por LECTURA del cortocircuito en\n"
    . "   IntegrityChecker::project() (checkLinks=false evita valueResource(), que\n"
    . "   es la llamada cara), no por esta medición. Sería medible con un catálogo\n"
    . "   de miles de items; con 19 el ruido domina la señal.\n";

echo "\n3. D-6: la plantilla suma, no sustituye\n";

$withTemplate = null;
foreach ($items as $item) {
    if ($item->resourceTemplate()) {
        $withTemplate = $item;
        break;
    }
}

if (null === $withTemplate) {
    skip('un REA con plantilla sigue evaluando la licencia (D-6)',
        'ningún REA del catálogo tiene plantilla asignada todavía — PEND-012 sigue abierto');
} else {
    // D-6: con plantilla, el mínimo SIGUE evaluándose. Antes de la rebanada 2
    // este REA no habría producido missing_license ni missing_alignment jamás,
    // porque la plantilla era una rama excluyente.
    $codes = array_column($checker->check($withTemplate, false)->getIssues(), 'code');
    $hasLicence = (bool) $withTemplate->value(OERManager\Service\Governance\IntegrityPolicy::LICENSE_TERM);
    check('un REA con plantilla sigue evaluando la licencia (D-6)',
        $hasLicence === !in_array('missing_license', $codes, true),
        $hasLicence
            ? 'tiene licencia y aun asi se avisa de que falta'
            : 'no tiene licencia y el aviso no aparece: la plantilla la esta silenciando');
}

echo "\n4. Columna Curricular\n";

// Título no vacío de un valor de materia/curso, replicando exactamente lo que
// Curricular::titlesFor() + CurricularSummary::uniqueTitles() hacen: un
// literal usa su propio texto, un enlace usa el título del destino, y en
// ambos casos un título en blanco tras trim() NO cuenta como anclaje —
// CurricularSummary lo descarta antes de llegar al render.
function anchorTitle(Omeka\Api\Representation\ItemRepresentation $item, string $term): string
{
    foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
        $isLiteral = !str_starts_with($value->type(), 'resource');
        $title = $isLiteral
            ? (string) $value->value()
            : (($target = $value->valueResource()) ? (string) $target->displayTitle() : '');
        if ('' !== trim($title)) {
            return trim($title);
        }
    }
    return '';
}

$curricular = new OERManager\ColumnType\Curricular();
$withAnchor = 0;
$rendered = 0;
foreach ($items as $item) {
    // «Tiene anclaje» = renderContent() produciría contenido: al menos un
    // título no vacío en materia o en curso. Es el subconjunto exacto que la
    // etiqueta promete, no «al menos uno rinde en todo el catálogo».
    $hasAnchor = '' !== anchorTitle($item, OERManager\Service\Governance\CurricularPairs::SUBJECT_TERM)
        || '' !== anchorTitle($item, OERManager\Service\Governance\CurricularPairs::STAGE_TERM);
    if ($hasAnchor) {
        $withAnchor++;
    }

    $html = $curricular->renderContent(
        $services->get('ViewRenderer'),
        $item,
        []
    );
    if (null !== $html) {
        $rendered++;
    }
}
check('la celda curricular rinde exactamente en los REA con anclaje (ni más ni menos)',
    $rendered === $withAnchor,
    "ha rendido en $rendered de $withAnchor REA con anclaje (catálogo completo: " . count($items) . ')');

echo "\n5. Curso huérfano ⇒ anclaje parcial (ADR-0005 §4 ampliado 2026-08-10)\n";

// Un nivel educativo que ninguna materia del REA sostiene es un anclaje MAL
// HECHO, no un caso de visualización. Antes de esta regla los 19 REA estaban
// en `complete` y el filtro «parcial» no tenía con qué ejercitarse.
$statuses = ['complete' => 0, 'partial' => 0, 'none' => 0];
$orphanNotPartial = [];
foreach ($items as $item) {
    $status = OERManager\ColumnType\AlignmentStatus::statusFor($item);
    $statuses[$status]++;
    $hasOrphan = [] !== OERManager\Service\Governance\CurricularPairs::of($item)['orphanCourses'];
    if ($hasOrphan && 'complete' === $status) {
        $orphanNotPartial[] = $item->id();
    }
}
echo "   estados: complete={$statuses['complete']} partial={$statuses['partial']} none={$statuses['none']}\n";

check(
    'ningún REA con curso huérfano se cuenta como completo',
    [] === $orphanNotPartial,
    'siguen en complete: ' . implode(', ', $orphanNotPartial)
);

// Guarda contra la regresión que motivó la regla: si TODOS volvieran a estar en
// complete, el filtro «parcial» habría vuelto a quedarse sin datos reales.
check(
    'el filtro «parcial» tiene datos con los que ejercitarse',
    $statuses['partial'] > 0,
    'los ' . count($items) . ' REA vuelven a estar en complete/none'
);

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL, $skipped SKIP\n";

exit($failed > 0 ? 1 : 0);
