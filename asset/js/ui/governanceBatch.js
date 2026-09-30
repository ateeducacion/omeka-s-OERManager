import {
    validateBatch,
    previewPairs,
    selectionAfterToggle,
    stripModel,
    previewModel,
    resultModel,
    fieldToggles
} from '../core/governanceBatchModel.js';
import { TERMS } from '../core/governanceModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';
import { buildVocabField, buildTextField, buildAuthorsField, addAuthorRow } from './governanceFields.js';
import { selectedIds, refreshSelectionUi } from './visibility.js';

/**
 * Batch licence and authorship (TASK-028 slice 4): the «select all matching»
 * strip, the form served into `#oer-batch-sidebar`, preview → confirm → job
 * progress, and reattach after a reload. Decisions live in
 * core/governanceBatchModel.js; this file only touches the DOM and the network.
 */

const JOB_KEY = 'oer-governance-batch-job';
const POLL_MS = 2000;
const MAX_POLL_RETRIES = 3;

const FIELD_ERROR_TEXT = {
    required: 'Escribe o elige un valor.',
    'not-http-uri': 'Debe ser una URL http o https.',
    'too-many': 'Solo se admite un valor.',
    invalid: 'Valor no válido.'
};

const PREVIEW_ERROR_TEXT = {
    'no-field': 'Marca al menos un campo.',
    mode: 'Modo no válido.',
    empty: 'La selección no contiene REA.',
    too_many: 'La selección supera el máximo permitido por lote; acota el filtro.',
    class_unresolved: 'No se encuentra la clase de REA en esta instalación.',
    computed_truncated: 'El filtro de integridad o de anclaje supera el tope de cálculo; combínalo con otro filtro.',
    plan_expired: 'La previsualización ha caducado. Vuelve a previsualizar.',
    dispatch: 'No se pudo iniciar el lote.',
    csrf: 'La sesión ha caducado. Recarga la página.',
    id: 'El lote ya no está disponible.',
    not_found: 'El lote ya no está disponible.',
    not_batch: 'El lote ya no está disponible.',
    unexpected: 'Error inesperado. Consulta el registro.'
};

const JOB_ERROR_TEXT = {
    job_died: 'El proceso del lote se interrumpió antes de terminar. Revisa el registro de trabajos.',
    job_error: 'El lote terminó con error. Revisa el registro de trabajos.',
    job_stopped: 'El lote se detuvo.',
    job_completed: 'El lote terminó; su resumen ya no está disponible.',
    unexpected: 'El lote terminó con error. Revisa el registro de trabajos.'
};

const FAILURE_TEXT = {
    denied: 'sin permiso',
    not_found: 'ya no existe',
    invalid: 'valor no válido',
    unexpected: 'error inesperado'
};

let selection = { scope: 'ids', pageCount: 0, checked: 0, totalMatching: 0 };
// One poller for the whole page: reopening the sidebar must not start another.
let pollTimer = null;
// Set by the mounted form: repaints its target sentence and drops a preview
// that no longer matches the selection.
let onSelectionChange = null;

function t(text) {
    return Omeka.jsTranslate(text);
}

function safeStorage(action) {
    try {
        return action(window.localStorage);
    } catch (error) {
        return null;
    }
}

function post(url, pairs) {
    const body = new URLSearchParams();
    pairs.forEach(([name, value]) => body.append(name, value));
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
        .then((response) => response.json())
        .catch(() => ({ error: 'unexpected' }));
}

function currentQuery() {
    const params = new URLSearchParams(window.location.search);
    params.delete('page');
    return params.toString();
}

function paintStrip() {
    const strip = document.querySelector('.oer-select-matching-strip');
    if (!strip) {
        return;
    }
    const model = stripModel(selection);
    strip.hidden = !model.visible;
    if (!model.visible) {
        return;
    }
    const text = 'matching-all' === model.text ? strip.dataset.matchingAll : strip.dataset.pageAll;
    strip.querySelector('.oer-select-matching-text').textContent =
        text.replace('%1$s', 'matching-all' === model.text ? model.count : selection.pageCount);
    const action = strip.querySelector('.oer-select-matching-action');
    action.dataset.action = model.action;
    action.textContent = ('clear' === model.action ? strip.dataset.clear : strip.dataset.selectMatching)
        .replace('%1$s', model.count);
}

