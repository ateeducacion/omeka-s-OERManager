# Superficies de lectura del drawer — diseño (TASK-028, rebanada 3a)

> **Fecha:** 2026-08-11 · **Tarea:** TASK-028, rebanada 3a.
> **Gobierno previo:** el catálogo de campos lo fijó TASK-027 (aprobado por **ADR-0013**); el CÓMO estructural, la rebanada 1; la norma visual, **ADR-0014**; el registro de curación que aquí se lee, **ADR-0015**.
> **Regla de corte:** esta rebanada **no escribe nada**. Ni un formulario, ni una acción con CSRF, ni una property nueva.

---

## 1. Por qué esta rebanada existe

El drawer del catálogo muestra hoy **nueve campos fijos de solo lectura** (`asset/js/core/drawerModel.js`) y nada más. Mientras tanto el módulo calcula dos cosas que **ningún usuario ha visto nunca**:

- **`IntegrityChecker`** corre en cada guardado desde TASK-005 y vuelca sus incidencias **solo al log de Omeka**. La rebanada 2 le dio una columna con un contador; el detalle de *qué* falla sigue sin superficie.
- **El registro de curación de ADR-0015** (`dcterms:provenance` append-only, con estado previo y con la justificación de la IA) se escribe y se consume para deshacer, pero **no se puede leer** desde ninguna parte de la interfaz.

Falta además el **enlace al editor nativo de Omeka**, que TASK-027 §5.1 marcó como imprescindible: hoy no hay salida del drawer al item.

## 2. El corte lectura / escritura

Decisión del propietario (2026-08-11). La rebanada 3 se parte en dos por la frontera que separa lo barato y desbloqueado de lo caro y bloqueado:

| | Contenido | Coste y bloqueos |
| --- | --- | --- |
| **3a (esta)** | Issues de integridad, historial de curación, enlace al item nativo, lista de medios | Solo lectura: sin ACL de escritura, sin CSRF, sin escritura RDF. **No depende de ningún PEND** |
| **3b** | Gobernanza y ficha descriptiva editables, visibilidad desde el drawer | Necesita el patrón `clear_property_values` + `collectionAction=append` que ya mordió en TASK-004, validación por campo, y el vocabulario de licencias de **PEND-011** |

---

## 3. Decisiones

| ID | Decisión | Motivo |
| --- | --- | --- |
| **E-1** | El historial sale de los **eventos de curación** (ADR-0015), **no** de las value annotations | Es el log real: append-only y **muestra eliminaciones**. Una anotación vive en el valor, así que al vaciar una dimensión desaparece con ella — la limitación que TASK-027 §7.4 declaró y que ADR-0015 vino a resolver |
| **E-2** | **Se asume que el historial nace vacío**, y la sección lo declara | Ver §4 |
| **E-3** | La justificación de la IA se muestra **tras un «ver porqué» colapsado**. **Resuelve PEND-013** | Mantiene el principio de TASK-023 —no anclar al curador *antes* de decidir— porque aquí ya está decidido y escrito, y no tira un dato cuya regeneración costaría otra pasada de LLM |
| **E-4** | El historial se sirve desde una **acción del servidor**, nunca desde el JSON-LD del item | Ver §5 |
| **E-5** | La ficha destilada, la traza de visión y la aptitud para la IA **no entran** | Ver §6: no son campos del item, y TASK-027 §5.6/§5.8 daba por hecho que sí |

---

## 4. El historial nace vacío, y hay que decirlo (E-2)

**Medición sobre el catálogo real (19 REA):**

| Fuente | Entradas | REA afectados |
| --- | --- | --- |
| Value annotations | **386** | 15 |
| — de ellas con justificación de la IA | 114 | — |
| Eventos `dcterms:provenance` | **0** | 0 |

El registro de ADR-0015 se estrenó con TASK-007 y el arnés de verificación limpió los eventos que dejó, así que hoy no hay ninguno. La consecuencia es directa: **la sección se estrena sin una sola entrada en los 19 REA**, y el «ver porqué» no tendrá nada que desplegar hasta que alguien re-catalogue.

