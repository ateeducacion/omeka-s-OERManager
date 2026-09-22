/**
 * Pure model for the licence and authorship form (RF-015, TASK-028 slice 3b).
 *
 * This module lets the form warn before a round trip, but it never has the
 * final word: `governance-apply` re-validates with GovernanceFields and
 * LicenceStatus, and its answer is what actually gets painted. Every rule
 * here mirrors one of those two PHP classes on purpose, and each function
 * below says which.
 *
 * Pure core, no DOM: the boundary set in slice 1. No import from `ui/`.
 */

/** The five governance terms of GovernanceFields, kept identical on purpose. */
export const TERMS = {
    LICENCE: 'dcterms:license',
    CREATOR: 'dcterms:creator',
    PUBLISHER: 'dcterms:publisher',
    RIGHTS_HOLDER: 'dcterms:rightsHolder',
    SOURCE: 'dcterms:source'
};

/**
 * form-state key -> term, plus the two things GovernanceFields::SPEC encodes
 * server-side: whether the field takes more than one value, and whether its
 * values are checked as an http(s) URI. `dcterms:creator` is the only
 * multi-valued field today.
 */
const FIELD_SPEC = [
    { key: 'licence', term: TERMS.LICENCE, multiple: false, uri: true },
    { key: 'creator', term: TERMS.CREATOR, multiple: true, uri: false },
    { key: 'publisher', term: TERMS.PUBLISHER, multiple: false, uri: false },
    { key: 'rightsHolder', term: TERMS.RIGHTS_HOLDER, multiple: false, uri: false },
    { key: 'source', term: TERMS.SOURCE, multiple: false, uri: true }
];

/** Read-view labels and empty-state text, kept in step with drawer-details.phtml (Task 9). */
const ROW_SPEC = [
    { term: TERMS.LICENCE, label: 'Licencia', missingText: 'Sin licencia' },
    { term: TERMS.CREATOR, label: 'Autoría', missingText: 'Sin autoría' },
    { term: TERMS.PUBLISHER, label: 'Editor', missingText: 'Sin editor' },
    { term: TERMS.RIGHTS_HOLDER, label: 'Titular de derechos', missingText: 'Sin titular de derechos' },
    { term: TERMS.SOURCE, label: 'Fuente', missingText: 'Sin fuente' }
];

/**
 * Turns one form field's raw input (a string for a single-valued field, an
 * array for the repeatable author field) into the trimmed, non-empty list
 * GovernanceFields::normalise() expects on the wire. Mirrors its per-entry
 * loop: trim, then drop candidates that are empty after trimming — for a
 * single-valued field this is the same rule applied to a one-item list.
 * @param {string|Array<string>} raw
 * @returns {Array<string>}
 */
function normaliseCandidates(raw) {
    const list = Array.isArray(raw) ? raw : [raw];
    return list
        .map((candidate) => String(candidate === null || candidate === undefined ? '' : candidate).trim())
        .filter((text) => text !== '');
}

/**
 * Host of an http(s) URI, or '' if the URI has none. A hand-rolled parser,
 * not `new URL()`: `new URL()` throws on malformed input and normalises in
 * ways PHP's `parse_url()` does not, and this must agree with `parse_url()`
 * exactly, not merely resemble it. Scoped to what the five governance fields
 * actually carry (plain http/https URIs, no userinfo or IPv6 host in
 * practice); userinfo and a port are still stripped so an accidental one
 * does not silently pass validation or break canonicalisation.
 * @param {string} uri
 * @returns {string}
 */
