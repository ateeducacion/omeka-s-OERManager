<?php

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\ColumnType\AlignmentStatus;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Job\Dispatcher;
use Omeka\Permissions\Exception\PermissionDeniedException;
use Omeka\Settings\Settings;

/**
 * Vista maestra del catálogo REA (TASK-003, ADR-0005) y re-catalogador
 * curricular/tags (TASK-004, RF-004/RF-005). El re-catalogador sigue el patrón
 * obligatorio preview + confirmación + auditoría (skill recatalogador).
 */
class IndexController extends AbstractActionController
{
    /** Identificadores del token CSRF de la escritura del re-catalogador. */
    public const CSRF_NAME = 'oer_recatalog';
    public const CSRF_SALT = 'oermanager';

    /** Tope de items por evaluación síncrona de accuracy (acota coste de tokens). */
    private const MAX_EVALUATE_ITEMS = 50;

    private MasterViewQuery $masterViewQuery;
    private CurriculumSearch $curriculumSearch;
    private RecatalogService $recatalogService;
    private LoggerInterface $logger;
    private AiCataloguer $aiCataloguer;
    private MediaSourceInterface $mediaSource;
    private EvaluationScorer $scorer;
    private Settings $settings;
    private Dispatcher $jobDispatcher;
    private ProposalStore $proposalStore;

    public function __construct(
        MasterViewQuery $masterViewQuery,
        CurriculumSearch $curriculumSearch,
        RecatalogService $recatalogService,
        LoggerInterface $logger,
        AiCataloguer $aiCataloguer,
        MediaSourceInterface $mediaSource,
        EvaluationScorer $scorer,
        Settings $settings,
        Dispatcher $jobDispatcher,
        ProposalStore $proposalStore
    ) {
        $this->masterViewQuery = $masterViewQuery;
        $this->curriculumSearch = $curriculumSearch;
        $this->recatalogService = $recatalogService;
        $this->logger = $logger;
        $this->aiCataloguer = $aiCataloguer;
        $this->mediaSource = $mediaSource;
        $this->scorer = $scorer;
        $this->settings = $settings;
        $this->jobDispatcher = $jobDispatcher;
        $this->proposalStore = $proposalStore;
    }

    /** Validador CSRF compartido por la vista (genera) y el apply (valida). */
    private function csrfValidator(): Csrf
    {
        return new Csrf([
            'name' => self::CSRF_NAME,
            'salt' => self::CSRF_SALT,
            'timeout' => 3600,
        ]);
    }

    public function indexAction()
    {
        $query = $this->params()->fromQuery();
        $searchParams = $this->masterViewQuery->buildSearchParams($query);
        $response = $this->api()->search('items', $searchParams);
        $items = $response->getContent();
        $isPartialFilter = AlignmentStatus::PARTIAL === ($query['alignment'] ?? '');

        if ($isPartialFilter) {
            $items = $this->masterViewQuery->filterPartialAlignment($items);
        }

        $this->paginator($response->getTotalResults());

        // CSRF: token por sesión, consumido por setVisibilityAction via JS.
        $session = new SessionContainer('OERManager');
        if (empty($session->visibilityCsrfToken)) {
            $session->visibilityCsrfToken = bin2hex(random_bytes(16));
        }

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/index');
        $view->setVariable('items', $items);
        $view->setVariable('query', $query);
        $view->setVariable('isPartialFilter', $isPartialFilter);
        // CSRF de visibilidad (QA TASK-003) y de la confirmación del
        // re-catalogador (TASK-004, I4): mecanismos independientes.
        $view->setVariable('csrfToken', $session->visibilityCsrfToken);
        $view->setVariable('recatalogCsrf', $this->csrfValidator()->getHash());
        // Pre-relleno IA (TASK-010): solo si la conexión LLM está activa y con modelo.
        $view->setVariable('aiEnabled', $this->aiEnabled());
        return $view;
    }

