/**
 * Configuración de la vista maestra, leída UNA sola vez (TASK-028). Antes se
 * leía con $('#oer-master-view-table').data(...) en más de diez puntos
 * dispersos, de modo que un dato que la plantilla dejara de emitir no se
 * detectaba hasta fallar en producción.
 */
export function readConfig() {
    const table = document.getElementById('oer-master-view-table');
    if (!table) {
        return null;
    }
    const d = table.dataset;
    const flag = (value) => '1' === value;
    return Object.freeze({
        setVisibilityUrl: d.setVisibilityUrl,
        csrfToken: d.csrfToken,
        searchTermsUrl: d.searchTermsUrl,
        recatalogPreviewUrl: d.recatalogPreviewUrl,
        recatalogApplyUrl: d.recatalogApplyUrl,
        recatalogLastEventUrl: d.recatalogLastEventUrl,
        recatalogUndoUrl: d.recatalogUndoUrl,
        aiProposeUrl: d.aiProposeUrl,
        aiProposeStatusUrl: d.aiProposeStatusUrl,
        aiProposeCancelUrl: d.aiProposeCancelUrl,
        aiEnabled: flag(d.aiEnabled),
        canRecatalog: flag(d.canRecatalog),
        recatalogCsrf: d.recatalogCsrf,
        drawerHistoryUrl: d.drawerHistoryUrl
    });
}
