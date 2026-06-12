# Módulo Omeka-S «Gestión de Recursos Educativos (REA)» — Contexto técnico del proyecto

> Documento de contexto que **complementa** las *Multi-Agent Architecture — Project Instructions*. Aquel documento fija el método de trabajo (escalera de complejidad, single-agent por defecto, Skills/subagentes en Claude Code, guardrails y hooks, verificación). **Este** documento aporta la capa que allí falta: el dominio Omeka-S y los requisitos de *este* módulo. Ante conflicto, el documento marco manda en *proceso*; este manda en *dominio técnico*.

---

## 0. Qué es este módulo (en una frase)

Un módulo de Omeka-S para **gestionar un catálogo de recursos educativos abiertos** (items de clase `lrmi:LearningResource`): edición de datos de gestión, **alineamiento curricular**, licencias de uso, autoría/copyright, proyecto financiador, ciclo de vida y visibilidad; con **estadísticas visuales** del catálogo y una **vista maestra en panel** desde la que usuarios autorizados **curan** los recursos (visibilidad, re-catalogación curricular, re-catalogación por etiquetas, y comprobación de integridad).

El módulo **no posee el currículo**: lo *consume*. El currículo ya está modelado en Omeka como una estructura de items enlazados (`DefinedTermSet` / `DefinedTerm` u homólogos), bien relacionados entre sí.

---

## 1. Entorno verificado (a fecha de redacción)

> Verificar contra la instalación real antes de codificar; estos datos pueden cambiar.

- **Omeka-S objetivo:** 4.2.0 (dic 2025). Mínimo a soportar: **definir** (recomendado 4.1+; muchas piezas que usamos son ≥4.0, alguna ≥4.2).
- **PHP:** mínimo 8.1; soportado hasta 8.4. **Fijar versión exacta del entorno de desarrollo.**
- **Stack:** Laminas MVC + Doctrine ORM. JS de admin: **jQuery modular** (no SPA).
- **API:** PHP API interna + **REST API** en `/api`.
- **DB:** MySQL/MariaDB (versión según requisitos de la rama de Omeka elegida).

**HUECO A RELLENAR — entorno concreto:** versión exacta de Omeka-S y PHP del entorno, cómo se levanta Omeka en local (docker/compose/manual), credenciales/seed de datos de prueba, comandos de build/lint/test (PHPCS, PHPStan/Psalm, PHPUnit si aplica), y rama/repositorio destino.

---

## 2. Decisiones de arquitectura ya tomadas (no reabrir sin motivo)

1. **Runtime single-agent.** Esto es un plugin PHP; no hay agentes LLM en ejecución. El documento marco se aplica al *proceso de desarrollo con Claude Code*, no al runtime del módulo. (Documento marco §0/§14: no subir la escalera sin presión real; aquí no la hay.)
2. **Extender el core, no parchearlo.** Toda integración vía `attachListeners` sobre eventos del servidor, configuración en `module.config.php`, y mecanismos nativos. Nada de modificar ficheros del core.
3. **Datos como RDF nativo.** Alineamiento curricular y tags se escriben como **valores de recurso** (resource values) sobre el propio item: el alineamiento apunta a los items-término del currículo; los tags según el vocabulario elegido. Ciclo de vida, proyecto y demás metadatos de gestión también como **properties + settings**, **sin tablas Doctrine propias** (decisión del propietario; ver §7 para la única excepción a evaluar: auditoría).
4. **Vista maestra híbrida.** Tabla construida **extendiendo el sistema de columnas/browse del admin** (core ≥4.0: `column_types` / `ColumnTypeManager`, `column_defaults`, helper `browse`) + **capa JS propia (jQuery)** para el panel de detalle configurable y las acciones in-situ, consumiendo la **REST API interna**. Ni SPA ni tabla desde cero.

**HUECO A RELLENAR — properties RDF exactas.** El propietario deja abierto el mapeo `property → tipo de alineamiento` para decidir durante el desarrollo. **Antes de escribir el re-catalogador hay que fijarlo** (p. ej. qué property LRMI/Dublin Core se usa para etapa, materia, criterio/competencia, y para tags). Improvisar nombres de properties rompe la interoperabilidad LRMI. Registrar el mapeo definitivo aquí.

