import {
    validateBatch,
    previewPairs,
    selectionAfterToggle,
    stripModel,
    previewModel,
    resultModel,
    fieldToggles,
    storedJob,
    rememberedJob,
    recentRowModel,
    undoResultModel,
    UNDO_KIND
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
    running: 'El lote todavía no ha terminado.',
    undo_running: 'Ya se está deshaciendo este lote.',
    unexpected: 'Error inesperado. Consulta el registro.'
};

const JOB_ERROR_TEXT = {
    job_died: 'El proceso del lote se interrumpió antes de terminar. Revisa el registro de trabajos.',
    job_error: 'El lote terminó con error. Revisa el registro de trabajos.',
    job_stopped: 'El lote se detuvo.',
    job_completed: 'El lote terminó; su resumen ya no está disponible.',
    plan_unreadable: 'No se puede leer qué REA tocó este lote; no se ha deshecho nada.',
    unexpected: 'El lote terminó con error. Revisa el registro de trabajos.'
};

const FAILURE_TEXT = {
    denied: 'sin permiso',
    not_found: 'ya no existe',
    invalid: 'valor no válido',
    unexpected: 'error inesperado'
};

const MODE_TEXT = { fill: 'Rellenar solo vacíos', replace: 'Sustituir' };
const JOB_STATUS_TEXT = { completed: 'completado', stopped: 'cancelado', error: 'con error', died: 'interrumpido' };
const UNDO_BADGE_TEXT = { done: 'Deshecho', running: 'Deshaciendo…', partial: 'Deshecho parcialmente' };
const REVIEW_TEXT = {
    modified_later: 'modificado después del lote',
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

function trackedJob() {
    return storedJob(safeStorage((storage) => storage.getItem(JOB_KEY)));
}

function track(jobId, kind) {
    safeStorage((storage) => storage.setItem(JOB_KEY, rememberedJob(jobId, kind)));
}

function untrack() {
    safeStorage((storage) => storage.removeItem(JOB_KEY));
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

function renderProgress(root, state, urls, csrf, kind = 'batch') {
    if ('undo' === kind) {
        renderUndoProgress(root, state, urls, csrf);
        return;
    }
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
    // D7: a failed batch can still be undone for whatever it did write
    // before failing, so the button is offered whenever there is a batch id
    // at all, not only on a clean finish.
    if (model.batch) {
        const undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'button oer-batch-undo-this';
        undo.textContent = t('Deshacer este lote');
        undo.addEventListener('click', () => confirmUndo(root, {
            jobId: state.jobId, title: model.batch, terms: [], mode: '', planned: state.tallies ? state.tallies.total : null
        }, urls, csrf));
        box.appendChild(undo);
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

function renderUndoProgress(root, state, urls, csrf) {
    const box = root.querySelector('.oer-batch-progress');
    box.hidden = false;
    box.textContent = '';
    const model = undoResultModel(state);
    if (!model.finished) {
        const bar = document.createElement('progress');
        bar.max = 100;
        bar.value = model.percent;
        const label = document.createElement('p');
        label.textContent = state.total
            ? t('Deshaciendo: %1$s de %2$s REA').replace('%1$s', state.done).replace('%2$s', state.total)
            : t('Iniciando…');
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = t('Cancelar el deshacer');
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
        box.appendChild(summary);
    } else {
        const f = model.figures;
        summary.textContent = t('%1$s deshechos · %2$s modificados después del lote · %3$s no escritos por el lote · %4$s ya deshechos · %5$s fallidos')
            .replace('%1$s', f.undone)
            .replace('%2$s', f.modifiedLater)
            .replace('%3$s', f.notInBatch)
            .replace('%4$s', f.alreadyUndone)
            .replace('%5$s', f.failed);
        if ('stopped' === model.status) {
            summary.textContent = `${t('Deshacer cancelado.')} ${summary.textContent}`;
        }
        box.appendChild(summary);
        if (model.review.length) {
            const intro = document.createElement('p');
            intro.textContent = t('Revisa a mano estos REA:');
            const list = document.createElement('ul');
            model.review.forEach(({ id, code }) => {
                const li = document.createElement('li');
                const link = document.createElement('a');
                link.href = urls.item.replace('__ID__', String(id));
                link.target = '_blank';
                link.textContent = `#${id}`;
                li.append(link, ` — ${t(REVIEW_TEXT[code] || REVIEW_TEXT.unexpected)}`);
                list.appendChild(li);
            });
            box.append(intro, list);
        }
    }
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.className = 'button';
    reload.textContent = t('Recargar la vista');
    reload.addEventListener('click', () => window.location.reload());
    box.appendChild(reload);
}

/** Hides the form while a Job is tracked; progress takes its place. */
function hideForm(root) {
    ['.oer-batch-target', '.oer-batch-fields', '.oer-batch-mode', '.oer-batch-actions', '.oer-batch-preview', '.oer-batch-recent']
        .forEach((selector) => {
            const el = root.querySelector(selector);
            if (el) {
                el.hidden = true;
            }
        });
}

function rowSummary(row) {
    const parts = [];
    if (row.terms.length) {
        parts.push(row.terms.map((term) => t(term)).join(', '));
    }
    if (row.mode) {
        parts.push(t(MODE_TEXT[row.mode]));
    }
    if (null !== row.planned && undefined !== row.planned) {
        parts.push(t('%1$s REA en el plan').replace('%1$s', row.planned));
    }
    return parts.join(' · ');
}

function confirmUndo(root, row, urls, csrf) {
    const box = root.querySelector('.oer-batch-undo-confirm');
    const details = root.querySelector('.oer-batch-recent');
    details.hidden = false;
    details.open = true;
    box.textContent = '';
    box.hidden = false;
    const text = document.createElement('p');
    const summary = rowSummary(row);
    text.textContent = `${t('Deshacer el lote %1$s').replace('%1$s', row.title)}${summary ? ` (${summary})` : ''}. `
        + t('Se restaurarán los valores anteriores al lote. Los REA modificados después del lote no se tocarán y aparecerán en el resultado.');
    const go = document.createElement('button');
    go.type = 'button';
    go.className = 'button oer-batch-undo-go';
    go.textContent = t('Deshacer lote');
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.textContent = t('No deshacer el lote');
    cancel.addEventListener('click', () => {
        box.hidden = true;
        box.textContent = '';
    });
    go.addEventListener('click', () => {
        go.disabled = true;
        post(urls.undo, [['csrf', csrf], ['batchJobId', String(row.jobId)]]).then((response) => {
            if (response.error) {
                go.disabled = false;
                showErrors(root, { _: response.error });
                return;
            }
            box.hidden = true;
            track(response.jobId, 'undo');
            hideForm(root);
            poll(root, { jobId: response.jobId, kind: 'undo' }, urls, csrf);
        });
    });
    box.append(text, go, cancel);
}

function renderRecent(root, batches, urls, csrf) {
    const list = root.querySelector('.oer-batch-recent-list');
    list.textContent = '';
    if (!batches.length) {
        const li = document.createElement('li');
        li.textContent = t('No hay lotes que puedas deshacer.');
        list.appendChild(li);
        return;
    }
    batches.forEach((batch) => {
        const row = recentRowModel(batch, TERM_LABELS);
        const li = document.createElement('li');
        li.className = 'oer-batch-recent-row';
        const head = document.createElement('p');
        const when = batch.started ? new Date(batch.started).toLocaleString() : '';
        head.textContent = [row.title, when, row.owner].filter(Boolean).join(' · ');
        const meta = document.createElement('p');
        meta.className = 'oer-batch-recent-meta';
        meta.textContent = [rowSummary(row), t(JOB_STATUS_TEXT[row.status] || row.status)].filter(Boolean).join(' · ');
        li.append(head, meta);
        if (row.badge) {
            const badge = document.createElement('span');
            badge.className = `oer-batch-undo-badge oer-batch-undo-${row.badge}`;
            badge.textContent = t(UNDO_BADGE_TEXT[row.badge]);
            li.appendChild(badge);
        }
        if (row.action) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button oer-batch-undo-open';
            button.textContent = 'retry' === row.action ? t('Reintentar el deshacer del lote') : t('Deshacer lote');
            button.setAttribute('aria-label', 'retry' === row.action
                ? t('Reintentar el deshacer del lote %1$s').replace('%1$s', row.title)
                : t('Deshacer el lote %1$s').replace('%1$s', row.title));
            button.addEventListener('click', () => confirmUndo(root, row, urls, csrf));
            li.appendChild(button);
        }
        list.appendChild(li);
    });
}

function mountRecent(root, urls, csrf) {
    const details = root.querySelector('.oer-batch-recent');
    if (!details) {
        return;
    }
    let loaded = false;
    details.addEventListener('toggle', () => {
        if (!details.open || loaded) {
            return;
        }
        loaded = true;
        const error = root.querySelector('.oer-batch-recent-error');
        error.hidden = true;
        post(urls.recent, [['csrf', csrf]]).then((response) => {
            if (response.error) {
                loaded = false;
                error.textContent = t(PREVIEW_ERROR_TEXT[response.error] || PREVIEW_ERROR_TEXT.unexpected);
                error.hidden = false;
                return;
            }
            renderRecent(root, response.batches || [], urls, csrf);
        });
    });
}

function poll(root, job, urls, csrf, retries = 0) {
    window.clearTimeout(pollTimer);
    if (!root.isConnected) {
        return;
    }
    post(urls.status, [['csrf', csrf], ['jobId', String(job.jobId)]]).then((state) => {
        if (state.error) {
            // A network blip must not lose a long batch's progress display.
            if ('unexpected' === state.error && retries < MAX_POLL_RETRIES) {
                pollTimer = window.setTimeout(() => poll(root, job, urls, csrf, retries + 1), POLL_MS * (retries + 2));
                return;
            }
            untrack();
            showErrors(root, { _: state.error });
            return;
        }
        const finished = ('undo' === job.kind ? undoResultModel(state) : resultModel(state)).finished;
        renderProgress(root, { ...state, jobId: job.jobId }, urls, csrf, job.kind);
        if (finished) {
            untrack();
            return;
        }
        pollTimer = window.setTimeout(() => poll(root, job, urls, csrf), POLL_MS);
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
        recent: root.dataset.recentUrl,
        undo: root.dataset.undoUrl,
        item: root.dataset.itemUrl
    };
    const csrf = root.dataset.csrf;

    const pending = trackedJob();
    if (pending) {
        onSelectionChange = null;
        hideForm(root);
        // The recent list lives in `.oer-batch-recent`, hidden by hideForm()
        // but still reachable through «Deshacer este lote» once the batch
        // finishes; it must be mounted here too, not only on the fresh-form
        // path below, or that button opens a list that never loads.
        mountRecent(root, urls, csrf);
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
    mountRecent(root, urls, csrf);
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
                    track(applied.jobId, 'batch');
                    hideForm(root);
                    poll(root, { jobId: applied.jobId, kind: 'batch' }, urls, csrf);
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
    if (trackedJob()) {
        $(() => {
            const opener = document.querySelector('.oer-batch-governance-open');
            if (opener) {
                opener.closest('.oer-selection-bar').hidden = false;
                opener.click();
            }
        });
    }
}
