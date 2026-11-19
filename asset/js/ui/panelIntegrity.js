/**
 * Refreshes the panel's integrity after a change made inside it (TASK-065,
 * spec A6), without re-rendering the whole drawer.
 *
 * Where each issue goes and how it is worded is decided on the server
 * (`PanelIssues`, `drawer-details.phtml`). Re-deciding it here would duplicate
 * that judgement in the browser, and reloading the whole drawer would throw
 * away an open, unconfirmed re-cataloguing in another tab. So this asks the
 * server for the panel again and swaps ONLY the integrity pieces: the rail,
 * the header verdict, the summary line, the tab counts and the issues listed
 * at the top of each tab. Everything else, open edits included, stays.
 */

function swapNode(current, fresh, insert) {
    if (current && fresh) {
        current.replaceWith(document.importNode(fresh, true));
    } else if (current) {
        current.remove();
    } else if (fresh) {
        insert(document.importNode(fresh, true));
    }
}

function swapIntegrity(panelEl, fresh) {
    const status = fresh.dataset.integrity || 'ok';
    panelEl.dataset.integrity = status;
    const sidebar = document.getElementById('oer-detail-sidebar');
    if (sidebar) {
        sidebar.dataset.integrity = status;
    }

    // The verdict and the summary link share one place in the header, right
    // after the visibility: at most one of them is there.
    const visibility = panelEl.querySelector('.oer-panel-visibility');
    const afterVisibility = (node) => visibility && visibility.insertAdjacentElement('afterend', node);
    swapNode(
        panelEl.querySelector('.oer-panel-verdict'),
        fresh.querySelector('.oer-panel-verdict'),
        afterVisibility
    );
    swapNode(
        panelEl.querySelector('.oer-integrity-summary'),
        fresh.querySelector('.oer-integrity-summary'),
        afterVisibility
    );

    // The tab's label and count are its children; its state (selected,
    // tabindex) is on the element itself and is kept.
    panelEl.querySelectorAll('.oer-panel-tabs [role="tab"]').forEach((tab) => {
        const freshTab = fresh.querySelector(`#${tab.id}`);
        if (freshTab) {
            tab.replaceChildren(...Array.from(freshTab.childNodes, (node) => document.importNode(node, true)));
        }
    });

    panelEl.querySelectorAll('[role="tabpanel"]').forEach((panel) => {
        const freshPanel = fresh.querySelector(`#${panel.id}`);
        swapNode(
            panel.querySelector(':scope > .oer-tab-issues'),
            freshPanel && freshPanel.querySelector(':scope > .oer-tab-issues'),
            (node) => panel.prepend(node)
        );
    });
}

/**
 * @param {Element} panelEl the drawer's `.oer-detail-panel`
 * @param {string|number} itemId
 * @returns {Promise<void>} settles either way; a failed read leaves the panel as it was
 */
export function refreshIntegrity(panelEl, itemId) {
    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
    const url = opener && opener.dataset.sidebarContentUrl;
    if (!url) {
        return Promise.resolve();
    }
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => (response.ok ? response.text() : Promise.reject(new Error(`drawer-details ${response.status}`))))
        .then((html) => {
            const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.oer-detail-panel');
            if (fresh && panelEl.isConnected) {
                swapIntegrity(panelEl, fresh);
            }
        })
        .catch(() => {
            // The save itself succeeded and its answer is already shown; a
            // stale count until the next opening is better than an error here.
        });
}