**No hay atajo.** Un evento registra el **estado previo**, y para lo ya escrito ese estado no existe en ningún sitio: las anotaciones dicen quién puso el valor actual, no qué había antes. Los eventos **no se pueden generar retroactivamente**.

**Obligación de esta rebanada:** la sección vacía debe **declarar su cobertura**. Un curador que vea el vacío sin explicación leerá «este REA nunca se tocó», que es falso —hay 386 anotaciones diciendo lo contrario—. El texto tiene que decir que el registro cubre desde que existe, no desde siempre.

Es el único punto donde esta rebanada **tiene** que explicarse en pantalla.

---

## 5. El historial no puede venir del JSON-LD (E-4)

`RecatalogService.php:352` escribe el valor del evento con **`is_public => false`**. El drawer carga el item con `fetch(apiUrl)` **sin cabeceras de autenticación** (`asset/js/ui/drawer.js:59`), así que la API pública nunca devolverá esos valores.

Esto no es un hallazgo nuevo: el cierre de TASK-007 lo dejó anotado como aviso literal *«para la rebanada 3 de TASK-028, que es su cara de lectura»*, tras comprobar en contenedor que un valor privado no se ve sin autenticar. Se recoge aquí porque es lo que fija la arquitectura.

**Consecuencia:** una acción del controlador, dentro de la sesión de admin, que lea los eventos en el servidor y devuelva ya el modelo de presentación. El payload íntegro (con los ids a restaurar) **no sale al cliente**: solo lo necesita el servidor al deshacer, igual que ya hace `recatalogLastEventAction`.

---

## 6. Corrección al estudio de TASK-027 (E-5)

**§5.8 asume que la ficha destilada y la traza de visión son campos del item, y no lo son.** `debug.ficha` y `debug.vision` viajan en la respuesta de `ai-propose` y viven en `ProposalStore` con **TTL de una hora**; no se persisten por item. Lo mismo vale para la «aptitud para la IA» (`content.sources` / `content.skipped`) de §5.6.

Mostrarlos en el drawer de un REA cualquiera exigiría **re-ejecutar un propose**, con su coste en tokens y su latencia.

**Se quedan donde ya están**, en el panel de depuración que aparece tras un propose. Persistirlos por item es una decisión de diseño con coste de almacenamiento que nadie ha tomado; si se quiere, es tarea propia.

---

## 7. Alcance

**Entra:**

1. **Diagnóstico — issues de integridad.** Severidad, campo y mensaje, desde `IntegrityChecker::check($item, true)`. Aquí **con la comprobación de enlaces encendida**: es un item a la vez y el detalle importa. D-7 la apaga solo en la tabla, donde se pagan 25 filas por página.
2. **Trazabilidad — historial de curación.** Eventos en orden inverso: cuándo, quién, resumen legible, y por dimensión los valores añadidos y retirados **con su título**. «Ver porqué» colapsado donde el evento traiga justificación. Aviso de cobertura cuando esté vacío (§4).
3. **Enlace al item nativo de Omeka.**
4. **Lista de medios** con nombre y tipo, y **aviso de REA sin ningún medio** — hoy afecta a #40442, y un REA sin medio no es un recurso.

**No entra:** todo lo editable (3b) · ficha destilada, traza de visión y aptitud para la IA (§6) · miniatura y colección (opcionales en TASK-027 §9) · exponer las 386 anotaciones como «procedencia», descartado en E-1 y disponible en el RDF si se quisiera después.

---

## 8. Arquitectura

La frontera de siempre en este módulo: **la decisión, pura y probada en host; el glue del core, delgado y verificado en contenedor.**

### 8.1 Piezas puras (host, PHPUnit / `node --test`)

| Fichero | Qué decide |
| --- | --- |
| `src/Service/Governance/CurationHistory.php` | De una lista de payloads de evento al modelo de presentación: orden inverso, cambios por dimensión, qué entradas traen justificación |
| `asset/js/core/historyModel.js` | Del JSON de la acción a filas pintables, **incluido el estado vacío y su aviso de cobertura** |
| `asset/js/core/integrityModel.js` | Agrupa las incidencias por severidad y las ordena |

