import { TERMS, buildPayload, validate, licenceState, rows } from '../core/governanceModel.js';
import { messageFor } from '../core/messages.js';
import { integrityGroups, integrityChecked, INTEGRITY_OK_TEXT, INTEGRITY_UNKNOWN_TEXT } from '../core/integrityModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';
import { GOVERNANCE_SLOT } from './drawerDetails.js';

/**
 * Licence and authorship edit form (RF-015, TASK-028 slice 3b, Task 11).
 *
 * The read view and its markup are `view/oer-manager/admin/index/drawer-details.phtml`
 * (Task 9): a `<dl>` of five rows, a notice per unconfigured vocabulary, an
 * «Editar» button and an empty `.oer-governance-form` slot, all inside
 * `.oer-area-governance`. This file fills that slot on click.
 *
 * Initialised once from `main.js`, like `ui/recatalog.js`: `initGovernance()`
 * subscribes to `GOVERNANCE_SLOT`, the event `drawerDetails.js` dispatches
 * whenever a freshly-rendered drawer contains `.oer-area-governance` (fix
 * round 1 — an earlier version had `drawerDetails.js` import and call this
 * file directly; that hardcoded the coupling in both directions, which does
 * not scale once a second editor of these same fields joins the panel).
 * `drawerDetails.js` does not know this file exists, the same way it does
 * not know `ui/recatalog.js` exists — it only announces that a slot exists.
 *
 * Unlike `ANCHOR_SLOT`, `GOVERNANCE_SLOT`'s detail carries no fetched data of
 * its own, only `{ itemId, section }` — `section` (the `.oer-area-governance`
 * element) already carries everything the form needs (current values, vocab
 * options, notices, the default rights holder, the item id and the per-render
 * CSRF hash) as JSON in its own `data-governance` attribute, server-rendered
 * by the same authenticated request that rendered the rest of the drawer.
 * This is deliberately unlike `ANCHOR_SLOT`'s `itemJson`, which `drawer.js`
 * fetches separately and unauthenticated (`fetch(apiUrl)` against `/api`) —
 * that route degrades to `null` on a private item, which would have broken
 * this form outright, so the data travels through the authenticated markup
 * instead. Either way, `governance.js` never receives the page-level `config`
 * object `main.js` builds for the other UI modules; everything it needs comes
 * from the DOM.
 *
 * Listeners built per slot event are bound to nodes inside that specific
 * `section` (never to `document`), so nothing accumulates across repeated
 * drawer opens: the old `section` and everything inside it, listeners
 * included, is discarded together the next time the drawer renders.
 *
 * Wire shape and error handling follow `ui/recatalog.js` (the closest
 * sibling that already posts to a curation-write endpoint with a CSRF hash
 * and repaints from the response): `governance[<term>]` for the four
 * single-valued fields, `governance[<term>][]` for the repeatable
 * `dcterms:creator`, one `governance[<term>][]=` (or `governance[<term>]=`)
 * with an empty value when a field is cleared — present but empty, which
 * `IndexController::collectGovernanceFields()` reads with
 * `array_key_exists()`, not an empty check. A field the curator never
 * touched is omitted from the payload entirely, so it stays untouched
 * server-side; only fields the current form values disagree with their
 * initial (as loaded, or as the default rights holder pre-filled) are sent.
 *
 * On success the response's own `values`/`licenceStatus`/`integrity` repaint
 * the section, the Licence cell of the row and the integrity banner — never
 * recomputed here, per the slice's standing rule that duplicating a
 * server-side judgement in the browser only invites the two to drift.
 */

const FIELD_ERROR_TEXT = {
    'not-http-uri': 'Debe ser una URL http o https.',
    'too-many': 'Solo se admite un valor.'
};

const SEVERITY_HEADING = {
    error: 'Errores',
    warning: 'Avisos'
};

const SEVERITY_COUNT_FORMS = {
    error: ['error', 'errores'],
    warning: ['aviso', 'avisos']
};

