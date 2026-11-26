<?php

declare(strict_types=1);

namespace OERManager\Controller\Admin;

use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\GovernanceService;
use OERManager\Service\GovernanceSettings;
use OERManager\Service\Wizard\DuplicateFinder;
use OERManager\Service\Wizard\MediaMetadataReader;
use OERManager\Service\Wizard\ReaWizardSave;
use OERManager\Service\Wizard\ReaWizardSchema;
use OERManager\Service\Wizard\UploadPolicy;
use OERManager\Service\Wizard\WizardUploadStore;
use OERManager\Service\Workflow\RecatalogRule;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Service\Workflow\WorkflowStatus;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Settings\Settings;

/**
 * TASK-077: the guided wizard to add a REA, reached from «Mis propuestas»
 * (spec `2026-11-26-task-077-rea-add-wizard-design.md`).
 *
 * `index` serves the page (`?id=` resumes a Borrador or Rechazado REA);
 * `upload` stores the file of step ① and reads its metadata; `duplicates`
 * looks for other REA with the same title or file name; `save` writes the
 * REA (ReaWizardSave). Every write is POST + CSRF and answers JSON.
 *
 * Who may resume which REA: RecatalogRule, as in the panel, narrowed to the
 * states in which a REA can be proposed (private Borrador or Rechazado). A
 * REA the user may not resume is answered exactly as a missing one.
 */
class ReaWizardController extends AbstractActionController
{
    public const ROUTE = 'admin/oer-manager-wizard';
    public const CSRF_NAME = 'oer_wizard';

    /** Every action of this controller; ReaAcl grants them to both REA roles. */
    public const PRIVILEGES = ['index', 'upload', 'duplicates', 'save'];

    /** The wizard's whole state is small: a few fields and some hundred ids. */
    private const MAX_STATE_BYTES = 262144;

    public const NOT_FOUND_MESSAGE = 'No se encuentra ese REA o no puedes continuarlo.'; // @translate

    /** @var array<string,string> error code => message */
    public const MESSAGES = [
        'method' => 'Petición no válida.', // @translate
        'csrf' => 'La sesión ha caducado. Recarga la página: no perderás lo que escribiste.', // @translate
        'no_template' => 'El catálogo no tiene configurada la plantilla REA.', // @translate
        'state_invalid' => 'No se pudo leer lo que enviaste. Vuelve a intentarlo.', // @translate
        'not_found' => self::NOT_FOUND_MESSAGE,
        'title_required' => 'Para guardar un borrador basta con el título. Escríbelo en el paso «Datos».', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'upload_expired' => 'El fichero subido ya no está disponible. Vuelve a subirlo en el paso «Tu recurso».', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'url_invalid' => 'El enlace no es válido: revisa que esté completo y empiece por http:// o https://.', // @translate
        'invalid' => 'El catálogo no ha aceptado el REA.', // @translate
        'denied' => 'No tienes permiso para guardar este REA.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'governance' => 'El REA se ha guardado como borrador, pero la licencia o la autoría no son válidas.', // @translate
        'anchoring' => 'El REA se ha guardado como borrador, pero el anclaje no es válido.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'incomplete' => 'El REA se ha guardado como borrador, pero le falta algo para poder proponerlo.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'invalid_transition' => 'El REA se ha guardado, pero no se puede proponer en su estado actual.', // @translate
        'unexpected' => 'No se pudo guardar. Vuelve a intentarlo dentro de un momento.', // @translate
        'no_file' => 'No se ha recibido ningún fichero.', // @translate
        'too_large' => 'El fichero es demasiado grande para el catálogo.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'type' => 'Ese tipo de fichero no se admite. Sube un proyecto de eXeLearning, un .zip, un PDF, una imagen, un audio, un vídeo o un documento de oficina.', // @translate
        // phpcs:ignore Generic.Files.LineLength.TooLong -- one msgid for the catalogue
        'extension' => 'El catálogo no admite ficheros con esa extensión. Pide al equipo que la habilite o súbelo en otro formato.', // @translate
        'failed' => 'No se pudo subir el fichero. Vuelve a intentarlo.', // @translate
    ];

    /** @var \Closure(): ?list<array<string,mixed>> */
    private \Closure $templateProperties;
    /** @var \Closure(int): list<array<string,string>> */
    private \Closure $vocabEntries;
    /** @var \Closure(string): bool */
    private \Closure $isUploadedFile;

