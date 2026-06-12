<?php

namespace OERManager\Form;

use Laminas\Form\Form;

/**
 * Formulario de configuración del módulo (module.ini: configurable = true).
 * FASE 1: sin campos; las opciones reales llegan con los RF detallados
 * (PEND-007) y no se persiste nada todavía.
 */
class ConfigForm extends Form
{
    public function init(): void
    {
        // Punto de extensión: opciones de configuración en fases posteriores.
    }
}
