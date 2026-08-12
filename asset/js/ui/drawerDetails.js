import { DRAWER_RENDERED } from './drawer.js';
import { integrityGroups, integrityChecked, INTEGRITY_OK_TEXT, INTEGRITY_UNKNOWN_TEXT } from '../core/integrityModel.js';
import { historyRows, HISTORY_EMPTY_NOTICE } from '../core/historyModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';

/**
 * Secciones del drawer que el cliente no puede calcular: integridad e historial
 * (rebanada 3a de TASK-028).
 *
 * Se engancha a `oer:drawer-rendered` en vez de estar cableado dentro de
 * drawer.js, igual que el panel del re-catalogador: así el drawer no depende de
 * estas secciones y se pueden mover sin romperlo.
 *
 * Todo el texto se escribe con textContent: los títulos y los mensajes vienen
 * del catálogo, nunca del código.
 */

/**
 * Severidad → encabezado del grupo. El glifo de ADR-0014 regla 3 lo pone el
 * `list-style-type` en CSS, que un lector de pantalla no anuncia; este
 * encabezado es el texto alternativo que le falta.
 */
const SEVERITY_LABELS = {
    error: 'Errores',
    warning: 'Avisos'
};

function heading(text, tag = 'h4') {
    const element = document.createElement(tag);
    element.textContent = text;
    return element;
}

function note(text, className) {
    const element = document.createElement('p');
    element.className = className;
    element.textContent = text;
    return element;
}

function renderIntegrity(integrity) {
    const section = document.createElement('section');
    section.className = 'oer-drawer-section oer-drawer-integrity';
    section.appendChild(heading(Omeka.jsTranslate('Integridad')));

    // «No se pudo leer el item» y «se leyó y está impecable» no pueden
    // compartir pantalla: sin esta rama, un id inválido o un fetch que el ACL
    // deniega acababan pintando lo mismo que un REA sano.
    if (!integrityChecked(integrity)) {
        section.appendChild(note(Omeka.jsTranslate(INTEGRITY_UNKNOWN_TEXT), 'oer-drawer-empty'));
        return section;
    }

    const groups = integrityGroups(integrity);
    if (!groups.length) {
        section.appendChild(note(Omeka.jsTranslate(INTEGRITY_OK_TEXT), 'oer-drawer-empty'));
        return section;
    }

    groups.forEach((group) => {
        const groupLabel = SEVERITY_LABELS[group.severity] || group.severity;
        section.appendChild(heading(Omeka.jsTranslate(groupLabel), 'h5'));

        const list = document.createElement('ul');
        list.className = `oer-integrity-issues oer-integrity-issues-${group.severity}`;
        group.issues.forEach((issue) => {
            const item = document.createElement('li');
            const fieldLabel = TERM_LABELS[issue.field] || issue.field;
            item.textContent = `${fieldLabel}: ${issue.message}`;
            list.appendChild(item);
        });
        section.appendChild(list);
    });

    return section;
}

/**
 * @param {object} change
 * @param {boolean} hasReasons Viene de `historyModel::historyRows` (fila
 *   completa, no solo este `change`): si la fila entera no trae ningún
 *   porqué, no hace falta mirar `value.reason` en cada valor retirado.
 */
function renderChange(change, hasReasons) {
    const block = document.createElement('div');
    block.className = 'oer-history-change';

    const label = document.createElement('span');
    label.className = 'oer-history-term';
    label.textContent = change.emptied
        ? `${change.label} (${Omeka.jsTranslate('vaciada')})`
        : change.label;
    block.appendChild(label);

    (change.added || []).forEach((title) => {
        const line = document.createElement('span');
        line.className = 'oer-history-added';
        line.textContent = `+ ${title}`;
        block.appendChild(line);
    });

    (change.removed || []).forEach((value) => {
        const line = document.createElement('span');
        line.className = 'oer-history-removed';
        line.textContent = `− ${value.title}`;
        block.appendChild(line);

        // PEND-013: la justificación de la IA se muestra, pero COLAPSADA. Aquí ya
        // está decidido y escrito, así que no ancla la decisión del curador; y
        // regenerarla costaría otra pasada de LLM.
        if (hasReasons && value.reason) {
            const why = document.createElement('details');
            why.className = 'oer-history-why';
            const toggle = document.createElement('summary');
            toggle.textContent = Omeka.jsTranslate('Ver porqué');
            why.appendChild(toggle);
            const reason = document.createElement('p');
            reason.textContent = value.reason;
            why.appendChild(reason);
            block.appendChild(why);
        }
    });

    return block;
}

function renderHistory(history) {
    const section = document.createElement('section');
    section.className = 'oer-drawer-section oer-drawer-history';
    section.appendChild(heading(Omeka.jsTranslate('Historial de curación')));

    const rows = historyRows(history);
    if (!rows.length) {
        section.appendChild(note(Omeka.jsTranslate(HISTORY_EMPTY_NOTICE), 'oer-drawer-empty'));
        return section;
    }

    rows.forEach((row) => {
        const entry = document.createElement('article');
        entry.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';

        const header = document.createElement('p');
        header.className = 'oer-history-header';
        header.textContent = `${row.whenLabel} · ${row.contributor}`;
        entry.appendChild(header);

        // `row.summary` (jerga RDF: «lrmi:teaches +1 −2 (vaciada)») se omite a
        // propósito: el diff de abajo ya dice lo mismo con etiquetas humanas, y
        // pintar los dos duplicaba la información. El resumen se queda donde
        // tiene sentido, en el propio valor RDF (`dcterms:provenance`); sigue
        // en el contrato del modelo, solo deja de pintarse aquí.
        row.changes.forEach((change) => entry.appendChild(renderChange(change, row.hasReasons)));
        section.appendChild(entry);
    });

    return section;
}

export function initDrawerDetails(config) {
    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        const url = config.drawerDetailsUrl;
        if (!url) {
            return;
        }

        const placeholder = document.createElement('div');
        placeholder.className = 'oer-drawer-details';
        placeholder.textContent = Omeka.jsTranslate('Cargando detalle…');
        content.appendChild(placeholder);

        fetch(`${url}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                // Omeka devuelve JSON también en sus errores de API (403 del ACL,
                // 500...): sin este chequeo, ese cuerpo -sin integrity ni
                // history- se parseaba igual y acababa en la misma pantalla que
                // un REA sano. Solo con un cuerpo no-JSON caía al .catch.
                if (!response.ok) {
                    throw new Error(`drawer-details respondió ${response.status}`);
                }
                return response.json();
            })
            .then((details) => {
                placeholder.textContent = '';
                placeholder.appendChild(renderIntegrity(details.integrity));
                placeholder.appendChild(renderHistory(details.history));
            })
            .catch(() => {
                placeholder.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle ampliado.');
            });
    });
}
