import { drawerRows, drawerTitle } from '../core/drawerModel.js';

/**
 * Drawer de detalle del REA (ADR-0005 §6), extraído de oer-master-view.js en
 * TASK-028. Capa de UI: el qué se pinta lo decide core/drawerModel.js.
 *
 * Tras pintar emite `oer:drawer-rendered` con el contenedor y el JSON del item.
 * El panel del re-catalogador se engancha ahí en vez de estar cableado dentro:
 * así el drawer no depende del re-catalogador y la transición de TASK-028 puede
 * mover uno sin romper el otro.
 */
export const DRAWER_RENDERED = 'oer:drawer-rendered';

function buildContent(itemJson) {
    const content = document.createElement('div');

    const heading = document.createElement('h3');
    heading.textContent = drawerTitle(itemJson);
    content.appendChild(heading);

    const visibility = document.createElement('span');
    visibility.className = 'oer-drawer-visibility';
    visibility.textContent = itemJson['o:is_public'] ? 'Público' : 'Privado';
    content.appendChild(visibility);

    const list = document.createElement('dl');
    drawerRows(itemJson).forEach((row) => {
        const term = document.createElement('dt');
        term.textContent = row.label;
        list.appendChild(term);
        const definition = document.createElement('dd');
        definition.textContent = row.text;
        list.appendChild(definition);
    });
    content.appendChild(list);

    return content;
}

function drawerElements() {
    const drawer = document.getElementById('oer-drawer');
    return { drawer, content: drawer ? drawer.querySelector('.oer-drawer-content') : null };
}

export function openDrawer(apiUrl, itemId) {
    const { drawer, content } = drawerElements();
    if (!drawer || !content) {
        return;
    }
    drawer.dataset.lastItemId = itemId;
    content.textContent = Omeka.jsTranslate('Cargando…');
    drawer.hidden = false;
    drawer.setAttribute('aria-hidden', 'false');
    const closeButton = drawer.querySelector('.oer-drawer-close');
    if (closeButton) {
        closeButton.focus();
    }

    fetch(apiUrl, { headers: { Accept: 'application/json' } })
        .then((response) => response.json())
        .then((itemJson) => {
            content.textContent = '';
            const rendered = buildContent(itemJson);
            content.appendChild(rendered);
            document.dispatchEvent(new CustomEvent(DRAWER_RENDERED, {
                detail: { itemId, itemJson, content }
            }));
        })
        .catch(() => {
            content.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle.');
        });
}

function closeDrawer() {
    const { drawer } = drawerElements();
    if (!drawer) {
        return;
    }
    const lastId = drawer.dataset.lastItemId;
    drawer.hidden = true;
    drawer.setAttribute('aria-hidden', 'true');
    if (lastId) {
        const opener = document.querySelector(`.oer-open-drawer[data-item-id="${lastId}"]`);
        if (opener) {
            opener.focus();
        }
    }
}

export function initDrawer(config) {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('.oer-open-drawer');
        if (opener) {
            event.preventDefault();
            const row = opener.closest('tr');
            openDrawer(row ? row.dataset.apiUrl : '', opener.dataset.itemId);
            return;
        }
        if (event.target.closest('.oer-drawer-close')) {
            closeDrawer();
        }
    });

    document.addEventListener('keydown', (event) => {
        const drawer = document.getElementById('oer-drawer');
        if ('Escape' === event.key && drawer && !drawer.hidden) {
            closeDrawer();
        }
    });
}
