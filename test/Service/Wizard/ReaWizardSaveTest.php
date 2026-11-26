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
            $this->settings
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
            'resource' => ['kind' => 'upload', 'token' => $this->token()],
            'alignment' => ['lrmi:educationalLevel' => [11], 'schema:about' => [21], 'lrmi:teaches' => [31]],
            'propose' => false,
        ];
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
        $token = $state['resource']['token'];
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
        $this->assertSame([['o:ingester' => 'oermanager_wizard', 'wizard_token' => $token]], $data['o:media']);
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
            ['values' => ['dcterms:title' => ['Solo título']], 'resource' => null, 'alignment' => [], 'propose' => false],
            self::schema(),
            null,
            7,
            'Ana P'
        );
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('o:media', $this->creates[0][1]);
        $this->assertSame([], $this->commits, 'no template while a required field is empty');
        $this->assertSame(
            ['ok' => false, 'error' => 'title_required'],
            $this->save()->save(['values' => ['dcterms:title' => ['  ']]], self::schema(), null, 7, 'Ana P')
        );
    }

    public function testAnExpiredUploadWritesNothing(): void
    {
        $state = $this->state(['resource' => ['kind' => 'upload', 'token' => str_repeat('a', 32)]]);
        $this->assertSame(['ok' => false, 'error' => 'upload_expired'], $this->save()->save($state, self::schema(), null, 7, 'Ana P'));
        $this->assertSame([], $this->creates);
    }

    /** Code review of PR #100: Omeka's `url` ingester downloads and refuses web pages, so a link is a value. */
    public function testALinkIsStoredAsAUriValueNotDownloaded(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $this->save()->save($this->state(['resource' => ['kind' => 'url', 'url' => 'https://ejemplo.es/recursos/matemáticas/']]), self::schema(), null, 7, 'Ana P');
        $data = $this->creates[0][1];
        $this->assertArrayNotHasKey('o:media', $data);
        $this->assertSame([['type' => 'uri', 'property_id' => 60, '@id' => 'https://ejemplo.es/recursos/matemáticas/']], $data['schema:url']);
        foreach (['javascript:alert(1)', 'https://', 'https://x y', 'ftp://example.org/a'] as $bad) {
            $this->assertSame(
                ['ok' => false, 'error' => 'url_invalid'],
                $this->save()->save($this->state(['resource' => ['kind' => 'url', 'url' => $bad]]), self::schema(), null, 7, 'Ana P'),
                $bad
            );
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
                'message' => 'Destinos inválidos para lrmi:teaches: 31'],
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
            'errors' => ['dcterms:license' => 'not-uri']], $result);
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
        $this->assertSame(['ok' => false, 'id' => 100, 'error' => 'incomplete', 'missing' => ['subject']], $result);
        foreach ($this->commits as [, , $data]) {
            $this->assertArrayNotHasKey('curation:status', $data);
        }
    }

    public function testAResumeClearsOnlyTheManagedDataTermsAndReplacesTheMainMedia(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $old = $this->createMock(MediaRepresentation::class);
        $old->method('id')->willReturn(55);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('media')->willReturn([$old]);
        $existing->method('resourceTemplate')->willReturn(null);
        $this->api->expects($this->once())->method('batchDelete')->with('media', [55]);

        $state = $this->state();
        $result = $this->save()->save($state, self::schema(), $existing, 7, 'Ana P');

        $this->assertTrue($result['ok']);
        [$resource, $id, $data, , $options] = $this->updates[0];
        $this->assertSame(['items', 100], [$resource, $id]);
        $this->assertSame([1, 4, 50, 61], $data['clear_property_values'], 'only changed data terms; never provenance');
        $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
        $this->assertArrayNotHasKey('o:is_public', $data);
        $this->assertSame('media', $this->creates[0][0]);
        $this->assertSame(['o:item' => ['o:id' => 100], 'o:ingester' => 'oermanager_wizard',
            'wizard_token' => $state['resource']['token']], $this->creates[0][1]);
    }

    public function testAResumeThatKeepsTheMediaTouchesNoMedia(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones');
        $this->api->expects($this->never())->method('batchDelete');
        $this->save()->save($this->state(['resource' => ['kind' => 'keep']]), self::schema(), $existing, 7, 'Ana P');
        $this->assertSame([], $this->creates);
    }

    public function testAResumeThatNoLongerMeetsTheTemplateDropsIt(): void
    {
        $this->required = ['dcterms:title', 'lrmi:assesses'];
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('resourceTemplate')->willReturn(new \stdClass());
        $this->save()->save($this->state(['resource' => ['kind' => 'keep']]), self::schema(), $existing, 7, 'Ana P');
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
        $state = $this->state(['resource' => ['kind' => 'keep']]);
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
        $this->save()->save($this->state(['resource' => ['kind' => 'keep']]), self::schema(), $existing, 7, 'Ana P');
        $this->assertSame([50, 61], $this->updates[0][2]['clear_property_values']);
        $this->assertArrayNotHasKey('dcterms:title', $this->updates[0][2]);
    }

    public function testAResumeToALinkReplacesTheFilesAndAnUploadClearsTheLink(): void
    {
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $old = $this->createMock(MediaRepresentation::class);
        $old->method('id')->willReturn(55);
        $existing = $this->item(100, 'Fracciones');
        $existing->method('media')->willReturn([$old]);
        $this->api->expects($this->once())->method('batchDelete')->with('media', [55]);
        $this->save()->save($this->state(['resource' => ['kind' => 'url', 'url' => 'https://example.org/r']]), self::schema(), $existing, 7, 'Ana P');
        $this->assertContains(60, $this->updates[0][2]['clear_property_values']);
        $this->assertSame('https://example.org/r', $this->updates[0][2]['schema:url'][0]['@id']);

        $this->updates = [];
        $linked = $this->item(101, 'Fracciones', ['schema:url' => [$this->value('', null, 'uri', 'https://example.org/r')]]);
        $this->save()->save($this->state(), self::schema(), $linked, 7, 'Ana P');
        $this->assertContains(60, $this->updates[0][2]['clear_property_values']);
        $this->assertArrayNotHasKey('schema:url', $this->updates[0][2]);
    }

    /** Code review of PR #100: the template is kept only if the stored REA already meets it. */
    public function testAResumeDropsTheTemplateUntilTheStoredReaMeetsItAndSetsItAtTheEnd(): void
    {
        $this->required = ['dcterms:title', 'dcterms:license'];
        $this->governance->method('apply')->willReturn(['updated' => true]);
        $this->recatalog->method('apply')->willReturn(['updated' => true]);
        $existing = $this->item(100, 'Fracciones', ['dcterms:title' => [$this->value('Fracciones')]]);
        $existing->method('resourceTemplate')->willReturn(new \stdClass());
        $this->save()->save($this->state(['resource' => ['kind' => 'keep']]), self::schema(), $existing, 7, 'Ana P');
        $this->assertNull($this->updates[0][2]['o:resource_template']);
        $this->assertSame([[100, [], ['o:resource_template' => ['o:id' => 12]]]], $this->commits);
    }

    /** Code review of PR #100: once the REA exists, every failure answers its id. */
    public function testAFailureAfterTheCreationAnswersTheId(): void
    {
        $exception = new ValidationException('refused');
        $this->governance->method('apply')->willThrowException($exception);
        $this->assertSame(
            ['ok' => false, 'id' => 100, 'step' => 'governance', 'error' => 'invalid', 'messages' => []],
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
            ['ok' => false, 'id' => 100, 'step' => 'propose', 'error' => 'unexpected', 'exception' => 'LogicException'],
            $this->save()->save($this->state(['propose' => true]), self::schema(), null, 7, 'Ana P')
        );
    }
}
