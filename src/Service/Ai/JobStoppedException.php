<?php

namespace OERManager\Service\Ai;

/**
 * La lanza AiCataloguer cuando shouldStop() es cierto en un límite de fase; el
 * AiProposeJob la captura y marca el estado `stopped` (TASK-020). No se produce
 * ninguna propuesta parcial.
 */
final class JobStoppedException extends \RuntimeException
{
}
