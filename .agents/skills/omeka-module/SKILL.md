---
name: omeka-module
description: "Change OERManager lifecycle, service registration, admin routes, column types, ACL or API integration."
---

# OERManager integration

Start with `Module.php`, `config/module.config.php`, and the corresponding controller/service/test.
Use the supported Omeka 4.2 API contract; confirm signatures and payloads in core before adding hooks.

- Keep `OERManager` PSR-4 wiring under `src/` and root `Module.php` as Omeka's entry point. Register
  factories, forms, controllers, column types and translations in their existing config sections.
- Register ACL rules through the existing bootstrap flow. Admin navigation's `resource` must match
  the controller ACL resource; hiding navigation never replaces server-side permission checks.
- Follow the implemented role/action matrix in the controller and current requirements. The old
  phase-one instruction granting no privileges is obsolete; PEND-005/006/007 have resolved decisions.
- Use native RDF/settings and native visibility. Do not add audit tables or change external curriculum items.
- API listeners use adapter identifiers; view events use controllers. Inspect the event emitter for
  entity versus response versus representation. Merge JSON additions instead of replacing core fields.
- Route RDF writes through the existing curation services and read `rdf-curation` first. `isPartial`
  alone does not preserve unrelated property values. Keep integrity/workflow effects consistent.
- Reuse current column types and view helpers. Translate labels and escape resource metadata for its context.

Run `make lint` and `make test`; run `make test-js` for admin browser logic. For route/ACL changes,
verify both allowed and denied direct requests in a disposable Omeka instance. Update the relevant
requirements/traceability/history when a durable decision or tracked task changes.
