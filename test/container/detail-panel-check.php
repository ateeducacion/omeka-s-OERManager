<?php

/**
 * Arnés de contenedor del panel de detalle integrado (TASK-032).
 *
 * Cubre lo que ningún test de host puede: `ItemPanelData` depende de
 * `ItemRepresentation` y no es instanciable fuera del contenedor. Su
 * resolución de ancestros, sus medios y su miniatura no los ha ejercitado
 * nadie todavía.
 *
 * La comprobación que más vale de todo el arnés es la 4: para cada REA del
 * catálogo, la suma de los valores en `groups` + `axes` + `orphans` tiene que
 * ser igual al número de valores de alineamiento ENLAZADOS del item. Si un
 * valor desaparece al agrupar, ahí salta — es la promesa que
 * `CurricularGrouping` hace en su propio docblock ("nada se pierde"),
 * verificada contra el catálogo real, no contra datos fabricados.
 *
 * SOLO LECTURA: no escribe nada en el catálogo. Solo lee items vía la API y
 * ejercita ItemPanelData y CurricularGrouping sobre ellos.
 *
 * Uso, desde el contenedor:
 *   php /var/www/html/modules/OERManager/test/container/detail-panel-check.php
 *
 * Sale 1 si alguna comprobación falla, para poder encadenarlo en un smoke test.
 */

require '/var/www/html/bootstrap.php';

use Omeka\Api\Representation\ItemRepresentation;
use OERManager\Service\Governance\CurricularGrouping;
use OERManager\Service\ItemPanelData;
use OERManager\Service\RecatalogService;

$application = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$services = $application->getServiceManager();
$api = $services->get('Omeka\ApiManager');
$panelData = $services->get(ItemPanelData::class);

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

// Igual que en columns-check.php y drawer-details-check.php: distingue «se
// verificó y pasó» de «no se pudo (o no se quiso) verificar».
function skip(string $label, string $motivo): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP $label — $motivo\n";
}

/**
 * Si el item tiene miniatura REAL (derivadas generadas de verdad a partir del
 * contenido del media primario) frente al icono genérico de tipo. Comprobado
 * en el catálogo real: el asset propio del item (`$item->thumbnail()`) NO
 * sirve para distinguirlo — varios items del catálogo, incluso de tipos de
 * medio distintos (p. ej. #4674/#4676/#5045, pdf, y #5047, zip), comparten
 * literalmente el mismo fichero de asset (mismo hash) como icono genérico. La
 * señal real es MediaRepresentation::hasThumbnails(): solo es true cuando
 * Omeka generó derivadas de verdad a partir del fichero (el caso, en este
 * catálogo, de los PDF sin asset propio asignado). Los 9 SCORM
 * (application/zip) nunca la tienen — no pueden tener derivadas.
 * thumbnailDisplayUrls() nunca distingue esto por sí sola: el fallback
 * también es una URL no vacía.
 */
function hasRealThumbnail(ItemRepresentation $item): bool
{
    $primaryMedia = $item->primaryMedia();
    return null !== $primaryMedia && $primaryMedia->hasThumbnails();
}

/**
 * Número de valores de alineamiento ENLAZADOS del item — los que
 * ItemPanelData::alignment() (privado) deja pasar a CurricularGrouping. Los
 * literales (p. ej. 2 REA con literales en schema:about) se descartan aquí
 * igual que allí, con el mismo criterio: valueResource() !== null.
 */
function linkedAlignmentCount(ItemRepresentation $item): int
{
    $count = 0;
    foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
        foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
            if (null !== $value->valueResource()) {
                $count++;
            }
        }
    }
    return $count;
}

