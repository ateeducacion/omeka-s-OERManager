# ADR-0001: Registros de gobierno en texto plano versionable

## Estado

Aceptado (2026-06-12, FASE 0)

## Contexto

El módulo Omeka-S «GestionRea» (nombre provisional) se desarrollará con Claude Code de forma trazable y verificable. Antes de escribir código hace falta un sitio estable donde vivan requisitos, decisiones, tareas y trazabilidad, de modo que: (a) los huecos sin decidir queden registrados como `[PENDIENTE]` en lugar de inventarse después; (b) la historia de decisiones sea auditable; (c) cualquier agente o humano que entre al repo encuentre el mismo contrato. Hay numerosos valores aún sin decidir por el propietario (PEND-001…PEND-008 en `docs/requirements.md`), lo que hace especialmente importante distinguir lo decidido de lo pendiente.

## Alternativas consideradas

- **A) Texto plano versionable en el repo (Markdown/YAML)** — diff en git, una fuente por dato, sin dependencias; lo lee igual un humano que un agente. Contra: disciplina manual para mantenerlo al día.
- **B) Herramienta externa (issues de GitHub/GitLab, Notion, Jira)** — buen flujo para humanos. Contra: fuera del repo (el agente no lo ve en contexto), no diffable junto al código, dependencia de servicio externo, riesgo de divergencia.
- **C) Sin registros formales (decisiones en el chat)** — coste cero hoy. Contra: las decisiones se pierden entre sesiones, los huecos se rellenan con conjeturas y no hay trazabilidad requisito→tarea→criterio.

## Decisión

Materializamos todo el gobierno del desarrollo como **texto plano versionable dentro del repo**: `CLAUDE.md`, `docs/requirements.md`, `docs/backlog.md`, `docs/traceability.md`, `docs/project-memory.md` y `docs/decisions/` (ADRs formato Nygard), más la configuración de Claude Code en `.claude/` (Skills y `settings.json` con hooks/guardrails). Reglas asociadas:

1. **Una sola fuente por dato** (single source of truth); el resto enlaza, no duplica.
2. **Append-only para la historia**: ADRs y decisiones no se borran ni reescriben; se añaden entradas nuevas o se marcan `[OBSOLETO]` / «Reemplazado por ADR-NNNN».
3. **IDs correlativos estables** (`RF-NNN`, `NFR-NNN`, `PEND-NNN`, `ADR-NNNN`, `TASK-NNN`); no se reutilizan ni renumeran.
4. **Trazabilidad obligatoria**: requisito ↔ decisión ↔ tarea ↔ criterio de aceptación; eslabón roto = defecto.
5. **Sin secretos** en ningún registro ni en `CLAUDE.md`.
6. **Preferir preguntar a inventar**: lo no decidido se registra como `[PENDIENTE]` con ID.

## Consecuencias

- Más fácil: retomar el trabajo entre sesiones, auditar por qué se decidió algo, detectar huecos antes de codificar, hacer review por diff.
- Más difícil: hay que mantener los registros al cerrar cada tarea/decisión (coste de disciplina; mitigado porque el agente lo tiene como instrucción en `CLAUDE.md`).
- Riesgo aceptado: si los registros no se actualizan, divergen del código; la matriz de trazabilidad sirve de detector (eslabones rotos).

## Fuentes

- `docs/referencia/multi-agent-architecture.md` §8 (CLAUDE.md, Skills, hooks, verificación) y §13 (guardrails/hooks).
- `docs/referencia/contexto-modulo-rea.md` §9 y §11 (checklist de arranque).
- Michael Nygard, *Documenting Architecture Decisions* (formato ADR).
- Instrucciones del propietario en el chat de la FASE 0 (2026-06-12).
