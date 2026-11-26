<?php

declare(strict_types=1);

namespace OERManager\Test\Controller;

use Laminas\Log\LoggerInterface;
use OERManager\Controller\Admin\ReaWizardController;
use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\GovernanceService;
use OERManager\Service\Wizard\DuplicateFinder;
use OERManager\Service\Wizard\MediaMetadataReader;
use OERManager\Service\Wizard\ReaWizardSave;
use OERManager\Service\Wizard\WizardUploadStore;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Api\Response;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class ReaWizardControllerTest extends TestCase
{
    use RepresentationFactory;

    private ReaWizardController $controller;
    private $save;
    private $duplicates;
    private $api;
    private $logger;
    private Settings $settings;
    private WizardUploadStore $uploads;
    private string $dir;
    private $params;
    private $request;
    private object $identity;
    private object $messages;
    private ?array $template;
    private ?ItemRepresentation $item = null;
    private bool $uploaded = true;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_wizard_ctl_' . bin2hex(random_bytes(6));
        $this->uploads = new WizardUploadStore($this->dir);
        $this->save = $this->createMock(ReaWizardSave::class);
        $this->duplicates = $this->createMock(DuplicateFinder::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settings = new Settings();
        $this->settings->set('disable_file_validation', true);
        $this->api = $this->createMock(ApiManager::class);
        $this->api->method('read')->willReturnCallback(function () {
            if (null === $this->item) {
                throw new \Omeka\Api\Exception\NotFoundException('missing');
            }
            return new Response($this->item);
        });
        $governance = $this->createMock(GovernanceService::class);
        $governance->method('formOptions')->willReturn(['options' => ['licence' => [
            ['uri' => 'https://creativecommons.org/licenses/by-sa/4.0/', 'label' => 'CC BY-SA 4.0'],
        ]]]);
        $this->template = [
            ['term' => 'dcterms:title', 'label' => 'Título', 'alternateLabel' => null, 'required' => true, 'dataTypes' => ['literal']],
            ['term' => 'lrmi:learningResourceType', 'label' => 'Tipo', 'alternateLabel' => null, 'required' => true,
                'dataTypes' => ['customvocab:7']],
            ['term' => 'dcterms:language', 'label' => 'Idioma', 'alternateLabel' => null, 'required' => false,
                'dataTypes' => ['customvocab:8']],
            ['term' => 'dcterms:license', 'label' => 'Licencia', 'alternateLabel' => null, 'required' => false,
                'dataTypes' => ['customvocab:3']],
        ];
        $this->controller = new ReaWizardController(
            $this->save,
            $this->uploads,
            new MediaMetadataReader(),
            $this->duplicates,
            $governance,
            new WorkflowService($this->api, $this->createMock(CurationWriter::class)),
            $this->settings,
            $this->logger,
            fn () => $this->template,
            static fn (int $id) => 7 === $id ? [['value' => 'Vídeo'], ['value' => 'Juego']] : [],
            fn (string $path) => $this->uploaded
        );
        $this->params = new class {
            public array $post = ['csrf' => 'valid'];
            public array $query = [];
            public array $files = [];
            public function fromPost($key = null, $default = null)
            {
                return null === $key ? $this->post : ($this->post[$key] ?? $default);
            }
            public function fromQuery($key = null, $default = null)
            {
                return null === $key ? $this->query : ($this->query[$key] ?? $default);
            }
            public function fromFiles($key = null, $default = null)
            {
                return $this->files[$key] ?? $default;
            }
        };
        $this->request = new class {
            public bool $post = true;
            public function isPost()
            {
                return $this->post;
            }
        };
        $this->identity = new class {
            public string $role = 'rea_proponente';
            public function getId()
            {
                return 7;
            }
            public function getRole()
            {
                return $this->role;
            }
            public function getName()
            {
                return 'Ana P';
            }
        };
        $this->messages = new class {
            public array $success = [];
            public function addSuccess($message)
            {
                $this->success[] = $message;
            }
        };
        $this->controller->plugins = [
            'params' => $this->params, 'request' => $this->request, 'identity' => $this->identity,
            'api' => $this->api, 'messenger' => $this->messages,
            'url' => new class {
                public function fromRoute($route, $params = [], $options = [])
                {
                    $query = isset($options['query']) ? '?' . http_build_query($options['query']) : '';
                    return $route . '/' . ($params['action'] ?? '') . (isset($params['id']) ? '/' . $params['id'] : '') . $query;
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function data(string $action): array
    {
        return $this->controller->{$action . 'Action'}()->getVariables();
    }

    private function rea(int $ownerId, ?string $status = null, bool $public = false): ItemRepresentation
    {
        $values = null === $status ? [] : ['curation:status' => [$this->value($status)]];
        if ('Rechazado' === $status) {
            $values['curation:note'] = [$this->value('Anclaje incompleto'), $this->value('Añade un criterio')];
        }
        $values['dcterms:title'] = [$this->value('Fracciones')];
        $course = $this->curItem(11, 'cur:Curso', '2.º ESO', ['cur:etapa' => [$this->value('', $this->curItem(3, 'cur:Etapa', 'ESO'), 'resource:item')]]);
        $values['lrmi:educationalLevel'] = [$this->value('', $course, 'resource:item')];
        $item = $this->item(100, 'Fracciones', $values);
        $owner = $this->createMock(UserRepresentation::class);
        $owner->method('id')->willReturn($ownerId);
        $item->method('owner')->willReturn($owner);
        $item->method('isPublic')->willReturn($public);
        $item->method('userIsAllowed')->willReturn(true);
        $item->method('modified')->willReturn(new \DateTime('@1795000000'));
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('source')->willReturn('clase.elpx');
        $media->method('size')->willReturn(4200);
        $media->method('mediaType')->willReturn('application/zip');
        $item->method('media')->willReturn([$media]);
        return $item;
    }

    public function testTheWritesArePostAndCsrfGuarded(): void
    {
        foreach (['upload', 'save'] as $action) {
            $this->request->post = false;
            $this->assertSame('method', $this->data($action)['error'], $action);
            $this->request->post = true;
            $this->params->post = ['csrf' => 'bad'];
            $this->assertSame('csrf', $this->data($action)['error'], $action);
        }
    }

    public function testThePageCarriesTheSchemaWithItsOptionsAndTheUrls(): void
    {
        $this->settings->set('oermanager_default_licence', 'https://creativecommons.org/licenses/by-sa/4.0/');
        $view = $this->controller->indexAction();
        $this->assertSame('oer-manager/admin/rea-wizard/index', $view->getTemplate());
        $config = $view->getVariable('config');
        $controls = array_column($config['schema']['data'], 'control', 'term');
        $this->assertSame('cards', $controls['lrmi:learningResourceType']);
        $this->assertSame('text', $controls['dcterms:language'], 'a vocabulary that does not resolve degrades to text');
        $this->assertSame('licence', $controls['dcterms:license']);
        $this->assertSame([['value' => 'Vídeo'], ['value' => 'Juego']], $config['schema']['data'][1]['options']);
        $this->assertSame('CC BY-SA 4.0', $config['schema']['data'][3]['options'][0]['label']);
        $this->assertSame(['dcterms:title', 'lrmi:learningResourceType'], $config['requiredTerms']);
        $this->assertSame('https://creativecommons.org/licenses/by-sa/4.0/', $config['defaultLicence']);
        $this->assertSame('admin/oer-manager-wizard/save', $config['urls']['save']);
        $this->assertSame('admin/oer-manager/curriculum-tree', $config['urls']['curriculumTree']);
        $this->assertSame('valid', $config['csrf']);
        $this->assertNull($config['item']);
    }

    public function testWithoutALicenceVocabularyTheLicenceIsAUrlField(): void
    {
        $governance = $this->createMock(GovernanceService::class);
        $governance->method('formOptions')->willReturn(['options' => ['licence' => []]]);
        $controller = new ReaWizardController(
            $this->save,
            $this->uploads,
            new MediaMetadataReader(),
            $this->duplicates,
            $governance,
            new WorkflowService($this->api, $this->createMock(CurationWriter::class)),
            $this->settings,
            $this->logger,
            fn () => $this->template,
            static fn () => []
        );
        $controller->plugins = $this->controller->plugins;
        $controls = array_column($controller->indexAction()->getVariable('config')['schema']['data'], 'control', 'term');
        $this->assertSame('url', $controls['dcterms:license']);
    }

    public function testWithoutATemplateThePageSaysSoAndSavingIsRefused(): void
    {
        $this->template = null;
        $view = $this->controller->indexAction();
        $this->assertSame(ReaWizardController::MESSAGES['no_template'], $view->getVariable('notice'));
        $this->assertNull($view->getVariable('config'));
        $this->params->post = ['csrf' => 'valid', 'state' => '{}'];
        $this->assertSame('no_template', $this->data('save')['error']);
    }

    public function testARejectedReaOfTheProposerResumesWithItsValuesAnchoringMediaAndReason(): void
    {
        $this->item = $this->rea(7, 'Rechazado');
        $this->params->query = ['id' => '100'];
        $item = $this->controller->indexAction()->getVariable('config')['item'];
        $this->assertSame(100, $item['id']);
        $this->assertSame('Rechazado', $item['status']);
        $this->assertSame('Anclaje incompleto — Añade un criterio', $item['rejection']);
        $this->assertSame(['Fracciones'], $item['values']['dcterms:title']);
        $this->assertSame([['id' => 11, 'title' => '2.º ESO']], $item['alignment']['lrmi:educationalLevel']);
        $this->assertSame(3, $item['stage']);
        $this->assertSame(1795000000000, $item['modified']);
        $this->assertSame(['name' => 'clase.elpx', 'size' => 4200, 'mediaType' => 'application/zip'], $item['media']);
    }

    public function testSomeoneElsesOrAProposedOrAMissingReaIsAnsweredAlike(): void
    {
        $this->params->query = ['id' => '100'];
        foreach ([fn () => $this->rea(8), fn () => $this->rea(7, 'Propuesto'), fn () => $this->rea(7, null, true), fn () => null] as $make) {
            $this->item = $make();
            $view = $this->controller->indexAction();
            $this->assertSame(ReaWizardController::NOT_FOUND_MESSAGE, $view->getVariable('notice'));
            $this->assertNull($view->getVariable('config'));
            $this->params->post = ['csrf' => 'valid', 'state' => json_encode(['id' => 100])];
            $this->assertSame('not_found', $this->data('save')['error']);
        }
    }

    public function testACuratorResumesADraftOfSomeoneElse(): void
    {
        $this->identity->role = 'rea_curador';
        $this->item = $this->rea(8);
        $this->params->query = ['id' => '100'];
        $this->assertSame(100, $this->controller->indexAction()->getVariable('config')['item']['id']);
    }

    public function testSaveHandsTheStateToReaWizardSaveAndRedirectsToMyProposals(): void
    {
        $state = ['values' => ['dcterms:title' => ['Fracciones']], 'propose' => true];
        $this->save->expects($this->once())->method('save')
            ->with($state, $this->callback(fn (array $schema) => 'dcterms:title' === $schema['data'][0]['term']), null, 7, 'Ana P')
            ->willReturn(['ok' => true, 'id' => 100, 'proposed' => true]);
        $this->params->post = ['csrf' => 'valid', 'state' => json_encode($state)];
        $data = $this->data('save');
        $this->assertTrue($data['ok']);
        $this->assertSame('admin/oer-manager/my-proposals?highlight=100', $data['redirect']);
        $this->assertSame([$data['message']], $this->messages->success);
    }

    public function testSaveFailuresCarryTheirMessage(): void
    {
        $this->save->method('save')->willReturn(['ok' => false, 'id' => 100, 'error' => 'incomplete', 'missing' => ['subject']]);
        $this->params->post = ['csrf' => 'valid', 'state' => '{"values":{}}'];
        $data = $this->data('save');
        $this->assertSame(['subject'], $data['missing']);
        $this->assertSame(ReaWizardController::MESSAGES['incomplete'], $data['message']);
        $this->assertSame([], $this->messages->success);
    }

    public function testAnUnexpectedErrorIsLoggedWithoutDetailAndAnsweredGenerically(): void
    {
        $this->save->method('save')->willThrowException(new \LogicException('secret content of the REA'));
        $this->logger->expects($this->once())->method('err')->with('OERManager wizard save failed: LogicException');
        $this->params->post = ['csrf' => 'valid', 'state' => '{}'];
        $this->assertSame('unexpected', $this->data('save')['error']);
    }

    public function testAnUnreadableOrOversizedStateIsRefused(): void
    {
        foreach (['not json', '"a string"', str_repeat('x', 300000)] as $raw) {
            $this->params->post = ['csrf' => 'valid', 'state' => $raw];
            $this->assertSame('state_invalid', $this->data('save')['error']);
        }
    }

    private function upload(string $content, string $name, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($tmp, $content);
        $this->params->files = ['file' => ['tmp_name' => $tmp, 'name' => $name, 'error' => $error, 'size' => strlen($content)]];
        return $this->data('upload');
    }

    public function testAnUploadedElpxIsStoredAndItsMetadataSuggested(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('content.xml', '<ode><odeProperties><odeProperty><key>pp_title</key><value>Fracciones</value></odeProperty></odeProperties><odeNavStructures/></ode>');
        $zip->close();
        $data = $this->upload((string) file_get_contents($tmp), '../../clase.elpx');
        @unlink($tmp);
        $this->assertTrue($data['ok']);
        $this->assertSame('clase.elpx', $data['name']);
        $this->assertSame('application/zip', $data['mediaType']);
        $this->assertSame(['title' => 'Fracciones'], $data['metadata']);
        $this->assertNotNull($this->uploads->get(7, $data['token']));
    }

    public function testUploadRefusals(): void
    {
        $this->assertSame('no_file', $this->data('upload')['error']);
        $this->assertSame('too_large', $this->upload('x', 'a.pdf', UPLOAD_ERR_INI_SIZE)['error']);
        $this->assertSame('failed', $this->upload('x', 'a.pdf', UPLOAD_ERR_PARTIAL)['error']);
        $this->assertSame('type', $this->upload("<html><body>hola</body></html>", 'a.pdf')['error']);
        $this->uploaded = false;
        $this->assertSame('failed', $this->upload('%PDF-1.4', 'a.pdf')['error']);
        $this->uploaded = true;
        $this->settings->set('disable_file_validation', false);
        $this->settings->set('media_type_whitelist', ['application/pdf']);
        $this->settings->set('extension_whitelist', ['pdf']);
        $data = $this->upload("%PDF-1.4\n%%EOF", 'a.elpx');
        $this->assertSame('extension', $data['error']);
        $this->assertSame(ReaWizardController::MESSAGES['extension'], $data['message']);
    }

    public function testDuplicatesLinkEachFoundRea(): void
    {
        $this->duplicates->expects($this->once())->method('find')->with('Fracciones', 'clase.elpx', 100)
            ->willReturn([['id' => 4, 'title' => 'Fracciones', 'reason' => 'title']]);
        $this->params->query = ['title' => 'Fracciones', 'name' => 'clase.elpx', 'id' => '100'];
        $this->assertSame(
            [['id' => 4, 'title' => 'Fracciones', 'reason' => 'title', 'url' => 'admin/id/show/4']],
            $this->data('duplicates')['duplicates']
        );
    }
}
