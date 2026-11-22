import { browseRequest, browseKey, browseRows, browseStatus } from '../core/curriculumBrowse.js';

/**
 * «Explorar cursos» (stages → courses) and «Ver materias» (the subjects of one
 * course) of the alignment editor (TASK-065, spec B8 addendum). For the
 * curator who does not know the name to type in the search box.
 *
 * What is open and what was loaded live in the editor's view state
 * (`view.browse`), not in the DOM: the navigator is redrawn on every change
 * (a checkbox included), and an open list must stay open. Each list is asked
 * once; a failed one is asked again when reopened.
 *
 * Accessibility (NFR-006): disclosure buttons with `aria-expanded` and
 * `aria-controls`, the loading/failure text in a polite live region, «Añadir»
 * as native buttons named after what they add.
 */

function t(text) {
    return Omeka.jsTranslate(text);
}

export function browseState() {
    return { open: new Set(), lists: {} };
}

/**
 * A disclosure button over one list. `idOf(parts)` gives stable DOM ids, so
 * the focus survives a redraw.
 */
export function browseDisclosure(view, idOf, { dimension, parentId, text, label, classes }) {
    const key = browseKey(dimension, parentId);
    const open = view.browse.open.has(key);
    const regionId = idOf(['browse', dimension, parentId || 'root', 'list']);
    const $button = $('<button>').attr({
        type: 'button',
        id: idOf(['browse', dimension, parentId || 'root']),
        'aria-expanded': open ? 'true' : 'false',
        'aria-controls': regionId,
        'data-dimension': dimension,
        'data-parent': parentId || ''
    }).addClass('oer-ae-browse-toggle ' + (classes || '')).text(text);
    if (label) {
        $button.attr('aria-label', label);
    }
    const $region = $('<div>').addClass('oer-ae-browse-region').attr('id', regionId).prop('hidden', !open);
    return { $button, $region, open };
}

/** The status line and rows of one list, each row with «Añadir» or «Añadido». */
export function browseList(view, dimension, parentId, chosenIds, idOf) {
    const key = browseKey(dimension, parentId);
    const entry = view.browse.lists[key];
    const $status = $('<p>').addClass('oer-ae-muted oer-ae-browse-status')
        .attr({ role: 'status', 'aria-live': 'polite' }).text(browseStatus(entry, t));
    const $list = $('<ul>').addClass('oer-ae-browse-list');
    browseRows(entry && entry.results, chosenIds).forEach((row) => {
        const $li = $('<li>').addClass('oer-ae-browse-row')
            .append($('<span>').addClass('oer-ae-browse-name').text(row.name));
        if (row.added) {
            $li.append($('<span>').addClass('oer-ae-browse-added').text(t('Añadido')));
        } else {
            $li.append($('<button>').attr({
                type: 'button',
                id: idOf(['browse', dimension, row.id, 'add']),
                'data-dimension': dimension, 'data-key': key, 'data-id': row.id,
                'aria-label': t('Añadir') + ' ' + row.name
            }).addClass('button oer-ae-small oer-ae-browse-add').text(t('Añadir')));
        }
        $list.append($li);
    });
    return [$status, $list];
}

/** The raw result behind an «Añadir» button, or null. */
export function browseResult(view, key, id) {
    const entry = view.browse.lists[key];
    return ((entry && entry.results) || []).find((result) => String(result.id) === String(id)) || null;
}

/**
 * Opens or closes one list; on opening, asks for it when it is not loaded
 * (or failed). `redraw` is called whenever the state changes.
 */
export function toggleBrowse(view, url, dimension, parentId, redraw) {
    const key = browseKey(dimension, parentId);
    if (view.browse.open.has(key)) {
        view.browse.open.delete(key);
        redraw();
        return;
    }
    view.browse.open.add(key);
    const entry = view.browse.lists[key];
    const request = browseRequest(dimension, parentId);
    if (request && (!entry || 'error' === entry.status)) {
        view.browse.lists[key] = { status: 'loading', results: [] };
        $.getJSON(url, request).done((response) => {
            view.browse.lists[key] = { status: 'loaded', results: (response && response.results) || [] };
            redraw();
        }).fail(() => {
            view.browse.lists[key] = { status: 'error', results: [] };
            redraw();
        });
    }
    redraw();
}
