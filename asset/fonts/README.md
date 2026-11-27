# Bundled fonts (ADR-0024)

Served from the module, never from a CDN: loading them from a third party would send each curator's IP
to it from the production admin. Public Sans and IBM Plex Mono are used only inside the curricular
alignment editor (ADR-0024). The wizard to add a REA bundled Inter until it moved into the admin's work
area; it now inherits the admin's Lato (ADR-0025 addendum 1).

| File | Family | Source | Licence |
| --- | --- | --- | --- |
| `public-sans-latin-wght-normal.woff2` | Public Sans (variable, 100–900) | `@fontsource-variable/public-sans@5.3.0` | SIL OFL 1.1 (`OFL-PublicSans.txt`) |
| `ibm-plex-mono-latin-500-normal.woff2` | IBM Plex Mono 500 | `@fontsource/ibm-plex-mono@5.3.0` | SIL OFL 1.1 (`OFL-IBMPlexMono.txt`) |

Latin subset: it covers Spanish. To update, download the same files from a newer version and
replace them.
