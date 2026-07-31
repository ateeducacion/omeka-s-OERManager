# ADR-0015: Evento de curación en el item — la reversibilidad que la anotación no puede dar

## Estado

Aceptado (2026-07-31). **Complementa ADR-0002; no lo deroga.**

## Contexto

ADR-0002 fijó la auditoría de curación como RDF nativo: cada valor de alineamiento se anota con
quién (`dcterms:contributor`), cuándo (`dcterms:modified`) y qué (`dcterms:provenance`), más el
porqué de la IA (`dcterms:description`, ampliación de TASK-023). Está implementado desde TASK-004
y cubre 340 valores del catálogo real.

Ese mecanismo tiene un límite estructural que TASK-004 dejó anotado como residuo y la skill
`recatalogador` marcaba en rojo: **una value annotation no puede registrar una eliminación**,
porque vive en el valor que se borra. Al vaciar una dimensión desaparece con ella. Además,
`RecatalogService::apply()` nunca leía el estado previo del item: `preview()` sí calcula
`added`/`removed`, pero el cliente los pintaba y no los reenviaba.

Consecuencia: la «vía de reversión» que la skill exige para toda operación en lote no existía. La
garantía efectiva era preview + confirmación, no el deshacer. Es lo que TASK-007 viene a cerrar.

NFR-002 sigue prohibiendo tablas Doctrine propias, y ADR-0002 §1 acota los vocabularios a
`dcterms`, `lrmi` y `schema`.

## Alternativas consideradas

- **Enriquecer la anotación del valor con el conjunto previo de su property.** El cambio más
  pequeño y cabe entero en ADR-0002. Descartada: si una dimensión se vacía a cero valores no queda
  dónde anotar y el evento desaparece — justo el caso destructivo que hay que cubrir. El deshacer
  no sería fiable.
- **Log JSON en fichero privado**, reutilizando el patrón ya probado de `ProposalStore`. No ensucia
  el RDF. Descartada: ADR-0002 ya evaluó y descartó el «log a fichero» (opción C), y la traza no
  viajaría con el item en un export ni sobreviviría a una migración de datos, que es exactamente lo
  que se compró al elegir RDF nativo.
- **Evento como valor en el propio item (elegida).**

## Decisión

1. **Cada `apply` que cambie algo añade UN valor `dcterms:provenance` al item**, con el resumen
   legible en el valor y el estado previo exacto en su `@annotation`. Se anexa:
   `dcterms:provenance` **nunca** entra en `clear_property_values`, así que el registro es
   append-only y ninguna re-catalogación posterior borra la traza de las anteriores.

2. **El valor es privado** (`is_public = false`): es un registro de máquina y no debe salir en la
   ficha pública del REA.

3. **La anotación del evento** lleva `dcterms:contributor`, `dcterms:modified`,
   `dcterms:provenance` con el marcador **`OERManager/curation-event/1`** —ASCII estable y **no
   traducible**, porque reconocer el evento por su resumen se rompería al compilar un `.mo`— y
   `dcterms:replaces` con el payload JSON. `replaces` es «lo que este recurso supersede», que es
   literalmente el estado anterior.

4. **Payload versionado** (`v: 1`); lo que no se sepa leer se rechaza en vez de interpretarse a
   medias. Por dimensión guarda `before`, `after` y `why` — el porqué de **todos** los valores
   previos que lo tuvieran, no solo los eliminados, porque el deshacer reescribe la property entera
   desde `before` y cada valor restaurado necesita el suyo de vuelta. Regenerarlo costaría otra
   pasada de LLM.

5. **`dcterms:modified` con precisión de microsegundos**, y es **el mismo sello** en el evento y en
   las anotaciones por valor de esa confirmación. Así el par `(contributor, modified)` sigue
   identificando el evento completo —la clave de agrupación que ADR-0013 §7.2 da por buena— y
   además es único: con precisión de segundo, dos escrituras seguidas compartían sello y la lectura
   del último evento tenía que desempatar por el orden de la colección, que Omeka no garantiza.

6. **Se deshace el último evento del item** (LIFO). La reversión **no es un camino de escritura
   privilegiado**: se reaplica por `apply()`, así que hereda la validación de destino, las
   anotaciones por valor y su propio evento. Deshacer un deshacer es, por tanto, rehacer.

7. **Guarda de obsolescencia.** Si el estado actual de alguna dimensión no coincide con el `after`
   del evento, alguien tocó el REA por otra vía (p. ej. el formulario nativo de Omeka) y deshacer
   tiraría su trabajo: el servicio se niega y hace falta confirmación explícita del curador.

8. **Un «Confirmar» que no cambia nada no se escribe.** Reescribir los mismos valores les pondría
   anotaciones con fecha y autor nuevos, falsificando la propia auditoría de ADR-0002.

## Consecuencias

- La reversibilidad que la skill `recatalogador` exige para toda operación en lote pasa a ser real
  y verificable, no solo preview + confirmación.
- Sin tablas propias (NFR-002), sin vocabularios nuevos (ADR-0002 §1) y sin dependencias añadidas.
- **El item acumula un valor por cada apply que cambie algo.** Es el precio de un registro
  append-only; a cambio, ninguna escritura posterior puede borrar la historia.
- **Límite aceptado:** el valor es privado, así que **un export anónimo no lo lleva**. La traza
  viaja con el item para quien tenga permiso de lectura sobre él, no para el mundo.
- **Límite heredado de ADR-0002:** al restaurar, los valores recuperados llevan anotaciones nuevas
  (autor y fecha de la reversión). La autoría original de esos valores solo pervive en el registro
  de eventos, no en la anotación del valor.
- La visibilidad (`is_public` del recurso) sigue fuera de la auditoría, como fijó ADR-0002 §3: el
  evento no la registra ni el deshacer la toca.
- La superficie de lectura del historial (ADR-0013 §7) **no** entra aquí: sigue siendo la rebanada 3
  de TASK-028, que ahora sí puede mostrar eliminaciones porque este registro las conserva.
- La ACL de las acciones nuevas es la del controlador entero (editor+), igual que el apply:
  deshacer no es más privilegiado que hacer. La deuda de granularidad sigue registrada en ADR-0013.

## Fuentes

- ADR-0002 (auditoría RDF nativa) y su ampliación de TASK-023; ADR-0013 §7.2 (clave de agrupación).
- `docs/backlog.md` TASK-004 (residuo B3) y TASK-007; `docs/requirements.md` RF-008, NFR-002.
- Skill `recatalogador`, bloque «⚠️ Reversibilidad — estado real».
- Verificado en el contenedor real (`test/container/undo-harness.php`, item #5045, 2026-07-31):
  31 comprobaciones, incluido el vaciado total de una dimensión con justificaciones de la IA y su
  restauración exacta.
- Decisiones del propietario en el chat (2026-07-30).