function targetText() {
    return 'matching' === selection.scope
        ? t('Se aplicará a los %1$s REA que coinciden con el filtro.').replace('%1$s', selection.totalMatching)
        : t('Se aplicará a %1$s REA seleccionados.').replace('%1$s', selectedIds().length);
}

function buildFields(root, governance) {
    const container = root.querySelector('.oer-batch-fields');
    const intro = document.createElement('p');
    intro.className = 'oer-batch-intro';
    intro.textContent = t('Marca los campos que quieres cambiar; los demás no se tocan.');
    container.appendChild(intro);
    const builders = {
        licence: () => buildVocabField(TERMS.LICENCE, 'Licencia', governance, 'licence'),
        creator: () => buildAuthorsField(governance),
        publisher: () => buildVocabField(TERMS.PUBLISHER, 'Editor', governance, 'publisher'),
        rightsHolder: () => buildTextField(TERMS.RIGHTS_HOLDER, 'Titular de derechos', String(governance.defaultRightsHolder || ''))
    };
    fieldToggles(governance.notices).forEach(({ key, text, notice }) => {
        const toggle = document.createElement('label');
        toggle.className = 'oer-batch-field-toggle';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'oer-batch-assign';
        box.dataset.key = key;
        toggle.appendChild(box);
        toggle.appendChild(document.createTextNode(` ${t(text)}`));
        container.appendChild(toggle);
        if (notice) {
            const p = document.createElement('p');
            p.className = 'oer-governance-notice';
            p.textContent = t(notice);
            container.appendChild(p);
        }
        const field = builders[key]().el;
        field.hidden = true;
        field.dataset.key = key;
        box.addEventListener('change', () => {
            field.hidden = !box.checked;
        });
        container.appendChild(field);
    });
}

function collectTicked(root) {
    const ticked = {};
    root.querySelectorAll('.oer-batch-assign:checked').forEach((box) => {
        const field = root.querySelector(`.oer-governance-field[data-key="${box.dataset.key}"]`);
        if ('creator' === box.dataset.key) {
            ticked.creator = Array.from(field.querySelectorAll('.oer-governance-author-input')).map((input) => input.value);
        } else {
            ticked[box.dataset.key] = field.querySelector('.oer-governance-input').value;
        }
    });
    return ticked;
}

function showErrors(root, errors) {
    root.querySelectorAll('.oer-governance-field-error').forEach((p) => {
        p.hidden = true;
    });
    const general = root.querySelector('.oer-batch-error');
    general.hidden = true;
    Object.entries(errors).forEach(([key, code]) => {
        const field = '_' === key ? null : root.querySelector(`.oer-governance-field[data-term="${key}"]`);
        const target = field ? field.querySelector('.oer-governance-field-error') : general;
        target.textContent = t(FIELD_ERROR_TEXT[code] || PREVIEW_ERROR_TEXT[code] || PREVIEW_ERROR_TEXT.unexpected);
        target.hidden = false;
    });
}

function renderPreview(root, response, onApply) {
    const box = root.querySelector('.oer-batch-preview');
    box.textContent = '';
    const model = previewModel(response);
    const table = document.createElement('table');
    model.rows.forEach((row) => {
        const tr = document.createElement('tr');
        const label = document.createElement('th');
        label.textContent = t(TERM_LABELS[row.term] || row.term);
        const write = document.createElement('td');
        write.textContent = t('se escribirá en %1$s').replace('%1$s', row.write);
        const other = document.createElement('td');
        other.textContent = 'overwrite' === row.otherKind
            ? t('se sobrescribirán %1$s').replace('%1$s', row.other)
            : t('ya tenían valor %1$s (se omiten)').replace('%1$s', row.other);
        if ('overwrite' === row.otherKind && row.other > 0) {
            other.className = 'oer-batch-overwrite';
        }
        tr.append(label, write, other);
        table.appendChild(tr);
    });
    box.appendChild(table);

    if (!model.canApply) {
        const none = document.createElement('p');
        none.textContent = t('No hay nada que escribir: todos los REA ya tienen valor en los campos marcados.');
        box.appendChild(none);
        box.hidden = false;
        return;
    }

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.className = 'button oer-batch-apply';
    apply.textContent = t('Aplicar a %1$s REA').replace('%1$s', response.total);
    if (model.needsOverwriteConfirm) {
        const confirm = document.createElement('label');
        const box2 = document.createElement('input');
        box2.type = 'checkbox';
        confirm.appendChild(box2);
        confirm.appendChild(document.createTextNode(
            ` ${t('Entiendo que se sobrescribirán %1$s valores existentes').replace('%1$s', model.overwriteTotal)}`
        ));
        apply.disabled = true;
        box2.addEventListener('change', () => {
            apply.disabled = !box2.checked;
        });
        box.appendChild(confirm);
    }
    apply.addEventListener('click', () => {
        apply.disabled = true;
        onApply(response.token);
    });
    box.appendChild(apply);
    box.hidden = false;
}

