import { TERMS, licenceState } from '../core/governanceModel.js';

/**
 * Governance field widgets (RF-015), shared by the item panel's form
 * (`ui/governance.js`, slice 3b) and the batch form (`ui/governanceBatch.js`,
 * slice 4). Moved here verbatim so both editors render and read fields the
 * same way; no behaviour lives here that is not already in slice 3b.
 */

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
export function firstEntryRaw(entries) {
    const entry = (entries || [])[0];
    if (!entry) {
        return '';
    }
    return String(entry.uri || entry.value || '');
}

export function buildFieldError() {
    const p = document.createElement('p');
    p.className = 'oer-governance-field-error';
    p.hidden = true;
    return p;
}

export function buildTextInput(value) {
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
export function buildVocabSelect(vocabOptions, currentRaw, currentEntry) {
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
export function buildLicenceHint(select, vocabOptions) {
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
export function buildVocabField(term, label, governance, vocabKey) {
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
export function buildTextField(term, label, value) {
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

export function buildAuthorRow(value) {
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
export function buildAuthorsField(governance) {
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
export function rightsHolderInitial(governance) {
    const entries = (governance.values && governance.values[TERMS.RIGHTS_HOLDER]) || [];
    if (entries.length) {
        return firstEntryRaw(entries);
    }
    return String(governance.defaultRightsHolder || '');
}

export function addAuthorRow(formEl) {
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
