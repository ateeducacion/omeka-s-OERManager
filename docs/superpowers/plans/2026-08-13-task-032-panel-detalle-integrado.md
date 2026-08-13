# TASK-032 — Panel de detalle integrado en la tabla

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El detalle del REA deja de ser un cajón lateral que tapa la tabla y pasa a abrirse **dentro de ella**, poniendo lado a lado lo que el REA dice que enseña y los archivos que lo componen.

**Architecture:** La fila de la tabla se expande (`<tr><td colspan>`) y el panel ocupa todo el ancho. Una **sola llamada autenticada** entrega ficha, miniatura, medios, anclaje agrupado e integridad; el historial viaja aparte y solo si se despliega. El agrupamiento curricular lo decide una clase **pura probada en host** (`CurricularGrouping`); el cliente pinta.

**Tech Stack:** PHP 8.4, Omeka-S 4.2, PHPUnit 11.5, `node --test` (sin dependencias ni bundler), PHPCS PSR-12.

**Spec:** `docs/superpowers/specs/2026-08-12-task-032-panel-detalle-integrado-design.md`

## Global Constraints

- **PHP 8.4.** No usar sintaxis de PHP 8.5 aunque el host la acepte.
- **PSR-12.** `make lint` en verde; ninguna línea sobre 120 caracteres. El lint **no** cubre `.phtml`, `.js` ni `test/`: para plantillas `php -l`, para JS `node --check`.
- **Esta tarea no escribe nada en el catálogo.** Ni formularios, ni CSRF, ni properties nuevas. Lo editable es la rebanada 3b. Si un paso te pide escribir, es un error del plan: para y repórtalo.
- **Sin tablas Doctrine propias** (NFR-002); **extender el core, nunca parchearlo** (NFR-001). Sin dependencias nuevas.
- **Frontera de la rebanada 1:** `asset/js/core/` decide y es **puro** (sin DOM, sin `Omeka.*`, sin `fetch`); `asset/js/ui/` pinta y traduce.
- Cadenas de UI traducibles: `Omeka.jsTranslate(...)` en JS; `// @translate` **en la misma línea que el literal** en PHP.
- **Todo lo interpolado en el DOM, escapado:** `textContent`, **nunca** `innerHTML` con dato del catálogo. En plantilla, el `$escape(...)` que el fichero ya usa.
- **ADR-0014 manda sobre cualquier criterio estético.** Sin tokens CSS nuevos: todo sale de las variables ya declaradas en `oer-master-view.css`.
- **Trampa de especificidad, aprendida el 2026-08-13:** la hoja del admin trae `a:link,a:visited{color:#a91919}`, especificidad **(0,0,1,1)**. Una clase sola —(0,0,1,0)— **no le gana**. Cualquier enlace del módulo que deba ir en tinta necesita repetir `:link` y `:visited` para llegar a (0,0,2,0). Ya mordió una vez.
- **Indentación: 4 espacios** en PHP y en JS, como el resto del repo.
- Comandos desde la raíz, **sin** `docker compose exec`: `make lint`, `make test`, `make test-js`.
- Contenedor: `omeka-s-moduletemplate-omekas-1`, módulo montado por volumen. Se invoca con `docker exec`.
- **Línea base:** 313 tests PHP (713 aserciones), 72 tests JS, lint limpio. No romper ninguno.

## Estructura de ficheros

**Se crean:**

| Fichero | Responsabilidad |
| --- | --- |
| `src/Service/Governance/CurricularGrouping.php` | De los valores del item al modelo agrupado y sus huérfanos. **Pura** |
| `test/Service/Governance/CurricularGroupingTest.php` | |
| `asset/js/core/detailAreas.js` | Qué áreas se pintan y en cuál de sus tres estados. **Pura** |
| `test/js/detailAreas.test.js` | |
| `test/container/detail-panel-check.php` | Arnés de verificación |

**Se modifican:**

| Fichero | Cambio |
| --- | --- |
| `src/Service/RecatalogService.php` | `alignmentFor()`: valores con su curso ancestro, para agrupar |
| `src/Service/ItemPanelData.php` *(nuevo, ver Task 2)* | Medios, miniatura y ficha del panel |
| `src/Controller/Admin/IndexController.php` | `drawer-details` devuelve el panel; `drawer-history` nueva y perezosa |
| `Module.php` | Privilegio `drawer-history` |
| `config/module.config.php` | Factoría del servicio nuevo |
| `asset/js/config.js` | Clave `drawerHistoryUrl` |
| `asset/js/ui/drawer.js` | Abre y cierra la fila; deja de gestionar un diálogo |
| `asset/js/ui/drawerDetails.js` | Pinta las áreas |
| `view/oer-manager/admin/index/index.phtml` | La fila de detalle y su `colspan` |
| `asset/css/oer-master-view.css` | Rejilla y áreas; se retira `.oer-drawer` fijo |
| `docs/` | Cierre de gobierno (Task 9) |

---

## Task 1: `CurricularGrouping` — el agrupamiento, puro

Es el corazón. Se escribe primero porque todo lo demás lo consume.

