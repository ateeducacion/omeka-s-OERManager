<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\GovernanceService;
use OERManager\Service\ReaSite;
use OERManager\Service\ReaTemplateFields;
use OERManager\Service\RecatalogService;
use OERManager\Service\Wizard\ReaWizardSave;
use OERManager\Service\Wizard\WizardUploadStore;
use OERManager\Service\Workflow\WorkflowService;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Settings\Settings;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;

final class ReaWizardSaveTest extends TestCase
{
    use RepresentationFactory;

    private const PROPS = ['dcterms:title' => 1, 'dcterms:description' => 4, 'lrmi:learningResourceType' => 50,
        'dcterms:license' => 49, 'dcterms:creator' => 2, 'curation:status' => 90, 'curation:note' => 91,
        'dcterms:provenance' => 51, 'schema:url' => 60, 'dcterms:references' => 61,
        'schema:isPartOf' => 70];

    private $api;
    private $writer;
    private $governance;
    private $recatalog;
    private ReaTemplateFields $template;
    private Settings $settings;
    private WizardUploadStore $uploads;
    private string $dir;
    /** @var list<array> */
    private array $creates = [];
    /** @var list<array> */
    private array $updates = [];
    /** @var list<array> */
    private array $commits = [];
    private array $required = ['dcterms:title'];
    private ?ItemRepresentation $created = null;
    /** @var list<string> media sources whose creation fails */
    private array $failMedia = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_wizard_save_' . bin2hex(random_bytes(6));
        $this->uploads = new WizardUploadStore($this->dir);
        $this->api = $this->createMock(ApiManager::class);
        $this->api->method('search')->willReturnCallback(function (string $resource, array $query) {
            return $this->response('resource_classes' === $resource ? [$this->classRep(9)] : []);
        });
        $this->api->method('create')->willReturnCallback(function (string $resource, array $data) {
            $this->creates[] = [$resource, $data];
            if ('media' === $resource) {
                $source = (string) ($data['ingest_url'] ?? $data['o:source'] ?? $data['wizard_token'] ?? '');
                if (in_array($source, $this->failMedia, true)) {
                    $errors = new ErrorStore();
                    $errors->addError('ingest_url', 'Invalid media type "text/html".');
                    $exception = new ValidationException('invalid');
                    $exception->setErrorStore($errors);
                    throw $exception;
                }
                return $this->response($this->media(200 + count($this->creates)));
            }
            return $this->response($this->created ?? $this->item(100, 'x'));
        });
        $this->api->method('update')->willReturnCallback(function (...$args) {
            $this->updates[] = $args;
            return $this->response(null);
        });
        $this->api->method('read')->willReturnCallback(fn () => $this->response($this->created ?? $this->item(100, 'x')));
        $this->writer = $this->createMock(CurationWriter::class);
        $this->writer->method('propertyId')->willReturnCallback(fn (string $term) => self::PROPS[$term] ?? null);
        $this->writer->method('stamp')->willReturn('2026-11-26T10:00:00.000000+00:00');
        $this->writer->method('annotation')->willReturn([]);
        $this->writer->method('commit')->willReturnCallback(function (...$args) {
            $this->commits[] = $args;
        });
        $this->governance = $this->createMock(GovernanceService::class);
        $this->recatalog = $this->createMock(RecatalogService::class);
        $this->template = $this->createMock(ReaTemplateFields::class);
        $this->template->method('requiredTerms')->willReturnCallback(fn () => $this->required);
        $this->settings = new Settings();
        $this->settings->set('oermanager_rea_template_id', 12);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function save(): ReaWizardSave
    {
        $site = $this->createMock(ReaSite::class);
        $site->method('siteId')->willReturn(3);
        return new ReaWizardSave(
            $this->api,
            $this->writer,
            $this->governance,
            $this->recatalog,
            new WorkflowService($this->api, $this->writer),
            $this->uploads,
            $this->template,
            $site,
            $this->settings,
            [],
            // No DNS in the tests: one internal host, every other one public.
            static fn (string $host): array => 'intranet.local' === $host ? ['10.0.0.8'] : ['93.184.216.34']
        );
    }