    /**
     * @param callable(): ?list<array<string,mixed>> $templateProperties the REA template's
     *   properties (term, label, alternateLabel, required, dataTypes), null without a readable template
     * @param callable(int): list<array<string,string>> $vocabEntries entries of a CustomVocab
     * @param callable(string): bool|null $isUploadedFile `is_uploaded_file`, replaceable in tests
     */
    public function __construct(
        private readonly ReaWizardSave $save,
        private readonly WizardUploadStore $uploads,
        private readonly MediaMetadataReader $metadata,
        private readonly DuplicateFinder $duplicates,
        private readonly GovernanceService $governance,
        private readonly WorkflowService $workflow,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        callable $templateProperties,
        callable $vocabEntries,
        ?callable $isUploadedFile = null
    ) {
        $this->templateProperties = \Closure::fromCallable($templateProperties);
        $this->vocabEntries = \Closure::fromCallable($vocabEntries);
        $this->isUploadedFile = \Closure::fromCallable($isUploadedFile ?? 'is_uploaded_file');
    }

    private function csrfValidator(): Csrf
    {
        return new Csrf(['name' => self::CSRF_NAME, 'salt' => 'oermanager', 'timeout' => 7200]);
    }

    public function indexAction()
    {
        $view = new ViewModel();
        $view->setTemplate('oer-manager/admin/rea-wizard/index');
        // ADR-0025 addendum 1: inside the admin's layout, like «Nuevo ítem».
        $view->setVariable(
            'myProposalsUrl',
            $this->url()->fromRoute('admin/oer-manager', ['action' => 'my-proposals'])
        );
        $schema = $this->schema();
        if (null === $schema) {
            $view->setVariable('notice', self::MESSAGES['no_template']);
            $view->setVariable('configUrl', $this->url()->fromRoute('admin/oer-manager', ['action' => 'config']));
            return $view;
        }
        $id = (int) $this->params()->fromQuery('id', 0);
        $item = null;
        if ($id > 0) {
            $item = $this->resumable($id);
            if (null === $item) {
                $view->setVariable('notice', self::NOT_FOUND_MESSAGE);
                return $view;
            }
        }
        $view->setVariable('config', $this->pageConfig($schema, $item));
        return $view;
    }