**Files:**
- Create: `src/Service/Governance/CurricularGrouping.php`
- Test: `test/Service/Governance/CurricularGroupingTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `CurricularGrouping::build(array $alignment): array`.
  - `$alignment`: `array<string, list<array{id:int, title:string, courseId:?int}>>` indexado por término RDF. `courseId` es el **id del curso ancestro** ya resuelto por el llamante; `null` si no tiene o no se pudo resolver. Para los propios cursos, `courseId` es su propio id.
  - Retorno:
    ```php
    [
      'groups' => list<array{
          courseId:int, courseTitle:string,
          subjects:list<string>, teaches:list<string>, assesses:list<string>
      }>,
      'axes' => list<string>,
      'orphans' => list<array{term:string, title:string, reason:string}>,
    ]
    ```

**Reglas que hay que respetar al pie de la letra:**

1. **Un curso forma grupo solo si alguna materia lo sostiene.** Un curso sin materia es huérfano con razón `course-without-subject` — es el caso real de los **7 REA** que desde ADR-0016 degrada el anclaje a *parcial*.
2. **Saberes y criterios cuelgan del CURSO, no de la materia.** ADR-0009 denormaliza su ancestro a `lrmi:educationalAlignment`, que apunta al Curso. Si un curso tuviera dos materias, no hay forma de repartir sus saberes entre ellas: se listan una vez en el grupo del curso.
3. **Los ejes NO se agrupan.** `dcterms:relation` es un vocabulario plano; todos cuelgan del mismo `DefinedTermSet`, así que agruparlos daría un grupo único con rótulo constante. Van en `axes`.
4. **Nada se pierde.** Todo valor que no entre en un grupo ni en `axes` aparece en `orphans` con su razón.
5. **El orden de los grupos es el de aparición de los cursos** en `lrmi:educationalLevel`. Determinista, sin ordenar por título.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/Service/Governance/CurricularGroupingTest.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\CurricularGrouping;
use PHPUnit\Framework\TestCase;

/**
 * Agrupamiento curricular del panel de detalle (TASK-032).
 *
 * El drawer mostraba listas planas por dimensión: con dos cursos mezclados, el
 * curador no podía saber qué saber pertenece a cuál. Medido en el catálogo
 * real: el REA #40437 lista «Educación Física» CUATRO veces seguidas.
 */
final class CurricularGroupingTest extends TestCase
{
    /** @return array{id:int,title:string,courseId:?int} */
    private function value(int $id, string $title, ?int $courseId): array
    {
        return ['id' => $id, 'title' => $title, 'courseId' => $courseId];
    }

    public function testNothingInNothingOut(): void
    {
        $result = CurricularGrouping::build([]);

        $this->assertSame([], $result['groups']);
        $this->assertSame([], $result['axes']);
        $this->assertSame([], $result['orphans']);
    }

    public function testACourseWithItsSubjectFormsAGroup(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología y Geología', 1)],
            'lrmi:teaches' => [$this->value(20, 'B3.1', 1)],
            'lrmi:assesses' => [$this->value(30, 'CE2.1', 1)],
        ]);

        $this->assertCount(1, $result['groups']);
        $this->assertSame(1, $result['groups'][0]['courseId']);
        $this->assertSame('3º ESO', $result['groups'][0]['courseTitle']);
        $this->assertSame(['Biología y Geología'], $result['groups'][0]['subjects']);
        $this->assertSame(['B3.1'], $result['groups'][0]['teaches']);
        $this->assertSame(['CE2.1'], $result['groups'][0]['assesses']);
        $this->assertSame([], $result['orphans']);
    }

    /** El defecto que motiva la tarea: dos cursos mezclados en listas planas. */
    public function testTwoCoursesKeepTheirOwnValues(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1), $this->value(2, '1º Bach', 2)],
            'schema:about' => [$this->value(10, 'Biología', 1), $this->value(11, 'Matemáticas I', 2)],
            'lrmi:teaches' => [$this->value(20, 'B3.1', 1), $this->value(21, 'SBII.2.3', 2)],
        ]);

        $this->assertCount(2, $result['groups']);
        $this->assertSame(['B3.1'], $result['groups'][0]['teaches']);
        $this->assertSame(['SBII.2.3'], $result['groups'][1]['teaches']);
        $this->assertSame(['Matemáticas I'], $result['groups'][1]['subjects']);
    }

    public function testGroupOrderFollowsTheDeclaredCourses(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(2, '1º Bach', 2), $this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1), $this->value(11, 'Matemáticas I', 2)],
        ]);

        $this->assertSame(['1º Bach', '3º ESO'], array_column($result['groups'], 'courseTitle'));
    }

    /** El caso real de los 7 REA (ADR-0016). */
    public function testACourseWithoutSubjectIsAnOrphanAndFormsNoGroup(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1), $this->value(9, '4º ESO', 9)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
        ]);

        $this->assertSame(['3º ESO'], array_column($result['groups'], 'courseTitle'));
        $this->assertSame(
            [['term' => 'lrmi:educationalLevel', 'title' => '4º ESO', 'reason' => 'course-without-subject']],
            $result['orphans']
        );
    }

    public function testAValueWhoseCourseIsNotDeclaredIsAnOrphan(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'lrmi:teaches' => [$this->value(20, 'Suelto', 77)],
        ]);

        $this->assertSame([], $result['groups'][0]['teaches']);
        $this->assertSame(
            [['term' => 'lrmi:teaches', 'title' => 'Suelto', 'reason' => 'course-not-declared']],
            $result['orphans']
        );
    }

    public function testAValueWithoutResolvedCourseIsAnOrphan(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'lrmi:assesses' => [$this->value(30, 'Sin curso', null)],
        ]);

        $this->assertSame(
            [['term' => 'lrmi:assesses', 'title' => 'Sin curso', 'reason' => 'course-not-declared']],
            $result['orphans']
        );
    }

    /** Los saberes de un curso huérfano no desaparecen: caen a huérfanos. */
    public function testValuesOfAnUnsupportedCourseFallToOrphans(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(9, '4º ESO', 9)],
            'lrmi:teaches' => [$this->value(20, 'B4.1', 9)],
        ]);

        $this->assertSame([], $result['groups']);
        $this->assertSame(
            ['4º ESO', 'B4.1'],
            array_column($result['orphans'], 'title')
        );
    }

    public function testAxesAreNeverGrouped(): void
    {
        $result = CurricularGrouping::build([
            'lrmi:educationalLevel' => [$this->value(1, '3º ESO', 1)],
            'schema:about' => [$this->value(10, 'Biología', 1)],
            'dcterms:relation' => [$this->value(40, 'Patrimonio', null), $this->value(41, 'Sostenibilidad', null)],
        ]);

        $this->assertSame(['Patrimonio', 'Sostenibilidad'], $result['axes']);
        $this->assertSame([], $result['orphans']);
    }

    /** Cuatro cursos con la misma materia: el REA #40437 del catálogo real. */
    public function testTheSameSubjectTitleInFourCoursesGivesFourGroups(): void
    {
        $alignment = ['lrmi:educationalLevel' => [], 'schema:about' => []];
        foreach ([1, 2, 5, 6] as $n) {
            $alignment['lrmi:educationalLevel'][] = $this->value($n, "{$n}º Primaria", $n);
            $alignment['schema:about'][] = $this->value(100 + $n, 'Educación Física', $n);
        }

        $result = CurricularGrouping::build($alignment);

        $this->assertCount(4, $result['groups']);
        $this->assertSame(
            ['1º Primaria', '2º Primaria', '5º Primaria', '6º Primaria'],
            array_column($result['groups'], 'courseTitle')
        );
        foreach ($result['groups'] as $group) {
            $this->assertSame(['Educación Física'], $group['subjects']);
        }
    }
}
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test`
Expected: FAIL — `Class "OERManager\Service\Governance\CurricularGrouping" not found`.

- [ ] **Step 3: Implementación mínima**

Crear `src/Service/Governance/CurricularGrouping.php`:

```php
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

    /** Un valor cuyo curso no está declarado en el item, o no se pudo resolver. */
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

        foreach ([self::SUBJECT_TERM => 'subjects',
                  self::TEACHES_TERM => 'teaches',
                  self::ASSESSES_TERM => 'assesses'] as $term => $bucket) {
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
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `make test && make lint`
Expected: PASS, 10 tests nuevos.

**Ojo con un test concreto:** `testValuesOfAnUnsupportedCourseFallToOrphans` espera los huérfanos en el orden `['4º ESO', 'B4.1']` — primero el curso (bucle de cursos), luego el saber (bucle de valores). Si tu implementación los produce en otro orden, **no cambies el test sin pensarlo**: el orden es parte del contrato que la UI pinta.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Governance/CurricularGrouping.php test/Service/Governance/CurricularGroupingTest.php
git commit -m "feat(panel): agrupamiento curricular puro con sus huerfanos"
```

---

## Task 2: Los datos del panel — anclaje resuelto, medios y miniatura

**Files:**
- Create: `src/Service/ItemPanelData.php`
- Modify: `config/module.config.php` (factoría del servicio nuevo)

**Interfaces:**
- Consumes: `CurricularGrouping::build()` de Task 1; `RecatalogService::ALIGNMENT_TERMS`.
- Produces: `ItemPanelData::forItem(ItemRepresentation $item): array` con las claves `identity`, `record`, `alignment`, `media`.