    /**
     * Cambia is_public individual o en lote (ADR-0005 §7). Reusa el permiso
     * nativo de edición del item: la API deniega por sí misma a quien no
     * pueda editar (NFR-003 en v1; matriz rol×acción propia → TASK-004).
     */
    public function setVisibilityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }

        $token = $this->params()->fromPost('oer_visibility_csrf', '');
        $session = new SessionContainer('OERManager');
        if (
            empty($token)
            || empty($session->visibilityCsrfToken)
            || !hash_equals($session->visibilityCsrfToken, $token)
        ) {
            $this->getResponse()->setStatusCode(403);
            return new JsonModel(['error' => 'Invalid CSRF token']);
        }

        $isPublic = (bool) $this->params()->fromPost('is_public');
        $id = $this->params()->fromPost('id');
        $ids = $id ? [$id] : (array) $this->params()->fromPost('resource_ids', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $updated = [];
        $denied = [];
        foreach ($ids as $resourceId) {
            try {
                $this->api()->update('items', $resourceId, ['o:is_public' => $isPublic], [], ['isPartial' => true]);
                $updated[] = $resourceId;
            } catch (PermissionDeniedException $e) {
                $denied[] = $resourceId;
            }
        }

        return new JsonModel([
            'is_public' => $isPublic,
            'updated' => $updated,
            'denied' => $denied,
        ]);
    }

    /**
     * Autocomplete de items-término del currículo (NFR-004): búsqueda
     * incremental, nunca el árbol completo. dimension='relation' busca ejes
     * temáticos; el resto, alineamiento curricular (ADR-0006).
     */
    public function searchTermsAction()
    {
        $dimension = (string) $this->params()->fromQuery('dimension', '');
        $text = (string) $this->params()->fromQuery('q', '');
        $context = [
            'etapa' => $this->params()->fromQuery('etapa'),
            'level' => $this->params()->fromQuery('level'),
            'about' => $this->params()->fromQuery('about'),
        ];

        if ('etapa' === $dimension) {
            $results = $this->curriculumSearch->searchEtapas($text);
        } elseif ('dcterms:relation' === $dimension) {
            $results = $this->curriculumSearch->searchAxes($text);
        } else {
            $results = $this->curriculumSearch->searchDimension($dimension, $text, $context);
        }

        return new JsonModel(['results' => $results]);
    }

    /**
     * Previsualización del re-catalogador (sin escribir): diff actual→propuesto
     * por property, con destinos inválidos marcados.
     */
    public function recatalogPreviewAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }

        $id = (int) $this->params()->fromPost('id');
        $alignment = $this->collectAlignment();

        return new JsonModel(['diff' => $this->recatalogService->preview($id, $alignment)]);
    }

    /**
     * Confirmación del re-catalogador: escribe el alineamiento propuesto con
     * auditoría dcterms (ADR-0002). La ACL la impone onBootstrap (editor+); la
     * API deniega además por permiso nativo de edición del item.
     */
    public function recatalogApplyAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }

        // CSRF: escritura masiva de RDF, no fiarse solo de la ACL (NFR-003).
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['updated' => false, 'error' => 'csrf']);
        }

        $id = (int) $this->params()->fromPost('id');
        $alignment = $this->collectAlignment();
        $justifications = $this->collectJustifications();
        $identity = $this->identity();
        $contributor = $identity ? $identity->getName() : 'unknown';

        try {
            $result = $this->recatalogService->apply($id, $alignment, $contributor, $justifications);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        } catch (\RuntimeException $e) {
            // Excepción de dominio (destinos inválidos): mensaje seguro y útil.
            return new JsonModel(['updated' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            // Inesperada: registrar el detalle, no filtrarlo al cliente (I2).
            $this->logger->err('OERManager recatalog apply item ' . $id . ': ' . $e->getMessage());
            return new JsonModel(['updated' => false, 'error' => 'unexpected']);
        }

        return new JsonModel($result);
    }

    /**
     * Propuesta IA (TASK-010, ADR-0007): extrae el contenido del item, clasifica
     * con el LLM y devuelve etiquetas+ids por dimensión para PRE-RELLENAR el panel.
     * No escribe nada: la IA propone, el curador confirma (luego usa el apply de
     * 4a). ACL editor+ (onBootstrap) + CSRF; los errores del LLM no se filtran.
     */
    public function aiProposeAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        if (!$this->aiEnabled()) {
            return new JsonModel(['error' => 'disabled']);
        }
        $id = (int) $this->params()->fromPost('id');
        if ($id <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['error' => 'not_found']);
        }

        // Async (TASK-020): el propose encadena 7-9 llamadas al LLM y puede agotar
        // el timeout del proxy (504, NFR-010). Se ejecuta como Job en 2º plano; el
        // navegador sondea el estado. Aquí solo se despacha y se devuelve el jobId.
        $this->proposalStore->sweepOld(3600); // limpia huérfanos oportunistamente
        try {
            $job = $this->jobDispatcher->dispatch(\OERManager\Job\AiProposeJob::class, [
                'item' => $id,
            ]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager ai propose dispatch item ' . $id . ': ' . $e->getMessage());
            return new JsonModel(['error' => 'dispatch']);
        }

        return new JsonModel(['jobId' => (int) $job->getId()]);
    }

    /**
     * Polling del propose asíncrono (TASK-020): devuelve el estado vivo del Job.
     * Cruza el fichero de resultado con el estado nativo del Job para no colgar el
     * sondeo si el Job muriera sin escribir (p. ej. PhpCli mal configurado).
     */
    public function aiProposeStatusAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        // Control de acceso PRIMERO: la API de jobs acota por ACL (dueño/admin).
        try {
            $job = $this->api()->read('jobs', $jobId)->getContent();
        } catch (\Exception $e) {
            return new JsonModel(['error' => 'not_found']);
        }

        $native = (string) $job->status();
        $finished = in_array($native, ['completed', 'error', 'stopped'], true);

        $state = $this->proposalStore->read($jobId);
        if (null !== $state) {
            // Un `in_progress` con el Job YA terminado es un estado zombi: el Job
            // murió sin escribir su resultado (OOM, proceso matado). Antes se
            // devolvía tal cual y el navegador seguía sondeando «Analizando…»
            // hasta el techo de 12 min: otro cuelgue sin causa visible (TASK-026).
            // El Job escribe SIEMPRE su estado final antes de terminar, así que
            // terminado + in_progress solo puede significar que se murió.
            if ($finished && 'in_progress' === ($state['status'] ?? '')) {
                return new JsonModel(['status' => 'error', 'code' => 'job_died']);
            }
            return new JsonModel($state); // in_progress / completed / error / stopped
        }
        // Sin fichero: cruzar con el estado nativo para no colgar el polling.
        if (in_array($native, ['error', 'stopped'], true)) {
            return new JsonModel(['status' => 'error', 'code' => 'job_' . $native]);
        }
        return new JsonModel(['status' => 'in_progress', 'step' => 'Iniciando…', 'done' => 0, 'total' => 5]);
    }

    /**
     * Cancela un propose asíncrono en marcha (TASK-020). Dispatcher::stop solo pone
     * el estado STOPPING; el Job lo ve entre fases y aborta sin propuesta parcial.
     */
    public function aiProposeCancelAction()
    {
        if (!$this->getRequest()->isPost()) {
            return new JsonModel(['error' => 'method']);
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['error' => 'csrf']);
        }
        $jobId = (int) $this->params()->fromPost('jobId');
        if ($jobId <= 0) {
            return new JsonModel(['error' => 'id']);
        }
        try {
            $this->api()->read('jobs', $jobId); // valida propiedad por ACL
            $this->jobDispatcher->stop($jobId);
        } catch (\Exception $e) {
            return new JsonModel(['error' => 'not_found']);
        }
        return new JsonModel(['stopped' => true]);
    }

    /**
     * Evaluación de accuracy (NFR-008): ejecuta la propuesta IA sobre REAs ya
     * catalogados (verdad-terreno = su alineamiento actual) y reporta métricas por
     * dimensión. Herramienta de iteración de prompts; solo lectura (sin CSRF).
     * GET ?ids=1,2,3
     */
    public function aiEvaluateAction()
    {
        if (!$this->aiEnabled()) {
            return new JsonModel(['error' => 'disabled']);
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $this->params()->fromQuery('ids', ''))),
            static fn (int $i): bool => $i > 0
        )));
        if (!$ids) {
            return new JsonModel(['error' => 'ids']);
        }
        // Acota el coste de tokens del LLM: la evaluación es síncrona (revisión
        // adversaria). El lote grande es RF-011 (Job en segundo plano, TASK-011).
        $ids = array_slice($ids, 0, self::MAX_EVALUATE_ITEMS);

        $dimensions = ['lrmi:educationalLevel', 'schema:about', 'lrmi:teaches', 'lrmi:assesses', 'dcterms:relation'];
        $perDimension = array_fill_keys($dimensions, []);
        $perItem = [];

        foreach ($ids as $id) {
            try {
                $item = $this->api()->read('items', $id)->getContent();
            } catch (\Exception $e) {
                continue;
            }
            $truth = $this->currentAlignment($item, $dimensions);
            try {
                $proposal = $this->aiCataloguer->propose(
                    $this->itemMetadataText($item),
                    $this->mediaSource->filesFor($id),
                    $this->mediaSource->imagesFor($id)
                );
            } catch (\Exception $e) {
                continue;
            }
            $proposed = $proposal['alignment'];
            $itemScores = [];
            foreach ($dimensions as $dimension) {
                $score = $this->scorer->score($proposed[$dimension] ?? [], $truth[$dimension] ?? []);
                $perDimension[$dimension][] = $score;
                $itemScores[$dimension] = [
                    'precision' => $score['precision'],
                    'recall' => $score['recall'],
                    'f1' => $score['f1'],
                    'exact' => $score['exact'],
                ];
            }
            $perItem[$id] = $itemScores;
        }

        $summary = [];
        foreach ($dimensions as $dimension) {
            $summary[$dimension] = $this->scorer->macroAverage($perDimension[$dimension]);
        }
        return new JsonModel(['summary' => $summary, 'items' => $perItem, 'evaluated' => count($perItem)]);
    }

    /**
     * ¿La asistencia IA está activa y CONFIGURADA de forma completa? Toggle +
     * modelo, y además base URL cuando el proveedor OpenAI-compatible la exige
     * (revisión adversaria, finding #5: no mostrar el botón si la llamada va a
     * fallar siempre por falta de base URL).
     */
    private function aiEnabled(): bool
    {
        if (!$this->settings->get(LlmSettings::ENABLED)) {
            return false;
        }
        if ('' === trim((string) $this->settings->get(LlmSettings::MODEL))) {
            return false;
        }
        $provider = (string) $this->settings->get(LlmSettings::PROVIDER, LlmSettings::PROVIDER_ANTHROPIC);
        if (
            LlmSettings::PROVIDER_OPENAI === $provider
            && '' === trim((string) $this->settings->get(LlmSettings::BASE_URL))
        ) {
            return false;
        }
        return true;
    }

    /**
     * Texto de metadatos del item para la IA: título + todos los valores
     * literales (no resource values, que son el propio alineamiento), sin
     * duplicados.
     */
    private function itemMetadataText(ItemRepresentation $item): string
    {
        $parts = [];
        $title = trim((string) $item->displayTitle(''));
        if ('' !== $title) {
            $parts[] = $title;
        }
        foreach ($item->values() as $info) {
            foreach ($info['values'] as $value) {
                if ('literal' !== $value->type()) {
                    continue;
                }
                $text = trim((string) $value->value());
                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }
        return implode("\n", array_values(array_unique($parts)));
    }


    /**
     * Alineamiento curricular/tags actual del item (verdad-terreno de evaluación).
     *
     * @param string[] $dimensions
     * @return array<string,int[]>
     */
    private function currentAlignment(ItemRepresentation $item, array $dimensions): array
    {
        $out = [];
        foreach ($dimensions as $term) {
            $ids = [];
            foreach ($item->value($term, ['all' => true, 'default' => []]) as $value) {
                $resource = $value->valueResource();
                if ($resource) {
                    $ids[] = $resource->id();
                }
            }
            $out[$term] = $ids;
        }
        return $out;
    }

    /**
     * Recoge del POST solo las properties de alineamiento conocidas (ADR-0004),
     * cada una con su lista de ids de item-término.
     *
     * @return array<string,array<int|string>>
     */
    private function collectAlignment(): array
    {
        $posted = (array) $this->params()->fromPost('alignment', []);
        $alignment = [];
        foreach (RecatalogService::ALIGNMENT_TERMS as $term) {
            if (array_key_exists($term, $posted)) {
                $alignment[$term] = (array) $posted[$term];
            }
        }
        return $alignment;
    }

    /**
     * Recoge del POST la justificación IA por saber/criterio (TASK-023): mapa
     * term => {itemId => texto}, solo para lrmi:teaches/lrmi:assesses. El POST es
     * manipulable, así que se castea el id a int, se acota el texto y se descartan
     * las entradas vacías; la integridad del alineamiento la garantiza aparte
     * RecatalogService::invalidTargets().
     *
     * @return array<string,array<int,string>>
     */
    private function collectJustifications(): array
    {
        $posted = (array) $this->params()->fromPost('justification', []);
        $out = [];
        foreach (['lrmi:teaches', 'lrmi:assesses'] as $term) {
            if (!isset($posted[$term]) || !is_array($posted[$term])) {
                continue;
            }
            foreach ($posted[$term] as $id => $text) {
                $itemId = (int) $id;
                $reason = trim(mb_substr((string) $text, 0, 200));
                if ($itemId > 0 && '' !== $reason) {
                    $out[$term][$itemId] = $reason;
                }
            }
        }
        return $out;
    }
}