const SINGLE_FIELDS = [
    { key: 'licence', term: TERMS.LICENCE, vocabKey: 'licence' },
    { key: 'publisher', term: TERMS.PUBLISHER, vocabKey: 'publisher' },
    { key: 'rightsHolder', term: TERMS.RIGHTS_HOLDER, vocabKey: null },
    { key: 'source', term: TERMS.SOURCE, vocabKey: null }
];

/** @returns {object|null} the section's embedded governance data, or null if absent/malformed. */
function parseGovernance(section) {
    const raw = section.dataset.governance;
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
}

/**
 * The raw, resubmittable text of a governance value entry: its `uri` if it
 * has one (never its `label`, which is presentation only — GovernanceService
 * re-resolves a submitted URI against the vocabulary on save), else its
 * `value`. Unlike `governanceModel.js`'s `rows()` (built for the read view's
 * comma-joined display text), a form field needs the value it will actually
 * resend, which for a uri-shaped entry is the uri, not the label.
 * @param {Array<{uri?: string, value?: string}>} entries
 * @returns {string}
 */
function firstEntryRaw(entries) {
    const entry = (entries || [])[0];
    if (!entry) {
        return '';
    }
    return String(entry.uri || entry.value || '');
}

function buildFieldError() {
    const p = document.createElement('p');
    p.className = 'oer-governance-field-error';
    p.hidden = true;
    return p;
}

function buildTextInput(value) {
    const input = document.createElement('input');
    input.type = 'text';
    input.value = value;
    return input;
}

/**
 * A `<select>` of vocabulary entries, pre-selected on `currentRaw`. When
 * `currentRaw` matches none of them (outside the vocabulary, or the vocab
 * changed since the value was written), an extra option carrying the current
 * value is injected and selected — without it, opening the form would show a
 * value the field does not actually have, and an unrelated save would
 * silently discard the real one.
 */
function buildVocabSelect(vocabOptions, currentRaw, currentEntry) {
    const select = document.createElement('select');
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = Omeka.jsTranslate('(sin valor)');
    select.appendChild(empty);

    let matched = '' === currentRaw;
    vocabOptions.forEach((entry) => {
        const optionValue = String(entry.uri || entry.value || '');
        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = entry.label || optionValue;
        if (optionValue === currentRaw) {
            option.selected = true;
            matched = true;
        }
        select.appendChild(option);
    });

    if (!matched && '' !== currentRaw) {
        const extra = document.createElement('option');
        extra.value = currentRaw;
        const currentLabel = (currentEntry && currentEntry.label) || currentRaw;
        extra.textContent = `${currentLabel} ${Omeka.jsTranslate('(fuera de la lista)')}`;
        extra.selected = true;
        select.insertBefore(extra, empty.nextSibling);
    }

    return select;
}

/**
 * Live echo of `LicenceStatus::of()` via `governanceModel.js`'s `licenceState()`
 * (ADR-0020, mirrored in Task 10) — advisory only, the server re-validates on
 * save regardless. Reuses `.oer-governance-warning`, the exact class and text
 * the read view already shows for `outside_vocab` (Task 9): the same warning,
 * shown earlier.
 */
function buildLicenceHint(select, vocabOptions) {
    const vocabUris = vocabOptions.map((entry) => entry.uri).filter(Boolean);
    const warn = document.createElement('span');
    warn.className = 'oer-governance-warning';
    warn.textContent = Omeka.jsTranslate('No está en la lista de licencias aprobadas');
    const update = () => {
        const state = licenceState(select.value ? [{ uri: select.value }] : [], vocabUris);
        warn.hidden = 'outside_vocab' !== state;
    };
    select.addEventListener('change', update);
    update();
    return warn;
}

/**
 * One field of the form: licence and publisher degrade to free text when
 * their vocabulary does not resolve (`governance.notices`, same condition
 * the read view's notice already explains) or resolves with no entries.
 */