**Los tres hechos medidos que obligan a que esto viva en el servidor** (spec §5): `o:thumbnail_display_urls` **no viaja** en el JSON del item; `o:media` solo trae `[{@id, o:id}]` sin nombre ni tipo ni tamaño; y el `fetch` anónimo del drawer rompería con el primer REA privado.

**Verificado en la instalación real, no supuesto:** `$item->thumbnailDisplayUrls()` devuelve `['square'=>…,'medium'=>…,'large'=>…]`; y por medio, `$media->displayTitle()`, `$media->mediaType()`, `$media->size()` y `$media->originalUrl()` existen y responden.

- [ ] **Step 1: Crear el servicio**

Crear `src/Service/ItemPanelData.php`:

```php
<?php

declare(strict_types=1);

namespace OERManager\Service;

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
                    'courseId' => CurricularGrouping::COURSE_TERM === $term
                        ? $id
                        : $this->courseIdOf($target),
                ];
            }
        }
        return $alignment;
    }

    /** Id del curso del que cuelga un item-término, o null si no se resuelve. */
    private function courseIdOf($target): ?int
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
     * @return list<array{title:string, type:string, size:int, url:?string}>
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
            ];
        }
        return $media;
    }
}
```

- [ ] **Step 2: Registrar la factoría**

`ItemPanelData` no recibe dependencias por constructor, así que va en `invokables`, no en `factories`. En `config/module.config.php`, dentro de `service_manager` → `invokables` (línea 48), junto a `IntegrityChecker`:

```php
            Service\ItemPanelData::class => Service\ItemPanelData::class,
```

- [ ] **Step 3: Verificar**

Run: `make lint && make test`
Expected: PASS, sin regresiones (esta tarea no añade tests de host: `ItemPanelData` depende de `ItemRepresentation` y no es instanciable fuera del contenedor — su verificación es el arnés de la Task 8).

Run: `php -r 'var_dump(is_array(include "config/module.config.php"));'`
Expected: `bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add src/Service/ItemPanelData.php config/module.config.php
git commit -m "feat(panel): datos del panel que el JSON-LD publico no puede dar"
```

---

## Task 3: La acción devuelve el panel; el historial se va aparte

**Files:**
- Modify: `src/Controller/Admin/IndexController.php`
- Modify: `Module.php` (privilegio `drawer-history`)
- Modify: `asset/js/config.js` (clave `drawerHistoryUrl`)
- Modify: `view/oer-manager/admin/index/index.phtml` (atributo `data-drawer-history-url`)

**Interfaces:**
- Consumes: `ItemPanelData::forItem()` de Task 2; `IntegrityChecker::check()` y `RecatalogService::history()`, ya existentes.
- Produces:
  - `drawer-details?id=N` → `{panel: {identity, record, alignment, media}, integrity: {status, issues}}`. Sin `history`.
  - `drawer-history?id=N` → `{history: [...]}`.

**Por qué el historial se va a su propia acción** (spec P-6): es la parte cara —una lectura de API por id referenciado— y el propietario pidió que naciera plegado. Cargarlo solo al desplegar acota el coste de abrir una fila.

- [ ] **Step 1: Reescribir `drawerDetailsAction` y añadir `drawerHistoryAction`**

En `src/Controller/Admin/IndexController.php`, sustituir `drawerDetailsAction()` por estas dos acciones:

```php
    /**
     * Panel de detalle completo (TASK-032): ficha, miniatura, medios, anclaje
     * agrupado e integridad, en una sola llamada autenticada.
     *
     * Va por el servidor por obligación, no por comodidad: la miniatura no
     * viaja en el JSON del item, `o:media` solo trae ids sin nombre ni tipo ni
     * tamaño, y el drawer cargaba con `fetch(apiUrl)` SIN autenticar — el
     * primer REA que se pusiera en privado habría dejado de abrir su panel.
     *
     * El historial NO viene aquí: es lo caro y nace plegado (drawer-history).
     *
     * Solo lectura: sin CSRF. La comprobación de enlaces va ENCENDIDA —al
     * contrario que en la tabla— porque aquí es un item a la vez.
     */
    public function drawerDetailsAction()
    {
        $item = $this->panelItem();
        if (null === $item) {
            return new JsonModel(['panel' => null, 'integrity' => null]);
        }

        $result = $this->integrityChecker->check($item, true);

        return new JsonModel([
            'panel' => $this->itemPanelData->forItem($item),
            'integrity' => [
                'status' => $result->getStatus(),
                'issues' => $result->getIssues(),
            ],
        ]);
    }

    /**
     * Historial de curación, servido aparte y bajo demanda (TASK-032, P-6).
     *
     * Es la parte cara del panel —una lectura de API por id referenciado— y
     * nace plegado, así que no se paga al abrir la fila sino al desplegarlo.
     */
    public function drawerHistoryAction()
    {
        $item = $this->panelItem();
        if (null === $item) {
            return new JsonModel(['history' => []]);
        }

        return new JsonModel(['history' => $this->recatalogService->history((int) $item->id())]);
    }

    /** Item de la petición, o null si el id no vale o no se puede leer. */
    private function panelItem()
    {
        $id = (int) $this->params()->fromQuery('id');
        if ($id <= 0) {
            return null;
        }
        try {
            return $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }
```

**Inyección de `ItemPanelData` en el controlador.** El controlador se construye en `config/module.config.php` línea 24, en `controllers` → `factories`, con catorce dependencias por constructor. Añade la decimoquinta **al final**, tras `IntegrityChecker`:

```php
                    $container->get(Service\IntegrityChecker::class),
                    $container->get(Service\ItemPanelData::class)
```

Y en `src/Controller/Admin/IndexController.php`, añade el parámetro correspondiente **al final** de la firma del constructor y su propiedad privada, con la misma forma que tiene `$integrityChecker`. No reordenes los parámetros existentes: la factoría los pasa por posición.

- [ ] **Step 2: Conceder el privilegio**

En `Module.php`, en la lista de privilegios del `allow(['editor', 'site_admin'], ...)` de curación, junto a `'drawer-details'`:

```php
                'drawer-details',
                'drawer-history',
```

**ACL por privilegio, nunca por controlador** — la lección de TASK-029.

- [ ] **Step 3: La URL nueva, por el camino que el repo ya tiene**

En `view/oer-manager/admin/index/index.phtml`, junto a `data-drawer-details-url`:

```php
    data-drawer-history-url="<?php echo $escape($this->url('admin/oer-manager', ['action' => 'drawer-history'])); ?>"
```

En `asset/js/config.js`, junto a `drawerDetailsUrl`:

```js
        drawerHistoryUrl: d.drawerHistoryUrl,
```

- [ ] **Step 4: Verificar**

Run: `make lint && make test && make test-js`
Expected: PASS.

Run: `php -l view/oer-manager/admin/index/index.phtml`
Expected: sin errores.

Run: `docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/acl-check.php`
Expected: sale con 0. Recorre la ACL rol a rol; si el privilegio nuevo quedara fuera o de más, lo dice.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/IndexController.php Module.php asset/js/config.js \
        view/oer-manager/admin/index/index.phtml