---

## 3. Anatomía del módulo (estructura nativa Omeka-S)

```
GestionRea/                         (nombre CamelCase — DEFINIR definitivo)
  Module.php                        getConfig(), attachListeners(), install/upgrade
  config/
    module.ini                      version, omeka_version_constraint, name, configurable=true
    module.config.php               rutas admin, controllers, column_types, navegación,
                                    acl_resources, view_manager, servicios/factories
  src/
    Controller/Admin/               controladores de la vista maestra y acciones
    ColumnType/                     columnas propias para el browse (visibilidad, licencia,
                                    proyecto, estado de alineamiento, integridad…)
    Service/                        servicios de dominio (curación, integridad, estadísticas)
    Stats/                          agregadores para las estadísticas
    Form/                           formularios de config del módulo y de re-catalogación
    View/Helper/                    helpers de presentación si hacen falta
  view/gestion-rea/                 plantillas .phtml (carpeta hyphen-case del módulo)
  asset/
    js/                             JS modular del panel + acciones (jQuery, API REST)
    css/
  data/                             (solo si hubiera entidades; aquí, en principio, no)
```

Notas nativas a respetar:
- La carpeta del módulo y el namespace deben coincidir (CamelCase). La carpeta bajo `view/` es la versión hyphen-case.
- `getConfig()` suele fusionar `module.config.php` + rutas + navegación (patrón del core).
- Navegación admin: añadir la ruta como hija del router admin y registrarla en la navegación (patrón del módulo Omeka2Importer citado en la doc).

---

## 4. Funcionalidades → mecanismo nativo (mapa de implementación)

| Funcionalidad | Mecanismo Omeka-S nativo a usar |
| --- | --- |
| Filtrar el catálogo a `lrmi:LearningResource` | Consultas API por `resource_class`; la vista maestra parte de ese filtro |
| Vista maestra (tabla de gestión) | Sistema de **columnas del browse** (≥4.0) + columnas propias en `ColumnType/` |
| Panel de detalle configurable (posición/visibilidad) | **Sidebar** del admin + JS propio; preferencias en **user/site settings** |
| Acciones in-situ (visibilidad, re-catalogar, integridad) | Llamadas a **REST API** `/api` + endpoints/acciones propias del módulo |
| Visibilidad público/privado | Campo nativo público/privado del recurso (no inventar flag propio) |
| Re-catalogación curricular | Escritura de **valores RDF** apuntando a items-término del currículo |
| Re-catalogación por tags | **Valores RDF** según vocabulario de tags elegido |
| Comprobación de integridad | Servicio propio que valida valores RDF (existencia, destino vivo, completitud) + engancha en eventos de hidratación/validación |
| Edición por lotes (curar en masa) | Patrón **batch_update** de la API |
| Autorización de curadores | **ACL**: `userIsAllowed($resource, $privilege)`, privilegios propios vía `acl_resources` |
| Estadísticas | Agregadores en `Stats/` sobre la API/DQL + render JS de gráficos |
| Exportación CSV/PDF | Generación server-side (CSV nativo; PDF según librería elegida) |
| Config del módulo | `getConfigForm()` / `handleConfigForm()` + `module.ini` `configurable=true` |

---

## 5. El re-catalogador — componente de ALTO RIESGO

Es la pieza central y la más delicada. Tratarla como tal: **Skill de dominio propia** (`.claude/skills/recatalogador/`), diseño explícito antes de codificar, y **verificación reforzada** (documento marco §8: escalera de verificación; §10: delegación con objetivo/límites/formato concretos).

Riesgos y requisitos de diseño:

