import test from 'node:test';
import assert from 'node:assert/strict';
import { buildPayload, validate, licenceState, rows } from '../../asset/js/core/governanceModel.js';

// The four tests below are verbatim from the task-10 brief: they pin the wire
// shape the governance-apply endpoint (built in an earlier task) already
// reads, so this module must agree with GovernanceFields::normalise() and
// LicenceStatus::of() exactly, not just approximately.

test('drops empty authors and keeps their order', () => {
    const payload = buildPayload({ creator: ['Ana', '  ', 'Luis'] });
    assert.deepEqual(payload['dcterms:creator'], ['Ana', 'Luis']);
});

test('a source that is not http is rejected before sending', () => {
    assert.deepEqual(validate({ source: 'ftp://x/y' }), { 'dcterms:source': 'not-http-uri' });
    assert.deepEqual(validate({ source: 'https://x/y' }), {});
});

test('licence state matches the server classification', () => {
    const vocab = ['https://x/by/4.0/'];
    assert.equal(licenceState([], vocab), 'missing');
    assert.equal(licenceState([{ uri: 'https://x/by/4.0/' }], vocab), 'in_vocab');
    assert.equal(licenceState([{ uri: 'https://x/mia/' }], vocab), 'outside_vocab');
    assert.equal(licenceState([{ uri: 'https://x/mia/' }], null), 'unchecked');
});

test('a cleared field is sent as an empty list, not omitted', () => {
    const payload = buildPayload({ creator: [], licence: '' });
    assert.deepEqual(payload['dcterms:creator'], []);
    assert.deepEqual(payload['dcterms:license'], []);
});

// The rest are not in the brief verbatim; they cover the remaining exports
// and the PHP mirroring points the brief called out by name, so a future
// change to GovernanceFields or LicenceStatus that drifts from this file has
// something to fail against.

test('buildPayload leaves a field entirely absent when the form never touched it', () => {
    const payload = buildPayload({ creator: ['Ana'] });
    assert.equal('dcterms:license' in payload, false);
    assert.equal('dcterms:publisher' in payload, false);
    assert.equal('dcterms:rightsHolder' in payload, false);
    assert.equal('dcterms:source' in payload, false);
});

test('buildPayload trims and wraps single-valued fields the same way as multi-valued ones', () => {
    const payload = buildPayload({
        licence: '  https://x/by/4.0/  ',
        publisher: '  Consejería  ',
        rightsHolder: '',
        source: 'https://x/y'
    });
    assert.deepEqual(payload['dcterms:license'], ['https://x/by/4.0/']);
    assert.deepEqual(payload['dcterms:publisher'], ['Consejería']);
    assert.deepEqual(payload['dcterms:rightsHolder'], []);
    assert.deepEqual(payload['dcterms:source'], ['https://x/y']);
});

test('validate rejects a non-http licence URI the same way as source', () => {
    assert.deepEqual(validate({ licence: 'ftp://x/by' }), { 'dcterms:license': 'not-http-uri' });
});

test('validate only checks fields present in the form state', () => {
    assert.deepEqual(validate({}), {});
});

test('validate flags a second value on a single-valued field as too-many, mirroring GovernanceFields', () => {
    assert.deepEqual(validate({ publisher: ['Uno', 'Dos'] }), { 'dcterms:publisher': 'too-many' });
});

test('licenceState canonicalisation matches LicenceStatus::canonicalUri: host case and one trailing slash do not change membership', () => {
    const vocab = ['https://creativecommons.org/licenses/by-sa/4.0/', 'https://creativecommons.org/licenses/by/4.0/'];
    const values = [{ uri: 'https://CreativeCommons.org/licenses/by/4.0' }];
    assert.equal(licenceState(values, vocab), 'in_vocab');
});

// The next two are ported from LicenceStatusTest.php's
// testHostRecurringElsewhereInTheUriIsNotAltered() and
// testOnlyOneTrailingSlashIsStripped() — the PHP side shipped both bugs once
// before catching them, and neither had a JS test of its own before this fix
// round. Each is written to fail against the naive fix that broke the PHP
// version: a global (all-occurrences) host replace for the first, an
// unbounded `replace(/\/+$/, '')` for the second.

