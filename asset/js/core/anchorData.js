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
    return { allowed: false, reason: '', current: {} };
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
        current
    };
}

/** Valores ya elegidos de un término, o lista vacía. */
export function valuesFor(recatalog, term) {
    return (recatalog && recatalog.current && recatalog.current[term]) || [];
}