function buildVocabField(term, label, governance, vocabKey) {
    const wrapper = document.createElement('div');
    wrapper.className = 'oer-governance-field';
    wrapper.dataset.term = term;

    const labelEl = document.createElement('label');
    labelEl.textContent = Omeka.jsTranslate(label);
    wrapper.appendChild(labelEl);

    const entries = (governance.values && governance.values[term]) || [];
    const currentRaw = firstEntryRaw(entries);
    const degraded = Boolean(governance.notices && governance.notices[term]);
    const vocabOptions = (governance.options && governance.options[vocabKey]) || [];
    const useSelect = !degraded && vocabOptions.length > 0;

    const control = useSelect
        ? buildVocabSelect(vocabOptions, currentRaw, entries[0])
        : buildTextInput(currentRaw);
    control.classList.add('oer-governance-input');
    wrapper.appendChild(control);

    if (useSelect && TERMS.LICENCE === term) {
        wrapper.appendChild(buildLicenceHint(control, vocabOptions));
    }

    wrapper.appendChild(buildFieldError());

    return { el: wrapper, initial: currentRaw };
}

/** A plain single-valued text field: rights holder and source. */
function buildTextField(term, label, value) {
    const wrapper = document.createElement('div');
    wrapper.className = 'oer-governance-field';
    wrapper.dataset.term = term;

    const labelEl = document.createElement('label');
    labelEl.textContent = Omeka.jsTranslate(label);
    wrapper.appendChild(labelEl);

    const input = buildTextInput(value);
    input.classList.add('oer-governance-input');
    wrapper.appendChild(input);

    wrapper.appendChild(buildFieldError());

    return { el: wrapper, initial: value };
}

function buildAuthorRow(value) {
    const row = document.createElement('div');
    row.className = 'oer-governance-author-row';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'oer-governance-author-input';
    input.value = value;
    row.appendChild(input);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'oer-governance-remove-author';
    remove.textContent = Omeka.jsTranslate('Eliminar');
    row.appendChild(remove);

    return row;
}

/**
 * `dcterms:creator`: a list of plain-text inputs, order preserved (the first
 * is the lead author — RF-015). With no authors yet, one blank row is seeded
 * for the curator to type into; `initial` tracks that seed, not an empty
 * list, so leaving it untouched and saving does not count as a change (same
 * "baseline is what the field displays" rule the rights holder default
 * follows below).
 */
function buildAuthorsField(governance) {
    const wrapper = document.createElement('div');
    wrapper.className = 'oer-governance-field oer-governance-field-authors';
    wrapper.dataset.term = TERMS.CREATOR;

    const labelEl = document.createElement('label');
    labelEl.textContent = Omeka.jsTranslate('Autoría');
    wrapper.appendChild(labelEl);

    const list = document.createElement('div');
    list.className = 'oer-governance-authors';
    wrapper.appendChild(list);

    const entries = (governance.values && governance.values[TERMS.CREATOR]) || [];
    // Literal-only in practice: `dcterms:creator` has no vocabulary (Task 1's
    // FIELD_SPEC), so `GovernanceService::currentValues()` never gives it a
    // `uri`-shaped entry.
    const seed = entries.length ? entries.map((entry) => String(entry.value || '')) : [''];
    seed.forEach((value) => list.appendChild(buildAuthorRow(value)));

    const addButton = document.createElement('button');
    addButton.type = 'button';
    addButton.className = 'oer-governance-add-author';
    addButton.textContent = Omeka.jsTranslate('Añadir autor');
    wrapper.appendChild(addButton);

    wrapper.appendChild(buildFieldError());

    return { el: wrapper, initial: seed };
}

/**
 * The rights holder's displayed starting value: its own value if the REA has
 * one, else `governance.defaultRightsHolder` — filled in, but not yet
 * written anywhere (`initial` below is set to this same displayed value, so
 * an untouched default is not sent on save; the curator has to actually
 * confirm it, same as any other field they'd type into).
 */
function rightsHolderInitial(governance) {
    const entries = (governance.values && governance.values[TERMS.RIGHTS_HOLDER]) || [];
    if (entries.length) {
        return firstEntryRaw(entries);
    }
    return String(governance.defaultRightsHolder || '');
}

