<?php

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\ColumnType\AlignmentStatus;
use OERManager\Service\CurriculumSearch;
use OERManager\Service\MasterViewQuery;
use OERManager\Service\RecatalogService;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * Vista maestra del catálogo REA (TASK-003, ADR-0005) y re-catalogador
 * curricular/tags (TASK-004, RF-004/RF-005). El re-catalogador sigue el patrón
 * obligatorio preview + confirmación + auditoría (skill recatalogador).
 */
class IndexController extends AbstractActionController
{
    private MasterViewQuery $masterViewQuery;
    private CurriculumSearch $curriculumSearch;
    private RecatalogService $recatalogService;

    public function __construct(
        MasterViewQuery $masterViewQuery,
        CurriculumSearch $curriculumSearch,
        RecatalogService $recatalogService
    ) {
        $this->masterViewQuery = $masterViewQuery;
        $this->curriculumSearch = $curriculumSearch;
        $this->recatalogService = $recatalogService;
    }

    public function indexAction()
    {
        $query = $this->params()->fromQuery();
        $searchParams = $this->masterViewQuery->buildSearchParams($query);
        $response = $this->api()->search('items', $searchParams);
        $items = $response->getContent();

        if (AlignmentStatus::PARTIAL === ($query['alignment'] ?? '')) {
            $items = $this->masterViewQuery->filterPartialAlignment($items);
        }

        $this->paginator($response->getTotalResults());

        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/index/index');
        $view->setVariable('items', $items);
        $view->setVariable('query', $query);
        return $view;
    }

    /**
     * Cambia is_public individual o en lote (ADR-0005 §7). Reusa el permiso
     * nativo de edición del item: la API deniega por sí misma a quien no
     * pueda editar (NFR-003 en v1; matriz rol×acción propia → PEND-007).
     */
    public function setVisibilityAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/oer-manager');
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
        $results = 'dcterms:relation' === $dimension
            ? $this->curriculumSearch->searchAxes($text)
            : $this->curriculumSearch->searchDimension($dimension, $text);

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

        $id = (int) $this->params()->fromPost('id');
        $alignment = $this->collectAlignment();
        $identity = $this->identity();
        $contributor = $identity ? $identity->getName() : 'unknown';

        try {
            $result = $this->recatalogService->apply($id, $alignment, $contributor);
        } catch (PermissionDeniedException $e) {
            return new JsonModel(['updated' => false, 'error' => 'denied']);
        } catch (\Exception $e) {
            return new JsonModel(['updated' => false, 'error' => $e->getMessage()]);
        }

        return new JsonModel($result);
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
}