function extractHost(uri) {
    const match = /^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/([^/?#]*)/.exec(uri);
    if (!match) {
        return '';
    }
    let authority = match[1];
    const at = authority.lastIndexOf('@');
    if (at !== -1) {
        authority = authority.slice(at + 1);
    }
    const colon = authority.indexOf(':');
    if (colon !== -1) {
        authority = authority.slice(0, colon);
    }
    return authority;
}

/**
 * Mirrors GovernanceFields::isHttpUri(): scheme must be http or https, and
 * there must be a host.
 * @param {string} candidate
 * @returns {boolean}
 */
function isHttpUri(candidate) {
    const scheme = /^([a-zA-Z][a-zA-Z0-9+.-]*):\/\//.exec(candidate);
    const lower = scheme ? scheme[1].toLowerCase() : '';
    if (lower !== 'http' && lower !== 'https') {
        return false;
    }
    return extractHost(candidate) !== '';
}

/**
 * Mirrors LicenceStatus::canonicalUri(): drop one trailing slash, then
 * lowercase only the host — the first occurrence of the host substring in
 * the URI — leaving the rest (including any later occurrence of that
 * substring in a path or query) untouched, because a path is case-sensitive.
 * @param {string} uri
 * @returns {string}
 */
function canonicalUri(uri) {
    let text = String(uri === null || uri === undefined ? '' : uri).trim();
    if (text.endsWith('/')) {
        text = text.slice(0, -1);
    }
    const host = extractHost(text);
    if (host === '') {
        return text;
    }
    const offset = text.indexOf(host);
    if (offset === -1) {
        return text;
    }
    return text.slice(0, offset) + host.toLowerCase() + text.slice(offset + host.length);
}

/**
 * Builds the `governance[<term>]` payload for the apply endpoint. A field
 * present in `formState` — even as an empty value — is always present in the
 * payload (as `[]` if it ended up empty after trimming): that is how a
 * cleared field is told apart from a field the form never touched, which the
 * endpoint (`IndexController::collectGovernanceFields()`) reads with
 * `array_key_exists`, not an empty check.
 *
 * `formState` keys: `licence`, `creator` (array), `publisher`,
 * `rightsHolder`, `source`.
 *
 * @param {object} formState
 * @returns {object} term -> list of raw strings, ready to send
 */
export function buildPayload(formState) {
    const payload = {};
    FIELD_SPEC.forEach(({ key, term }) => {
        if (!Object.prototype.hasOwnProperty.call(formState, key)) {
            return;
        }
        payload[term] = normaliseCandidates(formState[key]);
    });
    return payload;
}

/**
 * Client-side echo of GovernanceFields::normalise()'s two rejection rules —
 * `not-http-uri` for `dcterms:license`/`dcterms:source`, `too-many` for a
 * second value on a single-valued field — so the form can warn before
 * posting. Only fields present in `formState` are checked, same as the
 * server only validating terms present in `$raw`. The server re-validates
 * regardless and its `errors` response is authoritative.
 *
 * @param {object} formState
 * @returns {object} term -> error code, empty when nothing is wrong
 */
export function validate(formState) {
    const errors = {};
    FIELD_SPEC.forEach(({ key, term, multiple, uri }) => {
        if (!Object.prototype.hasOwnProperty.call(formState, key)) {
            return;
        }
        const entries = normaliseCandidates(formState[key]);
        if (uri && entries.some((text) => !isHttpUri(text))) {
            errors[term] = 'not-http-uri';
            return;
        }
        if (!multiple && entries.length > 1) {
            errors[term] = 'too-many';
        }
    });
    return errors;
}

/**
 * Mirrors LicenceStatus::of(): `missing` with no values, `unchecked` when no
 * vocabulary is configured (`vocabUris` null or undefined), `in_vocab` when
 * every value's URI canonicalises to a configured entry, `outside_vocab`
 * otherwise — including a value with no `uri` at all.
 *
 * @param {Array<{uri?: string}>} values licence values, as GovernanceService::read() shapes them
 * @param {Array<string>|null|undefined} vocabUris configured vocabulary URIs, null/undefined if unresolved
 * @returns {'missing'|'in_vocab'|'outside_vocab'|'unchecked'}
 */
export function licenceState(values, vocabUris) {
    if (!values || values.length === 0) {
        return 'missing';
    }
    if (vocabUris === null || vocabUris === undefined) {
        return 'unchecked';
    }
    const allowed = vocabUris.map(canonicalUri);
    const allInVocab = values.every((value) => {
        const uri = canonicalUri(value && value.uri);
        return uri !== '' && allowed.includes(uri);
    });
    return allInVocab ? 'in_vocab' : 'outside_vocab';
}

/**
 * Text of one governance value: a URI value's label if it has one, else its
 * URI; a literal value's text otherwise. Mirrors ValueText::of() (label wins,
 * URI is the fallback, never the raw `@value` of a URI-typed value).
 *
 * The branch is on "is `uri` set", mirroring PHP's `isset($entry['uri'])` —
 * not merely "does the key exist" — so an entry that carries `uri: null`
 * falls through to the literal branch exactly as PHP's `isset()` would,
 * rather than being treated as a URI value with an empty URI. The server
 * never sends such an entry today, but the two languages must not part ways
 * over one if it ever does.
 * @param {{uri?: string, value?: string, label?: string}} entry
 * @returns {string}
 */
function entryText(entry) {
    if (entry && entry.uri !== undefined && entry.uri !== null) {
        const label = String(entry.label || '').trim();
        return label !== '' ? label : String(entry.uri || '').trim();
    }
    return String((entry && entry.value) || '');
}

/**
 * Read-view rows for the governance section, one per field in
 * GovernanceFields order. Mirrors the render logic of
 * `drawer-details.phtml` (Task 9) — same per-field labels and empty text,
 * values comma-joined via `entryText` — so a JS-driven repaint after save
 * (Task 11, using the `values`/`licenceStatus` the apply endpoint returns)
 * cannot show something the initial server render would not.
 *
 * @param {{values?: object, licenceStatus?: string}} governance the shape GovernanceService::read() returns
 * @returns {Array<{term: string, label: string, text: string, missing: boolean, missingText: string, warning?: boolean}>}
 */
export function rows(governance) {
    const values = (governance && governance.values) || {};
    const licenceStatus = governance && governance.licenceStatus;

    return ROW_SPEC.map(({ term, label, missingText }) => {
        const entries = values[term] || [];
        const text = entries.map(entryText).filter((piece) => piece !== '').join(', ');
        const row = { term, label, text, missing: text === '', missingText };
        // The one case that earns a mark (ADR-0014, same rule as the phtml):
        // a licence present but outside the configured vocabulary.
        if (term === TERMS.LICENCE && text !== '' && licenceStatus === 'outside_vocab') {
            row.warning = true;
        }
        return row;
    });
}
