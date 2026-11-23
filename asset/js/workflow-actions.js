/**
 * Zona de botones del flujo de curación en la página del item (TASK-067,
 * rediseño de 2026-11-18). Las acciones con motivo son <details> y funcionan
 * sin JavaScript; esto solo mejora el uso:
 * - abrir una cierra las demás y pone el foco en su campo;
 * - Esc o «Cancelar» la cierran y devuelven el foco a su botón;
 * - un clic fuera del panel la cierra.
 */
(function () {
    'use strict';

    function panels() {
        return Array.prototype.slice.call(document.querySelectorAll('.oer-workflow-reason'));
    }

    function close(details, refocus) {
        if (!details.open) {
            return;
        }
        details.open = false;
        if (refocus) {
            details.querySelector('summary').focus();
        }
    }

    function init() {
        panels().forEach(function (details) {
            details.addEventListener('toggle', function () {
                if (!details.open) {
                    return;
                }
                panels().forEach(function (other) {
                    if (other !== details) {
                        close(other, false);
                    }
                });
                var field = details.querySelector('[name="reason"]');
                if (field) {
                    field.focus();
                }
            });
            details.addEventListener('keydown', function (event) {
                if ('Escape' === event.key) {
                    event.preventDefault();
                    close(details, true);
                }
            });
            var cancel = details.querySelector('[data-oer-workflow-cancel]');
            if (cancel) {
                cancel.addEventListener('click', function () {
                    close(details, true);
                });
            }
        });

        document.addEventListener('click', function (event) {
            panels().forEach(function (details) {
                if (details.open && !details.contains(event.target)) {
                    close(details, false);
                }
            });
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
