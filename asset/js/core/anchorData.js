/**
 * Lo que el área de anclaje ofrece (TASK-035): `drawer-details` lo escribe en
 * `data-recatalog` del hueco del re-catalogador, leído con la sesión del
 * admin. Sustituye al `itemJson` que `drawer.js` pedía a `/api` sin
 * autenticar, que un REA privado no contestaba nunca.
 *
 * `allowed` es la respuesta de `RecatalogRule` para este usuario y este REA
 * (el servidor la vuelve a aplicar en cada escritura); `reason`, el motivo ya
 * traducido cuando no puede; `current`, término → [{id, title}].
 *
 * Núcleo puro. Ante datos ausentes o rotos, cerrado: sin editor y sin motivo.
 */
function closed() {
    return { allowed: false, reason: '', current: {}, aiProposal: null };
}

function cleanValues(values) {
    if (!Array.isArray(values)) {
        return [];
    }
    const out = [];
    values.forEach((value) => {
        const id = value ? Number(value.id) : 0;
        if (!Number.isInteger(id) || id <= 0) {
            return;
        }
        out.push({ id, title: 'string' === typeof value.title ? value.title : '' });
    });
    return out;
}

export function parseRecatalog(raw) {
    if (!raw) {
        return closed();
    }
    let data;
    try {
        data = JSON.parse(raw);
    } catch (error) {
        return closed();
    }
    if (!data || 'object' !== typeof data || Array.isArray(data)) {
        return closed();
    }
    const current = {};
    const source = data.current && 'object' === typeof data.current ? data.current : {};
    Object.keys(source).forEach((term) => {
        current[term] = cleanValues(source[term]);
    });
    return {
        allowed: true === data.allowed,
        reason: 'string' === typeof data.reason ? data.reason : '',
        current,
        aiProposal: parseAiProposal(data.aiProposal)
    };
}

/** Valores ya elegidos de un término, o lista vacía. */
export function valuesFor(recatalog, term) {
    return (recatalog && recatalog.current && recatalog.current[term]) || [];
}

/** TASK-011: the stored AI proposal of the panel, or null when absent or malformed. */
function parseAiProposal(raw) {
    if (!raw || 'object' !== typeof raw || Array.isArray(raw) || 'string' !== typeof raw.created) {
        return null;
    }
    const alignment = {};
    const source = raw.alignment && 'object' === typeof raw.alignment ? raw.alignment : {};
    Object.keys(source).forEach((term) => {
        const chips = (Array.isArray(source[term]) ? source[term] : [])
            .filter((chip) => chip && Number.isInteger(chip.id) && chip.id > 0 && 'string' === typeof chip.title);
        if (chips.length) {
            alignment[term] = chips.map((chip) => ({ id: chip.id, title: chip.title }));
        }
    });
    const justifications = raw.justifications && 'object' === typeof raw.justifications && !Array.isArray(raw.justifications)
        ? raw.justifications
        : {};
    return { created: raw.created, changed: true === raw.changed, alignment, justifications };
}

/** «Propuesta IA del dd/mm/aaaa.», plus the warning when the REA changed since. */
export function proposalNotice(aiProposal, translate = (text) => text) {
    const [year, month, day] = aiProposal.created.slice(0, 10).split('-');
    let text = translate('Propuesta IA del %s.').replace('%s', `${day}/${month}/${year}`);
    if (aiProposal.changed) {
        text += ' ' + translate('El REA ha cambiado desde la propuesta.');
    }
    return text;
}
