<?php

namespace OERManager\Controller\Admin;

use Laminas\Form\FormElementManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\ColumnType\AlignmentStatus;
use OERManager\Form\ConfigForm;
use OERManager\Service\Ai\AiCataloguer;
use OERManager\Service\Ai\EvaluationScorer;
use OERManager\Service\Ai\ProposalStore;
use OERManager\Service\ComputedFilter;
use OERManager\Service\ComputedPredicates;
use OERManager\Service\ConfigPayload;
use OERManager\Service\Content\MediaSourceInterface;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\IntegrityChecker;
use OERManager\Service\ItemPanelData;
use OERManager\Service\Llm\LlmSettings;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;
use OERManager\Service\ResourceTypeVocab;
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
    private ComputedFilter $computedFilter;
    private ResourceTypeVocab $resourceTypeVocab;
    private FormElementManager $formElementManager;
    private IntegrityChecker $integrityChecker;
    private ItemPanelData $itemPanelData;

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
        ProposalStore $proposalStore,
        ComputedFilter $computedFilter,
        ResourceTypeVocab $resourceTypeVocab,
        FormElementManager $formElementManager,
        IntegrityChecker $integrityChecker,
        ItemPanelData $itemPanelData
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
        $this->computedFilter = $computedFilter;
        $this->resourceTypeVocab = $resourceTypeVocab;
        $this->formElementManager = $formElementManager;
        $this->integrityChecker = $integrityChecker;
        $this->itemPanelData = $itemPanelData;
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
        // Orden por defecto de `oer_items` (TASK-028, D5): escribe sort_by y
        // sort_order en la request si no vienen, así que ha de ir ANTES de leer
        // la query. Es el patrón del core (cfr. Admin\ItemController::browseAction).
        $this->browse()->setDefaults('oer_items');
        $query = $this->params()->fromQuery();
        $searchParams = $this->masterViewQuery->buildSearchParams($query);

        // Una sola rama computada (TASK-028 rebanada 2). Antes había un if/else
        // escrito a medida del filtro «parcial»; con un segundo filtro computado
        // ese bloque —búsqueda con tope, ComputedFilter, paginator, isTruncated—
        // se habría duplicado entero, que es cómo se propagan los defectos D4.
        $computedKeys = ComputedPredicates::activeKeys($query);

        if ([] !== $computedKeys) {
            // Las representaciones se piden de una vez, acotadas al tope, en vez
            // de resolver ids y releer cada uno: los predicados necesitan el item
            // entero, así que un read por id serían hasta HARD_CAP consultas.
            $fullParams = $searchParams;
            $fullParams['page'] = 1;
            $fullParams['per_page'] = ComputedFilter::HARD_CAP;
            $response = $this->api()->search('items', $fullParams);

            $candidates = [];
            foreach ($response->getContent() as $candidate) {
                $candidates[(int) $candidate->id()] = $candidate;
            }

            $predicate = $this->computedPredicate($computedKeys, $candidates, $query);
            $filtered = $this->computedFilter->apply(
                array_keys($candidates),
                $predicate,
                (int) ($query['page'] ?? 1),
                (int) $this->settings->get('pagination_per_page', 25)
            );
            $items = array_map(static fn (int $id) => $candidates[$id], $filtered['ids']);
            $this->paginator($filtered['total']);
            // `$filtered['truncated']` está aquí por CONTRATO de ComputedFilter (su
            // API lo expone, así que se respeta), pero con $fullParams['per_page'] =
            // HARD_CAP nunca se dispara en esta llamada: ComputedFilter aplica ese
            // mismo tope internamente, así que su propio truncado no puede activarse
            // sobre una lista que ya venía acotada a HARD_CAP. Quien de verdad detecta
            // que se ha recortado el catálogo es la comparación siguiente: si la
            // búsqueda base ya devolvía más de HARD_CAP candidatos antes de aplicar el
            // predicado computado, el aviso de "resultado acotado" debe mostrarse.
            $isTruncated = $filtered['truncated']
                || $response->getTotalResults() > ComputedFilter::HARD_CAP;
        } else {
            $response = $this->api()->search('items', $searchParams);
            $items = $response->getContent();
            $this->paginator($response->getTotalResults());
            $isTruncated = false;
        }

        // Estado de integridad de las filas visibles, para el riel de ADR-0014.
        // Con la comprobación de enlaces apagada (D-7) esto son lecturas en
        // memoria; la columna lo recalcula por su cuenta porque el mecanismo
        // nativo de columnas no ofrece un canal para pasárselo.
        $integrityStatuses = [];
        foreach ($items as $item) {
            $integrityStatuses[(int) $item->id()] = $this->integrityChecker
                ->check($item, false)
                ->getStatus();
        }

        // CSRF: token por sesión, consumido por setVisibilityAction via JS.
        $session = new SessionContainer('OERManager');
        if (empty($session->visibilityCsrfToken)) {
            $session->visibilityCsrfToken = bin2hex(random_bytes(16));
        }

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/index');
        $view->setVariable('items', $items);
        $view->setVariable('query', $query);
        $view->setVariable('isTruncated', $isTruncated);
        $view->setVariable('integrityStatuses', $integrityStatuses);
        // D1: si el vocabulario degrada, la plantilla cae a texto libre.
        $view->setVariable('resourceTypeValues', $this->resourceTypeVocab->values());
        // CSRF de visibilidad (QA TASK-003) y de la confirmación del
        // re-catalogador (TASK-004, I4): mecanismos independientes.
        $view->setVariable('csrfToken', $session->visibilityCsrfToken);
        $view->setVariable('recatalogCsrf', $this->csrfValidator()->getHash());
        // Pre-relleno IA (TASK-010): solo si la conexión LLM está activa y con modelo.
        $view->setVariable('aiEnabled', $this->aiEnabled());
        return $view;
    }

    /**
     * Compone los predicados computados activos en uno solo (AND).
     *
     * @param list<string> $keys
     * @param array<int,ItemRepresentation> $candidates
     * @return callable(int):bool
     */
    private function computedPredicate(array $keys, array $candidates, array $query): callable
    {
        $predicates = [];

        foreach ($keys as $key) {
            if (ComputedPredicates::ALIGNMENT_PARTIAL === $key) {
                $predicates[] = static fn (int $id): bool => AlignmentStatus::PARTIAL
                    === AlignmentStatus::statusFor($candidates[$id]);
                continue;
            }
            if (ComputedPredicates::INTEGRITY === $key) {
                $wanted = (string) $query['integrity'];
                $checker = $this->integrityChecker;
                $predicates[] = static fn (int $id): bool => $wanted
                    === $checker->check($candidates[$id], false)->getStatus();
            }
        }

        return static function (int $id) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($id)) {
                    return false;
                }
            }
            return true;
        };
    }

    /**
     * Búsqueda avanzada de la vista maestra (TASK-028, D-5): los filtros que no
     * caben en la barra rápida. Solo pinta el formulario; el filtrado lo hace
     * indexAction con los mismos parámetros GET.
     */
    public function searchAction()
    {
        $query = $this->params()->fromQuery();

        // Los filtros curriculares llegan como id: se resuelve su título para que
        // el chip precargado diga qué se está filtrando y no un número.
        $titles = [];
        foreach (['stage', 'subject', 'project', 'axis'] as $key) {
            $id = (int) ($query[$key] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $titles[$key] = (string) $this->api()->read('items', $id)->getContent()->displayTitle();
            } catch (\Exception $e) {
                // El término ya no existe: el chip se queda con el id.
            }
        }

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/search');
        $view->setVariable('query', $query);
        $view->setVariable('resourceFilterTitles', $titles);
        $view->setVariable('resourceTypeValues', $this->resourceTypeVocab->values());
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
     * Configuración del módulo (TASK-029). Vivía en el listado de Módulos, a la
     * que solo se llegaba por *Módulos → OER Manager → Configurar*; ahora cuelga
     * del menú lateral, junto a la vista maestra que es donde se trabaja.
     *
     * La ACL la impone `Module::onBootstrap` con el privilegio `config`, que
     * solo tienen Supervisor (site_admin) y global_admin: aquí se gobiernan la
     * clave API del LLM y los vocabularios de todo el catálogo, así que no es
     * una acción de curación.
     *
     * El CSRF lo ponía Omeka en la página de Módulos; al servir el formulario
     * por nuestra cuenta hay que ponerlo nosotros (lo añade `ConfigForm::init`).
     */
    public function configAction()
    {
        /** @var ConfigForm $form */
        $form = $this->formElementManager->get(ConfigForm::class);
        $settings = $this->settings;
        $form->setData(ConfigPayload::read(
            static fn (string $key, mixed $default = null): mixed => $settings->get($key, $default)
        ));

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                foreach (ConfigPayload::write($post) as $key => $value) {
                    $settings->set($key, $value);
                }
                $this->messenger()->addSuccess('Configuración guardada.'); // @translate
                // Redirect tras POST: recargar no reenvía el formulario, y el
                // campo de la clave API vuelve a pintarse vacío (write-only).
                return $this->redirect()->toRoute('admin/oer-manager', ['action' => 'config']);
            }
            $this->messenger()->addFormErrors($form);
        }

        $view = new ViewModel();
        $view->setVariable('form', $form);
        return $view;
    }

    /**
     * Último evento de curación del item (TASK-007, ADR-0015), para que el panel
     * sepa si hay algo que deshacer y de cuándo. Solo lectura: sin CSRF, mismo
     * criterio que el preview. La ACL la impone onBootstrap (editor+).
     */
    public function recatalogLastEventAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }

        $id = (int) $this->params()->fromPost('id');
        try {
            $event = $this->recatalogService->lastEvent($id);
        } catch (\Exception $e) {
            return new JsonModel(['event' => null]);
        }
        if (null === $event) {
            return new JsonModel(['event' => null]);
        }

        // El payload no sale al cliente: solo lo necesita el servidor al deshacer.
        return new JsonModel(['event' => [
            'when' => $event['when'],
            'contributor' => $event['contributor'],
            'summary' => $event['summary'],
        ]]);
    }

    /**
     * Panel de detalle completo (TASK-032): ficha, miniatura, medios, anclaje
     * agrupado e integridad, en una sola llamada autenticada.
     *
     * Va por el servidor por obligación, no por comodidad: la miniatura no
     * viaja en el JSON del item, `o:media` solo trae ids sin nombre ni tipo ni
     * tamaño, y el drawer cargaba con `fetch(apiUrl)` SIN autenticar — el
     * primer REA que se pusiera en privado habría dejado de abrir su panel.
     *
     * El historial NO viene aquí: es lo caro y nace plegado (drawer-history).
     *
     * Solo lectura: sin CSRF. La comprobación de enlaces va ENCENDIDA —al
     * contrario que en la tabla— porque aquí es un item a la vez.
     */
    public function drawerDetailsAction()
    {
        $item = $this->panelItem();
        if (null === $item) {
            return new JsonModel(['panel' => null, 'integrity' => null]);
        }

        $result = $this->integrityChecker->check($item, true);

        return new JsonModel([
            'panel' => $this->itemPanelData->forItem($item),
            'integrity' => [
                'status' => $result->getStatus(),
                'issues' => $result->getIssues(),
            ],
        ]);
    }

    /**
     * Historial de curación, servido aparte y bajo demanda (TASK-032, P-6).
     *
     * Es la parte cara del panel —una lectura de API por id referenciado— y
     * nace plegado, así que no se paga al abrir la fila sino al desplegarlo.
     */
    public function drawerHistoryAction()
    {
        $item = $this->panelItem();
        if (null === $item) {
            return new JsonModel(['history' => []]);
        }

        return new JsonModel(['history' => $this->recatalogService->history((int) $item->id())]);
    }

    /** Item de la petición, o null si el id no vale o no se puede leer. */
    private function panelItem()
    {
        $id = (int) $this->params()->fromQuery('id');
        if ($id <= 0) {
            return null;
        }
        try {
            return $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Deshace la última re-catalogación del item (TASK-007). Escribe, así que
     * lleva CSRF y ACL igual que el apply: deshacer no es más privilegiado que
     * hacer, pero tampoco menos (NFR-003).
     */
    public function recatalogUndoAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
        }

        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return new JsonModel(['updated' => false, 'error' => 'csrf']);
        }

        $id = (int) $this->params()->fromPost('id');
        // El curador ya confirmó que el REA cambió por otra vía y aun así quiere
        // revertir; sin esta confirmación explícita el servicio se niega.
        $force = (bool) $this->params()->fromPost('force');
        $identity = $this->identity();
        $contributor = $identity ? $identity->getName() : 'unknown';

        try {
            $result = $this->recatalogService->undo($id, $contributor, $force);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        } catch (\RuntimeException $e) {
            return new JsonModel(['updated' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->err('OERManager recatalog undo item ' . $id . ': ' . $e->getMessage());
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
