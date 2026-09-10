<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Columnas de gobernanza con property FIJA: «Licencia» y «Tipo de recurso»
 * (TASK-042).
 *
 * Hasta TASK-042 las dos eran el tipo genérico `oerGovernanceValue`, cuya
 * property solo se fijaba en `column_defaults`. Ese tipo no tiene formulario de
 * datos, así que el selector de columnas del usuario lo ofrecía pero no dejaba
 * decir qué property mostrar: quien quitaba la columna no podía volver a
 * añadirla. Aquí la property y el texto de vacío van con el tipo, no con la
 * configuración. Pura para poder probarse en el host.
 */
final class GovernanceColumns
{
    public const LICENCE = 'oerLicence';

    public const RESOURCE_TYPE = 'oerResourceType';

    private const FIXED = [
        self::LICENCE => [
            'label' => 'Licencia', // @translate
            'property_term' => IntegrityPolicy::LICENSE_TERM,
            'empty_label' => 'Sin licencia', // @translate
        ],
        self::RESOURCE_TYPE => [
            'label' => 'Tipo de recurso', // @translate
            'property_term' => 'lrmi:learningResourceType',
            'empty_label' => 'Sin tipo', // @translate
        ],
    ];

    /**
     * Datos efectivos de una columna. La property y el texto de vacío fijos
     * GANAN a lo guardado —una selección antigua no puede reapuntarla a otra
     * property—; la cabecera y el valor por defecto del usuario se respetan.
     * Con `$column` null (tipo genérico) se devuelve lo configurado tal cual.
     */
    public static function resolve(?string $column, array $data): array
    {
        $fixed = self::FIXED[$column ?? ''] ?? null;
        if (null === $fixed) {
            return $data;
        }
        $data['property_term'] = $fixed['property_term'];
        $data['empty_label'] = $fixed['empty_label'];
        return $data;
    }

    /** Rótulo del tipo en el selector de columnas, o null si no es de propiedad fija. */
    public static function label(?string $column): ?string
    {
        return self::FIXED[$column ?? '']['label'] ?? null;
    }
}
