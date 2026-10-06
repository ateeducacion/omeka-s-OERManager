# AI cataloguer on eXeLearning packages — analysis of four real REA (2026-10-06)

Evidence behind TASK-054 to TASK-057 and RF-020. Requested by the owner on 2026-10-06 while validating
TASK-053 (branch `feature/task-053-elpx-extraction`): «no termina de extraer bien el contexto». The
cataloguer is a key piece of the project, so the whole flow was traced, not only extraction.

## 1. Method

All read-only. Extraction was re-run in the container with the branch code (no LLM). Classification was
traced from the two stored proposals of «Código gomero» (jobs 497/498) and from three new real runs of
`test/container/propose-harness.php` (propose only, never apply) on the other three REA. Configured models
at the time: classification `openai/gpt-4o-mini`, extraction/distillation `openai/gpt-5.4-nano`, temperature
0.3, vision off.

**Ground truth.** Every package carries an eXeLearning `lomloe` iDevice where its authors selected the
curriculum elements (stage, course, area, competences, criteria, basic knowledge, with code and text).
Every declared criterion/knowledge code exists in the catalogue as `dcterms:identifier` of **exactly one**
curriculum item (21/21), and the declared course resolves to exactly one course item by title (4/4).
Specific competences (`…C5`) exist once per course and need the declared course to disambiguate.

| REA (item) | Declared by the authors | Extracted / truncated | Proposed course | Hits vs declared leaves |
| --- | --- | --- | --- | --- |
| Código gomero (#41437) | 4º Primaria · Conocimiento del Medio + Educación Artística | 17.8k / no | 1º Infantil 0 años, 4º Infantil 3 años | 0 / 8 (18 extra) |
| Timanfaya volcánica (#41440) | 6º Infantil de 5 años · Descubrimiento y exploración del entorno | 24k / **yes** | 1º, 4º, 5º Infantil | 0 / 6 (16 extra) |
| Geometría canaria (#41435) | 4º Primaria · Matemáticas | 10k / no | 4º–6º Infantil, 1º Primaria | 0 / 2 (18 extra) |
| La vivienda inteligente (#41433) | 4º ESO · Tecnología | 24k / **yes** | 1º Bachillerato, 1º–3º ESO | 0 / 3 (11 extra) |

**0 of 19 declared leaves proposed; the course is wrong in 4 of 4.** The distilled summary was good in all
four and quoted the level verbatim («2º Ciclo EP», «segundo ciclo de EI», «Educación Primaria. Segundo
ciclo», «Tecnología 4º ESO»).

## 2. Extraction (`ContentExtractor`, TASK-053 branch)

The package is read through `content.xml`, page by page; the rendered HTML is not mixed in. Game questions
are decoded (quiz 24/24, guess 19/19, identify 18/18 in «Código gomero»). Defects:

1. **The declared alignment is lost.** `odeComponentTexts()` reads `jsonProperties` only when `htmlView` is
   empty. The `lomloe` `htmlView` is a code table, so at most bare codes reach the model, never their text
   (0 of 19 statements in any extracted text).
2. **Game UI strings.** `exeGameStrings()` keeps every string of the game JSON, including the `msgs`
   dictionary («¡Genial!», «Tu tiempo ha finalizado», «%s tarjetas»…): 25 %, 12 % and 9 % of the extracted
   text where measurable. The rubric iDevice yields its UI («Reiniciar», «Imprimir», «Ventana nueva»…).
3. **Budget.** `max_total_chars` = 24 000 is split equally per page. In «Timanfaya» each content page keeps
   ≈1.9k of 15k–63k characters and most game content is cut (classify 0/67, map 7/72 strings); in
   «La vivienda inteligente» the three content pages keep ≈6.5k each (classify 22/69, map 3/27).

Not defects: «Guía didáctica» is empty in the packages themselves; a PDF inside «Timanfaya» fails with
`pdf_iconv_unsupported`, the Alpine image issue already tracked in TASK-024(b)/TASK-031.

## 3. Classification (`CurricularClassifier`)

The failure is structural and repeats in all four runs:

1. **Inclusive stage step.** `ETAPA_GUIDANCE` asks to include every possible stage. With the stage quoted
   verbatim, the model still added one or two stages in 4/4 (Infantil + Primaria; Infantil + Primaria + ESO;
   Bachillerato + ESO).
2. **Candidate starvation — the extra stages are not harmless.** `gatherLeaves()` walks stages × subjects in
   order (Infantil first) and **returns as soon as `LEAF_CAP` = 200 is reached**. In «Geometría canaria» the
   subject step chose Matemáticas, yet not one Matemáticas candidate reached the knowledge step, nor any
   4º Primaria item: the cap was filled by Infantil and 1º–2º Primaria. The code comment and ADR-0010 assume
   that an extra stage is harmless; with the cap, it pushes the right candidates out.
3. **Subject candidates carry no course and are deduplicated by name** (`gatherFamilies()`). In «La vivienda
   inteligente» (declared «Tecnología», 4º ESO, with «Tecnología 4º ESO» quoted in the summary) the model chose
   «Tecnología e Ingeniería I» (1º Bachillerato) and «Tecnología y Digitalización» (1º–3º ESO); no 4º ESO
   candidate existed afterwards.
4. **Block candidates carry no subject or course and are deduplicated by title only** (`prefilterByBlock()`):
   the list mixes two near-identical sets of Infantil blocks with Primaria blocks under one label. In
   «Código gomero» the block that held every declared knowledge item («III. Sociedades y territorios») was
   dropped.
5. **Course is never asked**; it is derived from the chosen leaves (ADR-0010), so once the candidates are
   wrong the course cannot recover.
6. **Thematic axes are over-inclusive** (6–7 axes such as «Salud afectivo-sexual», «Huertos escolares» on
   resources about the whistled language or volcanoes).

The small classification model may add noise, but items 2–5 make the right answer unreachable whatever the
model; they come first.

## 4. Resulting work

- **TASK-054** extraction depth (items 2.1–2.3).
- **TASK-055 / RF-020** use the alignment declared in the package (section 1 shows it resolves exactly). Owner decisions of 2026-10-06: it guides the model, it is not proposed as-is; only knowledge and criteria; on divergence the curator is warned and decides, only when the declared codes resolve in the catalogue.
- **TASK-056** classifier candidate starvation and ambiguous labels (items 3.1–3.6).
- **TASK-057** an evaluation set built from packages with a declared alignment, scored with the existing
  `EvaluationScorer`, so every change above is measured against this 0/19 baseline.
