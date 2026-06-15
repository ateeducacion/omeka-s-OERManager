# ADR-0003: Empaquetado y licencia del módulo

## Estado

Aceptado (2026-06-15)

## Contexto

PEND-009: `config/module.ini` y `composer.json` se dejaron sin `license` a propósito, y el nombre de paquete Composer (`ate/oer-manager`) era provisional, puesto por el agente. Ambos bloquean un `make package` publicable y las cabeceras de licencia del código.

## Alternativas consideradas

- **Licencia.** `GPL-3.0-or-later` (estándar de buena parte del ecosistema Omeka-S, copyleft) / `MIT` (permisiva) / propietaria (uso interno).
- **Nombre de paquete.** `ate/oer-manager` (coherente con el ecosistema de la organización; `LearningObjectAdapter` ya es de ATE) / otro vendor.

## Decisión

- **Nombre de paquete Composer: `ate/oer-manager`** (se confirma el provisional).
- **Licencia: `GPL-3.0-or-later`** (identificador SPDX), aplicada a `composer.json` (`license`) y `config/module.ini` (`[info] license`).
- Las **cabeceras de licencia en los ficheros de código** quedan como follow-up (pocas clases en el scaffold; se añaden al tocarlas).
- El **autor** del módulo (`module.ini [info] author`) sigue sin confirmar por el propietario; no se inventa.

## Consecuencias

- `make package` puede generar un paquete publicable con licencia definida.
- `GPL-3.0-or-later` es compatible con publicar el módulo al ecosistema Omeka (NFR-007).
- El copyleft obliga a distribuir derivados bajo la misma licencia.

## Fuentes

- `docs/requirements.md` §1 (PEND-009).
- `docs/open-questions.md` PEND-009.
- Instrucciones del propietario en el chat (2026-06-15).