git commit -m "feat(panel): la accion devuelve el panel; el historial va aparte y perezoso"
```

---

## Task 4: `detailAreas.js` — qué áreas y en qué estado, puro

**Files:**
- Create: `asset/js/core/detailAreas.js`
- Test: `test/js/detailAreas.test.js`

**Interfaces:**
- Consumes: la respuesta de `drawer-details` de Task 3.
- Produces: `panelAreas(details)` → `[{id, state, ...}]` y las constantes de texto `AREA_LABELS`, `PANEL_ERROR_TEXT`, `MEDIA_EMPTY_TEXT`, `ALIGNMENT_EMPTY_TEXT`.

**El principio que esta pieza codifica**, y que la 3a pagó caro: **«no pude leer» y «no hay nada» nunca comparten pantalla.** Cada área tiene tres estados: `ready`, `empty` y `unknown`.

- [ ] **Step 1: Escribir el test que falla**

Crear `test/js/detailAreas.test.js`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { panelAreas, AREA_LABELS, PANEL_ERROR_TEXT } from '../../asset/js/core/detailAreas.js';

const panel = (extra = {}) => ({
    identity: { id: 1, title: 'REA', isPublic: true, thumbnail: null, editUrl: '/edit/1' },
    record: {},
    alignment: { groups: [], axes: [], orphans: [] },
    media: [],
    ...extra
});

const areaById = (areas, id) => areas.find((a) => a.id === id);

test('sin panel, todas las áreas quedan en estado desconocido', () => {
    const areas = panelAreas({ panel: null, integrity: null });
    assert.ok(areas.length > 0);
    assert.ok(areas.every((a) => a.state === 'unknown'));
});

test('un panel vacío da áreas vacías, no desconocidas', () => {
    const areas = panelAreas({ panel: panel(), integrity: { status: 'ok', issues: [] } });
    assert.equal(areaById(areas, 'media').state, 'empty');
    assert.equal(areaById(areas, 'alignment').state, 'empty');
});

test('el anclaje con grupos queda listo', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [{ courseTitle: '3º ESO' }], axes: [], orphans: [] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('el anclaje solo con huérfanos NO está vacío: hay algo que enseñar', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [], axes: [], orphans: [{ title: '4º ESO' }] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('los ejes solos también cuentan como contenido', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [], axes: ['Patrimonio'], orphans: [] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('con medios, el área de medios queda lista y conserva su lista', () => {
    const media = [{ title: 'guia.pdf', type: 'application/pdf', size: 10, url: '/f/guia.pdf' }];
    const areas = panelAreas({ panel: panel({ media }), integrity: { status: 'ok', issues: [] } });
    const area = areaById(areas, 'media');
    assert.equal(area.state, 'ready');
    assert.deepEqual(area.media, media);
});

test('la integridad no comprobada es desconocida, no sana', () => {
    const areas = panelAreas({ panel: panel(), integrity: null });
    assert.equal(areaById(areas, 'integrity').state, 'unknown');
});

test('el orden de las áreas es estable y empieza por el anclaje', () => {
    const areas = panelAreas({ panel: panel(), integrity: { status: 'ok', issues: [] } });
    assert.deepEqual(areas.map((a) => a.id), ['alignment', 'media', 'record', 'integrity']);
});

test('cada área tiene etiqueta traducible', () => {
    panelAreas({ panel: panel(), integrity: null }).forEach((area) => {
        assert.equal(typeof AREA_LABELS[area.id], 'string');
        assert.ok(AREA_LABELS[area.id].length > 0);
    });
});

test('el texto de error del panel existe', () => {
    assert.equal(typeof PANEL_ERROR_TEXT, 'string');
    assert.ok(PANEL_ERROR_TEXT.length > 0);
});
```

- [ ] **Step 2: Ejecutar y verificar que falla**

Run: `make test-js`
Expected: FAIL — `Cannot find module .../asset/js/core/detailAreas.js`.

- [ ] **Step 3: Implementación mínima**

Crear `asset/js/core/detailAreas.js`:

```js
/**
 * Áreas del panel de detalle y el estado de cada una (TASK-032).
 *
 * Codifica el principio que la rebanada 3a pagó caro: «no pude leer» y «no hay
 * nada» NUNCA comparten pantalla. De ahí tres estados y no dos.
 *
 * Núcleo puro, sin DOM: la frontera que estableció la rebanada 1. Los literales
 * viajan sin traducir; traduce la capa de UI.
 */

/** Orden de presentación. Anclaje y medios primero: son las dos mitades de la
 *  comparación que el curador viene a hacer (spec §6). */
const AREA_ORDER = ['alignment', 'media', 'record', 'integrity'];

export const AREA_LABELS = {
    alignment: 'Anclaje curricular',
    media: 'Medios',
    record: 'Información',
    integrity: 'Integridad'
};

export const PANEL_ERROR_TEXT = 'No se ha podido cargar el detalle de este REA.';
export const MEDIA_EMPTY_TEXT = 'Este REA no tiene ningún medio.';
export const ALIGNMENT_EMPTY_TEXT = 'Este REA no tiene anclaje curricular.';

const READY = 'ready';
const EMPTY = 'empty';
const UNKNOWN = 'unknown';

function alignmentState(alignment) {
    const hasSomething = Boolean(
        (alignment.groups || []).length
        || (alignment.axes || []).length
        || (alignment.orphans || []).length
    );
    return hasSomething ? READY : EMPTY;
}

/**
 * @param {{panel: object|null, integrity: object|null}} details
 * @returns {Array<{id: string, state: string}>}
 */
export function panelAreas(details) {
    const panel = details && details.panel;
    if (!panel) {
        return AREA_ORDER.map((id) => ({ id, state: UNKNOWN }));
    }

    const alignment = panel.alignment || { groups: [], axes: [], orphans: [] };
    const media = panel.media || [];
    const record = panel.record || {};
    const integrity = details.integrity;

    const byId = {
        alignment: { id: 'alignment', state: alignmentState(alignment), alignment },
        media: {
            id: 'media',
            state: media.length ? READY : EMPTY,
            media
        },
        record: {
            id: 'record',
            state: Object.keys(record).length ? READY : EMPTY,
            record
        },
        integrity: {
            id: 'integrity',
            state: integrity ? READY : UNKNOWN,
            integrity
        }
    };

    return AREA_ORDER.map((id) => byId[id]);
}
```

- [ ] **Step 4: Ejecutar y verificar que pasa**

Run: `make test-js`
Expected: PASS, 10 tests nuevos (82 en total).

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/detailAreas.js test/js/detailAreas.test.js
git commit -m "feat(panel): modelo puro de las areas del panel y sus tres estados"
```

---

## Task 5: La fila que se expande

**Files:**
- Modify: `view/oer-manager/admin/index/index.phtml`
- Modify: `asset/js/ui/drawer.js`

**Interfaces:**
- Consumes: nada nuevo.
- Produces: el evento `DRAWER_RENDERED` sigue emitiéndose con `{ itemId, itemJson, content }`, donde `content` pasa a ser el `<td>` de la fila de detalle. **Los consumidores existentes (`drawerDetails.js`, el panel del re-catalogador) no cambian de contrato.**

**El `colspan` NO se puede fijar a mano.** La tabla monta sus columnas con `$this->browse()->renderHeaderRow('oer_items')`, que las toma de la configuración de Omeka: el número varía por instalación. Se calcula en JS contando las celdas de la fila madre.

**Cambia el contrato de accesibilidad, a propósito** (spec §6): al dejar de ser `role="dialog"`, el panel ya no atrapa el foco ni se cierra con Esc por convención de diálogo. A cambio, el curador no pierde el sitio en el listado. El disparador pasa a llevar `aria-expanded`.

- [ ] **Step 1: Retirar el drawer flotante de la plantilla**

En `view/oer-manager/admin/index/index.phtml`, **borrar** el bloque completo:

```php
<div id="oer-drawer" class="oer-drawer" hidden aria-hidden="true" role="dialog" aria-label="...">
    <button type="button" class="oer-drawer-close" aria-label="...">&times;</button>
    <div class="oer-drawer-content"></div>