/** Recuento de valores que CurricularGrouping::build() reparte en su salida. */
function groupedAlignmentCount(array $alignment): int
{
    $count = count($alignment['axes']) + count($alignment['orphans']);
    foreach ($alignment['groups'] as $group) {
        // El curso ancestro mismo cuenta como un valor de alineamiento (el que
        // fue a lrmi:educationalLevel), además de lo que cuelga de él.
        $count++;
        $count += count($group['subjects']) + count($group['teaches']) + count($group['assesses']);
    }
    return $count;
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

// --- 1. forItem() responde con las cuatro claves sobre un REA real ---------
echo "1. ItemPanelData::forItem() responde sobre un REA real\n";
if (!$items) {
    skip('forItem() trae identity/record/alignment/media', 'no hay ningún REA en el catálogo');
} else {
    $sample = $items[0];
    $data = $panelData->forItem($sample);
    check(
        'trae exactamente las cuatro claves identity/record/alignment/media',
        ['identity', 'record', 'alignment', 'media'] === array_keys($data),
        'claves: ' . implode(', ', array_keys($data))
    );
    check(
        "identity.id coincide con el item consultado (#{$sample->id()})",
        ($data['identity']['id'] ?? null) === (int) $sample->id()
    );
    check(
        'alignment trae las tres claves de CurricularGrouping (groups/axes/orphans)',
        ['groups', 'axes', 'orphans'] === array_keys($data['alignment'])
    );
}

// --- 2. La miniatura se resuelve o es null, nunca cadena vacía -------------
echo "\n2. La miniatura se resuelve o es null, nunca cadena vacía\n";
$emptyStringThumbnails = [];
$realThumbnail = 0;
$genericIcon = 0;
$noThumbnail = 0;
foreach ($items as $item) {
    $thumbnail = $panelData->forItem($item)['identity']['thumbnail'];
    if ('' === $thumbnail) {
        $emptyStringThumbnails[] = (int) $item->id();
    }
    if (null === $thumbnail) {
        $noThumbnail++;
        continue;
    }
    if (hasRealThumbnail($item)) {
        $realThumbnail++;
    } else {
        $genericIcon++;
    }
}
check(
    'ningún REA del catálogo resuelve la miniatura a cadena vacía',
    [] === $emptyStringThumbnails,
    'ids con cadena vacía: ' . implode(', ', $emptyStringThumbnails)
);
printf(
    "   miniatura real=%d  icono genérico=%d  sin miniatura (null)=%d  (spec §7.1 midió 4 de 19)\n",
    $realThumbnail,
    $genericIcon,
    $noThumbnail
);

// --- 3. Los medios traen nombre/tipo/tamaño; #40442 no tiene ninguno -------
echo "\n3. Los medios traen nombre, tipo y tamaño (y #40442 no tiene ninguno)\n";
$withMediaExample = null;
foreach ($items as $item) {
    $mediaList = $panelData->forItem($item)['media'];
    if ($mediaList) {
        $withMediaExample = ['item' => $item, 'media' => $mediaList];
        break;
    }
}
if (null === $withMediaExample) {
    skip('un REA con medios trae nombre/tipo/tamaño no vacíos', 'ningún REA del catálogo tiene medios');
} else {
    $allFilled = true;
    foreach ($withMediaExample['media'] as $one) {
        if ('' === trim((string) $one['title']) || '' === trim((string) $one['type']) || $one['size'] <= 0) {
            $allFilled = false;
            break;
        }
    }
    check(
        sprintf(
            'un REA con medios (#%d) trae nombre/tipo/tamaño no vacíos en cada uno',
            $withMediaExample['item']->id()
        ),
        $allFilled
    );
}

$item40442 = null;
foreach ($items as $item) {
    if (40442 === (int) $item->id()) {
        $item40442 = $item;
        break;
    }
}
if (null === $item40442) {
    skip('#40442 (sin medios) trae lista de medios vacía', 'el item #40442 no está en este catálogo');
} else {
    check(
        '#40442 (sin ningún medio) trae media = []',
        [] === $panelData->forItem($item40442)['media']
    );
}

// --- 4. El agrupamiento no pierde valores -----------------------------------
echo "\n4. El agrupamiento no pierde valores (CurricularGrouping)\n";
$mismatches = [];
foreach ($items as $item) {
    $expected = linkedAlignmentCount($item);
    $alignment = $panelData->forItem($item)['alignment'];
    $actual = groupedAlignmentCount($alignment);
    if ($expected !== $actual) {
        $mismatches[] = sprintf('#%d (enlazados=%d agrupados=%d)', $item->id(), $expected, $actual);
    }
}
check(
    'groups + axes + orphans suma exactamente los valores de alineamiento enlazados, en los ' . count($items)
        . ' REA del catálogo',
    [] === $mismatches,
    implode(' · ', $mismatches)
);

// --- 5. El caso real de los REA con curso sin materia -----------------------
// El contexto de la tarea cita ~7 REA con este caso; columns-check.php mide el
// mismo hecho por otra vía (AlignmentStatus::statusFor() === 'partial') y da
// la cifra real del catálogo en cada momento — se reporta la que ESTE arnés
// mide, sin fijar un número exacto, porque el catálogo puede cambiar.
echo "\n5. Al menos un REA produce un huérfano course-without-subject (ADR-0016)\n";
$unsupportedCourseItems = [];
foreach ($items as $item) {
    $alignment = $panelData->forItem($item)['alignment'];
    foreach ($alignment['orphans'] as $orphan) {
        if (CurricularGrouping::REASON_UNSUPPORTED_COURSE === $orphan['reason']) {
            $unsupportedCourseItems[] = (int) $item->id();
            break;
        }
    }
}
if ([] === $unsupportedCourseItems) {
    skip(
        'al menos un REA tiene un curso que ninguna materia sostiene',
        'ninguno en el catálogo actual — el catálogo cambió y este caso ya no se puede ejercitar aquí'
    );
} else {
    check(
        'al menos un REA produce un huérfano course-without-subject',
        true,
        'REA con el caso: ' . implode(', ', $unsupportedCourseItems)
    );
    printf(
        "   REA con curso huérfano (course-without-subject): %s — %d de %d (contexto de la tarea citaba ~7)\n",
        implode(', ', $unsupportedCourseItems),
        count($unsupportedCourseItems),
        count($items)
    );
}

// --- 6. #40437 produce 4 grupos, cada uno con «Educación Física» -----------
echo "\n6. #40437 produce 4 grupos, uno por curso, cada uno con «Educación Física»\n";
$item40437 = null;
foreach ($items as $item) {
    if (40437 === (int) $item->id()) {
        $item40437 = $item;
        break;
    }
}
if (null === $item40437) {
    skip('#40437 produce 4 grupos con «Educación Física»', 'el item #40437 no está en este catálogo');
} else {
    $groups = $panelData->forItem($item40437)['alignment']['groups'];
    check(
        '#40437 produce exactamente 4 grupos (uno por curso de Primaria)',
        4 === count($groups),
        'grupos encontrados: ' . count($groups)
    );
    $allHaveEducacionFisica = [] !== $groups;
    foreach ($groups as $group) {
        if (!in_array('Educación Física', $group['subjects'], true)) {
            $allHaveEducacionFisica = false;
        }
    }
    check(
        'cada uno de los 4 grupos trae «Educación Física» entre sus materias',
        $allHaveEducacionFisica,
        'materias por grupo: ' . implode(' | ', array_map(
            static fn (array $g): string => implode(',', $g['subjects']),
            $groups
        ))
    );
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "$passed OK, $failed FAIL, $skipped SKIP\n";

exit($failed > 0 ? 1 : 0);
