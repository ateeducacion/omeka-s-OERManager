import { chipIdsByTerm } from './termPicker.js';

/**
 * Búsqueda avanzada (TASK-028, D-5): vuelca al formulario los términos elegidos
 * por autocompletado.
 *
 * Los filtros curriculares se rellenan con el mismo widget de chips que el
 * re-catalogador, que no lleva `name`, así que al enviar hay que copiar el id
 * del chip al campo que sí viaja. Se toma solo el primero: el filtro acota por
 * un ancestro, no por varios.
 */
export function initSearchForm() {
    const form = document.getElementById('oer-advanced-search');
    if (!form) {
        return;
    }
    form.addEventListener('submit', () => {
        const idsByTerm = chipIdsByTerm($(form));
        form.querySelectorAll('.oer-term-value').forEach((input) => {
            const ids = idsByTerm[input.dataset.for] || [];
            input.value = ids.length ? ids[0] : '';
        });
    });
}
