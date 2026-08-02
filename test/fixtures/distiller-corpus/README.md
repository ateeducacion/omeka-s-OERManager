# Corpus de evaluación del destilador (TASK-022)

Casos **reales** del catálogo del propietario (Omeka `localhost:8080`,
`resource_class_id=4758`), extraídos con el `ContentExtractor` endurecido de
TASK-022 el 2026-07-07. Sirven para (a) fijar los ejemplos few-shot del prompt
(`PromptBuilder::DISTILLATION_EXAMPLES`) y (b) evaluar regresiones de la ficha.

Cada `item-<id>.md` contiene:

- **Procedencia**: id, título, tipo de medio, y notas del diagnóstico.
- **Extracto** (recortado) del texto que hoy produce el `ContentExtractor` — la
  entrada real que ve el destilador. No es el binario original (no se versiona
  media pesada); es lo que llega al LLM.
- **Ficha de referencia**: la ficha ideal que debería producir el destilador.
  Redactada del contenido real; **pendiente de validación del propietario**.

## Cobertura (por qué estos seis)

| Item | Medio | Qué ejercita |
| --- | --- | --- |
| 4674 | PDF limpio | Nivel citado LITERAL («1º ESO MATEMÁTICAS») → few-shot ejemplo 1 |
| 3181 | SCORM Netex | Ruido técnico que hay que ignorar → few-shot ejemplo 2 |
| 4359 | SCORM Netex | Ruido de interfaz + sin metadatos de item |
| 40437 | PDF 15 MB | Caso bueno largo (reparto de presupuesto) |
| 40442 | PDF escaneado | `pdf_empty` → solo señal por visión (requiere `vision_enabled`) |
| 37129 | SCORM 41 MB | Vendor CKEditor + contenido al final del ZIP (denylist + reparto) |

## Protocolo de evaluación funcional (contenedor, propietario)

La medición con LLM real no es automatizable en el host (sin red ni clave). En el
contenedor:

1. Ejecutar «Proponer con IA» sobre los 6 items y capturar la **ficha** del panel
   de debug (`debug.ficha`).
2. Comparar cada ficha con la de referencia de este corpus: ¿coincide el Tema y
   el «Qué enseña»? ¿aparece el «Nivel citado» esperado? ¿se ha colado ruido
   técnico?
3. Criterio de éxito (coherente con ADR-0012): **tasa de acuerdo** ficha↔referencia
   sobre repeticiones, no identidad literal.

Los tests golden en host (`ContentExtractorTest`, `PromptBuilderTest`) cubren la
parte determinista: extracción limpia y presencia de guía/ejemplos en el prompt.

## Estado de la medición

| Casos | Medido | Dónde |
| --- | --- | --- |
| PDF (#4674, #40437, #40442) | 2026-08-02, pila glibc, 3 repeticiones | [despliegue-pruebas-pdf.md](../../../docs/referencia/despliegue-pruebas-pdf.md) §6 |
| ZIP/SCORM (#3181, #4359, #37129) | 2026-07-22 en Alpine, **rehecho el 2026-08-02** sobre glibc + `zip`, 3 repeticiones | ídem §6 |

Acuerdo ficha↔referencia en Tema y «Qué enseña»: **18/18 ejecuciones**, corpus completo.
Ruido vendor (`ckeditor`, `apollo`, `wiris`, `imagelink_`…) en los SCORM: **0 apariciones**.
**Caducado:** #40442 perdió sus medios (ver su ficha); ya no cubre «PDF escaneado → visión».
