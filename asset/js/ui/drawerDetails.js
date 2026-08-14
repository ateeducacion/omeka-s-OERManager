import { DRAWER_RENDERED } from './drawer.js';
import {
    integrityGroups, integrityChecked, INTEGRITY_OK_TEXT, INTEGRITY_UNKNOWN_TEXT
} from '../core/integrityModel.js';
import { historyRows, historyChecked, HISTORY_EMPTY_NOTICE, HISTORY_UNKNOWN_TEXT } from '../core/historyModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';
import {
    panelAreas, AREA_LABELS, PANEL_ERROR_TEXT, MEDIA_EMPTY_TEXT, ALIGNMENT_EMPTY_TEXT
} from '../core/detailAreas.js';

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
    section.appendChild(heading(Omeka.jsTranslate(AREA_LABELS.integrity)));

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

    // «No se pudo leer» y «no hay nada» no pueden compartir pantalla (I4,
    // revisión final de rama): antes `drawerHistoryAction` mandaba `[]` tanto
    // si el item no se pudo leer como si de verdad no tenía curaciones, y el
    // cliente pintaba el mismo aviso de cobertura para los dos casos.
    if (!historyChecked(history)) {
        section.appendChild(note(Omeka.jsTranslate(HISTORY_UNKNOWN_TEXT), 'oer-drawer-empty'));
        return section;
    }

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

function areaSection(area) {
    const section = document.createElement('section');
    section.className = `oer-area oer-area-${area.id}`;
    section.appendChild(heading(Omeka.jsTranslate(AREA_LABELS[area.id]), 'h4'));
    return section;
}

function renderHeader(identity) {
    const header = document.createElement('div');
    header.className = 'oer-panel-header';

    // La miniatura ancla la fila y distingue el tipo, pero NO verifica
    // contenido: solo 4 de los 19 REA tienen derivadas reales; en el resto
    // Omeka devuelve su icono genérico (spec §7.1). Si falta, se pinta un hueco
    // que se lee como ausencia, no un icono de imagen rota.
    const thumb = document.createElement('span');
    thumb.className = 'oer-panel-thumb';
    if (identity.thumbnail) {
        const img = document.createElement('img');
        img.src = identity.thumbnail;
        img.alt = '';
        thumb.appendChild(img);
    } else {
        thumb.classList.add('oer-panel-thumb-none');
    }
    header.appendChild(thumb);

    const title = document.createElement('h3');
    title.className = 'oer-panel-title';
    title.textContent = identity.title || Omeka.jsTranslate('(sin título)');
    header.appendChild(title);

    const visibility = document.createElement('span');
    visibility.className = 'oer-panel-visibility';
    visibility.textContent = identity.isPublic
        ? Omeka.jsTranslate('Público')
        : Omeka.jsTranslate('Privado');
    header.appendChild(visibility);

    if (identity.editUrl) {
        const link = document.createElement('a');
        link.className = 'oer-drawer-edit-link';
        link.href = identity.editUrl;
        link.textContent = Omeka.jsTranslate('Abrir en el editor de Omeka');
        header.appendChild(link);
    }

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'oer-detail-close';
    close.textContent = '×';
    close.setAttribute('aria-label', Omeka.jsTranslate('Cerrar el detalle'));
    header.appendChild(close);

    return header;
}

/**
 * Código de razón de `CurricularGrouping::build` → texto de UI. El código
 * `course-not-declared` cubre DOS casos (Task 1): un valor cuyo curso no
 * está declarado en el item, y un valor cuyo curso SÍ está declarado pero al
 * que ninguna materia sostiene (el curso mismo quedó huérfano como
 * `course-without-subject`). Una traducción literal de "no declarado" sería
 * falsa en el segundo caso, así que el texto se redacta para ser cierto en
 * los dos: no afirma que el curso falte, solo que aquí no hay uno que agrupe
 * el valor. Un código que no aparezca aquí degrada a "sin razón mostrada" en
 * vez de arriesgar una traducción inventada.
 */
const ORPHAN_REASON_LABELS = {
    'course-without-subject': 'ninguna materia lo sostiene',
    'course-not-declared': 'sin un curso que lo agrupe en este REA'
};

function renderAlignment(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        section.appendChild(note(Omeka.jsTranslate(ALIGNMENT_EMPTY_TEXT), 'oer-drawer-empty'));
        return section;
    }

    area.alignment.groups.forEach((group) => {
        const block = document.createElement('div');
        block.className = 'oer-align-group';

        const title = document.createElement('p');
        title.className = 'oer-align-course';
        title.textContent = group.subjects.length
            ? `${group.courseTitle} · ${group.subjects.join(', ')}`
            : group.courseTitle;
        block.appendChild(title);

        [['Saberes', group.teaches], ['Criterios', group.assesses]].forEach(([label, values]) => {
            const line = document.createElement('p');
            line.className = 'oer-align-line';
            line.textContent = `${Omeka.jsTranslate(label)}: ${values.length ? values.join(' · ') : '—'}`;
            block.appendChild(line);
        });

        section.appendChild(block);
    });

    if (area.alignment.axes.length) {
        const axes = document.createElement('p');
        axes.className = 'oer-align-axes';
        axes.textContent = `${Omeka.jsTranslate('Ejes')}: ${area.alignment.axes.join(' · ')}`;
        section.appendChild(axes);
    }

    if (area.alignment.orphans.length) {
        const block = document.createElement('div');
        block.className = 'oer-align-orphans';
        block.appendChild(heading(Omeka.jsTranslate('Sin encajar'), 'h5'));
        area.alignment.orphans.forEach((orphan) => {
            const line = document.createElement('p');
            line.className = 'oer-align-orphan';
            const label = TERM_LABELS[orphan.term] || orphan.term;
            const reasonText = ORPHAN_REASON_LABELS[orphan.reason];
            line.textContent = reasonText
                ? `${label}: ${orphan.title} (${Omeka.jsTranslate(reasonText)})`
                : `${label}: ${orphan.title}`;
            block.appendChild(line);
        });
        section.appendChild(block);
    }

    return section;
}