function renderProgress(root, state, urls, csrf) {
    const box = root.querySelector('.oer-batch-progress');
    box.hidden = false;
    box.textContent = '';
    const model = resultModel(state);
    if (!model.finished) {
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = model.percent;
        const label = document.createElement('p');
        label.textContent = state.total
            ? t('%1$s de %2$s REA').replace('%1$s', state.done).replace('%2$s', state.total)
            : t('Iniciando…');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = t('Cancelar');
        cancel.addEventListener('click', () => {
            cancel.disabled = true;
            post(urls.cancel, [['csrf', csrf], ['jobId', String(state.jobId)]]);
        });
        box.append(bar, label, cancel);
        return;
    }
    const summary = document.createElement('p');
    if ('error' === model.status) {
        summary.textContent = t(JOB_ERROR_TEXT[model.code] || JOB_ERROR_TEXT.unexpected);
    } else {
        const tallies = model.tallies;
        summary.textContent = t('%1$s escritos · %2$s omitidos (ya tenían valor) · %3$s sin cambios · %4$s fallidos')
            .replace('%1$s', tallies.written || 0)
            .replace('%2$s', tallies.skipped || 0)
            .replace('%3$s', tallies.unchanged || 0)
            .replace('%4$s', model.failed.length);
        if ('stopped' === model.status) {
            summary.textContent = `${t('Lote cancelado.')} ${summary.textContent}`;
        }
    }
    box.appendChild(summary);
    if (model.batch) {
        const id = document.createElement('p');
        id.textContent = t('Identificador del lote: %1$s').replace('%1$s', model.batch);
        box.appendChild(id);
    }
    if (model.failed.length) {
        const list = document.createElement('ul');
        model.failed.forEach(({ id, code }) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = urls.item.replace('__ID__', String(id));
            link.target = '_blank';
            link.textContent = `#${id}`;
            li.append(link, ` — ${t(FAILURE_TEXT[code] || FAILURE_TEXT.unexpected)}`);
            list.appendChild(li);
        });
        box.appendChild(list);
    }
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.className = 'button';
    reload.textContent = t('Recargar la vista');
    reload.addEventListener('click', () => window.location.reload());
    box.appendChild(reload);
}

function poll(root, jobId, urls, csrf, retries = 0) {
    window.clearTimeout(pollTimer);
    if (!root.isConnected) {
        return;
    }
    post(urls.status, [['csrf', csrf], ['jobId', String(jobId)]]).then((state) => {
        if (state.error) {
            // A network blip must not lose a long batch's progress display.
            if ('unexpected' === state.error && retries < MAX_POLL_RETRIES) {
                pollTimer = window.setTimeout(() => poll(root, jobId, urls, csrf, retries + 1), POLL_MS * (retries + 2));
                return;
            }
            safeStorage((storage) => storage.removeItem(JOB_KEY));
            showErrors(root, { _: state.error });
            return;
        }
        renderProgress(root, { ...state, jobId }, urls, csrf);
        if (resultModel(state).finished) {
            safeStorage((storage) => storage.removeItem(JOB_KEY));
            return;
        }
        pollTimer = window.setTimeout(() => poll(root, jobId, urls, csrf), POLL_MS);
    });
}