    private static function schema(): array
    {
        return ['data' => [
            ['term' => 'dcterms:title', 'control' => 'text', 'dataType' => 'literal', 'options' => []],
            ['term' => 'dcterms:description', 'control' => 'textarea', 'dataType' => 'literal', 'options' => []],
            ['term' => 'lrmi:learningResourceType', 'control' => 'cards', 'dataType' => 'customvocab:7',
                'options' => [['value' => 'Vídeo'], ['value' => 'Juego']]],
            ['term' => 'dcterms:references', 'control' => 'url', 'dataType' => 'uri', 'options' => []],
            ['term' => 'dcterms:license', 'control' => 'licence', 'dataType' => 'customvocab:3', 'options' => []],
            ['term' => 'dcterms:creator', 'control' => 'text', 'dataType' => 'literal', 'options' => []],
        ]];
    }

    private function token(): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'oerup');
        file_put_contents($tmp, 'zip');
        return $this->uploads->put(7, $tmp, 'clase.elpx', 'application/zip');
    }

    private function state(array $over = []): array
    {
        return $over + [
            'values' => ['dcterms:title' => ['Fracciones'], 'dcterms:description' => ['Compras y recetas'],
                'lrmi:learningResourceType' => ['Vídeo'], 'dcterms:references' => ['https://example.org/x'],
                'dcterms:license' => ['https://creativecommons.org/licenses/by-sa/4.0/'], 'dcterms:creator' => ['Ana']],
            'resources' => [['key' => 'r1', 'kind' => 'upload', 'token' => $this->token()]],
            'alignment' => ['lrmi:educationalLevel' => [11], 'schema:about' => [21], 'lrmi:teaches' => [31]],
            'propose' => false,
        ];
    }

    private function media(int $id): MediaRepresentation
    {
        $media = $this->createMock(MediaRepresentation::class);
        $media->method('id')->willReturn($id);
        return $media;
    }

    private function classRep(int $id): object
    {
        return new class ($id) {
            public function __construct(private int $id)
            {
            }
            public function id()
            {
                return $this->id;
            }
        };
    }

    public function testADraftIsCreatedPrivateInTheReaSiteWithItsDataAndMedia(): void
    {
        $state = $this->state();
        $token = $state['resources'][0]['token'];
        $this->governance->expects($this->once())->method('apply')
            ->with(100, ['dcterms:license' => ['https://creativecommons.org/licenses/by-sa/4.0/'], 'dcterms:creator' => ['Ana']], 'Ana P', null, false, null, false)
            ->willReturn(['updated' => true]);
        $this->recatalog->expects($this->once())->method('apply')->with(100, [
            'lrmi:educationalLevel' => [11], 'schema:about' => [21], 'lrmi:teaches' => [31],
            'lrmi:assesses' => [], 'dcterms:relation' => [],
        ], 'Ana P')->willReturn(['updated' => true]);

        $result = $this->save()->save($state, self::schema(), null, 7, 'Ana P');

        $this->assertSame(['ok' => true, 'id' => 100, 'proposed' => false], $result);
        [$resource, $data] = $this->creates[0];
        $this->assertSame('items', $resource);
        $this->assertFalse($data['o:is_public']);
        $this->assertSame(['o:id' => 9], $data['o:resource_class']);
        $this->assertSame([['o:id' => 3]], $data['o:site']);
        $this->assertArrayNotHasKey('o:resource_template', $data, 'the template is set once the REA meets it');
        $this->assertArrayNotHasKey('o:media', $data, 'each media is created on its own, so one failure names itself');
        $this->assertSame(['media', ['o:item' => ['o:id' => 100], 'o:ingester' => 'oermanager_wizard', 'wizard_token' => $token]],
            $this->creates[1]);
        $this->assertSame([], $this->updates, 'a new REA\'s media keep the order they were created in');
        $this->assertSame([['type' => 'literal', 'property_id' => 1, '@value' => 'Fracciones']], $data['dcterms:title']);
        $this->assertSame([['type' => 'customvocab:7', 'property_id' => 50, '@value' => 'Vídeo']], $data['lrmi:learningResourceType']);
        $this->assertSame([['type' => 'uri', 'property_id' => 61, '@id' => 'https://example.org/x']], $data['dcterms:references']);
        $this->assertArrayNotHasKey('dcterms:license', $data, 'governance terms go through GovernanceService');
        $this->assertArrayNotHasKey('curation:status', $data);
        // Template met: set with an append-only commit that clears nothing.
        $this->assertSame([[100, [], ['o:resource_template' => ['o:id' => 12]]]], $this->commits);
        $this->assertNull($this->uploads->get(7, $token), 'the stored upload is forgotten once ingested');
    }

    public function testADraftNeedsOnlyATitle(): void
    {
        $this->required = ['dcterms:title', 'dcterms:creator'];
        $result = $this->save()->save(
            ['values' => ['dcterms:title' => ['Solo título']], 'resources' => [], 'alignment' => [], 'propose' => false],
            self::schema(),
            null,
            7,
            'Ana P'
        );
        $this->assertTrue($result['ok']);
        $this->assertCount(1, $this->creates, 'no media');
        $this->assertSame([], $this->commits, 'no template while a required field is empty');
        $this->assertSame(
            ['ok' => false, 'error' => 'title_required'],
            $this->save()->save(['values' => ['dcterms:title' => ['  ']]], self::schema(), null, 7, 'Ana P')
        );
    }

    public function testAnExpiredUploadWritesNothing(): void
    {
        $state = $this->state(['resources' => [['key' => 'r9', 'kind' => 'upload', 'token' => str_repeat('a', 32)]]]);
        $this->assertSame(['ok' => false, 'error' => 'upload_expired', 'key' => 'r9'], $this->save()->save($state, self::schema(), null, 7, 'Ana P'));
        $this->assertSame([], $this->creates);
    }

    /** Owner's review of PR #100: a link is a media, with the ingester that fits it; never a schema:url value. */
    public function testALinkBecomesAMediaWithTheIngesterThatFitsIt(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $this->save()->save($this->state(['resources' => [
            ['key' => 'l1', 'kind' => 'url', 'url' => 'https://ejemplo.es/recursos/guía.pdf'],
            ['key' => 'l2', 'kind' => 'url', 'url' => 'https://youtu.be/abc123'],
        ]]), self::schema(), null, 7, 'Ana P');
        $this->assertArrayNotHasKey('schema:url', $this->creates[0][1]);
        $this->assertSame(['o:item' => ['o:id' => 100], 'o:ingester' => 'url', 'ingest_url' => 'https://ejemplo.es/recursos/guía.pdf'],
            $this->creates[1][1]);
        $this->assertSame(['o:item' => ['o:id' => 100], 'o:ingester' => 'youtube', 'o:source' => 'https://youtu.be/abc123'],
            $this->creates[2][1]);
        foreach (['javascript:alert(1)', 'https://', 'https://x y', 'ftp://example.org/a'] as $bad) {
            $this->assertSame(
                ['ok' => false, 'error' => 'url_invalid', 'key' => 'l1'],
                $this->save()->save($this->state(['resources' => [['key' => 'l1', 'kind' => 'url', 'url' => $bad]]]), self::schema(), null, 7, 'Ana P'),
                $bad
            );
        }
    }

    /** Security review (SSRF): the server never downloads a link to an internal address. */
    public function testALinkToAnInternalAddressIsRefusedBeforeAnythingIsWritten(): void
    {
        foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest/', 'http://intranet.local/doc.pdf'] as $url) {
            $this->assertSame(
                ['ok' => false, 'error' => 'url_private', 'key' => 'l1'],
                $this->save()->save($this->state(['resources' => [['key' => 'l1', 'kind' => 'url', 'url' => $url]]]),
                    self::schema(), null, 7, 'Ana P'),
                $url
            );
        }
        $this->assertSame([], $this->creates);
    }

    public function testAMediaOmekaRefusesLeavesADraftWithTheOthersAndNamesIt(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $this->failMedia = ['https://ejemplo.es/pagina'];
        $state = $this->state(['propose' => true]);
        $state['resources'][] = ['key' => 'l1', 'kind' => 'url', 'url' => 'https://ejemplo.es/pagina'];
        $result = $this->save()->save($state, self::schema(), null, 7, 'Ana P');
        $this->assertSame([
            'ok' => false, 'id' => 100, 'step' => 'media', 'error' => 'media',
            'failed' => [['key' => 'l1', 'messages' => ['Invalid media type "text/html".']]],
            'created' => [['key' => 'r1', 'id' => 202]],
        ], $result);
        foreach ($this->commits as [, , $data]) {
            $this->assertArrayNotHasKey('curation:status', $data, 'not proposed while a media is missing');
        }
    }

    public function testAnApiValidationErrorAnswersItsMessages(): void
    {
        $errors = new ErrorStore();
        $errors->addError('o:media', ['file' => ['Cannot store files with the resolved extension "elpx".']]);
        $exception = new ValidationException('invalid');
        $exception->setErrorStore($errors);
        $api = $this->createMock(ApiManager::class);
        $api->method('search')->willReturn($this->response([$this->classRep(9)]));
        $api->method('create')->willThrowException($exception);
        $this->api = $api;
        $result = $this->save()->save($this->state(), self::schema(), null, 7, 'Ana P');
        $this->assertSame('invalid', $result['error']);
        $this->assertSame(['Cannot store files with the resolved extension "elpx".'], $result['messages']);
    }

    public function testAFailedAnchoringLeavesADraftAndDoesNotPropose(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willThrowException(new \RuntimeException('Destinos inválidos para lrmi:teaches: 31'));
        $result = $this->save()->save($this->state(['propose' => true]), self::schema(), null, 7, 'Ana P');
        $this->assertSame(
            ['ok' => false, 'id' => 100, 'step' => 'anchoring', 'error' => 'anchoring',
                'message' => 'Destinos inválidos para lrmi:teaches: 31', 'created' => [['key' => 'r1', 'id' => 202]]],
            $result
        );
        foreach ($this->commits as [, , $data]) {
            $this->assertArrayNotHasKey('curation:status', $data);
        }
    }

    public function testGovernanceErrorsLeaveADraft(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => false, 'errors' => ['dcterms:license' => 'not-uri']]);
        $this->recatalog->expects($this->never())->method('apply');
        $result = $this->save()->save($this->state(), self::schema(), null, 7, 'Ana P');
        $this->assertSame(['ok' => false, 'id' => 100, 'step' => 'governance', 'error' => 'governance',
            'errors' => ['dcterms:license' => 'not-uri'], 'created' => [['key' => 'r1', 'id' => 202]]], $result);
    }

    public function testProposingGoesThroughTheWorkflowOnlyWhenNothingIsMissing(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $media = $this->createMock(MediaRepresentation::class);
        $item = $this->item(100, 'Fracciones');
        $item->method('media')->willReturn([$media]);
        $this->created = $item;
        $result = $this->save()->save($this->state(['propose' => true]), self::schema(), null, 7, 'Ana P');
        $this->assertSame(['ok' => true, 'id' => 100, 'proposed' => true], $result);
        $status = end($this->commits);
        $this->assertSame('Propuesto', $status[2]['curation:status'][0]['@value']);

        $this->commits = [];
        $state = $this->state(['propose' => true]);
        $state['alignment'] = ['lrmi:educationalLevel' => [11]];
        $result = $this->save()->save($state, self::schema(), null, 7, 'Ana P');
        $this->assertSame(['ok' => false, 'id' => 100, 'error' => 'incomplete', 'missing' => ['subject'],
            'created' => [['key' => 'r1', 'id' => 204]]], $result);
        foreach ($this->commits as [, , $data]) {
            $this->assertArrayNotHasKey('curation:status', $data);
        }
    }

    public function testAResumeClearsOnlyTheManagedDataTermsAndSetsTheMediaInTheirOrder(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('media')->willReturn([$this->media(55), $this->media(56)]);
        $existing->method('resourceTemplate')->willReturn(null);
        $this->api->expects($this->never())->method('batchDelete');

        // 56 first, then a new upload; 55 is removed.
        $state = $this->state();
        $state['resources'] = [['key' => 'm56', 'kind' => 'media', 'id' => 56], $state['resources'][0]];
        $result = $this->save()->save($state, self::schema(), $existing, 7, 'Ana P');

        $this->assertTrue($result['ok']);
        [$resource, $id, $data, , $options] = $this->updates[0];
        $this->assertSame(['items', 100], [$resource, $id]);
        $this->assertSame([1, 4, 50, 61], $data['clear_property_values'], 'only changed data terms; never provenance');
        $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
        $this->assertArrayNotHasKey('o:is_public', $data);
        $this->assertArrayNotHasKey('o:media', $data);
        $this->assertSame(['media', ['o:item' => ['o:id' => 100], 'o:ingester' => 'oermanager_wizard',
            'wizard_token' => $state['resources'][1]['token']]], $this->creates[0]);
        // Omeka keeps the listed media in this order and removes the rest.
        $this->assertSame(['items', 100, ['o:media' => [['o:id' => 56], ['o:id' => 201]]], [], ['isPartial' => true]],
            $this->updates[1]);
    }

    public function testAResumeThatKeepsTheMediaInTheirOrderTouchesNoMedia(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('media')->willReturn([$this->media(55), $this->media(56)]);
        $keep = [['key' => 'a', 'kind' => 'media', 'id' => 55], ['key' => 'b', 'kind' => 'media', 'id' => 56],
            ['key' => 'c', 'kind' => 'media', 'id' => 999]];
        $this->save()->save($this->state(['resources' => $keep]), self::schema(), $existing, 7, 'Ana P');
        $this->assertSame([], $this->creates);
        $this->assertCount(1, $this->updates, 'only the data update: a media of another item is ignored');

        $this->updates = [];
        $swap = [['key' => 'b', 'kind' => 'media', 'id' => 56], ['key' => 'a', 'kind' => 'media', 'id' => 55]];
        $this->save()->save($this->state(['resources' => $swap]), self::schema(), $existing, 7, 'Ana P');
        $this->assertSame(['o:media' => [['o:id' => 56], ['o:id' => 55]]], $this->updates[1][2]);
    }

    public function testAResumeThatNoLongerMeetsTheTemplateDropsIt(): void
    {
        $this->required = ['dcterms:title', 'lrmi:assesses'];
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('resourceTemplate')->willReturn(new \stdClass());
        $this->save()->save($this->state(['resources' => []]), self::schema(), $existing, 7, 'Ana P');
        $this->assertArrayHasKey('o:resource_template', $this->updates[0][2]);
        $this->assertNull($this->updates[0][2]['o:resource_template']);
    }

    public function testValuesOfTermsOutsideTheSchemaAreIgnored(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $state = $this->state();
        $state['values']['curation:status'] = ['Publicado'];
        $state['values']['dcterms:provenance'] = ['x'];
        $this->save()->save($state, self::schema(), null, 7, 'Ana P');
        $this->assertArrayNotHasKey('curation:status', $this->creates[0][1]);
        $this->assertArrayNotHasKey('dcterms:provenance', $this->creates[0][1]);
    }

    public function testAUriVocabularyValueIsWrittenAsItsUri(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $schema = self::schema();
        $schema['data'][2]['options'] = [['uri' => 'https://example.org/video', 'label' => 'Vídeo']];
        $state = $this->state();
        $state['values']['lrmi:learningResourceType'] = ['https://example.org/video'];
        $this->save()->save($state, $schema, null, 7, 'Ana P');
        $this->assertSame(
            [['type' => 'customvocab:7', 'property_id' => 50, '@id' => 'https://example.org/video', 'o:label' => 'Vídeo']],
            $this->creates[0][1]['lrmi:learningResourceType']
        );
    }

    public function testAnItemVocabularyValueIsWrittenAsALinkToTheItem(): void
    {
        // Owner's report on PR #100: «Proyecto» (schema:isPartOf) is a CustomVocab of items.
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $schema = self::schema();
        $schema['data'][] = ['term' => 'schema:isPartOf', 'control' => 'select', 'dataType' => 'customvocab:9',
            'options' => [['value' => '41371', 'label' => 'Impulsa', 'resource' => 41371]]];
        $state = $this->state();
        $state['values']['schema:isPartOf'] = ['41371'];
        $this->save()->save($state, $schema, null, 7, 'Ana P');
        $this->assertSame(
            [['type' => 'customvocab:9', 'property_id' => 70, 'value_resource_id' => 41371]],
            $this->creates[0][1]['schema:isPartOf']
        );
    }

    public function testAResumeReadsAnItemValueAsItsIdAndLeavesItUntouched(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $schema = self::schema();
        $schema['data'][] = ['term' => 'schema:isPartOf', 'control' => 'select', 'dataType' => 'customvocab:9',
            'options' => [['value' => '41371', 'label' => 'Impulsa', 'resource' => 41371]]];
        $existing = $this->item(100, 'Fracciones', [
            'dcterms:title' => [$this->value('Fracciones')],
            'schema:isPartOf' => [$this->value('', $this->item(41371, 'Impulsa'), 'customvocab:9')],
        ]);
        $state = $this->state(['resources' => []]);
        $state['values']['schema:isPartOf'] = ['41371'];
        $this->save()->save($state, $schema, $existing, 7, 'Ana P');
        $this->assertArrayNotHasKey('schema:isPartOf', $this->updates[0][2], 'an unchanged project is not rewritten');
        $this->assertNotContains(70, $this->updates[0][2]['clear_property_values'] ?? []);
    }

    public function testAResumeRewritesOnlyTheTermsThatChanged(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones', [
            'dcterms:title' => [$this->value('Fracciones')],
            'dcterms:description' => [$this->value('Compras y recetas')],
        ]);
        $this->save()->save($this->state(['resources' => []]), self::schema(), $existing, 7, 'Ana P');
        $this->assertSame([50, 61], $this->updates[0][2]['clear_property_values']);
        $this->assertArrayNotHasKey('dcterms:title', $this->updates[0][2]);
    }

    public function testTheWizardNeitherWritesNorClearsSchemaUrl(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $linked = $this->item(101, 'Fracciones', ['schema:url' => [$this->value('', null, 'uri', 'https://example.org/r')]]);
        $this->save()->save($this->state(['resources' => [['key' => 'l', 'kind' => 'url', 'url' => 'https://example.org/r']]]),
            self::schema(), $linked, 7, 'Ana P');
        $this->assertNotContains(60, $this->updates[0][2]['clear_property_values']);
        $this->assertArrayNotHasKey('schema:url', $this->updates[0][2]);
        $this->assertSame('url', $this->creates[0][1]['o:ingester']);
    }

    public function testAResumeDropsTheTemplateUntilTheStoredReaMeetsItAndSetsItAtTheEnd(): void
    {
        $this->required = ['dcterms:title', 'dcterms:license'];
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones', ['dcterms:title' => [$this->value('Fracciones')]]);
        $existing->method('resourceTemplate')->willReturn(new \stdClass());
        $this->save()->save($this->state(['resources' => []]), self::schema(), $existing, 7, 'Ana P');
        $this->assertNull($this->updates[0][2]['o:resource_template']);
        $this->assertSame([[100, [], ['o:resource_template' => ['o:id' => 12]]]], $this->commits);
    }

    /** Code review of PR #100: once the REA exists, every failure answers its id. */
    public function testAFailureAfterTheCreationAnswersTheId(): void
    {
        $exception = new ValidationException('refused');
        $this->governance->method('apply')->willThrowException($exception);
        $this->assertSame(
            ['ok' => false, 'id' => 100, 'step' => 'governance', 'error' => 'invalid', 'messages' => [],
                'created' => [['key' => 'r1', 'id' => 202]]],
            $this->save()->save($this->state(), self::schema(), null, 7, 'Ana P')
        );
    }

    public function testAnUnexpectedFailureWhileProposingAnswersTheIdAndTheClass(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $this->api = $this->createMock(ApiManager::class);
        $this->api->method('search')->willReturn($this->response([$this->classRep(9)]));
        $this->api->method('create')->willReturn($this->response($this->item(100, 'x')));
        $this->api->method('read')->willThrowException(new \LogicException('boom'));
        $this->assertSame(
            ['ok' => false, 'id' => 100, 'step' => 'propose', 'error' => 'unexpected', 'exception' => 'LogicException',
                'created' => [['key' => 'r1', 'id' => 100]]],
            $this->save()->save($this->state(['propose' => true]), self::schema(), null, 7, 'Ana P')
        );
    }
}