test('only the host is case-folded: the same string recurring in the path stays verbatim, so a path-case difference is outside the vocabulary', () => {
    // The host string "AB.CO" also occurs, in the same case, inside the path.
    // A naive case-fold that replaces every occurrence of the host substring
    // (not just the host component) would fold the path's copy too and wrongly
    // report in_vocab; canonicalUri() must fold only the host.
    const vocab = ['https://ab.co/path/ab.co/tail'];
    const values = [{ uri: 'https://AB.CO/path/AB.CO/tail' }];
    assert.equal(licenceState(values, vocab), 'outside_vocab');
});

test('exactly one trailing slash is stripped, not every one', () => {
    // A naive `replace(/\/+$/, '')` (or repeated single-slash stripping) would
    // remove both slashes and wrongly report in_vocab; canonicalUri() strips
    // one and leaves the second as a real path difference.
    const vocab = ['https://example.org/licence'];
    const values = [{ uri: 'https://example.org/licence//' }];
    assert.equal(licenceState(values, vocab), 'outside_vocab');
});

test('licenceState treats a value without a uri as outside the vocabulary, never missing', () => {
    const vocab = ['https://x/by/4.0/'];
    assert.equal(licenceState([{ value: 'ccby' }], vocab), 'outside_vocab');
});

test('rows lists the five governance fields in GovernanceFields order, with their read-view text', () => {
    const governance = {
        values: {
            'dcterms:license': [{ type: 'uri', uri: 'https://x/by/4.0/' }],
            'dcterms:creator': [{ type: 'literal', value: 'Ana' }, { type: 'literal', value: 'Luis' }],
            'dcterms:publisher': [],
            'dcterms:rightsHolder': [{ type: 'literal', value: 'Consejería' }],
            'dcterms:source': []
        },
        licenceStatus: 'in_vocab'
    };

    const result = rows(governance);

    assert.deepEqual(result.map((row) => row.term), [
        'dcterms:license',
        'dcterms:creator',
        'dcterms:publisher',
        'dcterms:rightsHolder',
        'dcterms:source'
    ]);
    assert.equal(result[0].text, 'https://x/by/4.0/');
    assert.equal(result[0].missing, false);
    assert.equal(result[1].text, 'Ana, Luis');
    assert.equal(result[2].missing, true);
});

test('rows prefers the label over the bare uri, like ValueText::of', () => {
    const governance = {
        values: { 'dcterms:license': [{ type: 'customvocab:2', uri: 'https://x/by/4.0/', label: 'CC BY 4.0' }] },
        licenceStatus: 'in_vocab'
    };
    assert.equal(rows(governance)[0].text, 'CC BY 4.0');
});

test('rows marks the licence row with a warning only when the server says outside_vocab', () => {
    const outside = rows({
        values: { 'dcterms:license': [{ type: 'uri', uri: 'https://x/mia/' }] },
        licenceStatus: 'outside_vocab'
    });
    assert.equal(outside[0].warning, true);

    const inVocab = rows({
        values: { 'dcterms:license': [{ type: 'uri', uri: 'https://x/by/4.0/' }] },
        licenceStatus: 'in_vocab'
    });
    assert.equal(inVocab[0].warning, undefined);

    const empty = rows({ values: {}, licenceStatus: 'missing' });
    assert.equal(empty[0].warning, undefined);
});

test('rows treats a uri key that is explicitly null as unset, like PHP isset(), and falls back to the literal value', () => {
    const governance = {
        values: { 'dcterms:publisher': [{ type: 'literal', value: 'Texto libre', uri: null }] }
    };
    assert.equal(rows(governance)[2].text, 'Texto libre');
});

test('rows tolerates a governance payload with no values at all', () => {
    const result = rows({});
    assert.equal(result.length, 5);
    assert.ok(result.every((row) => row.missing === true));
});
