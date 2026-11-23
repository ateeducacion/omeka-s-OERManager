/**
 * Search box of the tree editor (TASK-065 part B, spec B4): one input over
 * `search-terms` that calls `onPick` with the chosen result instead of adding
 * a chip. The course box is narrowed by the Etapa helper; a subject box by its
 * own course.
 *
 * Accessibility (NFR-006): a labelled native input, results as native
 * buttons, the count in a polite live region. ArrowDown goes from the input
 * to the results, ArrowUp/ArrowDown move between them, Escape closes them and
 * returns to the input (it does not close the drawer).
 */

let boxSeq = 0;

function t(text) {
    return Omeka.jsTranslate(text);
}

function lineageOf(result) {
    return [result.parentTitle, result.block, result.description]
        .filter((part) => part && String(part).trim())
        .join(' · ');
}

export function buildTermSearch({ config, dimension, label, placeholder, context, onPick }) {
    boxSeq += 1;
    const id = 'oer-term-searchbox-' + boxSeq;
    const $box = $('<div>').addClass('oer-term-searchbox');
    const $input = $('<input>').attr({
        type: 'search',
        id,
        autocomplete: 'off',
        spellcheck: 'false',
        placeholder: placeholder || t('Buscar término…')
    }).addClass('oer-term-searchbox-input');
    const $results = $('<ul>').addClass('oer-term-searchbox-results').prop('hidden', true);
    const $status = $('<p>').addClass('oer-visually-hidden').attr({ role: 'status', 'aria-live': 'polite' });
    let timer = null;
    let request = 0;

    const close = () => {
        $results.empty().prop('hidden', true);
    };

    const pick = (result) => {
        $input.val('');
        close();
        $status.text('');
        onPick(result);
    };

    $input.on('input', () => {
        window.clearTimeout(timer);
        const text = String($input.val() || '').trim();
        if (text.length < 2) {
            close();
            $status.text('');
            return;
        }
        timer = window.setTimeout(() => {
            const mine = ++request;
            const params = Object.assign({}, context ? context() : {}, { dimension, q: text });
            $.getJSON(config.searchTermsUrl, params).done((response) => {
                if (mine !== request) {
                    return;
                }
                const results = (response && response.results) || [];
                $results.empty();
                results.forEach((result) => {
                    const $button = $('<button>').attr('type', 'button').addClass('oer-term-searchbox-result')
                        .append($('<span>').addClass('oer-term-title').text(result.title));
                    const lineage = lineageOf(result);
                    if (lineage) {
                        $button.append($('<span>').addClass('oer-term-lineage').text(lineage));
                    }
                    $button.on('click', () => pick(result));
                    $results.append($('<li>').append($button));
                });
                if (!results.length) {
                    $results.append($('<li>').addClass('no-results').text(t('Sin resultados')));
                }
                $results.prop('hidden', false);
                $status.text(1 === results.length ? t('1 resultado') : results.length + ' ' + t('resultados'));
            });
        }, 250);
    });

    $input.on('keydown', (event) => {
        if ('Enter' === event.key) {
            event.preventDefault();
        }
        if ('ArrowDown' === event.key && !$results.prop('hidden')) {
            event.preventDefault();
            $results.find('button').first().trigger('focus');
        }
        if ('Escape' === event.key && !$results.prop('hidden')) {
            // Closes the results, not the drawer (drawer.js closes on Escape).
            event.stopPropagation();
            close();
        }
    });

    $results.on('keydown', 'button', function (event) {
        const $buttons = $results.find('button');
        const index = $buttons.index(this);
        if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
            event.preventDefault();
            const next = 'ArrowDown' === event.key ? index + 1 : index - 1;
            if (next < 0) {
                $input.trigger('focus');
            } else if (next < $buttons.length) {
                $buttons.eq(next).trigger('focus');
            }
        }
        if ('Escape' === event.key) {
            event.stopPropagation();
            close();
            $input.trigger('focus');
        }
    });

    return $box.append($('<label>').attr('for', id).text(label), $input, $results, $status);
}
