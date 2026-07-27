# Diseño — Propose asíncrono como Omeka Job, versión completa (TASK-020)

- **Fecha:** 2026-07-24
- **Estado:** diseño acordado (brainstorming con el propietario, «async completo»)
- **Componente:** catalogación IA-assistida / capa de transporte (no toca el clasificador)
- **RF/NFR:** **NFR-010** (fiabilidad, el que TASK-020 cumple); RF-011/NFR-008/NFR-011
  solo tangencial (misma infra / calidad intacta).
- **ADR afectados:** ADR-0007 (IA propone / curador confirma), ADR-0008 (conexión LLM),
  ADR-0011 (capa de contexto). No cambia ADR-0004 (mapeo) ni ADR-0009 (grafo).

## 1. Problema

El «Proponer con IA» es **síncrono**: una petición HTTP encadena 7-9 llamadas al
LLM. El tiempo de pared llega a minutos (medido ~600 s con un PDF de 28 MB por
visión, item #4362); el proxy corta a ~60 s → **504**, un 5xx no controlado que
**incumple NFR-010** (c). El 504 es un **caso extremo** (PDF enormes / modelos
lentos): el uso corriente son 13-34 s. Pero para cumplir NFR-010 y no bloquear al
curador, el propose no debe depender de una única petición HTTP síncrona.

**Alcance:** propose asíncrono de **1 item**, «versión completa» de usabilidad
(progreso, cancelar, recuperación). El **lote (RF-011/TASK-011) no** se implementa
aquí, pero la infra de Job se diseña reutilizable.

## 2. Qué NO cambia

- Clasificador, prompts, calidad de la propuesta (NFR-008/NFR-011): **idénticos**.
- Flujo **previsualizar → confirmar → aplicar** (ADR-0007): idéntico. El Job **solo
  propone, nunca escribe**. La escritura sigue siendo el clic de «Aplicar»
  (rápido, sin LLM, sin 504).
- Confirmación de PDF grandes (TASK-025) y justificación (TASK-023): intactas.

## 3. Flujo

```
1. clic "Proponer con IA"
   POST /admin/oer-manager/ai-propose {id, csrf, large_pdf}
   → despacha AiProposeJob, responde {jobId} en <1s. Guarda item→jobId en localStorage.

2. Job (PhpCli, proceso aparte): por cada fase escribe progreso en {jobId}.json:
   {status:'in_progress', step:'Destilando ficha…', done:2, total:6}
   Al terminar: {status:'completed', payload:{…}}   (o {status:'error'|'stopped'})

3. Navegador cada ~3s: POST /ai-propose-status {jobId, csrf}
   ← el estado actual del fichero → pinta el paso ("Clasificando saberes… 4/6")

4. Al completarse → el panel se rellena igual que hoy → previsualizar → aplicar.
```

## 4. Decisiones de usabilidad (resueltas — «async completo»)

1. **Progreso por pasos** (no solo spinner): el Job reporta la **fase** en curso
   (extraer → destilar → visión → clasificar curricular → ejes) y el panel la
   muestra con `done/total`. Granularidad de **fase** (no cada llamada), suficiente
   y sin cirugía profunda; ver §5.2.
2. **Seguir trabajando / cerrar el panel:** el Job corre en 2º plano; el curador
   puede cerrar el panel u operar otros items. Al **reabrir** el item, el JS mira
   `localStorage[item→jobId]` y **reengancha** el sondeo.
3. **Abandono de pestaña / recuperación:** el resultado se **conserva hasta un TTL**
   (no se borra en la primera lectura), así que al volver (mismo navegador) se
   recupera. Cross-navegador no se recupera (raro; documentado).
4. **Cancelar:** botón «Cancelar» → para el Job (`Dispatcher::stop($jobId)`, que
   solo pone el estado `STOPPING` — verificado en el core). El Job comprueba la
   señal **entre fases** (`AbstractJob::shouldStop()`, que hace una **consulta
   fresca a BD**, así que ve la parada puesta por el proceso web); en cuanto termina
   la fase en curso **aborta limpio** y escribe `{status:'stopped'}`. **No se
   muestra una propuesta parcial** (un alineamiento a medias confundiría al
   curador): cancelar = sin propuesta.
5. **Robustez del fichero / huérfanos:** escritura **atómica** (temp + `rename`);
   **barrido por TTL** (`sweepOld`) lanzado de forma oportunista en cada nuevo
   propose limpia huérfanos (pestaña cerrada, job muerto). Ficheros JSON pequeños en
   **directorio privado** (no `files/` público).

## 5. Componentes (unidades con un solo propósito)

### 5.1 `Service\Ai\ProposalStore` (PURO, testeable en host)

Canal de estado/resultado entre el Job y el polling (dos procesos PHP distintos).

- `write(int $jobId, array $state): void` — atómica (temp + rename). El `state` es
  el estado vivo (`in_progress`+paso, o `completed`+payload, o `error`/`stopped`).
- `read(int $jobId): ?array` — idempotente (no borra: permite recuperación §4.3).
- `sweepOld(int $ttlSeconds): void` — borra ficheros más viejos que el TTL.

Directorio base **privado** e inyectable (`sys_get_temp_dir().'/oer-manager-proposals/'`
en producción; temporal en los tests). Nombre `{jobId}.json`. **TTL por defecto 1
hora** (holgado para la recuperación tras abandono, §4.3; el propose más lento
medido fue ~10 min). El `sweepOld` corre de forma oportunista al despachar cada
propose (§5.5), así que no hace falta un cron.

### 5.2 `Service\Ai\ProgressReporter` (interfaz) + implementaciones

Cómo la tubería larga informa de su fase y consulta si debe parar. Interfaz mínima:

- `report(string $step, int $done, int $total): void`
- `shouldStop(): bool`

Implementaciones:
- **`JobProgressReporter`** (producción): `report` escribe el estado en
  `ProposalStore`; `shouldStop` consulta `AbstractJob::shouldStop()` del Job.
- **Null object** (`NullProgressReporter`): no-op (`report` no hace nada,
  `shouldStop` siempre `false`); es el default para el resto de callers y los tests
  de host — así el cableado del progreso es **opcional** y no rompe nada existente.

La excepción de parada `JobStoppedException` (namespace del módulo) la lanza
`AiCataloguer` cuando `shouldStop()` es cierto en un límite de fase; el
`AiProposeJob` la captura y escribe `stopped`.

Se pasa **opcional** a `AiCataloguer::propose(..., ?ProgressReporter $progress = null)`.
`propose` llama `report()` al entrar en cada fase y comprueba `shouldStop()` en los
límites de fase; **si para, lanza una excepción de parada** que el Job traduce a
`stopped` (no se devuelve ni usa una propuesta parcial, §4.4). Los clasificadores
**no cambian de firma**: el reporte es de grano de fase, emitido desde
`AiCataloguer`, que ya orquesta las fases. (Cancelar *dentro* de la cascada
curricular —grano fino— es mejora futura; hoy corta al acabar la fase.)

`done/total` es **aproximado y dinámico**: el total de fases varía (la visión solo
si hay imágenes/PDF rescatable; los bloques solo a veces). El reporter calcula el
total conocido al arrancar y las etiquetas de paso son la señal principal; el
número es orientativo, no una barra exacta.

### 5.3 `Service\Ai\ProposeRunner`

«itemId + decisión (+ progress) → payload completo para el navegador». Centraliza
lo que hoy está disperso en el controlador: leer el item, `itemMetadataText`,
`filesFor`/`imagesFor`, `AiCataloguer::propose`, y `enrichLabels`. Devuelve el mismo
payload que hoy (incluido `needs_confirmation`). **Lo usa el `AiProposeJob`** (el
`aiProposeAction` ya no ejecuta el propose, solo despacha). La acción de evaluación
(`aiEvaluate`, herramienta de dev síncrona) puede reutilizar `itemMetadataText` de
aquí, pero no requiere el enriquecido; queda como está por ahora.

### 5.4 `Job\AiProposeJob` (extends `Omeka\Job\AbstractJob`)

`perform()`:
1. Args `{itemId, largePdfDecision}`.
2. `$progress = new JobProgressReporter($store, $this->job)`.
3. `try { $payload = $runner->run($itemId, $largePdfDecision, $progress); $store->write($jobId, ['status'=>'completed','payload'=>$payload]); }`
4. `catch (JobStoppedException) { $store->write($jobId, ['status'=>'stopped']); }`
5. `catch (LlmException|\Throwable $e) { $store->write($jobId, ['status'=>'error','code'=>…]); }`
   — estado limpio y **reintentable** (NFR-010a), sin filtrar la clave; relanza si
   procede para que el Job quede en el estado nativo de error de Omeka.

Identidad: el Job corre en un proceso CLI **impersonando a su owner** (mecanismo
nativo de Omeka), así que las llamadas a la API dentro del propose (leer item,
`enrichLabels`) usan la ACL del curador. Verificado que Omeka fija la identidad del
job por su owner.

### 5.5 `IndexController`

- `aiProposeAction`: CSRF/ACL/`aiEnabled`/id **síncronos** (instantáneos). Lanza
  `sweepOld` oportunista; **despacha** `AiProposeJob`; devuelve `{jobId}`. Si el
  despacho falla, error limpio.
- `aiProposeStatusAction` (polling): CSRF; **primero** lee el Job por la API
  (`read('jobs', $jobId)` → **acotado por ACL**: lanza si no es dueño/admin);
  **solo después** de pasar ese control lee el fichero. Combina las dos fuentes,
  que es lo que hace el estado robusto:
  - fichero con `completed`/`error`/`stopped` → se devuelve tal cual;
  - fichero con `in_progress` → progreso (paso `done/total`);
  - **sin fichero pero el Job en estado nativo `error`/`stopped`** (murió sin
    escribir, p. ej. PhpCli no arrancó o el proceso cascó) → **`error`
    reintentable**, no un `in_progress` eterno;
  - sin fichero y Job `starting`/`in_progress` → `in_progress` de arranque.
  Este cruce evita que un Job muerto deje el polling colgado indefinidamente.
- `aiProposeCancelAction`: CSRF; verifica propiedad del Job por la API y **para** con
  `Dispatcher::stop($jobId)`.
- Rutas nuevas: `/ai-propose-status`, `/ai-propose-cancel`.

### 5.6 JS (`asset/js/oer-master-view.js`)

- **arrancar:** POST `ai-propose` → `{jobId}`; `localStorage['oer-ai-job-'+itemId]=jobId`;
  pinta progreso + botón «Cancelar».
- **sondear:** cada ~3 s POST `ai-propose-status`; pinta `step done/total`;
  `completed` → tratar payload **igual que hoy** (incluye `needs_confirmation` →
  diálogo → re-despacho `include`/`skip`); `error`/`stopped` → mensaje; limpia el
  jobId de localStorage al terminar.
- **cancelar:** POST `ai-propose-cancel`; deja de sondear.
- **reenganche:** al abrir el panel de un item, si hay jobId en localStorage,
  reanuda el sondeo (recupera progreso o resultado dentro del TTL).
- **guardia de doble arranque:** si ya hay un jobId pendiente para ese item (en
  localStorage y sin terminar), no se lanza un segundo Job: el botón queda
  deshabilitado / se reengancha al existente. Evita jobs duplicados por doble clic o
  reapertura.
- **tope de sondeos:** límite de tiempo/intentos; si se excede, deja de preguntar y
  ofrece reintentar (no cuelga la UI).

## 6. Seguridad

- Fichero de resultado en **directorio privado**, nunca en `files/` público.
- Polling y cancelación **acotados por ACL** vía la API de Jobs (dueño/admin).
- **Sin secretos** en el fichero; errores saneados (TASK-021). CSRF en arranque,
  polling y cancelación.
- Escritura atómica; `sweepOld` por TTL limpia huérfanos.

## 7. Riesgos

- **PhpCli debe arrancar de verdad.** `phpcli_path` en auto-detección; **verificar
  en contenedor** que el Job corre en 2º plano (php-fpm puede exponer el `PHP_BINARY`
  del fpm, no del cli). Si el despacho falla, detectarlo y avisar.
- **Multi-servidor con disco no compartido:** el fichero asume disco común (1
  servidor, lo normal en Omeka). Límite documentado; multi-nodo necesitaría canal
  por setting (descartado por simplicidad).
- **Reenganche cross-navegador:** localStorage es por navegador; recuperar desde
  otro dispositivo no está cubierto (raro).
- **Cancelar es de grano de fase:** un cancel durante la cascada curricular espera a
  que termine la fase. Grano fino = mejora futura.
- **Crecimiento de la tabla `job`:** cada propose crea un registro de Job en Omeka
  (y el caso de confirmación de PDF grande, §4/TASK-025, crea **dos**: uno para el
  `ask` y otro para el `include`/`skip`; el `ask` es casi instantáneo, sin LLM). Es
  el comportamiento nativo de cualquier Job de Omeka; se confía en la poda de Jobs
  de Omeka (EasyAdmin) para el mantenimiento. No es un problema del módulo, pero
  conviene tenerlo presente en instalaciones con proposes muy frecuentes.
- **Base verificada contra el core (2026-07-24):** `dispatch()` persiste el Job y
  devuelve su id **antes** de lanzar el proceso; `PhpCli` ejecuta en background
  (`… > /dev/null 2>&1 &`); `shouldStop()` relee el estado por DQL (ve la parada de
  otro proceso); `Dispatcher::stop()` existe. El único riesgo vivo es la
  auto-detección de `phpcli_path` (verificar en contenedor).

## 8. Pruebas

- **Host (TDD real):** `ProposalStore` (escritura atómica; `read` idempotente; que
  dos writes seguidos no dejan fichero corrupto; `sweepOld` respeta el TTL);
  `AiCataloguer::propose` con un `ProgressReporter` fake (reporta las fases
  esperadas; **al `shouldStop()` lanza `JobStoppedException` y NO produce
  propuesta**; el reporter null-object no altera el comportamiento actual → no
  regresión de los 177 tests).
- **Contenedor (glue):** el Job en 2º plano de verdad, despacho que devuelve id al
  instante, polling con progreso, cancelación efectiva, reenganche, el **cruce
  estado-nativo/fichero** ante un Job que no arranca, y **que PhpCli arranca** —
  arnés de `test/container/`. **Primer paso de la implementación en contenedor:
  confirmar que un Job trivial corre en 2º plano** (des-riesga `phpcli_path` antes
  de construir lo demás).

## 9. Fuera de alcance (YAGNI)

- Lote (RF-011/TASK-011): comparte infra, aparte.
- SSE/WebSocket (empuje del servidor): la infra del host no lo trae; polling basta.
- Canal por setting (multi-servidor): descartado por simplicidad.
- Cancelar de grano fino (dentro de la cascada): mejora futura.
- Progreso por sub-paso (cada llamada LLM): grano de fase es suficiente.

## 10. Gobierno

- Al cerrar: `docs/backlog.md` (TASK-020), `docs/traceability.md`,
  `docs/project-memory.md`. Sin ADR nuevo (es transporte); nota en NFR-010 si aplica.