</div>
```

Y en el `<a class="oer-open-drawer">` de cada fila, añadir el estado inicial:

```php
aria-expanded="false"
```

- [ ] **Step 2: Reescribir la apertura y el cierre**

En `asset/js/ui/drawer.js`, sustituir `drawerElements()`, `openDrawer()`, `closeDrawer()` e `initDrawer()` por:

```js
/** Fila de detalle abierta ahora mismo, o null. Solo una a la vez (P-7). */
let openRow = null;

function closeDetail() {
    if (!openRow) {
        return;
    }
    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${openRow.dataset.itemId}"]`);
    openRow.remove();
    openRow = null;
    if (opener) {
        opener.setAttribute('aria-expanded', 'false');
        opener.focus();
    }
}

/**
 * Inserta la fila de detalle justo debajo de su fila madre.
 *
 * El colspan se cuenta de la propia fila: la tabla monta sus columnas con
 * `renderHeaderRow('oer_items')`, que las toma de la configuración de Omeka, y
 * fijarlo a mano rompería en cuanto alguien añada o quite una columna.
 */
function detailRowFor(row, itemId) {
    const detail = document.createElement('tr');
    detail.className = 'oer-detail-row';
    detail.dataset.itemId = itemId;
    // El riel de la fila madre continúa en la de detalle (ADR-0014 regla 4):
    // cortarlo dejaría el canto roto justo en la fila que se está mirando.
    detail.dataset.integrity = row.dataset.integrity || 'ok';

    const cell = document.createElement('td');
    cell.colSpan = row.cells.length;
    cell.className = 'oer-detail-cell';
    detail.appendChild(cell);

    row.parentNode.insertBefore(detail, row.nextSibling);
    return { detail, cell };
}

export function openDrawer(apiUrl, itemId) {
    const row = document.querySelector(`tr[data-resource-id="${itemId}"]`);
    if (!row) {
        return;
    }

    const wasOpen = openRow && openRow.dataset.itemId === String(itemId);
    closeDetail();
    if (wasOpen) {
        return; // segundo clic sobre la misma fila: se pliega
    }

    const { detail, cell } = detailRowFor(row, itemId);
    openRow = detail;

    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
    if (opener) {
        opener.setAttribute('aria-expanded', 'true');
    }

    cell.textContent = Omeka.jsTranslate('Cargando…');

    fetch(apiUrl, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`item respondió ${response.status}`);
            }
            return response.json();
        })
        .then((itemJson) => {
            cell.textContent = '';
            document.dispatchEvent(new CustomEvent(DRAWER_RENDERED, {
                detail: { itemId, itemJson, content: cell }
            }));
        })
        .catch(() => {
            cell.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle.');
        });
}

export function initDrawer(config) {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('.oer-open-drawer');
        if (opener) {
            event.preventDefault();
            const row = opener.closest('tr');
            openDrawer(row ? row.dataset.apiUrl : '', opener.dataset.itemId);
            return;
        }
        if (event.target.closest('.oer-detail-close')) {
            closeDetail();
        }
    });

    document.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            closeDetail();
        }
    });
}
```

**Y borra de `drawer.js` todo lo que solo servía al cajón:** `buildContent()`, el `<dl>` de los nueve campos, el enlace al editor y el párrafo de medios. Esa información la pinta ahora `drawerDetails.js` desde el panel del servidor. `drawerModel.js` deja de importarse aquí.

- [ ] **Step 3: Verificar**

Run: `make lint && make test && make test-js`
Expected: PASS.

Run: `node --check asset/js/ui/drawer.js`
Expected: sin errores.

Run: `php -l view/oer-manager/admin/index/index.phtml`
Expected: sin errores.

**Comprobación de que no queda nada colgando:**

Run: `grep -rn "oer-drawer-content\|oer-drawer-close\|getElementById('oer-drawer')" asset/ view/`
Expected: **sin resultados**. Si aparece alguno, ese código quedó huérfano al retirar el cajón.

- [ ] **Step 4: Commit**

```bash
git add asset/js/ui/drawer.js view/oer-manager/admin/index/index.phtml
git commit -m "feat(panel): el detalle se abre dentro de la tabla, no sobre ella"
```

---

## Task 6: Pintar las áreas

**Files:**
- Modify: `asset/js/ui/drawerDetails.js`

**Interfaces:**
- Consumes: `panelAreas`, `AREA_LABELS`, `PANEL_ERROR_TEXT`, `MEDIA_EMPTY_TEXT`, `ALIGNMENT_EMPTY_TEXT` de Task 4; `integrityGroups`, `integrityChecked`, `INTEGRITY_OK_TEXT`, `INTEGRITY_UNKNOWN_TEXT` y `TERM_LABELS`, ya existentes; `historyRows` y `HISTORY_EMPTY_NOTICE`, ya existentes.
- Produces: nada que consuma otra tarea.

**Lo que se conserva tal cual de la 3a, porque ya está probado y revisado:** `renderIntegrity` con sus encabezados de severidad y su etiqueta de campo; `renderChange` y `renderHistory` con el «ver porqué» colapsado. **No los reescribas**: se mueven de sitio, no de forma.

- [ ] **Step 1: Cabecera, áreas y medios**

En `asset/js/ui/drawerDetails.js`, añadir estas funciones (y los `import` correspondientes de `detailAreas.js`):

```js
function areaSection(area) {
    const section = document.createElement('section');
    section.className = `oer-area oer-area-${area.id}`;
    section.appendChild(heading(Omeka.jsTranslate(AREA_LABELS[area.id]), 'h4'));
    return section;
}

