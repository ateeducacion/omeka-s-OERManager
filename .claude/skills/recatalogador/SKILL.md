---
name: recatalogador
description: USAR OBLIGATORIAMENTE ante cualquier trabajo que toque el re-catalogador, el alineamiento curricular, los tags o la escritura de valores RDF sobre items (diseño, código, revisión o pruebas). Es el componente de ALTO RIESGO del módulo — re-cataloga en masa y puede corromper el catálogo; no trabajes en él sin cargar esta skill.
---

# Re-catalogador — reglas de negocio, escritura RDF y UX jerárquica

## Propósito

Concentrar las reglas de negocio del re-catalogador, el mapeo exacto de properties RDF y la estrategia de UX sobre la jerarquía curricular, para que ninguna escritura de alineamiento/tags se improvise.

## Cuándo dispararse

- Diseñar o codificar la re-catalogación curricular o por tags (individual o por lotes).
- Cualquier escritura de resource values de alineamiento sobre items.
- Revisar diffs que afecten a properties de alineamiento o al chequeo de integridad posterior.

## Invariantes ya fijados (no esperar al destilado)

- El alineamiento se escribe como **resource value apuntando al item-término** del currículo (no literal); validar que el destino existe y es del tipo esperado.
- Operaciones en lote: **previsualización + confirmación + vía de reversión** (o auditoría que permita revertir, según PEND-006) antes de ejecutar.
- Tras re-catalogar, el chequeo de integridad debe poder confirmar el estado resultante.
- ACL estricta: solo roles con privilegio de curación (matriz rol×acción en PEND-007).
- UX sobre jerarquía grande: no cargar el árbol completo; búsqueda incremental y lazy-load por nivel (NFR-004).

## Contenido

`[PENDIENTE: reglas de negocio del propietario — mapeo property RDF → tipo de alineamiento y tags (PEND-005); cardinalidad y obligatoriedad por nivel (etapa/materia/criterio/competencia); vocabulario de tags controlado vs. libre; quién puede recatalogar qué; límites de lote; estrategia definitiva de reversión según la decisión de auditoría (PEND-006). No inventar properties LRMI/Dublin Core hasta que PEND-005 esté resuelto.]`
