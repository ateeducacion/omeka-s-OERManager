# Preguntas de decisión abiertas — OERManager

> Para que el **propietario** resuelva los `[PENDIENTE]` que bloquean las siguientes fases. Responde inline (bajo cada pregunta o en otro canal); cada bloque resuelto se formaliza después como **ADR** en `docs/decisions/` y se marca el PEND-NNN como Resuelto en [requirements.md](requirements.md). No inventamos estas respuestas (regla de oro del repo).
>
> Estado a 2026-06-12: FASE 0 y FASE 1 cerradas; skill `omeka-module` destilada y arnés de tests activo. Lo que sigue (vista maestra, re-catalogador, integridad, estadísticas, auditoría) depende de lo de abajo.
>
> Estado a 2026-06-15: **PEND-005** (mapeo RDF, ADR-0004), **PEND-006** (auditoría RDF, ADR-0002), **PEND-008** (skills descartadas) y **PEND-009** (paquete/licencia, ADR-0003) resueltos. **Único pendiente: PEND-007.**

---

## PEND-005 — Mapeo de properties RDF (bloquea re-catalogador TASK-004 e integridad TASK-005) ✅ RESUELTO

> **Resuelto (2026-06-15, ADR-0004):** confirmado contra la instalación real. Etapa `lrmi:educationalLevel`, materia `schema:about`, saberes `lrmi:teaches`, criterios `lrmi:assesses`, eje temático/tags `dcterms:relation` (controlado por `schema:DefinedTermSet` en config), proyecto `schema:isPartOf` (acción de gestor), licencia `dcterms:rights` (CustomVocab). Todo `resource:item` salvo la licencia (literal).

Es el bloqueo más crítico: el re-catalogador escribe valores RDF y, si las properties no son las correctas, se rompe la interoperabilidad LRMI. Necesitamos, **confirmado contra vuestra instalación real** (no supuesto):

1. **Niveles de alineamiento curricular y su property exacta.** Para cada nivel que useis (p. ej. etapa, materia, criterio/competencia/saberes), ¿qué property RDF lo representa? Candidatos habituales a confirmar: `dcterms:educationLevel`, `dcterms:subject`, o LRMI `educationalAlignment`/`AlignmentObject`. ¿Una property distinta por nivel, o una sola property con el tipo de alineamiento en el item-término?
2. **Tipo de dato.** Confirmamos que el alineamiento se escribe como **resource value** apuntando al item-término del currículo (data type `resource:item`), ¿correcto?
3. **Tags.** ¿Qué property para las etiquetas y vocabulario **controlado** (lista cerrada de items-término/CustomVocab) vs. **libre** (literal)?
4. **Clase y plantilla.** ¿La clase es exactamente `lrmi:LearningResource`? ¿Hay una `resource_template` de REA que fije las properties obligatorias? (esto alimenta también las reglas de integridad).

> Cómo confirmarlo sin exponer credenciales: desde el panel admin → Vocabularies / Resource templates / un item REA de ejemplo. Si prefieres, autorízame una consulta de solo lectura a la BD del contenedor y lo extraigo yo.

## PEND-006 — Auditoría de curación (bloquea TASK-007) ✅ RESUELTO

> **Resuelto (2026-06-15, ADR-0002):** auditoría **RDF nativa** vía value annotations con `dcterms` (quién `dcterms:contributor`, cuándo `dcterms:modified`, qué `dcterms:provenance`); sin módulo Log ni tablas propias. La **visibilidad** se gestiona nativa pero queda **fuera** de la auditoría RDF. Properties exactas → PEND-005.

Quién cambió visibilidad/alineamiento, qué y cuándo. Opciones (de `contexto-modulo-rea.md` §7):

- **A) Módulo History Log (Daniel-KM).** *Nota verificada 2026-06-12: el módulo **Log de Daniel Berthereau ya está instalado** en vuestro entorno*, así que esta opción no añade dependencia nueva.
- **B) Value annotations** nativas (core ≥3.1): anotar los valores con quién/cuándo. Nativo pero menos "log".
- **C) Log a fichero/servicio** desde los eventos de API.
- **D) Renunciar** y registrarlo como riesgo aceptado.

**Pregunta:** ¿qué opción? (recomendación preliminar, a tu criterio: **A**, dado que el módulo Log ya está y respeta "sin tablas propias").

## PEND-007 — RF/NFR detallados (bloquea estadísticas TASK-006, ACL efectiva; punto 1 ya resuelve vista maestra v1)

Desglosado para poder responder por partes:

1. **Vista maestra — columnas exactas y orden, filtros y panel de detalle.** ✅ **Resuelto (2026-06-16, ADR-0005)** para el alcance v1 (lectura + curación de visibilidad): ver `docs/superpowers/specs/2026-06-15-vista-maestra-design.md`. El panel de detalle **configurable** por el admin queda fuera de v1 y sigue abierto.
2. **Re-catalogador — reglas de negocio:** ¿una o varias materias/etapas por recurso (cardinalidad)? ¿mínimo de alineamiento para considerar un recurso "completo"? ¿límite de tamaño de lote?
3. **Curación — acciones y matriz rol×acción:** lista exacta de acciones y qué rol de Omeka puede cada una (ACL).
4. **Integridad — reglas que definen "íntegro":** campos obligatorios, destino de alineamiento vivo, licencia presente, etc.
5. **Estadísticas — catálogo:** lista de gráficos, dimensión (cursos/etapas, materias, proyectos, licencias), tipo (barras/tarta/línea), filtros y plantilla de export PDF.
6. **NFR:** rendimiento (nº de recursos y de items-término del currículo; tiempos objetivo), idiomas i18n (¿solo es/en?), nivel de accesibilidad objetivo.

## PEND-009 — Metadatos de empaquetado (bloquea `make package` publicable) ✅ RESUELTO

> **Resuelto (2026-06-15, ADR-0003):** paquete `ate/oer-manager`; licencia `GPL-3.0-or-later`, aplicada a `composer.json` y `config/module.ini`.

- Nombre de paquete Composer (provisional puesto por el agente: `ate/oer-manager`). ¿Lo dejamos así o usais otro vendor?
- **Licencia** del módulo (`module.ini` y `composer.json` la tienen sin definir a propósito). ¿GPL-3.0-or-later, como buena parte del ecosistema Omeka, u otra?

## PEND-008 — Skills preexistentes no localizadas ✅ RESUELTO

> **Resuelto (2026-06-15):** descartadas. No forman parte del módulo; se retiran del gobierno. Si en el futuro hace falta validación legal o QA de exports, se decidirá entonces.

`rea-validacion-legal` (licencias/copyright REA) y `spreadsheet-analyzer` (QA de exports) se daban por preexistentes pero no aparecen en el repo ni en `~/.claude/skills/`. ¿Existen en otro sitio (otro repo/equipo) o las creamos desde cero cuando hagan falta?

---

## Orden recomendado para desbloquear

> Actualizado 2026-06-16: PEND-005/006/008/009 cerrados; PEND-007 punto 1 (vista maestra v1) cerrado con ADR-0005. **Pendiente: PEND-007 puntos 2-6** (re-catalogador, matriz rol×acción, integridad, estadísticas, NFR).

1. TASK-003 (vista maestra v1) **en curso**, sin bloqueo.
2. **PEND-007 puntos 2-6** (reglas del re-catalogador, matriz rol×acción, integridad, estadísticas, NFR) → desbloquea TASK-004/005/006 y la matriz rol×acción definitiva.