function buildForm(governance) {
    const formEl = document.createElement('div');
    formEl.className = 'oer-governance-edit-form';
    const initial = {};

    const licence = buildVocabField(TERMS.LICENCE, 'Licencia', governance, 'licence');
    formEl.appendChild(licence.el);
    initial.licence = licence.initial;

    const authors = buildAuthorsField(governance);
    formEl.appendChild(authors.el);
    initial.creator = authors.initial;

    const publisher = buildVocabField(TERMS.PUBLISHER, 'Editor', governance, 'publisher');
    formEl.appendChild(publisher.el);
    initial.publisher = publisher.initial;

    const rightsHolder = buildTextField(TERMS.RIGHTS_HOLDER, 'Titular de derechos', rightsHolderInitial(governance));
    formEl.appendChild(rightsHolder.el);
    initial.rightsHolder = rightsHolder.initial;

    const source = buildTextField(TERMS.SOURCE, 'Fuente', firstEntryRaw((governance.values || {})[TERMS.SOURCE]));
    formEl.appendChild(source.el);
    initial.source = source.initial;

    const formError = document.createElement('p');
    formError.className = 'oer-governance-form-error';
    formError.hidden = true;
    formEl.appendChild(formError);

    const actions = document.createElement('div');
    actions.className = 'oer-governance-form-actions';
    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'button oer-governance-save';
    saveButton.textContent = Omeka.jsTranslate('Guardar');
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'oer-governance-cancel';
    cancelButton.textContent = Omeka.jsTranslate('Cancelar');
    actions.appendChild(saveButton);
    actions.appendChild(cancelButton);
    formEl.appendChild(actions);

    return { formEl, initial };
}

function addAuthorRow(formEl) {
    const list = formEl && formEl.querySelector(`.oer-governance-field[data-term="${TERMS.CREATOR}"] .oer-governance-authors`);
    if (!list) {
        return;
    }
    const row = buildAuthorRow('');
    list.appendChild(row);
    const input = row.querySelector('input');
    if (input) {
        input.focus();
    }
}

/**
 * Only the fields whose current form value disagrees with what the field
 * displayed when the form was built. A field the curator never touched (or
 * touched and put back exactly as it was) is left out entirely: per
 * `governanceModel.js`'s `buildPayload()`, only keys present in this object
 * reach the wire, and an absent key is "do not touch" to the endpoint. An
 * emptied field still reaches here as a present key with an empty value
 * (or an empty array for authors) — `buildPayload()` is what turns that into
 * the explicit "clear it" the server expects.
 */
function collectFormState(formEl, initial) {
    const state = {};
    SINGLE_FIELDS.forEach(({ key, term }) => {
        const fieldEl = formEl.querySelector(`.oer-governance-field[data-term="${term}"]`);
        const control = fieldEl && fieldEl.querySelector('.oer-governance-input');
        if (!control) {
            return;
        }
        if (control.value !== initial[key]) {
            state[key] = control.value;
        }
    });

    const authorsField = formEl.querySelector(`.oer-governance-field[data-term="${TERMS.CREATOR}"]`);
    if (authorsField) {
        const current = Array.from(authorsField.querySelectorAll('.oer-governance-author-input'))
            .map((input) => input.value);
        if (JSON.stringify(current) !== JSON.stringify(initial.creator)) {
            state.creator = current;
        }
    }

    return state;
}

/**
 * `governance[<term>]` / `governance[<term>][]` pairs for `$.param()`, same
 * shape `ui/recatalog.js`'s `collectAlignmentPairs()` already uses for
 * `alignment[<term>][]`: an explicit clear is one pair with an empty value,
 * not zero pairs — `IndexController::collectGovernanceFields()` reads the
 * key with `array_key_exists()`, so the key has to arrive on the wire even
 * when there is nothing left to send for it.
 */
