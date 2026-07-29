# ADR-0014: Lenguaje visual de las superficies de curación

## Estado

Propuesto (2026-07-29). Redactado a petición del propietario tras la pasada de diseño de la vista maestra; **pendiente de su aceptación o rechazo**.

## Contexto

La vista maestra recibió una pasada de diseño el 2026-07-29 (addendum de TASK-028 rebanada 1) que reordenó su jerarquía visual. Esa pasada tomó decisiones de criterio —qué hereda del anfitrión, qué señal manda, cómo se codifican los estados— que quedaron registradas como **hechos** en `backlog.md`, `traceability.md` y `project-memory.md`, no como norma. Este ADR existe para decidir si algunas de ellas pasan a ser vinculantes.

Las fuerzas que obligan a decidir ahora, y no después:

1. **Quedan tres rebanadas de UI por construir** (gobernanza legal y autoría editable; integridad e historial de las 340 anotaciones; cola de calidad con contadores), todas sobre la misma tabla y el mismo drawer. Sin una regla común, cada una inventa su propio tratamiento de estados y la vista maestra acaba siendo cuatro pantallas pegadas.
2. **ADR-0013 multiplica los estados en pantalla.** El catálogo de campos de TASK-027 introduce, solo en columnas: semáforo de integridad ok/aviso/error, licencia en tres estados (*del vocabulario* / *fuera del vocabulario* / *sin licencia*), marca ⚠ sobre valores literales que se pintan como enlace (D2), marca ⚠ sobre `dcterms:isPartOf` literal compitiendo, y trazabilidad sí/no. Son cinco codificaciones de estado nuevas: o comparten alfabeto, o el curador tiene que aprender cinco.
3. **El módulo vive dentro del admin de Omeka**, no en una página propia. Cada decisión visual es una decisión sobre *cuánto* apartarse del anfitrión, y hasta ahora se ha tomado ad hoc.
4. **La escala de producción no es la de desarrollo.** El propietario fija (2026-07-29) que en producción habrá **miles de REA**. Un tratamiento visual que funciona sobre 19 filas —y todos los criterios medidos en TASK-027 lo fueron sobre 19— no se puede dar por bueno a esa escala sin declararlo.

Este ADR **no** decide qué campos se muestran ni con qué prioridad: eso es ADR-0013 y su spec, y siguen mandando. Decide **cómo se expresa visualmente** lo que aquellos deciden mostrar.

## Alternativas consideradas

- **A) Sin norma visual; cada rebanada decide.** Coste cero hoy. Contra: es el estado que produjo la pantalla que hubo que rehacer —cuatro filas sueltas de controles, un rótulo del core sin traducir, seis columnas tratadas como iguales cuando una es la razón de ser de la pantalla—. Con cinco codificaciones de estado nuevas por delante, repetirlo es previsible.
- **B) Sistema de diseño propio del módulo** (tipografía, paleta e identidad propias). Da libertad total y una pantalla memorable. Contra: un módulo con identidad propia dentro del admin de Omeka se lee como una pieza rota, no como una pieza distinguida; y obliga a mantener un sistema entero para siete pantallas.
- **C) Heredar el chrome del anfitrión y normar solo la codificación de estados y la jerarquía.** Poca superficie de norma, aplicable a las tres rebanadas pendientes, y no compite con Omeka. Contra: renuncia a identidad visual propia, y ata a decisiones del anfitrión que el módulo no controla (si Omeka cambia su paleta, el módulo la sigue).
- **D) Adoptar un framework CSS de terceros.** Contra: dependencia nueva contra NFR-001/NFR-002, peso, y conflicto seguro con el CSS del admin.

## Decisión

Se adopta la **opción C**. Cuatro reglas, aplicables a la vista maestra, al drawer, a la cola de calidad y a cualquier superficie de curación futura del módulo.

### 1. El chrome se hereda; el presupuesto se gasta en jerarquía

Tipografía, paleta de acento y filetes se toman del admin de Omeka (`Lato`; acento `#a91919`; filetes `#dfdfdf`), leídos de su hoja compilada, no reinventados. El módulo **no** introduce familias tipográficas, fondos de página ni identidad propia.

Lo que sí es propio es la **jerarquía**: qué señal manda en cada pantalla. Corolario operativo — *una pantalla de curación se ordena alrededor de la decisión que habilita, no alrededor de la simetría de sus columnas.* Es la misma regla rectora de ADR-0013 §1 llevada del **qué** al **cómo**.

Los tokens propios del módulo (tinta `#2b3240`, apagado `#79818f`, superficie `#f6f7f9` y la tríada de estado) se declaran **una sola vez** como variables CSS en `asset/css/oer-master-view.css` y no se duplican como literales. Una sola fuente por dato, igual que ADR-0001 §1.

### 2. El rojo del anfitrión significa «te necesita», no «esto es un enlace»

Dentro de las superficies de curación del módulo, el acento `#a91919` queda **reservado al estado que reclama acción del curador** y a `:hover`/`:focus`. Los enlaces en reposo dentro de tablas densas van en tinta.

