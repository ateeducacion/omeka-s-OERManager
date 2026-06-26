# Diseño: Clasificador semántico con candidatos ricos + pre-filtrado por bloques

**Fecha:** 2026-06-26
**Estado:** aprobado para implementación

---

## Contexto

**Problema raíz:** el clasificador IA propone saberes básicos y criterios de evaluación incoherentes porque el LLM recibe como candidatos los `dcterms:title` de esos ítems-término, que son códigos opacos (`SBIG01SBI.1`, `SBIG01CE1.1`...). El LLM no puede hacer clasificación semántica sobre códigos; decide aleatoriamente o por posición en la lista.

**La señal semántica correcta** está en `dcterms:description` de cada ítem-término del currículo:
- Saber básico: 29–375 chars (media ~155 chars ≈ 39 tokens), texto legible con el contenido curricular.
- Criterio de evaluación: 170–500 chars (media ~300 chars ≈ 75 tokens), texto más rico.
- Etapa, Curso, Asignatura: sus `dcterms:title` ya son legibles ("ESO", "1º ESO", "Biología y Geología") — no necesitan description para ser semánticamente claros.

Los saberes también tienen `dcterms:subject` con el nombre del bloque temático ("I. Proyecto científico", "II. Geología"...), que agrupa los saberes y es una señal de clasificación adicional.

---

## Escala del grafo curricular ESO (verificada 2026-06-26)

```
ESO (item 5055)
  └── 4 Cursos
        └── 66 Asignaturas
              ├── 2298 Saberes básicos  (29–375 chars/description, media ~155)
              └──  943 Criterios        (170–500 chars/description, media ~300)
```

Por asignatura: 27–61 saberes (peor caso Matemáticas: 61 en 6 bloques), ~16–18 criterios.

---

## Solución: E1 + E2, con E3 como roadmap

### E1 — Candidatos con descripción semántica (fix del root cause)

Incluir `dcterms:description` y `dcterms:subject` (bloque) en el prompt del LLM para cada candidato.

**Antes (prompt actual para saberes):**
```
1. SBIG01SBI.1
2. SBIG01SBI.2
3. SBIG01SBI.3
```

**Después (con E1):**
```
1. [I. Proyecto científico] Aproximación a los pasos del método científico a través de ejemplos de la vida cotidiana. (SBIG01SBI.1)
2. [I. Proyecto científico] Reconocimiento del error experimental y la incertidumbre como fuente de aprendizaje. (SBIG01SBI.2)
3. [II. Geología] Identificación de los tipos de rocas y minerales principales. (SBIG01SBI.3)
```

Para etapas, cursos, asignaturas y ejes temáticos (donde el título ya es legible), el comportamiento es idéntico al actual: solo se muestra el título.

Coste adicional por clasificación de saberes: ~30 candidatos × ~39 tokens/saber ≈ +1170 tokens — completamente manejable.

### E2 — Pre-filtrado por bloque temático (reducción de contexto)

Después de elegir la asignatura (paso 3), si el número de saberes candidatos supera un umbral (BLOCK_THRESHOLD = 25), se ejecuta un paso intermedio:

1. Extrae los nombres de bloque únicos de los candidatos ya cargados (sin nueva llamada a la API).
2. Hace 1 llamada LLM adicional: "¿Qué bloques temáticos de la asignatura X son relevantes para este recurso?"
3. Filtra los candidatos en PHP por los bloques seleccionados.
4. El LLM de clasificación de saberes solo ve los candidatos de los bloques relevantes.
5. Fallback: si el LLM no selecciona ningún bloque, se usan todos los candidatos (sin filtrar).

E2 **no aplica** a criterios de evaluación: su `dcterms:subject` contiene códigos de competencias clave (CCL1, STEM4...), no nombres de bloque. Los criterios (~16 por asignatura × ~75 tokens) son manejables sin pre-filtrado.

**Cascada actualizada (total: 7 llamadas LLM cuando se activa E2, 6 si no):**
```
Step 1: Etapa           (sin write, acota contexto)
Step 2: Curso           (write: lrmi:educationalLevel)
Step 3: Asignatura      (write: schema:about)
Step 3.5: [si saberes > 25] Selección de bloques temáticos (sin write, filtra candidatos)
Step 4: Saberes básicos (write: lrmi:teaches, candidatos filtrados si E2 activo)
Step 5: Criterios       (write: lrmi:assesses, con descripciones E1)
```

### E3 — Embeddings + reranking LLM (roadmap, TASK-011)

**No implementar ahora.** La interfaz `TermResolverInterface` es el punto de extensión natural.