function mountForm(root) {
    let governance;
    try {
        governance = JSON.parse(root.dataset.governance);
    } catch (error) {
        return;
    }
    const urls = {
        preview: root.dataset.previewUrl,
        apply: root.dataset.applyUrl,
        status: root.dataset.statusUrl,
        cancel: root.dataset.cancelUrl,
        item: root.dataset.itemUrl
    };
    const csrf = root.dataset.csrf;

    const pending = safeStorage((storage) => storage.getItem(JOB_KEY));
    if (pending) {
        onSelectionChange = null;
        ['.oer-batch-target', '.oer-batch-fields', '.oer-batch-mode', '.oer-batch-actions'].forEach((selector) => {
            root.querySelector(selector).hidden = true;
        });
        poll(root, pending, urls, csrf);
        return;
    }

    const targetEl = root.querySelector('.oer-batch-target');
    const previewBox = root.querySelector('.oer-batch-preview');
    // A preview is a frozen plan: once the fields, the mode or the selection
    // change, its Apply button would run values the form no longer shows.
    const invalidatePreview = () => {
        previewBox.hidden = true;
        previewBox.textContent = '';
    };
    onSelectionChange = () => {
        if (!root.isConnected) {
            onSelectionChange = null;
            return;
        }
        targetEl.textContent = targetText();
        invalidatePreview();
    };
    targetEl.textContent = targetText();
    buildFields(root, governance);
    ['input', 'change'].forEach((type) => {
        root.querySelector('.oer-batch-fields').addEventListener(type, invalidatePreview);
        root.querySelector('.oer-batch-mode').addEventListener(type, invalidatePreview);
    });
    root.addEventListener('click', (event) => {
        if (event.target.closest('.oer-governance-add-author')) {
            addAuthorRow(root);
            invalidatePreview();
        }
        const remove = event.target.closest('.oer-governance-remove-author');
        if (remove) {
            remove.closest('.oer-governance-author-row').remove();
            invalidatePreview();
        }
    });

    root.querySelector('.oer-batch-preview-button').addEventListener('click', () => {
        const ticked = collectTicked(root);
        const errors = validateBatch(ticked);
        if (Object.keys(errors).length) {
            showErrors(root, errors);
            return;
        }
        showErrors(root, {});
        const mode = root.querySelector('input[name="oer-batch-mode"]:checked').value;
        const target = 'matching' === selection.scope
            ? { scope: 'matching', query: currentQuery() }
            : { scope: 'ids', ids: selectedIds() };
        post(urls.preview, previewPairs({ ticked, mode, csrf, selection: target })).then((response) => {
            if (response.error) {
                showErrors(root, response.errors || { _: response.error });
                return;
            }
            renderPreview(root, response, (token) => {
                post(urls.apply, [['csrf', csrf], ['token', token]]).then((applied) => {
                    if (applied.error) {
                        showErrors(root, { _: applied.error });
                        return;
                    }
                    safeStorage((storage) => storage.setItem(JOB_KEY, String(applied.jobId)));
                    root.querySelector('.oer-batch-preview').hidden = true;
                    root.querySelector('.oer-batch-actions').hidden = true;
                    poll(root, applied.jobId, urls, csrf);
                });
            });
        });
    });
}

export function initGovernanceBatch(config) {
    if (!config || !config.canBatchGovernance) {
        return;
    }
    selection = { scope: 'ids', pageCount: 0, checked: 0, totalMatching: config.totalResults };

    document.addEventListener('oer:selection-changed', (event) => {
        selection = selectionAfterToggle(
            { ...selection, pageCount: event.detail.pageCount },
            { type: 'check', checked: event.detail.checked }
        );
        paintStrip();
        if (onSelectionChange) {
            onSelectionChange();
        }
    });

    document.addEventListener('click', (event) => {
        const action = event.target.closest('.oer-select-matching-action');
        if (!action) {
            return;
        }
        if ('clear' === action.dataset.action) {
            document.querySelectorAll('.oer-row-select').forEach((input) => {
                input.checked = false;
            });
            document.querySelectorAll('.oer-select-all').forEach((input) => {
                input.checked = false;
            });
            selection = selectionAfterToggle(selection, { type: 'clear' });
            refreshSelectionUi();
        } else {
            selection = selectionAfterToggle(selection, { type: 'select-matching' });
        }
        paintStrip();
        if (onSelectionChange) {
            onSelectionChange();
        }
    });

    const sidebar = document.getElementById('oer-batch-sidebar');
    if (sidebar) {
        $(sidebar).on('o:sidebar-content-loaded', () => {
            const root = sidebar.querySelector('.oer-batch-governance');
            if (root) {
                mountForm(root);
            }
        });
    }

    // Checkbox states the browser restored on reload never fired a change.
    refreshSelectionUi();

    // Reattach: a running batch reopens its progress after a reload. Module
    // scripts run before DOMContentLoaded, while admin.js binds its sidebar
    // click handler inside a jQuery ready callback: click only after that.
    if (safeStorage((storage) => storage.getItem(JOB_KEY))) {
        $(() => {
            const opener = document.querySelector('.oer-batch-governance-open');
            if (opener) {
                opener.closest('.oer-selection-bar').hidden = false;
                opener.click();
            }
        });
    }
}
