/**
 * The flow bar's reason forms (TASK-065, spec A6): one open at a time. Pure,
 * so the rule is tested with `node --test`; `ui/workflowDrawer.js` applies it.
 *
 * @param {string|null} open the action whose reason form is open
 * @param {string|null} clicked the action pressed, or null for «Cancelar»
 * @returns {string|null} the action whose form is open afterwards
 */
export function nextOpen(open, clicked) {
    return clicked === open ? null : clicked;
}