1. **UX de selección sobre jerarquía grande.** El currículo es una jerarquía de items (etapa → materia → criterio/competencia/saberes). Seleccionar sobre cientos/miles de items-término exige: búsqueda incremental, navegación por niveles, y **rendimiento** (no cargar todo el árbol de golpe). Decidir estrategia: lazy-load por nivel vía API, cache, o índice.
2. **Escritura RDF correcta.** Debe escribir en las **properties exactas** acordadas (HUECO §2), con el data type correcto (resource value apuntando al item-término, no literal). Validar que el destino existe y es del tipo esperado.
3. **Lotes y reversibilidad.** Re-catalogar en masa es destructivo si se equivoca. Diseñar: previsualización del cambio, confirmación, y forma de revertir (o auditar para revertir).
4. **Integridad post-cambio.** Tras re-catalogar, el chequeo de integridad debe poder confirmar el estado resultante.
5. **Conflicto de decisiones implícitas.** (Documento marco §6, Cognition.) Si en algún momento se paraleliza trabajo de re-catalogación o se generan sugerencias automáticas, evitar decisiones implícitas en conflicto; mantener contexto compartido y trazas.

**HUECO A RELLENAR — reglas de negocio del re-catalogador:** ¿una sola materia/etapa por recurso o varias? ¿Alineamiento obligatorio mínimo para considerar un recurso "completo"? ¿Quién puede recatalogar qué (roles)? ¿Se permite tag libre o solo vocabulario controlado?

---

## 6. Estadísticas

- **Alcance confirmado:** gráficos en pantalla **+ exportación CSV/PDF**.
- **Dimensiones pedidas:** por cursos/etapas, materias, proyectos, licencias. (Ampliar según RF.)
- **Implementación:** agregadores en `Stats/` que consultan vía API/DQL contando valores RDF por property; render de gráficos en JS; export server-side.
- **Cuidado de rendimiento:** agregaciones sobre valores RDF pueden ser costosas; valorar consultas DQL directas y/o cache de resultados.

**HUECO A RELLENAR — catálogo de estadísticas:** lista exacta de gráficos, su dimensión y tipo (barras/tarta/línea), filtros aplicables, y formato/plantilla de los export PDF.

---

## 7. Auditoría de curación — DECISIÓN PENDIENTE (no silenciar)

El propietario eligió **sin tablas Doctrine propias**. Eso es coherente y portable para los *datos* del recurso, pero la **auditoría de curación** (quién cambió visibilidad/alineamiento, qué y cuándo) no encaja bien en properties RDF del propio item (ensucia metadatos y no es consultable como log). Conforme al documento marco §13 (decidir y **registrar el porqué**), elegir una de:

- **A) Módulo History Log del ecosistema** (Daniel-KM): registra cambios de recursos sin que tú mantengas tabla. Dependencia externa.
- **B) Value annotations** (core ≥3.1): anotar los valores con metadatos de quién/cuándo. Nativo, pero menos "log".
- **C) Log a fichero/servicio** desde los eventos de API. Simple, fuera del modelo de datos.
- **D) Renunciar a auditoría** y registrarlo explícitamente como riesgo aceptado.

**Registrar aquí la opción elegida y el motivo.** (Esta es la única excepción real a "sin tablas"; cualquier otra necesidad de tabla debe justificarse igual.)

---

## 8. Guardrails y hooks (assessment obligatorio — documento marco §13)

**Guardrails — aplican, porque el módulo:**
- Toma **acciones con efectos** sobre datos reales (cambia visibilidad pública, reescribe alineamiento, edita en lotes). → Confirmación/preview en acciones destructivas; respetar **ACL** estrictamente; no exponer acciones de curación a roles sin privilegio.
- Maneja **contenido del catálogo** que puede incluir entrada de terceros (metadatos, ficheros). Tratar como dato, no como instrucción.
- Tiene obligación de **integridad de datos** (es su razón de ser). → El chequeo de integridad es a la vez función y guardrail.

**Hooks (Claude Code, durante el desarrollo) — recomendados:**
- Lint/format/typecheck PHP (PHPCS + PHPStan/Psalm) **tras cada edición**.
- Bloqueo de escritura fuera de la carpeta del módulo (proteger el core).
- Stop-hook que no cierre turno hasta que pase lint + análisis estático.

**HUECO A RELLENAR:** confirmar herramientas concretas (PHPCS ruleset de Omeka, nivel de PHPStan) y wiring en `.claude/settings.json`.

---