Motivo: en una tabla de miles de filas, un enlace por fila pintado en el color de alarma convierte la alarma en ruido de fondo. La afordancia de enlace se conserva en interacción (`:hover`, `:focus`, cursor) y por posición, que es donde el curador la busca.

**Esta regla se aparta de la convención del browse nativo de Omeka a sabiendas**, y solo dentro del módulo: el browse nativo de items no se toca.

### 3. Todo estado accionable se codifica por forma, no solo por color

Regla precisa, porque el matiz importa:

> **La distinción que dispara una acción del curador debe ser legible sin color.** Las distinciones que solo gradúan la severidad pueden apoyarse en color, siempre que el **nombre accesible** del elemento las enuncie en texto.

Aplicado: en la tabla, «hay que mirar esto» frente a «esto está bien» es lo accionable → glifos distintos (✓ / ⚠). «Parcial» frente a «sin alinear» gradúa, no dispara: comparte glifo, se distingue por color, y ambos llevan su rótulo completo en el nombre accesible. En la cola de calidad, donde la severidad **sí** dirige el triaje, vuelve a exigir formas distintas.

Corolario: **ningún estado se comunica solo con un rótulo de texto en una celda**. `Sí`/`No`, `Completo`, `ok` sueltos en una columna son indistinguibles del resto de metadatos a velocidad de barrido.

### 4. La escala de barrido tiene su propio canal

Cuando una pantalla existe para encontrar excepciones dentro de un volumen —y a miles de REA, todas las de curación lo son—, el estado que define la excepción tiene **un canal periférico continuo** además de su celda: hoy, un riel de color en el canto de la fila.

Ese canal invierte la saturación a propósito: **el estado sano se retira, el defectuoso se queda**. Se recorre buscando lo que falta, no confirmando lo que sobra.

El riel es un **mecanismo, no una property**: se ancla a la señal que en cada momento defina la excepción. Si ADR-0013 sustituye el alineamiento por la integridad como señal rectora, el riel se re-ancla a la integridad; no se elimina.

### Lo que este ADR NO norma

Composición concreta de cada pantalla, anchuras, qué columnas existen y en qué orden (es ADR-0013 y su spec), contenido de copy más allá de exigir que sea traducible, y la elección de glifos concretos. **Los glifos son detalle de implementación revisable sin ADR**; lo vinculante es que la distinción accionable tenga forma propia.

## Consecuencias

**Más fácil.** Las tres rebanadas pendientes tienen alfabeto: las cinco codificaciones de estado que introduce ADR-0013 (integridad, licencia, literal-vs-enlace, proyecto, trazabilidad) se resuelven con la misma regla en vez de inventarse cinco veces. Y el criterio de accesibilidad deja de decidirse por pantalla.

**Más difícil.** El módulo queda atado a decisiones del anfitrión que no controla: si Omeka cambia su paleta o su tipografía, el módulo la sigue —que es el comportamiento buscado, pero significa que un rediseño del admin arrastra al módulo sin aviso—. Y la regla 2 obliga a justificar cada uso de rojo, incluidos los que hoy son inocuos.

**Riesgo aceptado.** La regla 2 se aparta de la convención de enlaces del browse nativo. Un curador que trabaje a la vez en la vista maestra y en el browse de items verá los títulos de distinto color en cada una. Se acepta porque las dos pantallas tienen trabajos distintos: el browse navega, la vista maestra audita.

**Deuda declarada.** La pasada del 2026-07-29 se verificó en Chrome headless contra la hoja compilada del core, **no en el admin real logueado**. Esta norma se apoya en esa verificación; la primera sesión con credenciales debe confirmarla.

**Queda pendiente a raíz de esta decisión.** El contraste de la tríada de estado sobre las superficies del módulo no se ha medido contra WCAG AA; los valores se eligieron por criterio, no por medición. Si el propietario acepta este ADR, la medición es trabajo de la rebanada que estrene la columna de integridad, que es la que multiplica los estados en pantalla.

## Fuentes

- Pasada de diseño de la vista maestra, 2026-07-29 (addendum de TASK-028 rebanada 1, PR #16); registro en `backlog.md`, `traceability.md` y `project-memory.md`.
- Tokens del anfitrión leídos de `application/asset/css/style.css` del contenedor (Omeka-S 4.2): `Lato`, `#a91919`/`#e11717`, `#dfdfdf`, `#676767`.
- ADR-0005 (vista maestra v1), ADR-0013 (catálogo de campos v2 y su regla rectora), ADR-0001 §1 (una sola fuente por dato).
- `docs/superpowers/specs/2026-07-27-campos-ui-design.md` §4 (columnas de la v2, origen de las cinco codificaciones de estado nuevas).
- Decisión del propietario sobre escala de producción y sobre la columna de alineamiento, 2026-07-29 (ver ADR-0013 §Afinado).

---

*Registro append-only: los ADR no se borran ni se reescriben. Para cambiar una decisión, crear un ADR nuevo y marcar este como «Reemplazado por ADR-NNNN». Numeración correlativa de cuatro dígitos; no se reutiliza.*
