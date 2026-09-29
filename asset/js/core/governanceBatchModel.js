import { TERMS, buildPayload, validate } from './governanceModel.js';

/**
 * Pure model of the batch licence and authorship form (TASK-028 slice 4).
 * The server re-validates everything (BatchRequest, BatchSelection); this
 * only lets the form warn early and decide what to show. No DOM.
 */

export const BATCH_KEYS = ['licence', 'creator', 'publisher', 'rightsHolder'];

const KEY_TERM = {
    licence: TERMS.LICENCE,
    creator: TERMS.CREATOR,
    publisher: TERMS.PUBLISHER,
    rightsHolder: TERMS.RIGHTS_HOLDER
};

/** Mirrors BatchRequest: no-field, required, then GovernanceFields' own rules. */
export function validateBatch(ticked) {
    const keys = BATCH_KEYS.filter((key) => Object.prototype.hasOwnProperty.call(ticked, key));
    if (!keys.length) {
        return { _: 'no-field' };
    }
    const payload = buildPayload(ticked);
    const errors = {};
    keys.forEach((key) => {
        if (!payload[KEY_TERM[key]].length) {
            errors[KEY_TERM[key]] = 'required';
        }
    });
    return Object.assign(validate(ticked), errors);
}

export function previewPairs({ ticked, mode, csrf, selection }) {
    const payload = buildPayload(ticked);
    const pairs = [];
    BATCH_KEYS.forEach((key) => {
        const term = KEY_TERM[key];
        if (!payload[term]) {
            return;
        }
        if (TERMS.CREATOR === term) {
            payload[term].forEach((value) => pairs.push([`governance[${term}][]`, value]));
        } else {
            payload[term].forEach((value) => pairs.push([`governance[${term}]`, value]));
        }
    });
    pairs.push(['mode', mode], ['csrf', csrf]);
    if ('matching' === selection.scope) {
        pairs.push(['scope', 'matching'], ['query', selection.query]);
    } else {
        selection.ids.forEach((id) => pairs.push(['ids[]', String(id)]));
    }
    return pairs;
}

export function selectionAfterToggle(state, event) {
    if ('clear' === event.type) {
        return { ...state, scope: 'ids', checked: 0 };
    }
    if ('select-matching' === event.type) {
        return { ...state, scope: 'matching' };
    }
    const checked = event.checked;
    const scope = 'matching' === state.scope && checked === state.pageCount ? 'matching' : 'ids';
    return { ...state, checked, scope };
}

export function stripModel(state) {
    if ('matching' === state.scope) {
        return { visible: true, text: 'matching-all', action: 'clear', count: state.totalMatching };
    }
    const pageFull = state.pageCount > 0 && state.checked === state.pageCount;
    if (pageFull && state.totalMatching > state.pageCount) {
        return { visible: true, text: 'page-all', action: 'select-matching', count: state.totalMatching };
    }
    return { visible: false, text: '', action: null, count: 0 };
}

export function previewModel(response) {
    const replace = 'replace' === response.mode;
    const rows = Object.entries(response.summary || {}).map(([term, field]) => ({
        term,
        write: field.write,
        other: replace ? field.overwrite : field.skipped_has_value,
        otherKind: replace ? 'overwrite' : 'skipped'
    }));
    const overwriteTotal = replace ? rows.reduce((sum, row) => sum + row.other, 0) : 0;
    return {
        rows,
        canApply: response.writeTotal > 0,
        needsOverwriteConfirm: overwriteTotal > 0,
        overwriteTotal,
        writeTotal: response.writeTotal
    };
}

export function resultModel(state) {
    if ('in_progress' === state.status) {
        const percent = state.total > 0 ? Math.floor((state.done / state.total) * 100) : 0;
        return { finished: false, percent, status: 'in_progress' };
    }
    const tallies = state.tallies || {};
    return {
        finished: true,
        percent: 100,
        status: state.status,
        code: state.code,
        batch: state.batch,
        tallies,
        failed: tallies.failed || []
    };
}