function buildPairs(itemId, payload, csrf) {
    const pairs = [{ name: 'id', value: itemId }];
    Object.keys(payload).forEach((term) => {
        const values = payload[term];
        const multiple = TERMS.CREATOR === term;
        if (!values.length) {
            pairs.push({ name: multiple ? `governance[${term}][]` : `governance[${term}]`, value: '' });
            return;
        }
        if (multiple) {
            values.forEach((value) => pairs.push({ name: `governance[${term}][]`, value }));
        } else {
            pairs.push({ name: `governance[${term}]`, value: values[0] });
        }
    });
    pairs.push({ name: 'csrf', value: csrf });
    return pairs;
}

function clearFormErrors(formEl) {
    formEl.querySelectorAll('.oer-governance-field-error').forEach((box) => {
        box.hidden = true;
        box.textContent = '';
    });
    const formError = formEl.querySelector('.oer-governance-form-error');
    if (formError) {
        formError.hidden = true;
        formError.textContent = '';
    }
}

function renderFieldErrors(formEl, errors) {
    Object.keys(errors).forEach((term) => {
        const fieldEl = formEl.querySelector(`.oer-governance-field[data-term="${term}"]`);
        const box = fieldEl && fieldEl.querySelector('.oer-governance-field-error');
        if (!box) {
            return;
        }
        const code = errors[term];
        box.textContent = Omeka.jsTranslate(FIELD_ERROR_TEXT[code] || code);
        box.hidden = false;
    });
}

function showFormError(formEl, message) {
    const box = formEl.querySelector('.oer-governance-form-error');
    if (!box) {
        window.alert(message);
        return;
    }
    box.textContent = message;
    box.hidden = false;
}

/**
 * Read rows, straight from `governanceModel.js`'s `rows()` over the answer's
 * own `values`/`licenceStatus` — the same function the read view's initial
 * render is built to agree with (Task 10), so a save cannot show something
 * the phtml itself would not show for the same data.
 */
function renderReadRows(section, governanceAnswer) {
    const dl = section.querySelector('dl');
    if (!dl) {
        return;
    }
    dl.textContent = '';
    rows(governanceAnswer).forEach((row) => {
        const dt = document.createElement('dt');
        dt.textContent = Omeka.jsTranslate(row.label);
        dl.appendChild(dt);

        const dd = document.createElement('dd');
        const span = document.createElement('span');
        if (row.missing) {
            span.className = 'oer-value-missing';
            span.textContent = Omeka.jsTranslate(row.missingText);
        } else {
            span.className = 'oer-value';
            span.textContent = row.text;
        }
        dd.appendChild(span);
        if (row.warning) {
            const warn = document.createElement('span');
            warn.className = 'oer-governance-warning';
            warn.textContent = Omeka.jsTranslate('No está en la lista de licencias aprobadas');
            dd.appendChild(warn);
        }
        dl.appendChild(dd);
    });
}

/**
 * The row's Licence cell in the master table (`ColumnType\Licence`, class
 * `oerLicence` — `.column-oerLicence`), same markup convention
 * `GovernanceValue::renderContent()` renders server-side and `ui/visibility.js`'s
 * `paintUpdated()` already follows for the visibility column: create the
 * `.oer-value`/`.oer-value-missing` span if the curator never added this
 * column, otherwise update it in place. A no-op if the column is not shown —
 * a curator can remove it from their own column selection.
 */
function updateLicenceCell(itemId, governanceAnswer) {
    const table = document.getElementById('oer-master-view-table');
    if (!table) {
        return;
    }
    const cell = table.querySelector(`tr[data-resource-id="${itemId}"] .column-oerLicence`);
    if (!cell) {
        return;
    }
    const licenceRow = rows(governanceAnswer).find((row) => TERMS.LICENCE === row.term);
    if (!licenceRow) {
        return;
    }
    let mark = cell.querySelector('.oer-value, .oer-value-missing');
    if (!mark) {
        mark = document.createElement('span');
        cell.textContent = '';
        cell.appendChild(mark);
    }
    mark.className = licenceRow.missing ? 'oer-value-missing' : 'oer-value';
    mark.textContent = licenceRow.missing ? Omeka.jsTranslate(licenceRow.missingText) : licenceRow.text;
}