function renderHeader(identity) {
    const header = document.createElement('div');
    header.className = 'oer-panel-header';

    // La miniatura ancla la fila y distingue el tipo, pero NO verifica
    // contenido: solo 4 de los 19 REA tienen derivadas reales; en el resto
    // Omeka devuelve su icono genérico (spec §7.1). Si falta, se pinta un hueco
    // que se lee como ausencia, no un icono de imagen rota.
    const thumb = document.createElement('span');
    thumb.className = 'oer-panel-thumb';
    if (identity.thumbnail) {
        const img = document.createElement('img');
        img.src = identity.thumbnail;
        img.alt = '';
        thumb.appendChild(img);
    } else {
        thumb.classList.add('oer-panel-thumb-none');
    }
    header.appendChild(thumb);

    const title = document.createElement('h3');
    title.className = 'oer-panel-title';
    title.textContent = identity.title || Omeka.jsTranslate('(sin título)');
    header.appendChild(title);

    const visibility = document.createElement('span');
    visibility.className = 'oer-panel-visibility';
    visibility.textContent = identity.isPublic
        ? Omeka.jsTranslate('Público')
        : Omeka.jsTranslate('Privado');
    header.appendChild(visibility);

    if (identity.editUrl) {
        const link = document.createElement('a');
        link.className = 'oer-drawer-edit-link';
        link.href = identity.editUrl;
        link.textContent = Omeka.jsTranslate('Abrir en el editor de Omeka');
        header.appendChild(link);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'oer-detail-close';
    close.textContent = '×';
    close.setAttribute('aria-label', Omeka.jsTranslate('Cerrar el detalle'));
    header.appendChild(close);

    return header;
}

function renderAlignment(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        section.appendChild(note(Omeka.jsTranslate(ALIGNMENT_EMPTY_TEXT), 'oer-drawer-empty'));
        return section;
    }

    area.alignment.groups.forEach((group) => {
        const block = document.createElement('div');
        block.className = 'oer-align-group';

        const title = document.createElement('p');
        title.className = 'oer-align-course';
        title.textContent = group.subjects.length
            ? `${group.courseTitle} · ${group.subjects.join(', ')}`
            : group.courseTitle;
        block.appendChild(title);

        [['Saberes', group.teaches], ['Criterios', group.assesses]].forEach(([label, values]) => {
            const line = document.createElement('p');
            line.className = 'oer-align-line';
            line.textContent = `${Omeka.jsTranslate(label)}: ${values.length ? values.join(' · ') : '—'}`;
            block.appendChild(line);
        });

        section.appendChild(block);
    });

    if (area.alignment.axes.length) {
        const axes = document.createElement('p');
        axes.className = 'oer-align-axes';
        axes.textContent = `${Omeka.jsTranslate('Ejes')}: ${area.alignment.axes.join(' · ')}`;
        section.appendChild(axes);
    }

    if (area.alignment.orphans.length) {
        const block = document.createElement('div');
        block.className = 'oer-align-orphans';
        block.appendChild(heading(Omeka.jsTranslate('Sin encajar'), 'h5'));
        area.alignment.orphans.forEach((orphan) => {
            const line = document.createElement('p');
            line.className = 'oer-align-orphan';
            const label = TERM_LABELS[orphan.term] || orphan.term;
            line.textContent = `${label}: ${orphan.title}`;
            block.appendChild(line);
        });
        section.appendChild(block);
    }

    return section;
}

function renderMedia(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        // Un REA sin ningún medio no es un recurso: el aviso va con tratamiento
        // de aviso, no de dato (spec §7).
        section.appendChild(note(Omeka.jsTranslate(MEDIA_EMPTY_TEXT), 'oer-media-none'));
        return section;
    }

    const list = document.createElement('ul');
    list.className = 'oer-media-list';
    area.media.forEach((one) => {
        const item = document.createElement('li');

        const name = document.createElement('span');
        name.className = 'oer-media-name';
        name.textContent = one.title;
        item.appendChild(name);

        const meta = document.createElement('span');
        meta.className = 'oer-media-meta';
        meta.textContent = `${one.type} · ${Math.round(one.size / 1024)} KB`;
        item.appendChild(meta);

        if (one.url) {
            const link = document.createElement('a');
            link.className = 'oer-media-open';
            link.href = one.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = Omeka.jsTranslate('Abrir');
            item.appendChild(link);
        }

        list.appendChild(item);
    });
    section.appendChild(list);
    return section;
}

function renderRecord(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        section.appendChild(note(Omeka.jsTranslate('Sin ficha descriptiva.'), 'oer-drawer-empty'));
        return section;
    }

    const list = document.createElement('dl');
    Object.entries(area.record).forEach(([term, text]) => {
        const key = document.createElement('dt');
        key.textContent = TERM_LABELS[term] || term;
        list.appendChild(key);
        const value = document.createElement('dd');
        value.textContent = text;
        list.appendChild(value);
    });
    section.appendChild(list);
    return section;
}
```

- [ ] **Step 2: El historial, plegado y perezoso**

Añadir en el mismo fichero:

```js
/**
 * El historial nace plegado y su petición NO se lanza hasta que se despliega
 * (P-6): es la parte cara del panel, una lectura de API por id referenciado.
 */