    public function uploadAction()
    {
        $guard = $this->guard();
        if (null !== $guard) {
            return $guard;
        }
        $file = $this->params()->fromFiles('file');
        if (!is_array($file) || !isset($file['tmp_name'], $file['error'])) {
            return $this->failure('no_file');
        }
        $error = (int) $file['error'];
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->failure('too_large');
        }
        $tmp = (string) $file['tmp_name'];
        if (UPLOAD_ERR_OK !== $error || '' === $tmp || !($this->isUploadedFile)($tmp)) {
            return $this->failure('failed');
        }
        $name = WizardUploadStore::cleanName((string) ($file['name'] ?? ''));
        $mediaType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $refusal = UploadPolicy::refusal($mediaType, $name, [
            'disable_file_validation' => $this->settings->get('disable_file_validation'),
            'media_type_whitelist' => $this->settings->get('media_type_whitelist', []),
            'extension_whitelist' => $this->settings->get('extension_whitelist', []),
        ]);
        if (null !== $refusal) {
            return $this->failure($refusal);
        }
        $this->uploads->sweepOld();
        try {
            $token = $this->uploads->put($this->userId(), $tmp, $name, $mediaType);
        } catch (\RuntimeException $e) {
            $this->logger->err('OERManager wizard upload: the file could not be stored');
            return $this->failure('failed');
        }
        $stored = (array) $this->uploads->get($this->userId(), $token);
        return new JsonModel([
            'ok' => true,
            'token' => $token,
            'name' => $name,
            'size' => (int) ($stored['size'] ?? 0),
            'mediaType' => $mediaType,
            'metadata' => $this->metadata->read((string) ($stored['path'] ?? ''), $name),
        ]);
    }

    /** Read only, as `search-terms`: no CSRF. */
    public function duplicatesAction()
    {
        $exceptId = (int) $this->params()->fromQuery('id', 0);
        $found = $this->duplicates->find(
            mb_substr((string) $this->params()->fromQuery('title', ''), 0, 500),
            mb_substr((string) $this->params()->fromQuery('name', ''), 0, 255),
            $exceptId > 0 ? $exceptId : null
        );
        $out = [];
        foreach ($found as $duplicate) {
            $out[] = $duplicate + ['url' => $this->url()->fromRoute(
                'admin/id',
                ['controller' => 'item', 'action' => 'show', 'id' => $duplicate['id']]
            )];
        }
        return new JsonModel(['duplicates' => $out]);
    }

    public function saveAction()
    {
        $guard = $this->guard();
        if (null !== $guard) {
            return $guard;
        }
        $raw = (string) $this->params()->fromPost('state', '');
        $state = strlen($raw) > self::MAX_STATE_BYTES ? null : json_decode($raw, true, 16);
        if (!is_array($state)) {
            return $this->failure('state_invalid');
        }
        $schema = $this->schema();
        if (null === $schema) {
            return $this->failure('no_template');
        }
        $id = (int) ($state['id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $existing = $this->resumable($id);
            if (null === $existing) {
                return $this->failure('not_found');
            }
        }
        try {
            $result = $this->save->save($state, $schema, $existing, $this->userId(), $this->contributor());
        } catch (\Throwable $e) {
            // The detail goes to the log, never the catalogue's content or the user's data.
            $this->logger->err('OERManager wizard save failed: ' . get_class($e));
            return $this->failure('unexpected');
        }
        if (isset($result['exception'])) {
            // The class only: never the catalogue's content or the user's data.
            $this->logger->err(sprintf(
                'OERManager wizard save, step %s: %s',
                (string) ($result['step'] ?? '?'),
                (string) $result['exception']
            ));
            unset($result['exception']);
        }
        if (!$result['ok']) {
            $code = (string) $result['error'];
            return new JsonModel($result + ['message' => self::MESSAGES[$code] ?? self::MESSAGES['unexpected']]);
        }
        $message = $result['proposed']
            ? 'Has propuesto el REA. El equipo de curación lo revisará.' // @translate
            : 'Borrador guardado. Pulsa «Continuar» cuando quieras seguir.'; // @translate
        $this->messenger()->addSuccess($message);
        return new JsonModel($result + [
            'message' => $message,
            'redirect' => $this->url()->fromRoute(
                'admin/oer-manager',
                ['action' => 'my-proposals'],
                ['query' => ['highlight' => $result['id']]]
            ),
        ]);
    }

    /**
     * The template's schema with the options of each vocabulary field, or
     * null without a readable REA template.
     *
     * @return array{data:list<array<string,mixed>>,anchoring:list<string>}|null
     */
    private function schema(): ?array
    {
        $properties = ($this->templateProperties)();
        if (null === $properties) {
            return null;
        }
        $schema = ReaWizardSchema::fromTemplate($properties);
        $licences = (array) ($this->governance->formOptions()['options']['licence'] ?? []);
        foreach ($schema['data'] as &$field) {
            $field['options'] = [];
            if ('licence' === $field['control']) {
                $field['options'] = $licences;
            } elseif (null !== $field['vocabId']) {
                try {
                    $field['options'] = ($this->vocabEntries)((int) $field['vocabId']);
                } catch (\Throwable $e) {
                    $field['options'] = [];
                }
            }
            if ([] === $field['options'] && in_array($field['control'], ['cards', 'select'], true)) {
                // A vocabulary that does not resolve degrades to a text field, never hidden.
                $field['control'] = 'text';
            }
            if ([] === $field['options'] && 'licence' === $field['control']) {
                // Without a licence vocabulary the licence is a free URI (GovernanceService's notice).
                $field['control'] = 'url';
            }
        }
        unset($field);
        return $schema;
    }

    /** The REA when the current user may resume it in the wizard, else null. */
    private function resumable(int $id): ?ItemRepresentation
    {
        try {
            $item = $this->api()->read('items', $id)->getContent();
        } catch (\Exception $e) {
            return null;
        }
        $identity = $this->identity();
        $profile = RecatalogRule::profile($identity ? (string) $identity->getRole() : null);
        $owner = $item->owner();
        $isOwner = null !== $identity && null !== $owner && (int) $owner->id() === (int) $identity->getId();
        $status = $this->workflow->statusOf($item);
        $isPublic = (bool) $item->isPublic();
        $canUpdate = (bool) $item->userIsAllowed('update');
        $denied = RecatalogRule::readDenied($profile, $isOwner)
            || null !== RecatalogRule::denial($status, $isPublic, $profile, $isOwner, $canUpdate)
            || !WorkflowStatus::canPropose($status, $isPublic);
        return $denied ? null : $item;
    }

    /**
     * @param array{data:list<array<string,mixed>>,anchoring:list<string>} $schema
     * @return array<string,mixed>
     */
    private function pageConfig(array $schema, ?ItemRepresentation $item): array
    {
        $index = fn (string $action): string => $this->url()->fromRoute('admin/oer-manager', ['action' => $action]);
        $wizard = fn (string $action): string => $this->url()->fromRoute(self::ROUTE, ['action' => $action]);
        return [
            'csrf' => $this->csrfValidator()->getHash(),
            'schema' => $schema,
            'requiredTerms' => array_values(array_filter(array_map(
                static fn (array $p) => $p['required'] ? (string) $p['term'] : null,
                (array) ($this->templateProperties)()
            ))),
            'defaultLicence' => GovernanceSettings::parseDefaultLicence(
                $this->settings->get(GovernanceSettings::DEFAULT_LICENCE)
            ),
            'uploadLimit' => UploadPolicy::limitBytes(
                (string) ini_get('upload_max_filesize'),
                (string) ini_get('post_max_size')
            ),
            'urls' => [
                'upload' => $wizard('upload'),
                'save' => $wizard('save'),
                'duplicates' => $wizard('duplicates'),
                'searchTerms' => $index('search-terms'),
                'curriculumTree' => $index('curriculum-tree'),
                'myProposals' => $index('my-proposals'),
            ],
            'user' => $this->userId(),
            'item' => null === $item ? null : $this->itemState($schema, $item),
        ];
    }

    /**
     * What a resumed REA already has, in the wizard's shape.
     *
     * @param array{data:list<array<string,mixed>>} $schema
     * @return array<string,mixed>
     */
    private function itemState(array $schema, ItemRepresentation $item): array
    {
        $values = [];
        foreach ($schema['data'] as $field) {
            $term = (string) $field['term'];
            foreach ((array) $item->value($term, ['all' => true, 'default' => []]) as $value) {
                $uri = (string) $value->uri();
                $values[$term][] = '' !== $uri ? $uri : (string) $value->value();
            }
        }
        $alignment = [];
        $stage = null;
        foreach (ReaWizardSchema::ANCHORING_TERMS as $term) {
            foreach ((array) $item->value($term, ['all' => true, 'default' => []]) as $value) {
                $target = $value->valueResource();
                if (null === $target) {
                    continue;
                }
                $alignment[$term][] = ['id' => (int) $target->id(), 'title' => (string) $target->displayTitle()];
                if ('lrmi:educationalLevel' === $term && null === $stage) {
                    $stage = CurModel::refId($target, CurModel::EDGE_ETAPA) ?: null;
                }
            }
        }
        $media = $item->media()[0] ?? null;
        $status = $this->workflow->statusOf($item);
        $notes = WorkflowStatus::REJECTED === $status
            ? array_map(
                static fn ($value): string => (string) $value->value(),
                (array) $item->value(WorkflowStatus::NOTE_TERM, ['all' => true, 'default' => []])
            )
            : [];
        return [
            'id' => (int) $item->id(),
            // Milliseconds, as the browser's autosave stamps its local copy.
            'modified' => null === $item->modified() ? 0 : $item->modified()->getTimestamp() * 1000,
            'status' => $status ?? 'Borrador', // no status = Borrador (WorkflowStatus::normalize)
            'rejection' => implode(' — ', $notes),
            // An empty map must reach the browser as `{}`: as `[]` its string keys would be lost.
            'values' => $values ?: new \stdClass(),
            'alignment' => $alignment ?: new \stdClass(),
            'link' => self::link($item),
            'stage' => $stage,
            'media' => null === $media ? null : [
                'name' => (string) ($media->source() ?: $media->filename()),
                'size' => (int) $media->size(),
                'mediaType' => (string) $media->mediaType(),
            ],
        ];
    }

    /** The main resource when it is a link (ReaWizardSchema::LINK_TERM), or null. */
    private static function link(ItemRepresentation $item): ?string
    {
        $value = $item->value(ReaWizardSchema::LINK_TERM);
        if (null === $value) {
            return null;
        }
        $uri = (string) $value->uri();
        return '' !== $uri ? $uri : (string) $value->value();
    }

    private function guard(): ?JsonModel
    {
        if (!$this->getRequest()->isPost()) {
            return $this->failure('method');
        }
        if (!$this->csrfValidator()->isValid((string) $this->params()->fromPost('csrf'))) {
            return $this->failure('csrf');
        }
        return null;
    }

    private function failure(string $code): JsonModel
    {
        return new JsonModel(['ok' => false, 'error' => $code, 'message' => self::MESSAGES[$code] ?? '']);
    }

    private function userId(): int
    {
        $identity = $this->identity();
        return $identity ? (int) $identity->getId() : 0;
    }

    /** Name recorded in the annotations of a write (ADR-0002). */
    private function contributor(): string
    {
        $identity = $this->identity();
        return $identity ? (string) $identity->getName() : 'unknown';
    }
}