/**
 * The integrity banner and header verdict (`.oer-panel-alert`/
 * `.oer-panel-verdict`) plus the rail attribute on both the panel and the
 * sidebar (`data-integrity`, ADR-0014 §4) — from the answer's own recomputed
 * `integrity`, grouped by `integrityModel.js`'s `integrityGroups()` exactly
 * as `drawer-details.phtml` groups it server-side. Not every field an
 * integrity issue can name has a client-side label (`TERM_LABELS` covers the
 * curriculum terms and the licence, not the other four governance terms —
 * see the report), but only the licence can realistically appear here today:
 * nothing in this codebase yet makes the other four governance terms
 * required.
 */
function renderIntegrity(panelEl, integrity) {
    const status = (integrity && integrity.status) || 'ok';
    panelEl.dataset.integrity = status;
    const sidebar = document.getElementById('oer-detail-sidebar');
    if (sidebar) {
        sidebar.dataset.integrity = status;
    }

    const header = panelEl.querySelector('.oer-panel-header');
    const meta = header && header.querySelector('.oer-panel-meta');
    const visibility = meta && meta.querySelector('.oer-panel-visibility');
    let verdict = panelEl.querySelector('.oer-panel-verdict');
    let alert = panelEl.querySelector('.oer-panel-alert');
    const groups = integrityGroups(integrity);

    if (!groups.length) {
        if (alert) {
            alert.remove();
        }
        if (!verdict && visibility) {
            verdict = document.createElement('span');
            visibility.insertAdjacentElement('afterend', verdict);
        }
        if (verdict) {
            const checked = integrityChecked(integrity);
            verdict.className = `oer-panel-verdict oer-panel-verdict-${checked ? 'ok' : 'unknown'}`;
            verdict.textContent = Omeka.jsTranslate(checked ? INTEGRITY_OK_TEXT : INTEGRITY_UNKNOWN_TEXT);
        }
        return;
    }

    if (verdict) {
        verdict.remove();
    }

    const worst = groups[0].severity;
    if (!alert) {
        alert = document.createElement('section');
        // Same position the phtml renders it in: after the header AND after
        // the reject/publish workflow section when there is one (a panel
        // with neither has `anchor` fall through to `header`, and one with
        // neither section at all falls through to prepending).
        const anchor = panelEl.querySelector('.oer-panel-workflow') || header;
        panelEl.insertBefore(alert, anchor ? anchor.nextSibling : panelEl.firstChild);
    }
    alert.className = `oer-panel-alert oer-panel-alert-${worst}`;
    alert.textContent = '';

    const heading = document.createElement('h4');
    const counts = groups.map((group) => {
        const forms = SEVERITY_COUNT_FORMS[group.severity];
        const total = group.issues.length;
        const word = forms ? (1 === total ? forms[0] : forms[1]) : group.severity;
        return `${total} ${Omeka.jsTranslate(word)}`;
    });
    heading.textContent = `${Omeka.jsTranslate('Integridad')} · ${counts.join(', ')}`;
    alert.appendChild(heading);

    groups.forEach((group) => {
        const subheading = document.createElement('h5');
        subheading.textContent = Omeka.jsTranslate(SEVERITY_HEADING[group.severity] || group.severity);
        alert.appendChild(subheading);

        const list = document.createElement('ul');
        list.className = `oer-integrity-issues oer-integrity-issues-${group.severity}`;
        group.issues.forEach((issue) => {
            const item = document.createElement('li');
            const label = TERM_LABELS[issue.field] || issue.field;
            // `issue.message` is server text, already complete — not passed
            // through `jsTranslate()`, same as the phtml never re-translates it.
            item.textContent = `${Omeka.jsTranslate(label)}: ${issue.message}`;
            list.appendChild(item);
        });
        alert.appendChild(list);
    });
}

/**
 * Wires one governance area, freshly rendered. Called from the `GOVERNANCE_SLOT`
 * listener `initGovernance()` sets up below, once per slot event — i.e. once
 * per drawer render, same cadence as the direct call this replaced in fix
 * round 1 (see the file docblock). `section` is the exact `.oer-area-governance`
 * element the event handed over, not re-queried from a wider root.
 * @param {Element} section
 */
