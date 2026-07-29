<?php

declare(strict_types=1);

namespace OERManager\Service;

/**
 * Claves de configuración de la gobernanza del catálogo (ADR-0013).
 *
 * Regla «sembrar, no poseer»: el módulo consume lo que ya existe (el CustomVocab
 * de tipos de recurso) y siembra bajo demanda lo que no existe (el de licencias y
 * la plantilla REA), pero en los dos casos identifica el artefacto por **id en un
 * setting, nunca por etiqueta** — las etiquetas cambian con el idioma y con la
 * edición del admin.
 *
 * `parseId()` devuelve `null` para «no configurado», que es la condición con la
 * que el campo degrada a texto libre en vez de romper (mismo patrón que
 * CurriculumSearch, que devuelve [] cuando su setting está vacío).
 */
final class GovernanceSettings
{
    /** CustomVocab de licencias (dcterms:rights). Lo siembra el módulo si falta. */
    public const LICENCE_VOCAB_ID = 'oermanager_licence_vocab_id';

    /**
     * CustomVocab de tipos de recurso (lrmi:learningResourceType). El módulo NO lo
     * crea: en la instalación de referencia ya existe («Tipos de recursos», 29
     * términos en 7 familias) y duplicarlo competiría con el que alguien diseñó.
     */
    public const RESOURCE_TYPE_VOCAB_ID = 'oermanager_resource_type_vocab_id';

    /**
     * Plantilla REA (resource_template). Saber CUÁL es permite a IntegrityChecker
     * distinguir «la» plantilla de «otra cualquiera»: sin este setting, asignar una
     * plantilla cualquiera cambia las reglas de validación y silencia los avisos.
     */
    public const REA_TEMPLATE_ID = 'oermanager_rea_template_id';

    /** Titular de derechos por defecto al rellenar la ficha (RF-015). */
    public const DEFAULT_RIGHTS_HOLDER = 'oermanager_default_rights_holder';

    /** Tope del titular por defecto: es una etiqueta, no un texto libre. */
    public const MAX_RIGHTS_HOLDER_LEN = 200;

    /**
     * Id del artefacto configurado, o null si no lo está. Solo enteros positivos:
     * un 0, un valor vacío o un texto se tratan como «sin configurar».
     */
    public static function parseId(mixed $raw): ?int
    {
        if (is_array($raw) || null === $raw) {
            return null;
        }
        $value = trim((string) $raw);
        if ('' === $value || !is_numeric($value)) {
            return null;
        }
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    /** Titular de derechos saneado: recortado y acotado en longitud. */
    public static function parseRightsHolder(mixed $raw): string
    {
        if (is_array($raw) || null === $raw) {
            return '';
        }
        return mb_substr(trim((string) $raw), 0, self::MAX_RIGHTS_HOLDER_LEN);
    }
}
