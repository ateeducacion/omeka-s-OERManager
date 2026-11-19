import { initialTab, keyTarget } from '../core/panelTabs.js';

/**
 * Tabs of the detail panel (TASK-065, spec A1–A2 revised 2026-11-19).
 *
 * The markup is the admin's own sidebar tabs (`.sidebar-section-nav`, ADR-0014),
 * rendered by `drawer-details.phtml` with every panel in it. The core switches
 * them from `resource-form.js`, which the master view does not load, so this
 * file does it, scoped to one freshly-rendered drawer.
 *
 * Panels are hidden, never removed: an open re-cataloguing or governance edit
 * survives a tab switch. Showing a panel dispatches `PANEL_TAB_SHOWN` on it, so
 * whoever fills a panel lazily (the history) listens on its own panel and this
 * file does not need to know it exists.
 */
export const PANEL_TAB_SHOWN = 'oer:panel-tab-shown';

/** Remembered while the curator moves from one REA to the next. */
const STORAGE_KEY = 'oermanager.panelTab';

function remembered() {
    try {
        return window.sessionStorage.getItem(STORAGE_KEY);
    } catch (e) {
        return null;
    }
}

function remember(tab) {
    try {
        window.sessionStorage.setItem(STORAGE_KEY, tab);
    } catch (e) {
        // Private window or blocked storage: the panel opens on its first tab.
    }
}

/**
 * @param {Element} content the drawer's freshly-rendered content
 */
export function wireTabs(content) {
    const nav = content.querySelector('.oer-panel-tabs');
    if (!nav) {
        return;
    }
    const tabs = Array.from(nav.querySelectorAll('[role="tab"]'));
    const panelOf = (tab) => content.querySelector(`#${tab.getAttribute('aria-controls')}`);

    const select = (tab, { focus = false, store = true } = {}) => {
        tabs.forEach((other) => {
            const on = other === tab;
            other.setAttribute('aria-selected', on ? 'true' : 'false');
            other.classList.toggle('active', on);
            other.tabIndex = on ? 0 : -1;
            const panel = panelOf(other);
            if (panel) {
                panel.hidden = !on;
            }
        });
        if (focus) {
            tab.focus();
        }
        if (store) {
            remember(tab.dataset.tab);
        }
        const panel = panelOf(tab);
        if (panel) {
            panel.dispatchEvent(new CustomEvent(PANEL_TAB_SHOWN, { detail: { tab: tab.dataset.tab } }));
        }
    };

    nav.addEventListener('click', (event) => {
        const tab = event.target.closest('[role="tab"]');
        if (tab && nav.contains(tab)) {
            select(tab);
        }
    });

    nav.addEventListener('keydown', (event) => {
        const current = tabs.indexOf(event.target);
        if (current < 0) {
            return;
        }
        const next = keyTarget(event.key, current, tabs.length);
        if (null === next) {
            return;
        }
        event.preventDefault();
        select(tabs[next], { focus: true });
    });

    // The integrity summary (spec A6) opens the tab that holds the issues and
    // moves the focus to its panel, where they are listed first. Delegated on
    // `.oer-detail-panel`: it is new on every render (unlike `content`, which
    // outlives it), and `ui/panelIntegrity.js` may replace the summary later.
    const panelEl = nav.closest('.oer-detail-panel') || content;
    panelEl.addEventListener('click', (event) => {
        const link = event.target.closest('[data-goto-tab]');
        if (!link || !panelEl.contains(link)) {
            return;
        }
        const tab = tabs.find((one) => one.dataset.tab === link.dataset.gotoTab);
        if (!tab) {
            return;
        }
        select(tab);
        const panel = panelOf(tab);
        if (panel) {
            panel.focus();
        }
    });

    const first = initialTab(remembered(), tabs.map((tab) => tab.dataset.tab));
    const start = tabs.find((tab) => tab.dataset.tab === first);
    if (start) {
        select(start, { store: false });
    }
}