function mountSection(section) {
    const panelEl = section.closest('.oer-detail-panel');
    const formSlot = section.querySelector('.oer-governance-form');
    if (!panelEl || !formSlot) {
        return;
    }

    let governance = null;
    let initial = null;

    function closeForm() {
        section.dataset.mode = 'read';
        formSlot.textContent = '';
        governance = null;
        initial = null;
    }

    function openForm() {
        governance = parseGovernance(section);
        if (!governance) {
            return;
        }
        const built = buildForm(governance);
        initial = built.initial;
        formSlot.textContent = '';
        formSlot.appendChild(built.formEl);
        section.dataset.mode = 'edit';
        const first = built.formEl.querySelector('select, input');
        if (first) {
            first.focus();
        }
    }

    function submit() {
        const formEl = formSlot.firstElementChild;
        if (!formEl || !governance) {
            return;
        }
        clearFormErrors(formEl);
        const state = collectFormState(formEl, initial);
        if (!Object.keys(state).length) {
            // Nothing the curator actually changed: same outcome as Cancel,
            // no round trip to a server that would answer `unchanged` anyway.
            closeForm();
            return;
        }
        const clientErrors = validate(state);
        if (Object.keys(clientErrors).length) {
            renderFieldErrors(formEl, clientErrors);
            return;
        }

        const pairs = buildPairs(governance.id, buildPayload(state), governance.csrf);
        const saveButton = formEl.querySelector('.oer-governance-save');
        if (saveButton) {
            saveButton.disabled = true;
        }
        $.post(section.dataset.governanceApplyUrl, $.param(pairs))
            .done((response) => {
                if (!response.updated) {
                    if (response.errors) {
                        renderFieldErrors(formEl, response.errors);
                        return;
                    }
                    const code = response.error || (response.unchanged ? 'unchanged' : 'unexpected');
                    showFormError(formEl, messageFor(
                        code,
                        Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')
                    ));
                    return;
                }

                const itemId = governance.id;
                const csrf = governance.csrf;
                renderReadRows(section, response);
                updateLicenceCell(itemId, response);
                renderIntegrity(panelEl, response.integrity);
                // Refresh the embedded snapshot so a second «Editar» starts
                // from what was just saved, not the page's original load —
                // the CSRF hash is not part of the answer, so it is carried
                // over from what this same load already minted.
                section.dataset.governance = JSON.stringify({
                    id: itemId,
                    values: response.values,
                    licenceStatus: response.licenceStatus,
                    options: response.options,
                    notices: response.notices,
                    defaultRightsHolder: response.defaultRightsHolder,
                    csrf
                });
                closeForm();
            })
            .fail(() => {
                showFormError(formEl, Omeka.jsTranslate('No se pudo guardar.'));
            })
            .always(() => {
                if (saveButton) {
                    saveButton.disabled = false;
                }
            });
    }

    $(section).on('click', '.oer-governance-edit', openForm);
    $(formSlot).on('click', '.oer-governance-add-author', () => addAuthorRow(formSlot.firstElementChild));
    $(formSlot).on('click', '.oer-governance-remove-author', function () {
        $(this).closest('.oer-governance-author-row').remove();
    });
    $(formSlot).on('click', '.oer-governance-cancel', closeForm);
    $(formSlot).on('click', '.oer-governance-save', submit);
}

/**
 * Entry point, called once from `main.js` — same call shape as
 * `initRecatalog(config)` and the rest of the UI modules `main.js` bootstraps.
 * No `config` parameter: unlike those, nothing here reads the page-level
 * config object (see the file docblock for where its data comes from
 * instead), so there is nothing to pass — the same shape `initWorkflowDrawer()`
 * and `initSearchForm()` already use for the same reason.
 */
export function initGovernance() {
    document.addEventListener(GOVERNANCE_SLOT, (event) => {
        mountSection(event.detail.section);
    });
}