function renderHistoryArea(itemId, historyUrl) {
    const section = document.createElement('section');
    section.className = 'oer-area oer-area-history';

    const box = document.createElement('details');
    box.className = 'oer-history-box';
    const toggle = document.createElement('summary');
    toggle.textContent = Omeka.jsTranslate('Historial de curación');
    box.appendChild(toggle);

    const body = document.createElement('div');
    body.textContent = '';
    box.appendChild(body);

    let loaded = false;
    box.addEventListener('toggle', () => {
        if (!box.open || loaded || !historyUrl) {
            return;
        }
        loaded = true;
        body.textContent = Omeka.jsTranslate('Cargando historial…');
        fetch(`${historyUrl}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`drawer-history respondió ${response.status}`);
                }
                return response.json();
            })
            .then((data) => {
                body.textContent = '';
                body.appendChild(renderHistory(data.history));
            })
            .catch(() => {
                body.textContent = Omeka.jsTranslate('No se ha podido cargar el historial.');
                loaded = false;
            });
    });

    section.appendChild(box);
    return section;
}
```

- [ ] **Step 3: Reescribir `initDrawerDetails`**

Sustituir la función exportada por:

```js
export function initDrawerDetails(config) {
    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        const url = config.drawerDetailsUrl;
        if (!url) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'oer-detail-panel';
        panel.textContent = Omeka.jsTranslate('Cargando detalle…');
        content.appendChild(panel);

        fetch(`${url}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                // Omeka devuelve JSON también en sus errores de API (403 del
                // ACL, 500...): sin este chequeo ese cuerpo se parseaba igual y
                // acababa en la misma pantalla que un REA sano.
                if (!response.ok) {
                    throw new Error(`drawer-details respondió ${response.status}`);
                }
                return response.json();
            })
            .then((details) => {
                panel.textContent = '';
                if (!details.panel) {
                    panel.appendChild(note(Omeka.jsTranslate(PANEL_ERROR_TEXT), 'oer-drawer-empty'));
                    return;
                }

                panel.appendChild(renderHeader(details.panel.identity));

                const grid = document.createElement('div');
                grid.className = 'oer-panel-grid';
                const byId = {};
                panelAreas(details).forEach((area) => {
                    byId[area.id] = area;
                });
                grid.appendChild(renderAlignment(byId.alignment));

                const side = document.createElement('div');
                side.className = 'oer-panel-side';
                side.appendChild(renderMedia(byId.media));
                side.appendChild(renderRecord(byId.record));
                grid.appendChild(side);

                panel.appendChild(grid);
                panel.appendChild(renderIntegrity(byId.integrity.integrity));
                panel.appendChild(renderHistoryArea(itemId, config.drawerHistoryUrl));
            })
            .catch(() => {
                panel.textContent = Omeka.jsTranslate(PANEL_ERROR_TEXT);
            });
    });
}
```

- [ ] **Step 4: Verificar**

Run: `make lint && make test && make test-js`
Expected: PASS.

Run: `node --check asset/js/ui/drawerDetails.js`
Expected: sin errores.

**Autorrevisión obligatoria antes de commitear:**

Run: `grep -n "innerHTML" asset/js/ui/drawerDetails.js`
Expected: **sin resultados**.

Run: `grep -c "Omeka.jsTranslate" asset/js/ui/drawerDetails.js`
Expected: un número ≥ 15. Toda cadena de UI nueva pasa por ahí.

- [ ] **Step 5: Commit**

```bash
git add asset/js/ui/drawerDetails.js
git commit -m "feat(panel): pinta cabecera, anclaje agrupado, medios, ficha e historial perezoso"
```

---

## Task 7: La rejilla y las áreas

**Files:**
- Modify: `asset/css/oer-master-view.css`

**Interfaces:**
- Consumes: las clases que pintan las Tasks 5 y 6.
- Produces: nada.

**Cuatro reglas de ADR-0014 que este CSS tiene que respetar**, y una trampa:

1. **El panel es un estante, no una tarjeta:** fondo `--oer-surface`, y la fila madre marcada con `--oer-select`. Se lee como algo que la tabla abre **dentro de sí**.
2. **El riel continúa:** la fila de detalle hereda el riel de su fila madre.
3. **Áreas con aire, no con cajas:** rótulo en versalitas `--oer-muted` y un único filete entre columnas. Seis marcos convertirían el panel en una hoja de cálculo.
4. **Estado accionable con forma, no solo color.**
5. **La trampa:** `a:link,a:visited{color:#a91919}` del admin **gana a una clase sola**. Todo enlace que deba ir en tinta necesita `:link`/`:visited`.

- [ ] **Step 1: Retirar el cajón**

Borrar de `asset/css/oer-master-view.css` las reglas `.oer-drawer`, `.oer-drawer[hidden]`, `.oer-drawer-close` y `.oer-drawer-close:hover`. **Conservar** `.oer-drawer-content dt/dd`, `.oer-drawer-empty`, `.oer-drawer-edit-link` (con su `:link/:visited`), y todas las de integridad e historial.

- [ ] **Step 2: Añadir la rejilla**

Añadir al final del fichero:

```css
/* --- Panel de detalle integrado en la tabla (TASK-032) --- */

/* Un estante que la tabla abre dentro de sí, no una tarjeta encima
   (ADR-0014 regla 1). El riel de la fila madre continúa aquí: cortarlo
   dejaría el canto roto justo en la fila que se está mirando (regla 4). */
.oer-detail-row > .oer-detail-cell {
    background: var(--oer-surface);
    padding: 1.25em 1.5em;
}

.oer-detail-panel {
    color: var(--oer-ink);
}

.oer-panel-header {
    display: flex;
    align-items: center;
    gap: 0.75em;
    margin-bottom: 1em;
}

.oer-panel-thumb {
    flex: 0 0 auto;
    width: 64px;
    height: 64px;
    border: 1px solid var(--oer-rule);
    background: #fff;
    overflow: hidden;
}

.oer-panel-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Un REA sin miniatura es un dato, no un fallo de carga: hueco que se lee
   como ausencia, no icono de imagen rota. */
.oer-panel-thumb-none {
    background: repeating-linear-gradient(
        45deg, var(--oer-surface), var(--oer-surface) 6px, #fff 6px, #fff 12px
    );
}

.oer-panel-title {
    margin: 0;
    flex: 1 1 auto;
}

.oer-panel-visibility {
    color: var(--oer-muted);
    font-size: 0.9em;
}

.oer-detail-close {
    background: none;
    border: 0;
    cursor: pointer;
    font-size: 1.4em;
    line-height: 1;
    color: var(--oer-muted);
    padding: 0 0.25em;
}

.oer-detail-close:hover,
.oer-detail-close:focus {
    color: var(--oer-accent);
}

/* Anclaje y medios lado a lado: son las dos mitades de la comparación que el
   curador viene a hacer —lo que el REA DICE frente a lo que SUS ARCHIVOS
   son— y hoy están a pantallas de distancia. */
.oer-panel-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 2em;
}

/* Un solo filete entre columnas: el presupuesto se gasta en jerarquía, no en
   cromo (ADR-0014 regla 1). */
.oer-panel-side {
    border-left: 1px solid var(--oer-rule);
    padding-left: 2em;
}

@media (max-width: 900px) {
    .oer-panel-grid {
        grid-template-columns: 1fr;
    }

    .oer-panel-side {
        border-left: 0;
        padding-left: 0;
    }
}

.oer-area {
    margin-bottom: 1.25em;
}

.oer-area > h4 {
    margin: 0 0 0.5em;
    font-size: 0.8em;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--oer-muted);
}

.oer-align-group {
    margin-bottom: 0.9em;
}

.oer-align-course {
    margin: 0 0 0.15em;
    font-weight: bold;
}

.oer-align-line,
.oer-align-axes {
    margin: 0 0 0.15em 1em;
}

/* «Sin encajar» es accionable: forma propia además de color (regla 3). */
.oer-align-orphans {
    margin-top: 0.75em;
    border-left: 3px solid var(--oer-warn);
    padding-left: 0.75em;
}

.oer-align-orphans > h5 {
    margin: 0 0 0.25em;
    color: var(--oer-warn);
}

.oer-align-orphan {
    margin: 0;
}

.oer-media-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.oer-media-list > li {
    display: flex;
    align-items: baseline;
    gap: 0.5em;
    padding: 0.25em 0;
    border-bottom: 1px solid var(--oer-rule);
}

.oer-media-name {
    flex: 1 1 auto;
    word-break: break-word;
}

.oer-media-meta {
    color: var(--oer-muted);
    font-size: 0.85em;
    white-space: nowrap;
}

/* Tinta en reposo, acento en interacción. OJO: `a:link,a:visited` del admin
   tiene especificidad (0,0,1,1) y gana a una clase sola, así que hay que
   repetir :link/:visited para llegar a (0,0,2,0). Ya mordió una vez. */
.oer-media-open:link,
.oer-media-open:visited {
    color: var(--oer-ink);
    text-decoration: underline;
}

.oer-media-open:hover,
.oer-media-open:focus {
    color: var(--oer-accent-hover);
}

/* Un REA sin ningún medio no es un recurso: aviso, no dato. */
.oer-media-none {
    color: var(--oer-warn);
    font-style: italic;
}

.oer-history-box > summary {
    cursor: pointer;
    font-size: 0.8em;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--oer-muted);
}
```

- [ ] **Step 3: Marcar la fila madre abierta**

Añadir también:

```css
.oer-master-view-table tr.oer-row-open > td {
    background: var(--oer-select);
}
```

Y en `asset/js/ui/drawer.js`, dentro de `openDrawer`, añadir `row.classList.add('oer-row-open')` tras insertar la fila, y en `closeDetail` quitarla de la fila madre correspondiente. **Si al implementarlo ves que el selector no casa con el marcado real de la tabla, ajústalo al que casa y dilo en el informe** — no lo dejes sin efecto.

- [ ] **Step 4: Verificar**

Run: `make lint && make test && make test-js`
Expected: PASS.

Run: `grep -c "oer-drawer {" asset/css/oer-master-view.css`
Expected: `0`.

- [ ] **Step 5: Commit**

```bash
git add asset/css/oer-master-view.css asset/js/ui/drawer.js
git commit -m "feat(panel): rejilla de dos columnas, areas con aire y estante en la tabla"
```

---

## Task 8: Arnés de contenedor

**Files:**
- Create: `test/container/detail-panel-check.php`

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: ejecutable de CLI; sale 0 si todo pasa, 1 si no.

**Este arnés NO escribe.** A diferencia del de la 3a, aquí no hay nada que fabricar: todo lo que comprueba es de lectura. **No añadas un modo `--write`.**

- [ ] **Step 1: Leer los arneses hermanos**

Run: `sed -n '1,80p' test/container/drawer-details-check.php`

Fíjate en el patrón de `check()`/`skip()`, el bootstrap y el formato de salida. **Reutilízalo**; no inventes otro.

- [ ] **Step 2: Escribir el arnés**

Crear `test/container/detail-panel-check.php` con el bootstrap de sus hermanos y estas comprobaciones, en este orden:

1. **`ItemPanelData::forItem()` responde sobre un REA real** con las cuatro claves (`identity`, `record`, `alignment`, `media`).
2. **La miniatura se resuelve o es `null`**, nunca una cadena vacía. Declara en la salida cuántos de los REA tienen miniatura **real** frente a icono genérico: el spec §7.1 midió 4 de 19, y si esa proporción cambia conviene enterarse.
3. **Los medios traen nombre, tipo y tamaño** no vacíos para un REA que tenga alguno, y lista vacía para **#40442**, que no tiene ninguno.
4. **El agrupamiento no pierde valores.** Para cada REA del catálogo: la suma de los valores en `groups` + `axes` + `orphans` **es igual** al número de valores de alineamiento enlazados del item. Es la comprobación que más vale de todo el arnés — si un valor desaparece al agrupar, aquí salta.
5. **El caso real de los 7 REA:** al menos uno del catálogo produce un huérfano con razón `course-without-subject`. Si no aparece ninguno, **no falles**: informa, porque significa que el catálogo cambió y ese caso ya no se puede ejercitar aquí.
6. **El REA #40437 produce 4 grupos**, uno por curso, cada uno con «Educación Física» — el caso que motivó la tarea.

- [ ] **Step 3: Ejecutar**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 \
  php /var/www/html/modules/OERManager/test/container/detail-panel-check.php
```
Expected: todas en OK y salida 0.

- [ ] **Step 4: Confirmar que no rompe a los hermanos**

Run:
```bash
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/columns-check.php
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/search-filters-check.php
docker exec omeka-s-moduletemplate-omekas-1 php /var/www/html/modules/OERManager/test/container/drawer-details-check.php
```
Expected: `8 OK, 0 FAIL, 1 SKIP`, `8 OK, 0 FAIL` y `3 OK, 0 FAIL, 3 SKIP`.

- [ ] **Step 5: Commit**

```bash
git add test/container/detail-panel-check.php
git commit -m "test(contenedor): arnes del panel de detalle integrado"
```

---

## Task 9: Cerrar el gobierno

**Files:**
- Modify: `docs/backlog.md`, `docs/project-memory.md`, `docs/traceability.md`

- [ ] **Step 1: Backlog**

En `docs/backlog.md`, fila de **TASK-032**: estado a `hecha`, con lo entregado, las cifras de las suites y **lo declarado como no verificado**.

- [ ] **Step 2: Memoria**

En `docs/project-memory.md`, entrada nueva en «Estado actual». **Incluye los dos datos que gobiernan la tarea:** que solo **4 de los 19 REA** tienen miniatura real —así que la miniatura ancla pero no verifica—, y que el panel se sirve de **una llamada autenticada** porque el JSON-LD público no trae ni miniatura ni metadatos de medios y habría roto con el primer REA privado.

**Registra también la corrección a ADR-0005 §6:** su descripción del drawer como panel lateral queda desactualizada. **No se reescribe** (append-only, ADR-0001).

- [ ] **Step 3: Trazabilidad**

En `docs/traceability.md`, enlazar **RF-002** con TASK-032.

- [ ] **Step 4: Verificación final**

Run: `make lint && make test && make test-js`
Expected: PASS los tres.

- [ ] **Step 5: Commit**

```bash
git add docs/
git commit -m "docs: TASK-032 cerrada, panel de detalle integrado"
```

---

## Autorrevisión del plan

**Cobertura del spec.** §2 alcance: fila expandible (Task 5) · miniatura (Tasks 2, 6) · anclaje agrupado (Tasks 1, 2, 6) · medios abribles (Tasks 2, 6) · historial perezoso (Tasks 3, 6) · integridad como banda (Task 6) · re-catalogador recolocado (**no requiere tarea**: se engancha a `DRAWER_RENDERED`, que la Task 5 conserva con el mismo contrato). §4 agrupamiento y huérfanos → Task 1. §5 los tres hechos → Task 2. §6 layout y accesibilidad → Tasks 5, 7. §7 lenguaje visual → Task 7. §7.1 miniatura → Tasks 6, 8. §8 arquitectura → estructura de ficheros. §9 errores → Task 4 (tres estados) y Tasks 5, 6 (`response.ok`). §11 verificación → Tasks 1, 4 (host), 8 (contenedor); el navegador queda para el propietario, como declara el spec.

**Desviación consciente respecto al spec.** El spec §8.1 preveía que la resolución de la jerarquía viviera en `RecatalogService`. El plan la pone en un servicio nuevo, `ItemPanelData`, por dos motivos: `RecatalogService` ya ronda las 500 líneas y la revisión final de la 3a lo señaló, y esto es **lectura de presentación**, no re-catalogación. `RecatalogService` no se toca en toda la tarea.

**Placeholders.** La Task 8 describe sus seis comprobaciones en prosa a propósito, igual que hizo la 3a: el arnés debe calcarse del patrón de sus hermanos, y transcribir aquí una versión inventada llevaría al implementador a divergir de algo ya probado. El paso 1 le obliga a leerlos antes.

**Consistencia de tipos.** `CurricularGrouping::build(array $alignment): array` se usa con esa firma en `ItemPanelData::forItem()`. Las claves del modelo (`groups`, `axes`, `orphans`; dentro `courseId`, `courseTitle`, `subjects`, `teaches`, `assesses`; y `term`, `title`, `reason`) son idénticas en Tasks 1, 2, 4 y 6. `panelAreas(details)` recibe la respuesta entera —`{panel, integrity}`— y devuelve `{id, state, …}` con los mismos ids en Tasks 4 y 6. `DRAWER_RENDERED` conserva `{itemId, itemJson, content}` en Tasks 5 y 6.

**Riesgo conocido.** La Task 5 retira el `<div id="oer-drawer">` del que dependen el panel del re-catalogador y el de propuesta de IA **por el evento**, no por el nodo — pero ambos escriben dentro de `content`, que ahora es un `<td>`. Si alguno asumía anchura de cajón o `position: fixed`, se verá al mirarlo en el navegador. El `grep` del Step 3 de la Task 5 caza las referencias al nodo; **la anchura solo se caza mirando**.
