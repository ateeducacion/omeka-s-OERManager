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

/**
 * Severidad → [singular, plural] para el contador de la banda. `jsTranslate` no
 * hace plurales, así que la elección se resuelve aquí y cada forma viaja como
 * cadena propia al catálogo.
 */
const SEVERITY_COUNTS = {
    error: ['error', 'errores'],
    warning: ['aviso', 'avisos']
};

/**
 * Evento con el que el panel ofrece su hueco de edición del anclaje (TASK-033).
 * El re-catalogador se engancha aquí en vez de al `<td>` entero: así los cinco
 * selectores nacen DENTRO del área que ya cuenta el anclaje, y el curador deja
 * de leer el currículo en un sitio y corregirlo en otro.
 *
 * Contrato con `ui/recatalog.js`, en las dos direcciones:
 *  - este fichero entrega `{ itemId, itemJson, slot, bar }` y es dueño del modo
 *    (`data-mode` sobre `.oer-area-alignment`);
 *  - el re-catalogador puebla `slot` y `bar`, y marca su botón de entrada con
 *    `.oer-anchor-edit`, que es lo único que este fichero escucha de él.
 */
export const ANCHOR_SLOT = 'oer:anchor-slot';

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

/**
 * «2 avisos» / «1 error, 2 avisos». El spec §6 pedía este contador y TASK-032
 * no llegó a pintarlo: sin él la banda dice que algo pasa, pero no cuánto, que
 * es lo que decide si el curador para a mirarlo ahora o luego.
 */
function issueCount(groups) {
    return groups.map((group) => {
        const forms = SEVERITY_COUNTS[group.severity];
        const total = group.issues.length;
        if (!forms) {
            return `${total} ${Omeka.jsTranslate(group.severity)}`;
        }
        return `${total} ${Omeka.jsTranslate(1 === total ? forms[0] : forms[1])}`;
    }).join(', ');
}

/**
 * Banda de veredicto (TASK-033). Devuelve `null` cuando NO hay nada que mirar:
 * un REA sano deja de gastar una sección entera del cuerpo en decir que está
 * sano, y su veredicto se va a la cabecera (`verdictMark`).
 *
 * Los glifos siguen viniendo del `list-style-type` de
 * `.oer-integrity-issues-{error,warning}`, que ya distingue ⛔ de ⚠ por forma y
 * no solo por color (ADR-0014 regla 3); el encabezado por severidad sigue
 * siendo su texto alternativo para un lector de pantalla.
 */