**Diseño futuro:**
- Pre-computar embeddings de `dcterms:description` de todos los saberes/criterios (~3241 ítems, ~161K tokens totales).
- Nueva implementación `EmbeddingTermResolver implements TermResolverInterface`:
  - En tiempo de clasificación: embed del contenido del recurso → similaridad coseno contra el índice.
  - Devuelve top-K candidatos más similares (K ≈ 15) en lugar de los ~30–60 del filtro jerárquico.
- El LLM de selección final solo ve 15 candidatos muy relevantes → mayor precisión, menor coste.
- Infraestructura mínima: SQLite-vec o índice in-memory JSON refrescado bajo demanda (webhook o cron).
- **Prerrequisito:** E1+E2 en producción con métricas de accuracy basales.

---

## Cambios de implementación

### Archivos a modificar

| Archivo | Cambio |
|---------|--------|
| `src/Service/CurriculumSearch.php` | `mapResults()` extrae `description` y `block`; nuevo helper `firstLiteralValue()` |
| `src/Service/Ai/TermResolverInterface.php` | Docblock: tipo de retorno `array{id,title,description,block}` |
| `src/Service/Ai/PromptBuilder.php` | Firma de `buildSelectionPrompt` acepta candidatos ricos; `formatCandidate()`; nuevo `buildBlockSelectionPrompt()` |
| `src/Service/Ai/CurricularClassifier.php` | `BLOCK_THRESHOLD = 25`; `selectBlocks()`; lógica de pre-filtrado en `classify()`; eliminar `array_map` en `select()` |
| `src/Service/Ai/TagClassifier.php` | Eliminar `array_map`, pasar candidatos directamente |

`IndexSelection.php` y `CurriculumTermResolver.php` no cambian.

### Detalle crítico: `CurriculumSearch::mapResults()`

```php
private function mapResults($items): array
{
    $results = [];
    foreach ($items as $item) {
        $results[] = [
            'id'          => $item->id(),
            'title'       => (string) $item->displayTitle(),
            'description' => $this->firstLiteralValue($item, 'dcterms:description'),
            'block'       => $this->firstLiteralValue($item, 'dcterms:subject'),
        ];
    }
    return $results;
}

private function firstLiteralValue($item, string $term): string
{
    $values = $item->value($term, ['all' => true]);
    foreach ($values as $v) {
        if ('literal' === $v->type()) {
            return (string) $v->value();
        }
    }
    return '';
}
```

Los datos de `dcterms:description` y `dcterms:subject` ya vienen en la respuesta JSON-LD de la API REST de Omeka (la búsqueda retorna ítems completos). No hay llamadas adicionales a la API.

### Detalle crítico: `PromptBuilder::formatCandidate()`

```php
private function formatCandidate(array $c): string
{
    $desc  = trim($c['description'] ?? '');
    $block = trim($c['block'] ?? '');
    $title = trim($c['title'] ?? '');

    if ($desc !== '' && $desc !== $title) {
        $line = $desc;
        if ($block !== '') {
            $line = "[{$block}] {$line}";
        }
        $line .= " ({$title})";
        return $line;
    }
    return $title;
}
```

### Detalle crítico: E2 en `CurricularClassifier::classify()`

```php
// Tras el paso de asignatura, rastrear el título para el prompt de bloques:
if ($step['dimension'] === 'schema:about' && $ids) {
    foreach ($candidates as $c) {
        if ($c['id'] === $ids[0]) {
            $context['about_title'] = $c['title'];
            break;
        }
    }
}

// En el paso lrmi:teaches, antes de buildSelectionPrompt:
if ('lrmi:teaches' === $step['dimension'] && count($candidates) > self::BLOCK_THRESHOLD) {
    $blocks = array_values(array_unique(array_filter(array_column($candidates, 'block'))));
    if ($blocks) {
        $selectedBlocks = $this->selectBlocks((string)($context['about_title'] ?? ''), $blocks, $content);
        if ($selectedBlocks) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn($c) => in_array($c['block'], $selectedBlocks, true)
            ));
        }
        // Si selectedBlocks es vacío: fallback → usar todos los candidatos
    }
}
```

---

## Verificación

1. `make lint` — sin errores PSR-12.
2. Clasificar 2–3 ítems REA desde el admin de Omeka-S y confirmar:
   - Prompt de saberes muestra descripciones + bloques, no códigos.
   - LLM propone saberes semánticamente coherentes con el contenido del recurso.
   - Para Matemáticas (>25 saberes), el paso de bloques se activa correctamente.
   - Ejes temáticos, cursos, asignaturas no cambian de comportamiento.
3. Confirmar que no hay regresiones en `RecatalogService` (solo recibe `int[]` de ids, sin cambio).
