<?php

namespace OERManager\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\ColumnType\AlignmentStatus;
use OERManager\Service\MasterViewQuery;
use Omeka\Permissions\Exception\PermissionDeniedException;

/**
 * Vista maestra del catálogo REA (TASK-003, ADR-0005). v1: lectura +
 * curación de visibilidad; el resto (re-catalogador, panel configurable,
 * integridad completa, matriz rol×acción) llega en fases posteriores.
 */
class IndexController extends AbstractActionController
{
    private MasterViewQuery $masterViewQuery;

    public function __construct(MasterViewQuery $masterViewQuery)
    {
        $this->masterViewQuery = $masterViewQuery;
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
}