function renderAlert(integrity) {
    const groups = integrityChecked(integrity) ? integrityGroups(integrity) : [];
    if (!groups.length) {
        return null;
    }

    const section = document.createElement('section');
    // `integrityGroups` ordena por severidad, así que el primer grupo es el
    // peor: la banda se tiñe de lo más grave que contiene, no de lo primero
    // que llegó.
    section.className = `oer-panel-alert oer-panel-alert-${groups[0].severity}`;
    section.appendChild(heading(
        `${Omeka.jsTranslate(AREA_LABELS.integrity)} · ${issueCount(groups)}`
    ));

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
 * Marca de integridad de la cabecera (TASK-033). Solo se pinta cuando NO hay
 * banda, y distingue los dos estados que la rebanada 3a pagó caro: «no se pudo
 * comprobar» y «se comprobó y está limpio». Cada uno lleva su glifo propio
 * (✓ frente a ⚠, puestos por CSS) además de su texto: fundirlos en una sola
 * marca sería volver al fallo que aquella rebanada arregló.
 */
function verdictMark(integrity) {
    if (integrityChecked(integrity) && integrityGroups(integrity).length) {
        return null;
    }
    const mark = document.createElement('span');
    if (!integrityChecked(integrity)) {
        mark.className = 'oer-panel-verdict oer-panel-verdict-unknown';
        mark.textContent = Omeka.jsTranslate(INTEGRITY_UNKNOWN_TEXT);
        return mark;
    }
    mark.className = 'oer-panel-verdict oer-panel-verdict-ok';
    mark.textContent = Omeka.jsTranslate(INTEGRITY_OK_TEXT);
    return mark;
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
    // Sin encabezado propio: el <summary> del <details> que envuelve esto ya
    // dice «Historial de curación», y pintarlo dos veces era ruido (TASK-033).
    const section = document.createElement('section');
    section.className = 'oer-drawer-section oer-drawer-history';

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

function renderHeader(identity, integrity) {
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

    // El veredicto sube aquí cuando no hay banda que pintar: es lo primero que
    // se mira al abrir una fila, y antes obligaba a bajar hasta media pantalla.
    const verdict = verdictMark(integrity);
    if (verdict) {
        header.appendChild(verdict);
    }

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

/**
 * Anclaje curricular: la lectura agrupada y el hueco donde vive su editor
 * (TASK-033). Es la única zona con acciones del panel, porque es la decisión
 * que el panel habilita (ADR-0014 regla 1).
 *
 * La lectura va agrupada por curso·materia y la edición es plana, y no es un
 * descuido: `CurricularGrouping::build()` DERIVA los grupos recorriendo el
 * grafo del currículo, pero en RDF el item solo tiene cinco listas planas, que
 * es lo que el re-catalogador escribe. Por eso los selectores sustituyen a la
 * lectura en vez de pintarse dentro de cada grupo: un selector por grupo
 * prometería una escritura por grupo que no existe.
 */
function renderAlignment(area) {
    const section = areaSection(area);
    // El modo lo lleva la sección, no el editor: quien manda sobre la
    // composición del área es este fichero, y el re-catalogador solo pone su
    // botón de entrada. Empieza siempre en lectura, también al reabrirse tras
    // confirmar (`reopenDrawer` reconstruye la fila entera).
    section.dataset.mode = 'read';

    const read = document.createElement('div');
    read.className = 'oer-anchor-read';
    section.appendChild(read);

    // Hueco y barra van SIEMPRE, con o sin permiso de curación: los puebla el
    // re-catalogador si procede, y si no se quedan vacíos y el CSS los colapsa
    // con `:empty`. Decidirlo aquí obligaría a este fichero a conocer el ACL.
    const slot = document.createElement('div');
    slot.className = 'oer-anchor-slot';
    const bar = document.createElement('div');
    bar.className = 'oer-anchor-bar';

    if ('empty' === area.state) {
        read.appendChild(note(Omeka.jsTranslate(ALIGNMENT_EMPTY_TEXT), 'oer-drawer-empty'));
        section.appendChild(slot);
        section.appendChild(bar);
        return { section, slot, bar };
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

        // Las etiquetas salen de TERM_LABELS y no de literales propios: antes
        // esta línea decía «Saberes» y «Criterios» mientras la integridad y el
        // historial decían «Saberes básicos» y «Criterios de evaluación», así
        // que la misma dimensión tenía dos nombres según dónde se pintara.
        [['lrmi:teaches', group.teaches], ['lrmi:assesses', group.assesses]].forEach(([term, values]) => {
            const line = document.createElement('p');
            line.className = 'oer-align-line';
            const label = Omeka.jsTranslate(TERM_LABELS[term] || term);
            line.textContent = `${label}: ${values.length ? values.join(' · ') : '—'}`;
            block.appendChild(line);
        });

        read.appendChild(block);
    });

    if (area.alignment.axes.length) {
        const axes = document.createElement('p');
        axes.className = 'oer-align-axes';
        const axisLabel = Omeka.jsTranslate(TERM_LABELS['dcterms:relation']);
        axes.textContent = `${axisLabel}: ${area.alignment.axes.join(' · ')}`;
        read.appendChild(axes);
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
        read.appendChild(block);
    }

    section.appendChild(slot);
    section.appendChild(bar);
    return { section, slot, bar };
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

/**
 * Ofrece el hueco de edición del anclaje. `slot` y `bar` cambian según el
 * camino: en el normal cuelgan del área de anclaje; en el degradado, del panel
 * entero.
 *
 * La guarda de `isConnected` repite la de `drawer.js`: quien escucha este
 * evento pide al servidor la última curación del REA (`recatalog-last-event`),
 * y esa petición no se gasta en una fila que el curador ya plegó.
 */
function offerAnchorSlot(panel, itemId, itemJson, slot, bar) {
    if (!panel.isConnected) {
        return;
    }
    document.dispatchEvent(new CustomEvent(ANCHOR_SLOT, {
        detail: { itemId, itemJson, slot, bar }
    }));
}

/**
 * Camino degradado: `drawer-details` no contestó (403 del ACL, 500, red), así
 * que no hay área de anclaje donde colgar el editor. Se ofrece un hueco al
 * nivel del panel para que el re-catalogador se monte igual.
 *
 * Importa que exista: antes de TASK-033 el editor colgaba del `<td>` y
 * sobrevivía a este fallo por accidente. Al moverlo dentro del área, un
 * `drawer-details` caído dejaría al curador sin poder re-catalogar.
 */
function degradeToPanelSlot(panel, itemId, itemJson) {
    const slot = document.createElement('div');
    slot.className = 'oer-anchor-slot';
    const bar = document.createElement('div');
    bar.className = 'oer-anchor-bar';
    // Sin lectura que sustituir no hay dos modos: el editor se ve entero.
    panel.dataset.mode = 'edit';
    panel.appendChild(slot);
    panel.appendChild(bar);
    offerAnchorSlot(panel, itemId, itemJson, slot, bar);
}

export function initDrawerDetails(config) {
    // Entrar en edición es un cambio de composición, así que lo lleva este
    // fichero; el botón lo pone el re-catalogador, que es quien sabe si el
    // curador puede tocar el REA. El camino de vuelta NO está aquí: «Cancelar»
    // reconstruye la fila con `reopenDrawer` desde el re-catalogador, para que
    // salir de la edición descarte de verdad lo que no se confirmó.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.oer-anchor-edit');
        if (!button) {
            return;
        }
        const section = button.closest('.oer-area-alignment');
        if (!section) {
            return;
        }
        section.dataset.mode = 'edit';
        // El foco sigue a la composición: quien llegó al botón con el teclado
        // se quedaría mirando un botón que acaba de desaparecer.
        const first = section.querySelector('.oer-term-search');
        if (first) {
            first.focus();
        }
    });

    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, itemJson, content } = event.detail;
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
                    degradeToPanelSlot(panel, itemId, itemJson);
                    return;
                }

                const integrity = details.integrity;
                panel.appendChild(renderHeader(details.panel.identity, integrity));

                // La banda solo existe si hay algo que mirar; si no, el
                // veredicto ya está en la cabecera y el cuerpo se ahorra una
                // sección entera.
                const alert = renderAlert(integrity);
                if (alert) {
                    panel.appendChild(alert);
                }

                const grid = document.createElement('div');
                grid.className = 'oer-panel-grid';
                const byId = {};
                panelAreas(details).forEach((area) => {
                    byId[area.id] = area;
                });
                const anchor = renderAlignment(byId.alignment);
                grid.appendChild(anchor.section);

                const side = document.createElement('div');
                side.className = 'oer-panel-side';
                side.appendChild(renderMedia(byId.media));
                side.appendChild(renderRecord(byId.record));
                grid.appendChild(side);

                panel.appendChild(grid);
                panel.appendChild(renderHistoryArea(itemId, config.drawerHistoryUrl));
                offerAnchorSlot(panel, itemId, itemJson, anchor.slot, anchor.bar);
            })
            .catch(() => {
                panel.textContent = Omeka.jsTranslate(PANEL_ERROR_TEXT);
                degradeToPanelSlot(panel, itemId, itemJson);
            });
    });
}
