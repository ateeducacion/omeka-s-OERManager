/**
 * «Explorar cursos» and «Ver materias» of the alignment editor (TASK-065,
 * spec B8 addendum). Pure core: no DOM, no jQuery, so `node --test` covers it.
 *
 * The curator who does not know a course's or a subject's name browses it:
 * stages, then the courses of one stage; or the subjects of one course. Each
 * list is the children of ONE parent, asked to `search-terms` with
 * `browse=1` and no text; the server refuses a browse without a parent
 * (NFR-004), so this model never builds one.
 */
import { COURSE, SUBJECT, subjectName } from './alignmentTree.js';

export const STAGE = 'etapa';

/** The parent each browsable dimension is listed under. */
const PARENT_PARAM = Object.freeze({ [COURSE]: 'etapa', [SUBJECT]: 'level' });

const ID = /^[1-9]\d*$/;

/** Query of `search-terms` for the children of `parentId`; null when it cannot be asked. */
export function browseRequest(dimension, parentId) {
    if (STAGE === dimension) {
        return { dimension, browse: 1 };
    }
    const param = PARENT_PARAM[dimension];
    const id = String(parentId ?? '');
    if (!param || !ID.test(id)) {
        return null;
    }
    return { dimension, browse: 1, [param]: [id] };
}

/** Cache key of one list. */
export function browseKey(dimension, parentId) {
    return dimension + '|' + String(parentId ?? '');
}

/**
 * Rows to show: id as a string, the short name («Matemáticas», not
 * «Matemáticas · 1º ESO»), whether it is already in the alignment, and the
 * raw result to hand to addCourse/addSubject.
 */
export function browseRows(results, chosenIds) {
    const chosen = new Set((chosenIds || []).map(String));
    return (results || [])
        .filter((result) => result && ID.test(String(result.id ?? '')))
        .map((result) => {
            const id = String(result.id);
            return {
                id,
                name: subjectName(result.title, result.parentTitle),
                added: chosen.has(id),
                result
            };
        });
}

/** Text for the list's live region: loading, failure, empty; '' when there is a list. */
export function browseStatus(entry, t) {
    if (!entry) {
        return '';
    }
    if ('loading' === entry.status) {
        return t('Cargando…');
    }
    if ('error' === entry.status) {
        return t('No se pudo cargar la lista.');
    }
    return (entry.results || []).length ? '' : t('Sin resultados');
}
