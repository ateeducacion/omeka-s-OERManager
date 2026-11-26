import { previewModel, progressModel, storedAiJob } from '../core/aiBatchModel.js';
import { batchTarget } from './governanceBatch.js';

/**
 * Batch AI proposal (TASK-011): preview → launch → progress, served into
 * `#oer-batch-sidebar`, with reattach after a reload. Decisions live in
 * core/aiBatchModel.js.
 */

const JOB_KEY = 'oer-ai-batch-job';
const POLL_MS = 3000;
const ERROR_TEXT = {
    empty: 'La selección no contiene REA.',
    too_many: 'La selección supera el máximo permitido por lote; acota el filtro.',
    class_unresolved: 'No se encuentra la clase de REA en esta instalación.',
    computed_truncated: 'El filtro de integridad o de anclaje supera el tope de cálculo; combínalo con otro filtro.',
    running: 'Ya hay un lote de IA en marcha. Espera a que termine.',
    nothing: 'Ningún REA de la selección necesita propuesta.',
    ai_disabled: 'La IA no está configurada.',
    dispatch: 'No se pudo iniciar el lote.',
    csrf: 'La sesión ha caducado. Recarga la página.',
    unexpected: 'Error inesperado. Consulta el registro.'
};

const t = (text) => Omeka.jsTranslate(text);

function storage(action) {
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

function targetPairs(csrf) {
    const target = batchTarget();
    const pairs = [['csrf', csrf], ['scope', target.scope]];
    if ('matching' === target.scope) {
        pairs.push(['query', target.query]);
    } else {
        target.ids.forEach((id) => pairs.push(['ids[]', id]));
    }
    return pairs;
}

function showError(root, code) {
    const box = root.querySelector('.oer-ai-batch-error');
    box.textContent = t(ERROR_TEXT[code] || ERROR_TEXT.unexpected);
    box.hidden = false;
}

function renderProgress(root, jobId) {
    const box = root.querySelector('.oer-ai-batch-progress');
    box.hidden = false;
    root.querySelector('.oer-ai-batch-preview').hidden = true;
    root.querySelector('.oer-ai-batch-preview-button').hidden = true;
    box.textContent = '';
    const text = document.createElement('p');
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'button';
    cancel.textContent = t('Cancelar');
    cancel.addEventListener('click', () => {
        cancel.disabled = true;
        post(root.dataset.cancelUrl, [['csrf', root.dataset.csrf], ['jobId', jobId]]);
    });
    box.append(text, cancel);

    const tick = () => {
        if (!root.isConnected) {
            return;
        }
        post(root.dataset.statusUrl, [['csrf', root.dataset.csrf], ['jobId', jobId]]).then((state) => {
            if (state.error) {
                storage((s) => s.removeItem(JOB_KEY));
                showError(root, state.error);
                return;
            }
            const model = progressModel(state);
            text.textContent = model.text;
            if ('poll' === model.action) {
                setTimeout(tick, POLL_MS);
                return;
            }
            storage((s) => s.removeItem(JOB_KEY));
            cancel.remove();
            if (model.failed.length) {
                const list = document.createElement('ul');
                model.failed.forEach((failure) => {
                    const li = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = root.dataset.itemUrl.replace('__ID__', String(failure.id));
                    link.textContent = '#' + failure.id;
                    li.append(link, document.createTextNode(' (' + failure.code + ')'));
                    list.append(li);
                });
                box.append(list);
            }
        });
    };
    tick();
}

function mount(root) {
    const pending = storedAiJob(storage((s) => s.getItem(JOB_KEY)));
    if (pending) {
        renderProgress(root, pending);
        return;
    }
    const previewBox = root.querySelector('.oer-ai-batch-preview');
    root.querySelector('.oer-ai-batch-preview-button').addEventListener('click', () => {
        root.querySelector('.oer-ai-batch-error').hidden = true;
        post(root.dataset.previewUrl, targetPairs(root.dataset.csrf)).then((response) => {
            if (response.error) {
                showError(root, response.error);
                return;
            }
            const model = previewModel(response);
            previewBox.textContent = '';
            const dl = document.createElement('dl');
            model.lines.forEach(([label, value]) => {
                const dt = document.createElement('dt');
                dt.textContent = t(label);
                const dd = document.createElement('dd');
                dd.textContent = value;
                dl.append(dt, dd);
            });
            previewBox.append(dl);
            if (model.notice) {
                const p = document.createElement('p');
                p.textContent = t(model.notice);
                previewBox.append(p);
            }
            const launch = document.createElement('button');
            launch.type = 'button';
            launch.className = 'button oer-button-primary';
            launch.textContent = t('Lanzar');
            launch.disabled = !model.canLaunch;
            launch.addEventListener('click', () => {
                launch.disabled = true;
                post(root.dataset.launchUrl, targetPairs(root.dataset.csrf)).then((launched) => {
                    if (launched.error) {
                        showError(root, launched.error);
                        return;
                    }
                    storage((s) => s.setItem(JOB_KEY, String(launched.jobId)));
                    renderProgress(root, launched.jobId);
                });
            });
            previewBox.append(launch);
            previewBox.hidden = false;
        });
    });
}

export function initAiBatch(config) {
    if (!config || !config.canBatchAi) {
        return;
    }
    const sidebar = document.getElementById('oer-batch-sidebar');
    if (sidebar) {
        $(sidebar).on('o:sidebar-content-loaded', () => {
            const root = sidebar.querySelector('.oer-ai-batch');
            if (root) {
                mount(root);
            }
        });
    }
    // Reattach after a reload, as the governance batch does; after admin.js binds its sidebar handler.
    if (storedAiJob(storage((s) => s.getItem(JOB_KEY)))) {
        $(() => {
            const opener = document.querySelector('.oer-ai-batch-open');
            if (opener) {
                opener.closest('.oer-selection-bar').hidden = false;
                opener.click();
            }
        });
    }
}