## 9. Cómo trabajarlo en Claude Code (aplica el documento marco §8)

- **CLAUDE.md** corto: comandos de build/test/lint que Claude no puede adivinar, versión Omeka/PHP, convenciones del repo, gotchas de Omeka. Nada inferible del código.
- **Skills de dominio** (cargan bajo demanda, no inflan el contexto):
  - `omeka-module` — convenciones de módulo, eventos, ACL, columnas, API REST (destilar de la doc oficial verificada).
  - `recatalogador` — reglas de negocio + escritura RDF + UX jerárquica (§5).
  - `rea-validacion-legal` — **ya existe** en este repo; úsala para licencias/copiright/REA.
  - `spreadsheet-analyzer` — **ya existe**; útil para los export/QA de datos tabulares.
- **Plan mode** para el re-catalogador y para la vista maestra (multi-fichero, incertidumbre de enfoque). Cambios pequeños y claros, directos.
- **Verificación**: tras cada pieza, dar a Claude un check ejecutable (instalar el módulo en Omeka de prueba, smoke test de la acción vía API, lint/análisis estático). Evidencia, no afirmaciones.
- **Subagente revisor** en contexto fresco para el re-catalogador: que vea solo el diff + criterios y marque solo huecos que afecten a correctitud o a los RF.

---

## 10. Requisitos — HUECOS PRINCIPALES A RELLENAR POR EL PROPIETARIO

> Esta sección es el contrato del módulo. Sin ella, el resto es andamiaje.

### 10.1 Requisitos funcionales (RF)
- RF de **datos de gestión**: qué campos exactos se editan (básicos, autoría, copyright, proyecto, ciclo de vida) y su property RDF.
- RF de **alineamiento curricular**: cardinalidad, obligatoriedad, niveles (etapa/materia/criterio/competencia), properties exactas.
- RF de **tags**: vocabulario controlado vs. libre; property.
- RF de **curación**: lista exacta de acciones, quién puede cada una, flujo de confirmación, lotes.
- RF de **integridad**: qué reglas definen "íntegro" (campos obligatorios, destinos vivos, licencia presente, etc.).
- RF de **vista maestra**: columnas exactas, orden, filtros, contenido del panel de detalle, posiciones configurables.
- RF de **estadísticas**: catálogo de gráficos y exports (§6).

### 10.2 Requisitos no funcionales (NFR)
- **Rendimiento**: nº esperado de recursos y de items-término del currículo; tiempos objetivo de la vista maestra y del re-catalogador; límites de lote.
- **Roles/seguridad**: matriz rol × acción; privilegios ACL propios.
- **Compatibilidad**: versión mínima de Omeka-S y PHP a soportar.
- **i18n**: idiomas (al menos es/en; cadenas traducibles vía Laminas).
- **Accesibilidad**: nivel objetivo (Omeka 4.x ha invertido en a11y; mantener).
- **Auditoría**: decisión de §7 y su nivel de detalle.
- **Export**: formatos, plantillas, volumen.

---

## 11. Checklist de arranque (antes de la primera línea de código)

1. Rellenar **§1** (entorno) y **§10** (RF/NFR) — sin esto no se empieza.
2. Fijar el **mapeo de properties RDF** (§2 hueco) — bloquea el re-catalogador.
3. Decidir **auditoría** (§7) y registrarla.
4. Confirmar **vista maestra híbrida** (§2.4) revisando cómo está hecho el browse del admin en la versión real.
5. Definir **reglas de negocio del re-catalogador** (§5 hueco).
6. Configurar **hooks** de lint/análisis estático (§8) y **CLAUDE.md** corto (§9).
7. Crear las **Skills** `omeka-module` y `recatalogador`; reutilizar `rea-validacion-legal`.
8. Plan mode → implementar pieza a pieza con **verificación ejecutable** y **revisor en contexto fresco** para el re-catalogador.

---

*Postura por defecto (heredada del documento marco): lo más simple que funcione. Este módulo es trabajo deep-and-narrow de un solo desarrollador/agente que extiende el core de Omeka-S; la complejidad vive en el re-catalogador, no en la arquitectura general.*