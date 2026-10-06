# TASK-053 — AI cataloguer reads eXeLearning packages (design)

Short spec for GitHub issue #59. It settles the open points (4–6) of the issue with the owner's answers of
2026-10-05 and applies decision 7 (detection by content) recorded in the backlog the same day.

## 1. Checked against real files

The upstream repository (`exelearning/exelearning`, `test/fixtures/`) was read on 2026-10-05:
`really-simple-test-project.elpx`, `Manual de eXeLearning 3.0.elpx` (43 iDevice types),
`todos-los-idevices_dos_informes.elpx` and the per-format exports under `test/fixtures/export/`.

- **Every export writes a bare `<ode>` root without namespace** (web, page, SCORM, IMS and the exported
  `.elpx`); only a package saved by the editor declares `xmlns="http://www.intef.es/xsd/ode"`. Detecting by
  namespace alone would miss all exports.
- The layout in the issue holds: root `content.xml` in namespace `http://www.intef.es/xsd/ode`, `pp_*`
  properties, flat `odeNavStructure` pages, blocks in `odePagStructures`, iDevices in `odeComponents`, with
  `visibility`/`teacherOnly` as key/value properties at page, block and iDevice level.
- **`jsonProperties.textTextarea` usually repeats the `htmlView` HTML.** Reading both counts the text twice.
- **DataGame payloads come in two encodings, not one.** Some types store plain JSON (`trivial`, `relaciona`,
  `flipcards`, `mapa`, …). Most quizzes (`quext`, `selecciona`, `rosco`, `completa`, `ordena`, …) store
  `escape()`d text whose code units are XOR-ed with 146. No payload in the manual used the
  `encodeURIComponent` form the upstream docs describe; it is still accepted.
- **Not every export ships a root `content.xml`.** Some `scorm`, `ims`, `web` and `page` exports have it;
  others carry the original `.elpx` as a nested file instead. Those keep the generic ZIP path (decision 7),
  and the nested `.elpx` is reported as `nested_zip` (no recursion), not as `unsupported:elpx`.
- A theme-only `.elpx` (`basic-example-with-custom-theme.elpx`) has no `content.xml` at all.

## 2. Decisions

| # | Question | Decision | Source |
| --- | --- | --- | --- |
| D1 | Entry point | `OmekaMediaSource` passes `elpx` media. `ContentExtractor` treats `.elpx` as a ZIP with the same safeguards | issue §1 |
| D2 | Detection | Any `.zip`/`.elpx` whose **root** `content.xml` parses with root element `ode` either in the ODE namespace or bare but carrying `odeProperties`/`odeNavStructures` takes the structured path. Only that entry is read for detection | owner decision 7; bare root from the real exports (§1) |
| D3 | Structured path | Package metadata (title, subtitle, description, author, language, licence, keywords) as one piece, then one piece per visible page in tree order (page name, block names, iDevice text). Rendered HTML (`index.html`, `html/`), `theme/`, `libs/`, `idevices/`, `content/css`, `content/img` are not read and do not consume `max_zip_entries`. Repeated fragments are kept once | issue §2–3 |
| D4 | iDevice patterns | All four: `htmlView` text with scripts, styles and `js-hidden` elements removed; `jsonProperties` only when `htmlView` yields nothing; DataGame payloads (plain, URI-encoded or escape+XOR 146) and `script[type=application/json]` (interactive video) through the existing JSON string filter | owner, 2026-10-05 |
| D5 | Hidden content | `teacherOnly` is included. `visibility=false` pages (with their sub-pages), blocks and iDevices are skipped | owner, 2026-10-05 |
| D6 | `content/resources/` | Whitelisted files (text-layer PDF, txt, html, json, xml) go through the existing in-ZIP parsing with every cap. Scanned PDFs and images stay out (no vision rescue) | owner, 2026-10-05 |
| D7 | Metadata use | Model context only; nothing seeds the proposal (ADR-0007) | owner, 2026-10-05 |
| D8 | Degradation | An `.elpx` without `content.xml` is recorded as `elpx_content_missing`; with an unparsable or non-ODE one, as `elpx_content_invalid`. Both then fall back to the rendered pages with the eXeLearning noise segments (`theme`, `idevices`, `css`, `img`, `custom`) added **for `.elpx` only**. A corrupt archive is `zip_unreadable`; no `ZipArchive` is `zip_unsupported` | issue §1–2 |
| D9 | XML safety | `content.xml` is read by index in memory under the entry/ratio caps and counts toward the entry and byte budget. Parsed with `LIBXML_NONET`, without `LIBXML_NOENT`/`LIBXML_DTDLOAD`; a document whose DOCTYPE declares entities is rejected as invalid | issue "Traps" |

## 3. Out of scope

The legacy `.elp` (eXeLearning 2.x), vision on images or scanned PDFs inside a package, recursion into a
nested `.elpx`, and allowing `elpx` in Omeka's upload settings (an instance setting, not this module).

## 4. Checks

New `ContentExtractorTest` cases build packages in the test with `tempZip()` (no real catalogue content):
structured extraction without the rendered duplicate, every DataGame encoding and the interactive-video JSON,
hidden vs `teacherOnly`, `jsonProperties` fallback, detection in a `.zip`/SCORM, a generic ZIP with a foreign
`content.xml` unchanged, fallback without `content.xml` with noise outside the entry budget, corrupt and
entity-declaring packages, resources and caps. `OmekaMediaSourceTest` covers the `elpx` media.