function renderMedia(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        // Un REA sin ningún medio no es un recurso: el aviso va con tratamiento
        // de aviso, no de dato (spec §7).
        section.appendChild(note(Omeka.jsTranslate(MEDIA_EMPTY_TEXT), 'oer-media-none'));
        return section;
    }

    const list = document.createElement('ul');
    list.className = 'oer-media-list';
    area.media.forEach((one) => {
        const item = document.createElement('li');

        const name = document.createElement('span');
        name.className = 'oer-media-name';
        name.textContent = one.title;
        item.appendChild(name);

        const meta = document.createElement('span');
        meta.className = 'oer-media-meta';
        meta.textContent = `${one.type} · ${Math.round(one.size / 1024)} KB`;
        item.appendChild(meta);

        if (one.url) {
            const link = document.createElement('a');
            link.className = 'oer-media-open';
            link.href = one.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = Omeka.jsTranslate('Abrir');
            item.appendChild(link);
        }

        list.appendChild(item);
    });
    section.appendChild(list);
    return section;
}

function renderRecord(area) {
    const section = areaSection(area);
    if ('empty' === area.state) {
        section.appendChild(note(Omeka.jsTranslate('Sin ficha descriptiva.'), 'oer-drawer-empty'));
        return section;
    }

    const list = document.createElement('dl');
    Object.entries(area.record).forEach(([term, text]) => {
        const key = document.createElement('dt');
        key.textContent = TERM_LABELS[term] || term;
        list.appendChild(key);
        const value = document.createElement('dd');
        value.textContent = text;
        list.appendChild(value);
    });
    section.appendChild(list);
    return section;
}

/**
 * El historial nace plegado y su petición NO se lanza hasta que se despliega
 * (P-6): es la parte cara del panel, una lectura de API por id referenciado.
 */
function renderHistoryArea(itemId, historyUrl) {
    const section = document.createElement('section');
    section.className = 'oer-area oer-area-history';

    const box = document.createElement('details');
    box.className = 'oer-history-box';
    const toggle = document.createElement('summary');
    toggle.textContent = Omeka.jsTranslate('Historial de curación');
    box.appendChild(toggle);

    const body = document.createElement('div');
    body.textContent = '';
    box.appendChild(body);

    let loaded = false;
    box.addEventListener('toggle', () => {
        if (!box.open || loaded || !historyUrl) {
            return;
        }
        loaded = true;
        body.textContent = Omeka.jsTranslate('Cargando historial…');
        fetch(`${historyUrl}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`drawer-history respondió ${response.status}`);
                }
                return response.json();
            })
            .then((data) => {
                body.textContent = '';
                body.appendChild(renderHistory(data.history));
            })
            .catch(() => {
                body.textContent = Omeka.jsTranslate('No se ha podido cargar el historial.');
                loaded = false;
            });
    });

    section.appendChild(box);
    return section;
}

export function initDrawerDetails(config) {
    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        const url = config.drawerDetailsUrl;
        if (!url) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'oer-detail-panel';
        panel.textContent = Omeka.jsTranslate('Cargando detalle…');
        content.appendChild(panel);

        fetch(`${url}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                // Omeka devuelve JSON también en sus errores de API (403 del
                // ACL, 500...): sin este chequeo ese cuerpo se parseaba igual y
                // acababa en la misma pantalla que un REA sano.
                if (!response.ok) {
                    throw new Error(`drawer-details respondió ${response.status}`);
                }
                return response.json();
            })
            .then((details) => {
                panel.textContent = '';
                if (!details.panel) {
                    panel.appendChild(note(Omeka.jsTranslate(PANEL_ERROR_TEXT), 'oer-drawer-empty'));
                    return;
                }

                panel.appendChild(renderHeader(details.panel.identity));

                const grid = document.createElement('div');
                grid.className = 'oer-panel-grid';
                const byId = {};
                panelAreas(details).forEach((area) => {
                    byId[area.id] = area;
                });
                grid.appendChild(renderAlignment(byId.alignment));

                const side = document.createElement('div');
                side.className = 'oer-panel-side';
                side.appendChild(renderMedia(byId.media));
                side.appendChild(renderRecord(byId.record));
                grid.appendChild(side);

                panel.appendChild(grid);
                panel.appendChild(renderIntegrity(byId.integrity.integrity));
                panel.appendChild(renderHistoryArea(itemId, config.drawerHistoryUrl));
            })
            .catch(() => {
                panel.textContent = Omeka.jsTranslate(PANEL_ERROR_TEXT);
            });
    });
}
