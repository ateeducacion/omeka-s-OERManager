<?php

/**
 * OERManager — configuración del módulo.
 *
 * ACL: la concede `Module::onBootstrap()` por privilegio, con la matriz de
 * `Permissions\ReaAcl` (TASK-067, ADR-0023): solo `rea_proponente`,
 * `rea_curador` y `global_admin`.
 */

namespace OERManager;

use Laminas\Router\Http\Segment;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => function ($container) {
                return new Controller\Admin\IndexController(
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\CurriculumSearch::class),
                    $container->get(Service\RecatalogService::class),
                    $container->get('Omeka\Logger'),
                    $container->get(Service\Ai\AiCataloguer::class),
                    $container->get(Service\Content\MediaSourceInterface::class),
                    $container->get(Service\Ai\EvaluationScorer::class),
                    $container->get('Omeka\Settings'),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class),
                    $container->get(Service\ComputedFilter::class),
                    $container->get(Service\ResourceTypeVocab::class),
                    // TASK-029: la configuración se rinde desde el controlador,
                    // no desde Module. El manager (y no la instancia) porque es
                    // quien invoca `init()` del formulario.
                    $container->get('FormElementManager'),
                    $container->get(Service\IntegrityChecker::class),
                    $container->get(Service\ItemPanelData::class),
                    $container->get(Service\Workflow\WorkflowService::class),
                    $container->get('Omeka\Acl'),
                    // TASK-028 rebanada 3b: gobernanza (apply/read) y el router
                    // de deshacer por ámbito, que reemplaza la llamada directa a
                    // RecatalogService::undo() en recatalog-undo.
                    $container->get(Service\GovernanceService::class),
                    $container->get(Service\Curation\UndoRouter::class),
                    // Estado/cancelación del propose IA: dueño leído de la
                    // entidad Job, no de la API de jobs (vetada a editor/reviewer).
                    $container->get(Service\Governance\BatchJobLookup::class),
                    // TASK-064: valida o detecta el conjunto del currículo al guardar.
                    $container->get(Service\Curriculum\CurriculumTerms::class),
                    // TASK-067: validates the REA site on save.
                    $container->get(Service\ReaSite::class),
                    // TASK-039: reasons to reject and to discard.
                    $container->get(Service\Workflow\ReasonVocabularies::class),
                    // TASK-070: «En cola» and «Sin campo obligatorio» as id sets.
                    $container->get(Service\IdConstraintResolver::class)
                );
            },
            Controller\Admin\StatsController::class => function ($container) {
                return new Controller\Admin\StatsController(
                    $container->get(Service\Stats\CatalogSnapshot::class),
                    $container->get(Service\Stats\DimensionFacts::class),
                    $container->get(Service\Stats\DimensionCounter::class),
                    $container->get(Service\Stats\DimensionCrosser::class),
                    $container->get(Service\Stats\CompletenessAggregator::class),
                    $container->get(Service\Stats\CsvExport::class),
                    $container->get(Service\IntegrityChecker::class),
                    $container->get(Service\Stats\CurriculumOutline::class)
                );
            },
            Controller\Admin\GovernanceBatchController::class => function ($container) {
                return new Controller\Admin\GovernanceBatchController(
                    $container->get(Service\Governance\BatchSelection::class),
                    $container->get(Service\Governance\BatchPlanStore::class),
                    $container->get(Service\GovernanceService::class),
                    $container->get('Omeka\Job\Dispatcher'),
                    $container->get(Service\Ai\ProposalStore::class),
                    $container->get('Omeka\Logger'),
                    $container->get(Service\Governance\BatchJobLookup::class),
                    $container->get('Omeka\Acl')
                );
            },
        ],
    ],
    'service_manager' => [
        'invokables' => [
            Service\ItemPanelData::class => Service\ItemPanelData::class,
            // TASK-067 security review: one shared flag per request.
            Service\Workflow\StatusGuard::class => Service\Workflow\StatusGuard::class,
            // Patrón de filtros computados (ADR-0013, D4).
            Service\ComputedFilter::class => Service\ComputedFilter::class,
            // Catalogación IA (TASK-010): núcleo puro sin dependencias.
            Service\Ai\PromptBuilder::class => Service\Ai\PromptBuilder::class,
            Service\Ai\ResponseParser::class => Service\Ai\ResponseParser::class,
            Service\Ai\EvaluationScorer::class => Service\Ai\EvaluationScorer::class,
            // Estadísticas (TASK-006): agregadores puros sin dependencias.
            Service\Stats\DimensionFacts::class => Service\Stats\DimensionFacts::class,
            Service\Stats\DimensionCounter::class => Service\Stats\DimensionCounter::class,
            Service\Stats\DimensionCrosser::class => Service\Stats\DimensionCrosser::class,
            Service\Stats\CompletenessAggregator::class => Service\Stats\CompletenessAggregator::class,
            Service\Stats\CsvExport::class => Service\Stats\CsvExport::class,
        ],
        'factories' => [
            Service\MasterViewQuery::class => function ($container) {
                return new Service\MasterViewQuery($container->get('Omeka\ApiManager'));
            },
            // TASK-070: «Sin campo obligatorio» in the master view.
            Service\ReaTemplateFields::class => function ($container) {
                return new Service\ReaTemplateFields(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings')
                );
            },
            Service\IdConstraintResolver::class => function ($container) {
                return new Service\IdConstraintResolver(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\ReaTemplateFields::class)
                );
            },
            // ADR-0020 / slice 3b (TASK-028): la regla license_not_in_vocab necesita
            // saber si la licencia pertenece al vocabulario configurado. Reusa el
            // MISMO VocabEntries que ya lee el setting y degrada a null si no
            // resuelve (Task 5): duplicar esa lectura aquí bifurcaría la
            // degradación que la clase centraliza.
            Service\IntegrityChecker::class => function ($container) {
                return new Service\IntegrityChecker(
                    $container->get('OERManager\Service\Governance\VocabEntries\Licence'),
                    // TASK-064 slice 5: retired-set ids for «REA linked to a retired term».
                    $container->get(Service\Curriculum\CurriculumTerms::class),
                    // TASK-067: «REA sin sitio REA», one id-only search per request.
                    $container->get(Service\ReaSite::class)
                );
            },
            Service\ReaSite::class => function ($container) {
                return new Service\ReaSite($container->get('Omeka\ApiManager'), $container->get('Omeka\Settings'));
            },
            // Estadísticas (TASK-006): catálogo completo vía ApiManager.
            Service\Stats\CatalogSnapshot::class => function ($container) {
                return new Service\Stats\CatalogSnapshot($container->get('Omeka\ApiManager'));
            },
            // TASK-047: stages, courses and subjects of the curriculum, read-only
            // (on the cur: model since TASK-064 slice 5).
            Service\Stats\CurriculumOutline::class => function ($container) {
                return new Service\Stats\CurriculumOutline(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Curriculum\CurriculumTerms::class)
                );
            },
            // Vocabulario de tipos de recurso (D1). Dependencia BLANDA de
            // CustomVocab: no se declara en module.ini; si no está, degrada.
            Service\ResourceTypeVocab::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $api = $container->get('Omeka\ApiManager');
                return new Service\ResourceTypeVocab(
                    Service\GovernanceSettings::parseId(
                        $settings->get(Service\GovernanceSettings::RESOURCE_TYPE_VOCAB_ID)
                    ),
                    static function (int $id) use ($api): array {
                        return $api->read('custom_vocabs', $id)->getContent()->listValues();
                    }
                );
            },
            // Vocabulario de licencias (dcterms:license, ADR-0019). Dependencia BLANDA
            // de CustomVocab, igual que el de tipos de recurso: si no resuelve,
            // VocabEntries::uris() devuelve null (no []) y quien lo consuma lo trata
            // como «no hay vocabulario con el que juzgar pertenencia», no como «vacío».
            'OERManager\Service\Governance\VocabEntries\Licence' => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $api = $container->get('Omeka\ApiManager');
                return new Service\Governance\VocabEntries(
                    Service\GovernanceSettings::parseId(
                        $settings->get(Service\GovernanceSettings::LICENCE_VOCAB_ID)
                    ),
                    static function (int $id) use ($api): array {
                        $vocab = $api->read('custom_vocabs', $id)->getContent();
                        $entries = [];
                        if ('uri' === $vocab->type()) {
                            foreach (($vocab->listUriLabels() ?? []) as $uri => $label) {
                                $entry = ['uri' => (string) $uri];
                                if (null !== $label && '' !== (string) $label) {
                                    $entry['label'] = (string) $label;
                                }
                                $entries[] = $entry;
                            }
                            return $entries;
                        }
                        foreach (($vocab->listTerms() ?? []) as $term) {
                            $entries[] = ['value' => (string) $term];
                        }
                        return $entries;
                    }
                );
            },
            // TASK-039: the reasons to reject and to discard, each from its own
            // term CustomVocab, read by the id in its setting. Soft dependency:
            // without a usable vocabulary the reason is free text.
            Service\Workflow\ReasonVocabularies::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $reader = new Service\Governance\CustomVocabTerms($container->get('Omeka\ApiManager'));
                $vocab = static fn (string $key) => new Service\Governance\VocabEntries(
                    Service\GovernanceSettings::parseId($settings->get($key)),
                    $reader
                );
                return new Service\Workflow\ReasonVocabularies(
                    $vocab(Service\GovernanceSettings::REJECT_REASON_VOCAB_ID),
                    $vocab(Service\GovernanceSettings::DISCARD_REASON_VOCAB_ID)
                );
            },
            // Vocabulario de organismos editores (dcterms:publisher, RF-015). El
            // módulo no lo crea: es un CustomVocab que el admin da de alta y apunta
            // por id desde el setting, igual que el resto de vocabularios blandos.
            'OERManager\Service\Governance\VocabEntries\Publisher' => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $api = $container->get('Omeka\ApiManager');
                return new Service\Governance\VocabEntries(
                    Service\GovernanceSettings::parseId(
                        $settings->get(Service\GovernanceSettings::PUBLISHER_VOCAB_ID)
                    ),
                    static function (int $id) use ($api): array {
                        $vocab = $api->read('custom_vocabs', $id)->getContent();
                        $entries = [];
                        if ('uri' === $vocab->type()) {
                            foreach (($vocab->listUriLabels() ?? []) as $uri => $label) {
                                $entry = ['uri' => (string) $uri];
                                if (null !== $label && '' !== (string) $label) {
                                    $entry['label'] = (string) $label;
                                }
                                $entries[] = $entry;
                            }
                            return $entries;
                        }
                        foreach (($vocab->listTerms() ?? []) as $term) {
                            $entries[] = ['value' => (string) $term];
                        }
                        return $entries;
                    }
                );
            },
            // Currículo con el modelo cur: (TASK-064, ADR-0022): conjunto del
            // currículo, retirados y clases, resueltos una vez por petición.
            Service\Curriculum\CurriculumTerms::class => function ($container) {
                return new Service\Curriculum\ApiCurriculumTerms(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings')
                );
            },
            // Re-catalogador (TASK-004, RF-004/RF-005).
            Service\CurriculumSearch::class => function ($container) {
                return new Service\CurriculumSearch(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings'),
                    $container->get(Service\Curriculum\CurriculumTerms::class)
                );
            },
            Service\Curation\CurationWriter::class => function ($container) {
                return new Service\Curation\CurationWriter($container->get('Omeka\ApiManager'));
            },
            Service\RecatalogService::class => function ($container) {
                return new Service\RecatalogService(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\Settings'),
                    $container->get(Service\Curation\CurationWriter::class),
                    $container->get(Service\Curriculum\CurriculumTerms::class)
                );
            },
            // «Cola (N)» (TASK-067): two COUNT-only searches.
            Service\Workflow\QueueCounter::class => function ($container) {
                return new Service\Workflow\QueueCounter(
                    $container->get(Service\MasterViewQuery::class),
                    $container->get('Omeka\ApiManager')
                );
            },
            Service\Workflow\WorkflowService::class => function ($container) {
                // TASK-067: the status value is annotated (who and when, ADR-0002)
                // with the re-cataloguer's writer, and written by its targeted commit.
                return new Service\Workflow\WorkflowService(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Curation\CurationWriter::class),
                    $container->get(Service\Workflow\StatusGuard::class)
                );
            },
            // Gobernanza (TASK-028 slice 3b, RF-015): lee y escribe las cinco
            // properties de gobernanza por el mismo CurationWriter que el
            // re-catalogador, con los dos VocabEntries blandos (licencia,
            // editor) ya registrados más arriba.
            Service\GovernanceService::class => function ($container) {
                return new Service\GovernanceService(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Curation\CurationWriter::class),
                    $container->get('OERManager\Service\Governance\VocabEntries\Licence'),
                    $container->get('OERManager\Service\Governance\VocabEntries\Publisher'),
                    $container->get('Omeka\Settings')
                );
            },
            // Deshacer por ámbito (TASK-028 rebanada 3b): el ledger compartido
            // (ADR-0020) mezcla eventos de re-catalogación y de gobernanza; el
            // router lee el ámbito del propio evento y delega en el servicio
            // que sabe revertirlo.
            Service\Curation\UndoRouter::class => function ($container) {
                return new Service\Curation\UndoRouter(
                    $container->get(Service\RecatalogService::class),
                    $container->get(Service\GovernanceService::class)
                );
            },
            // Lote de gobernanza (TASK-028 slice 4): conjunto objetivo y planes.
            Service\Governance\BatchSelection::class => function ($container) {
                return new Service\Governance\BatchSelection(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\MasterViewQuery::class),
                    $container->get(Service\IntegrityChecker::class),
                    // TASK-070: a batch from «En cola» acts on the queue only.
                    $container->get(Service\IdConstraintResolver::class)
                );
            },
            // Dueño, clase y estado del Job de lote, leídos de la entidad: la
            // API de jobs no deja leerlos a editor/reviewer en Omeka 4.2.
            Service\Governance\BatchJobLookup::class => function ($container) {
                return new Service\Governance\BatchJobLookup($container->get('Omeka\EntityManager'));
            },
            Service\Governance\BatchPlanStore::class => function () {
                return new Service\Governance\BatchPlanStore(
                    sys_get_temp_dir() . '/oer-manager-batch-plans'
                );
            },

            // --- Catalogación IA-assistida (TASK-010, 4b) ---
            // Transporte HTTP del LLM (envuelve Laminas\Http\Client; sin SSRF).
            Service\Llm\HttpTransportInterface::class => function () {
                return new Service\Llm\LaminasHttpTransport();
            },
            // Cliente LLM activo según el proveedor configurado (ADR-0008).
            Service\Llm\LlmClientInterface::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $transport = $container->get(Service\Llm\HttpTransportInterface::class);
                $config = [
                    'api_key' => (string) $settings->get(Service\Llm\LlmSettings::API_KEY, ''),
                    'model' => (string) $settings->get(Service\Llm\LlmSettings::MODEL, ''),
                    'base_url' => (string) $settings->get(Service\Llm\LlmSettings::BASE_URL, ''),
                ];
                $provider = (string) $settings->get(
                    Service\Llm\LlmSettings::PROVIDER,
                    Service\Llm\LlmSettings::PROVIDER_ANTHROPIC
                );
                if (Service\Llm\LlmSettings::PROVIDER_OPENAI === $provider) {
                    return new Service\Llm\OpenAiCompatibleClient($transport, $config);
                }
                return new Service\Llm\AnthropicClient($transport, $config);
            },
            // Resolutor de términos (enumera candidatos acotados, adapta CurriculumSearch).
            Service\Ai\TermResolverInterface::class => function ($container) {
                return new Service\Ai\CurriculumTermResolver($container->get(Service\CurriculumSearch::class));
            },
            // Fuente de medios locales del item (sin URLs remotas).
            Service\Content\MediaSourceInterface::class => function ($container) {
                return new Service\Content\OmekaMediaSource(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\File\Store')
                );
            },
            // Extractor de contenido con el tope de tokens configurado (≈ chars/4).
            Service\Content\ContentExtractor::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $cap = (int) $settings->get(
                    Service\Llm\LlmSettings::CONTENT_TOKEN_CAP,
                    Service\Llm\LlmSettings::DEFAULT_CONTENT_TOKEN_CAP
                );
                return new Service\Content\ContentExtractor(['max_total_chars' => max(2000, $cap * 4)]);
            },
            // Perfil de inferencia compartido (paridad entre proveedores): el mismo
            // max_tokens + temperature en TODOS los pasos y por ambos adaptadores;
            // temperatura vacía = no enviar (default del proveedor).
            Service\Ai\CurricularClassifier::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\CurricularClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE)),
                    // TASK-062: Jev elige saberes y criterios solo si está activado (off por defecto).
                    $settings->get(Service\Llm\LlmSettings::DECISION_ENABLED)
                        ? new Service\Ai\JevLeafSelector(
                            new Service\Llm\OpenRouterDecisionClient(
                                $container->get(Service\Llm\HttpTransportInterface::class),
                                [
                                    'api_key' => (string) $settings->get(Service\Llm\LlmSettings::API_KEY, ''),
                                    'model' => (string) ($settings->get(Service\Llm\LlmSettings::DECISION_MODEL)
                                        ?: Service\Llm\LlmSettings::DEFAULT_DECISION_MODEL),
                                    'base_url' => (string) $settings->get(Service\Llm\LlmSettings::BASE_URL, ''),
                                ]
                            ),
                            $container->get(Service\Ai\PromptBuilder::class),
                            Service\Llm\LlmSettings::parseDecisionThreshold(
                                $settings->get(Service\Llm\LlmSettings::DECISION_THRESHOLD)
                            ),
                            200,
                            Service\Llm\LlmSettings::decisionCaps(
                                $settings->get(Service\Llm\LlmSettings::DECISION_MAX_TEACHES),
                                $settings->get(Service\Llm\LlmSettings::DECISION_MAX_ASSESSES)
                            )
                        )
                        : null
                );
            },
            Service\Ai\TagClassifier::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\TagClassifier(
                    $container->get(Service\Llm\LlmClientInterface::class),
                    $container->get(Service\Ai\TermResolverInterface::class),
                    $container->get(Service\Ai\PromptBuilder::class),
                    $container->get(Service\Ai\ResponseParser::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE))
                );
            },
            // Cliente LLM de extracción (barato, vision-capable; ADR-0011). Reusa
            // el mismo proveedor/endpoint/clave/transporte que el clasificador, con
            // EXTRACTION_MODEL (si está vacío, cae a MODEL). Clave de servicio propia:
            // convive con el cliente del clasificador (LlmClientInterface).
            'OERManager\Llm\ExtractionClient' => function ($container) {
                $settings = $container->get('Omeka\Settings');
                $transport = $container->get(Service\Llm\HttpTransportInterface::class);
                $model = (string) $settings->get(Service\Llm\LlmSettings::EXTRACTION_MODEL, '');
                if ('' === $model) {
                    $model = (string) $settings->get(Service\Llm\LlmSettings::MODEL, '');
                }
                $config = [
                    'api_key' => (string) $settings->get(Service\Llm\LlmSettings::API_KEY, ''),
                    'model' => $model,
                    'base_url' => (string) $settings->get(Service\Llm\LlmSettings::BASE_URL, ''),
                ];
                $provider = (string) $settings->get(
                    Service\Llm\LlmSettings::PROVIDER,
                    Service\Llm\LlmSettings::PROVIDER_ANTHROPIC
                );
                if (Service\Llm\LlmSettings::PROVIDER_OPENAI === $provider) {
                    return new Service\Llm\OpenAiCompatibleClient($transport, $config);
                }
                return new Service\Llm\AnthropicClient($transport, $config);
            },
            // Destilador fiel (ADR-0011): ficha del recurso con el modelo de extracción.
            Service\Ai\ContextDistiller::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Ai\ContextDistiller(
                    $container->get('OERManager\Llm\ExtractionClient'),
                    $container->get(Service\Ai\PromptBuilder::class),
                    Service\Llm\LlmSettings::parseMaxTokens($settings->get(Service\Llm\LlmSettings::MAX_TOKENS)),
                    Service\Llm\LlmSettings::parseTemperature($settings->get(Service\Llm\LlmSettings::TEMPERATURE))
                );
            },
            // Extractor de visión (ADR-0011): top-N imágenes + rescate de PDF escaneado
            // con el modelo de extracción. Apagado por defecto (VISION_ENABLED off):
            // egress de binarios a un tercero. El gating por capacidad del proveedor lo
            // resuelve el propio extractor (supportsImages()/supportsPdf()).
            Service\Content\MediaVisionExtractor::class => function ($container) {
                $settings = $container->get('Omeka\Settings');
                return new Service\Content\MediaVisionExtractor(
                    $container->get('OERManager\Llm\ExtractionClient'),
                    $container->get(Service\Ai\PromptBuilder::class),
                    (bool) $settings->get(Service\Llm\LlmSettings::VISION_ENABLED, false),
                    (int) $settings->get(
                        Service\Llm\LlmSettings::VISION_MAX_IMAGES,
                        Service\Llm\LlmSettings::DEFAULT_VISION_MAX_IMAGES
                    ),
                    maxTokens: Service\Llm\LlmSettings::parseMaxTokens(
                        $settings->get(Service\Llm\LlmSettings::MAX_TOKENS)
                    ),
                    temperature: Service\Llm\LlmSettings::parseTemperature(
                        $settings->get(Service\Llm\LlmSettings::TEMPERATURE)
                    ),
                    // Tope de envío del BINARIO del PDF (camino de respaldo): solo
                    // gobierna el bloque `document` nativo, que ya casi no se usa
                    // porque el PDF viaja rasterizado (TASK-026).
                    maxPdfBytes: Service\Llm\LlmSettings::parseVisionMaxPdfBytes(
                        $settings->get(Service\Llm\LlmSettings::VISION_MAX_PDF_BYTES)
                    ),
                    rasterizer: $container->get(Service\Content\PdfRasterizerInterface::class)
                );
            },
            // Rasterizador de PDF (TASK-026): convierte las primeras páginas en JPEG
            // con el mismo Imagick que usa Omeka para las derivadas. Sin la extensión
            // devuelve vacío y la visión cae al camino nativo del proveedor.
            Service\Content\PdfRasterizerInterface::class => function () {
                return new Service\Content\ImagickPdfRasterizer();
            },
            Service\Ai\AiCataloguer::class => function ($container) {
                return new Service\Ai\AiCataloguer(
                    $container->get(Service\Content\ContentExtractor::class),
                    $container->get(Service\Content\MediaVisionExtractor::class),
                    $container->get(Service\Ai\ContextDistiller::class),
                    $container->get(Service\Ai\CurricularClassifier::class),
                    $container->get(Service\Ai\TagClassifier::class)
                );
            },
            // Ensambla itemId → payload del navegador; lo reutiliza el AiProposeJob
            // en 2º plano (TASK-020).
            Service\Ai\ProposeRunner::class => function ($container) {
                return new Service\Ai\ProposeRunner(
                    $container->get('Omeka\ApiManager'),
                    $container->get(Service\Ai\AiCataloguer::class),
                    $container->get(Service\Content\MediaSourceInterface::class)
                );
            },
            // Canal de estado/resultado del propose asíncrono (fichero privado, TASK-020).
            Service\Ai\ProposalStore::class => function ($container) {
                return new Service\Ai\ProposalStore(sys_get_temp_dir() . '/oer-manager-proposals');
            },
            // TASK-011: the AI proposal stored in the REA (private curation:data).
            Service\Ai\StoredProposalWriter::class => function ($container) {
                $entityManager = $container->get('Omeka\EntityManager');
                $api = $container->get('Omeka\ApiManager');
                $propertyId = null;
                $resolved = false;
                return new Service\Ai\StoredProposalWriter(
                    function () use ($entityManager, $api, &$propertyId, &$resolved) {
                        if (!$resolved) {
                            $resolved = true;
                            $found = $api->search('properties', ['term' => Service\Ai\StoredProposal::TERM])
                                ->getContent();
                            $propertyId = $found ? (int) $found[0]->id() : null;
                        }
                        return null === $propertyId
                            ? null
                            : $entityManager->getReference(\Omeka\Entity\Property::class, $propertyId);
                    },
                    static fn () => new \Omeka\Entity\Value()
                );
            },
            Service\Ai\StoredProposals::class => function ($container) {
                return new Service\Ai\StoredProposals(
                    $container->get('Omeka\EntityManager'),
                    $container->get(Service\Ai\StoredProposalWriter::class)
                );
            },
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'oer-manager' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/oer-manager[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                    ],
                    'oer-manager-stats' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/oer-manager/stats[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\StatsController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                    ],
                    'oer-manager-batch' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/oer-manager/batch[/:action]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'OERManager\Controller\Admin',
                                'controller' => Controller\Admin\GovernanceBatchController::class,
                                'action' => 'form',
                            ],
                        ],
                        'may_terminate' => true,
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'OER Manager', // @translate
                'route' => 'admin/oer-manager',
                'resource' => Controller\Admin\IndexController::class,
                // TASK-067: curators only (a proposer would land on a 403).
                'privilege' => 'index',
                'pages' => [
                    // «Cola (N)» (ADR-0023 §3): Module::labelQueue() writes N
                    // before rendering. TASK-070: the master view's «En cola»
                    // filter, oldest catalogue entry first.
                    [
                        'id' => 'oer-queue',
                        'label' => 'Cola', // @translate
                        'route' => 'admin/oer-manager',
                        'query' => ['status' => 'queue', 'sort_by' => 'created', 'sort_order' => 'asc'],
                        'resource' => Controller\Admin\IndexController::class,
                        'privilege' => 'index',
                    ],
                    // TASK-029: la configuración deja el listado de Módulos y
                    // cuelga de aquí. `privilege` hace que la entrada solo se
                    // pinte a quien puede entrar (global_admin desde TASK-067).
                    [
                        'label' => 'Configuración', // @translate
                        'route' => 'admin/oer-manager',
                        'action' => 'config',
                        'resource' => Controller\Admin\IndexController::class,
                        'privilege' => 'config',
                    ],
                    [
                        'label' => 'Estadísticas', // @translate
                        'route' => 'admin/oer-manager-stats',
                        'resource' => Controller\Admin\StatsController::class,
                        'privilege' => 'index',
                    ],
                ],
            ],
            // «Mis propuestas» (TASK-067): the proposer's own entry.
            [
                'label' => 'Mis propuestas', // @translate
                'route' => 'admin/oer-manager',
                'action' => 'my-proposals',
                'resource' => Controller\Admin\IndexController::class,
                'privilege' => 'my-proposals',
            ],
        ],
    ],
    // Strings of `Omeka.jsTranslate()` that go through the translator
    // (NFR-005). TASK-064 slice 4 registers the knowledge/criteria tree's;
    // `// @translate` puts them in the template for the catalogues.
    'js_translate_strings' => [
        'Elige primero la asignatura (materia en curso) para ver sus saberes y criterios.', // @translate
        'La asignatura elegida no es una materia en curso del currículo.', // @translate
        'Alguna asignatura elegida no es una materia en curso del currículo y no tiene árbol.', // @translate
        'Sin términos vigentes en esta asignatura.', // @translate
        'No se pudo cargar el árbol del currículo.', // @translate
        'Cargando…', // @translate
        'Desplegar', // @translate
        'seleccionados', // @translate
        'retirado', // @translate
        'fuera del árbol', // @translate
        'Término retirado del currículo. Sustituto:', // @translate
        'Término retirado del currículo sin sustituto.', // @translate
        'No está en el árbol de la asignatura elegida.', // @translate
        // Text filter of each tree (owner decision 2026-11-17).
        'Filtrar:', // @translate
        'Ningún término coincide con el filtro.', // @translate
        '1 coincidencia', // @translate
        'coincidencias', // @translate
        // TASK-065: history timeline and the labels the client now translates.
        'término retirado (#%s)', // @translate
        'Ver 5 más', // @translate
        'Deshecho', // @translate
        'vaciada', // @translate
        'Curso', // @translate
        'Saberes básicos', // @translate
        'Criterios de evaluación', // @translate
        'Materia', // @translate
        'Eje temático', // @translate
        'Licencia', // @translate
        'Autoría', // @translate
        'Editor', // @translate
        'Titular de derechos', // @translate
        'Fuente', // @translate
        // TASK-065 part B: the re-cataloguer as a tree.
        'Cursos', // @translate
        'Añadir curso', // @translate
        'Añadir materia de', // @translate
        'Curso añadido:', // @translate
        'Materia añadida:', // @translate
        'Quitar curso', // @translate
        'Quitar materia', // @translate
        'Quitar', // @translate
        'Quitado:', // @translate
        '¿Quitar', // @translate
        'y sus', // @translate
        'términos?', // @translate
        'materia', // @translate
        'materias', // @translate
        'saber', // @translate
        'saberes', // @translate
        'criterio', // @translate
        'criterios', // @translate
        'Sin encajar', // @translate
        'No cuelgan de un curso o una materia elegidos. Quítalos o añade lo que les falta.', // @translate
        'Añadir su curso', // @translate
        'Añade un curso para empezar.', // @translate
        'IA', // @translate
        'Sustituto:', // @translate
        '1 resultado', // @translate
        'resultados', // @translate
        'inválido:', // @translate
        // TASK-065 B8: master-detail editor.
        'Anclaje curricular', // @translate
        'Estructura del anclaje', // @translate
        // TASK-065 B8 addendum: «Explorar cursos», «Ver materias», twisty note.
        'Explorar cursos', // @translate
        'Ver materias', // @translate
        'Ver materias de', // @translate
        'Añadido', // @translate
        'No se pudo cargar la lista.', // @translate
        'sub-saber', // @translate
        'sub-saberes', // @translate
        'sub-criterio', // @translate
        'sub-criterios', // @translate
        'elegido', // @translate
        'Sin resultados', // @translate
        'La selección cambió: revisa los cambios de nuevo.', // @translate
        'Añadir curso…', // @translate
        'propuestas de la IA', // @translate
        'Añadir', // @translate
        'Añade una materia a un curso para elegir sus saberes y criterios.', // @translate
        'Añade un curso y una materia para elegir sus saberes y criterios.', // @translate
        'Términos de la materia', // @translate
        'Filtrar', // @translate
        'Código o texto…', // @translate
        'elegidos', // @translate
        '1 cambio sin confirmar', // @translate
        'cambios sin confirmar', // @translate
        'Sin cambios', // @translate
        'Revisar cambios', // @translate
        'Revisar los cambios', // @translate
        'Nada se escribe en el REA hasta que confirmes.', // @translate
        'Seguir editando', // @translate
        'Resumen', // @translate
        'Se añaden', // @translate
        'Se quitan', // @translate
        'Propuestos por la IA', // @translate
        'Se guarda quién y cuándo, y podrás deshacerlo desde el historial.', // @translate
        'Confirmar re-catalogación', // @translate
        'Detener la propuesta', // @translate
        'Proponer con IA', // @translate
        // TASK-035: refusals the re-cataloguer shows from the server's `message`.
        ...array_values(Service\Workflow\RecatalogRule::MESSAGES),
        Controller\Admin\IndexController::NOT_FOUND_MESSAGE,
        Controller\Admin\IndexController::GOVERNANCE_UNDO_MESSAGE,
        // TASK-070: «Siguiente» after a workflow action, and the row's new state.
        'Siguiente', // @translate
        'Recargar la lista', // @translate
        'Publicado', // @translate
        'Rechazado', // @translate
        'Descartado', // @translate
        'Propuesto', // @translate
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    // Vista maestra (TASK-003/028): tipos propios bajo la clave `oer_items`,
    // que es independiente de la del browse nativo de items.
    'column_types' => [
        'invokables' => [
            'oerAlignmentStatus' => ColumnType\AlignmentStatus::class,
            'oerIsPublic' => ColumnType\IsPublic::class,
            'oerModified' => ColumnType\Modified::class,
            'oerId' => ColumnType\Id::class,
            'oerResourceTemplate' => ColumnType\ResourceTemplate::class,
            'oerCurricular' => ColumnType\Curricular::class,
            // Genérico: ya no se ofrece en el selector, pero sigue registrado
            // porque hay selecciones guardadas que lo usan (TASK-042).
            'oerGovernanceValue' => ColumnType\GovernanceValue::class,
            Service\Governance\GovernanceColumns::LICENCE => ColumnType\Licence::class,
            Service\Governance\GovernanceColumns::RESOURCE_TYPE => ColumnType\ResourceType::class,
        ],
        'factories' => [
            // Value necesita FormElementManager y ApiManager, igual que el del core.
            'oerValue' => function ($container) {
                return new ColumnType\Value(
                    $container->get('FormElementManager'),
                    $container->get('Omeka\ApiManager')
                );
            },
            'oerIntegrity' => function ($container) {
                return new ColumnType\Integrity(
                    $container->get(Service\IntegrityChecker::class)
                );
            },
        ],
    ],
    // Reequilibrio de ADR-0013 (TASK-028 rebanada 2): la tabla pasa de mostrar
    // el anclaje —que está al 100 %— a mostrar la gobernanza, que está vacía.
    // Ocho columnas contando el Título, que lo pinta la plantilla: es el tope
    // que TASK-027 §3 fijó para no forzar scroll horizontal en el admin.
    //
    // Los oerValue de lrmi:educationalLevel y schema:about salen (los fusiona
    // oerCurricular) y el de dcterms:rights también (lo sustituye la celda con
    // estado vacío). Siguen REGISTRADOS: un curador puede reactivarlos desde la
    // configuración nativa de columnas.
    //
    // OJO: column_defaults solo aplica a quien NO haya guardado su propia
    // selección. Quien la guardó tras la rebanada 1 conserva las columnas
    // viejas hasta que la reajuste.
    'column_defaults' => [
        'admin' => [
            'oer_items' => [
                // `oerAlignmentStatus` sale del juego por defecto: su señal la
                // absorbe `oerCurricular`, que ahora rotula «Anclaje curricular»
                // y funde el estado con los pares materia→curso. Sigue
                // REGISTRADA y su `statusFor()` alimenta el filtro de tres
                // estados, que no cambia.
                ['type' => 'oerIntegrity'],
                ['type' => 'oerCurricular'],
                // TASK-042: property y texto de vacío van con el tipo
                // (GovernanceColumns), no aquí, para que la columna sea la
                // misma que un usuario añade desde su selector.
                ['type' => Service\Governance\GovernanceColumns::RESOURCE_TYPE],
                ['type' => Service\Governance\GovernanceColumns::LICENCE],
                ['type' => 'oerIsPublic'],
                ['type' => 'oerModified'],
            ],
        ],
    ],
    'browse_defaults' => [
        'admin' => [
            'oer_items' => ['sort_by' => 'modified', 'sort_order' => 'desc'],
        ],
    ],
    // TASK-070: «Cola (N)» sorts by catalogue entry date, which no column
    // offers; without this the sort selector would show another option.
    'sort_defaults' => [
        'admin' => [
            'oer_items' => [
                'created' => 'Fecha de alta', // @translate
            ],
        ],
    ],
];