### 8.2 Glue

| Fichero | Cambio |
| --- | --- |
| `src/Service/RecatalogService.php` | `history(int $itemId): array`, junto a `lastEvent()`, que ya recorre exactamente los mismos valores |
| `src/Controller/Admin/IndexController.php` | Acción `drawer-details`: **una sola** llamada que devuelve integridad e historial. Solo lectura, sin CSRF |
| `config/module.config.php` | Ruta y ACL del privilegio nuevo, al nivel de las acciones de lectura existentes (`search-terms`, `recatalog-last-event`) |
| `asset/js/ui/drawer.js` | Pide `drawer-details` en paralelo al item y pinta las secciones nuevas |
| `asset/css/oer-master-view.css` | Estilos; lo accionable se distingue **por forma** además de por color (ADR-0014 regla 3) |

### 8.3 Reutilizar, no reescribir

`CurationEvent::decode()`, `summary()` y `restoreReasons()` · `IntegrityChecker` e `IntegrityResult::getIssuesBySeverity()` · `RecatalogService::qualifiedTitle()`, imprescindible para desambiguar títulos homónimos —el currículo repite «Matemáticas» en cuatro cursos y un historial que liste solo títulos mostraría líneas idénticas— · el patrón de arnés de `test/container/`.

---

## 9. Riesgos

| Riesgo | Mitigación |
| --- | --- |
| La sección se estrena vacía y el curador lo lee como «nunca se tocó» | E-2: aviso de cobertura explícito. Es el único punto donde esta rebanada debe explicarse |
| Resolver los títulos de los ids de un evento cuesta una lectura por id | Es **por item y bajo demanda**, no por fila de tabla. Se mide en el arnés |
| El drawer crece y `drawer.js` vuelve a ser el cajón que la rebanada 1 desmontó | Las secciones nuevas entran como módulos puros de `core/`; `drawer.js` solo orquesta |
| Una acción de lectura nueva alcanzable por quien no debe | ACL **por privilegio**, no por controlador — la lección de TASK-029, donde conceder el controlador entero dejaba una acción nueva al alcance de `editor` por herencia |

---

## 10. Verificación

**Host (obligatorio, TDD real).** PHPUnit sobre `CurationHistory`: orden inverso, evento sin justificación, **evento que vacía una dimensión por completo** —el caso que las anotaciones no pueden representar y que motiva E-1— y lista vacía. `node --test` sobre los dos modelos JS, incluido el estado vacío.

**Contenedor.** Arnés `test/container/drawer-details-check.php`, solo lectura sobre el catálogo salvo por lo que él mismo restaura: como no hay eventos (§4), **fabrica uno con `apply()`, lo lee y devuelve el item a su estado previo**, exactamente como ya hace `undo-harness.php`. Comprueba además que la integridad que devuelve la acción coincide con la que mide `columns-check.php`.

**Navegador con sesión real.** Esta rebanada **se cierra mirando el drawer**, no solo con arneses. La sesión del 2026-08-10 encontró dos defectos —la celda curricular que insinuaba que «Biología y Geología» estaba en Infantil, y los 7 REA con un curso que ninguna materia sostenía— que **quince revisiones por tarea y una revisión final de rama no habían visto**.

---

## 11. Consecuencias de gobierno

- **PEND-013 queda resuelto** por E-3 y debe marcarse como tal en `requirements.md`.
- **Sin ADR nuevo.** Nada aquí fija norma que ADR-0013, ADR-0014 y ADR-0015 no cubran ya. E-1 y E-4 son lecturas de ADR-0015, no decisiones nuevas; E-5 corrige un supuesto de un estudio, no una decisión.
- **TASK-030 se aligera:** el punto (6) de su alcance incluye el residuo de verificación de TASK-023, y el historial es su cara de lectura.

---

## 12. Resumen en una frase

El drawer deja de ser nueve campos fijos y estrena las dos señales que el módulo lleva calculando en silencio desde TASK-005 y TASK-007 — asumiendo que una de ellas nace vacía, y **diciéndolo en pantalla** en vez de dejar que el vacío mienta.
