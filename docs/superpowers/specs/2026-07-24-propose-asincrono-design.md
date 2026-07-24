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
4. **Cancelar:** botón «Cancelar» → para el Job (API de Omeka). El Job comprueba la
   señal de parada **entre pasos** (`shouldStop()`); corta en cuanto termina el paso
   en curso y escribe `{status:'stopped'}`.
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
en producción; temporal en los tests). Nombre `{jobId}.json`.

### 5.2 `Service\Ai\ProgressReporter` (interfaz) + implementaciones

Cómo la tubería larga informa de su fase y consulta si debe parar. Interfaz mínima:

- `report(string $step, int $done, int $total): void`
- `shouldStop(): bool`

Implementaciones:
- **`JobProgressReporter`** (producción): `report` escribe el estado en
  `ProposalStore`; `shouldStop` consulta `AbstractJob::shouldStop()` del Job.
- **Null object** (`NullProgressReporter`): no-op; es el default para el resto de
  callers y los tests de host — así el cableado del progreso es **opcional** y no
  rompe nada existente.

Se pasa **opcional** a `AiCataloguer::propose(..., ?ProgressReporter $progress = null)`.
`propose` llama `report()` al entrar en cada fase y comprueba `shouldStop()` en los
límites de fase; si para, devuelve lo obtenido hasta ahí y el Job marca `stopped`.
Los clasificadores **no cambian de firma**: el reporte es de grano de fase, emitido
desde `AiCataloguer`, que ya orquesta las fases. (Cancelar *dentro* de la cascada
curricular —grano fino— queda como mejora futura; hoy corta al acabar la fase.)

### 5.3 `Service\Ai\ProposeRunner`

«itemId + decisión (+ progress) → payload completo para el navegador». Centraliza
lo que hoy está disperso en el controlador: leer el item, `itemMetadataText`,
`filesFor`/`imagesFor`, `AiCataloguer::propose`, y `enrichLabels`. Devuelve el mismo
payload que hoy (incluido `needs_confirmation`). Lo usan el Job y el controlador.

### 5.4 `Job\AiProposeJob` (extends `Omeka\Job\AbstractJob`)

`perform()`:
1. Args `{itemId, largePdfDecision}`.
2. `$progress = new JobProgressReporter($store, $this->job)`.
3. `$payload = $runner->run($itemId, $largePdfDecision, $progress)`.
4. `$store->write($jobId, ['status'=>'completed', 'payload'=>$payload])`.
5. Si `shouldStop` cortó → `['status'=>'stopped']`. Si `LlmException`/error →
   `['status'=>'error','code'=>…]` (limpio y **reintentable**, NFR-010a, sin filtrar
   la clave). El Job queda además en el estado nativo de Omeka correspondiente.

Identidad: el Job tiene **owner** (quien lo despachó) → la ACL del propose se
preserva.

### 5.5 `IndexController`

- `aiProposeAction`: CSRF/ACL/`aiEnabled`/id **síncronos** (instantáneos). Lanza
  `sweepOld` oportunista; **despacha** `AiProposeJob`; devuelve `{jobId}`. Si el
  despacho falla, error limpio.
- `aiProposeStatusAction` (polling): CSRF; lee el Job **por la API** (`read('jobs',
  $jobId)` → **acotado por ACL**: solo el dueño/admin). Devuelve `read($jobId)` del
  store (progreso, resultado, error o stopped). Si el Job existe pero aún no hay
  fichero → `in_progress` de arranque.
- `aiProposeCancelAction`: CSRF; **para** el Job (dueño/admin) vía la API/dispatcher.
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

## 8. Pruebas

- **Host (TDD real):** `ProposalStore` (escritura atómica; `read` idempotente;
  `sweepOld` respeta el TTL); `AiCataloguer::propose` con un `ProgressReporter` fake
  (reporta las fases esperadas; para al `shouldStop()` y devuelve lo parcial).
- **Contenedor (glue):** el Job en 2º plano de verdad, despacho, polling con
  progreso, cancelación, reenganche, y **que PhpCli arranca** — arnés de
  `test/container/`.

## 9. Fuera de alcance (YAGNI)

- Lote (RF-011/TASK-011): comparte infra, aparte.
- SSE/WebSocket (empuje del servidor): la infra del host no lo trae; polling basta.
- Canal por setting (multi-servidor): descartado por simplicidad.
- Cancelar de grano fino (dentro de la cascada): mejora futura.
- Progreso por sub-paso (cada llamada LLM): grano de fase es suficiente.

## 10. Gobierno

- Al cerrar: `docs/backlog.md` (TASK-020), `docs/traceability.md`,
  `docs/project-memory.md`. Sin ADR nuevo (es transporte); nota en NFR-010 si aplica.
