<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\Curation\CurationWriter;
use OERManager\Service\CurationEvent;
use OERManager\Service\Governance\GovernanceFields;
use OERManager\Service\Governance\LicenceStatus;
use OERManager\Service\Governance\VocabEntries;
use OERManager\Service\GovernanceService;
use OERManager\Service\GovernanceSettings;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class GovernanceServiceTest extends TestCase
{
    use RepresentationFactory;

    private $api;
    private $settings;
    private array $items;
    private array $missingProperties = [];
    private array $writes = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->settings = $this->createMock(Settings::class);
        $this->items = [1 => $this->item(1)];
        $this->api->method('read')->willReturnCallback(function ($resource, $id) {
            if (!isset($this->items[$id])) {
                throw new \RuntimeException('Missing item');
            }
            return $this->response($this->items[$id]);
        });
        $this->api->method('search')->willReturnCallback(function ($resource, $query) {
            return $this->response(in_array(
                $query['term'],
                $this->missingProperties,
                true
            ) ? [] : [$this->item($this->propertyId($query['term']))]);
        });
        $this->api->method('update')->willReturnCallback(function ($resource, $id, $data, $files, $options) {
            $this->assertSame('items', $resource);
            $this->assertSame(['isPartial' => true, 'collectionAction' => 'append'], $options);
            $this->writes[] = $data;
        });
    }

    private function propertyId(string $term): int
    {
        return array_search($term, [
            GovernanceFields::LICENCE, GovernanceFields::CREATOR, GovernanceFields::PUBLISHER,
            GovernanceFields::RIGHTS_HOLDER, GovernanceFields::SOURCE,
            'dcterms:contributor', 'dcterms:modified', 'dcterms:provenance', 'dcterms:replaces',
            'dcterms:description',
        ], true) + 1;
    }

    /** @param list<array<string,string>> $licenceEntries */
    private function makeService(
        ?int $licenceVocabId = null,
        array $licenceEntries = [],
        bool $licenceThrows = false,
        ?int $publisherVocabId = null,
        array $publisherEntries = [],
        bool $publisherThrows = false
    ): GovernanceService {
        $licenceVocab = new VocabEntries($licenceVocabId, function () use ($licenceEntries, $licenceThrows) {
            if ($licenceThrows) {
                throw new \RuntimeException('vocab gone');
            }
            return $licenceEntries;
        });
        $publisherVocab = new VocabEntries($publisherVocabId, function () use ($publisherEntries, $publisherThrows) {
            if ($publisherThrows) {
                throw new \RuntimeException('vocab gone');
            }
            return $publisherEntries;
        });
        return new GovernanceService($this->api, new CurationWriter($this->api), $licenceVocab, $publisherVocab, $this->settings);
    }

    // --- read() ---

    public function testReadReportsCurrentValuesInVocabLicenceAndDefaultRightsHolder(): void
    {
        $this->items[1] = $this->item(1, '', [
            GovernanceFields::LICENCE => [
                $this->value('CC BY 4.0', null, 'customvocab:2', 'https://creativecommons.org/licenses/by/4.0/'),
            ],
            GovernanceFields::CREATOR => [$this->value('Ana'), $this->value('Luis')],
            GovernanceFields::SOURCE => [$this->value('', null, 'uri', 'https://example.org/rea/7')],
        ]);
        $this->settings->method('get')->willReturnCallback(
            fn ($key, $default = null) => GovernanceSettings::DEFAULT_RIGHTS_HOLDER === $key ? 'Consejería' : $default
        );
        $service = $this->makeService(
            licenceVocabId: 2,
            licenceEntries: [['uri' => 'https://creativecommons.org/licenses/by/4.0/', 'label' => 'CC BY 4.0']]
        );

        $result = $service->read($this->items[1]);

        $this->assertSame(
            ['type' => 'customvocab:2', 'uri' => 'https://creativecommons.org/licenses/by/4.0/', 'label' => 'CC BY 4.0'],
            $result['values'][GovernanceFields::LICENCE][0]
        );
        $this->assertSame(['Ana', 'Luis'], array_column($result['values'][GovernanceFields::CREATOR], 'value'));
        $this->assertSame([], $result['values'][GovernanceFields::PUBLISHER]);
        $this->assertSame(
            ['type' => 'uri', 'uri' => 'https://example.org/rea/7'],
            $result['values'][GovernanceFields::SOURCE][0]
        );
        $this->assertSame(LicenceStatus::IN_VOCAB, $result['licenceStatus']);
        $this->assertSame(
            [['uri' => 'https://creativecommons.org/licenses/by/4.0/', 'label' => 'CC BY 4.0']],
            $result['options']['licence']
        );
        $this->assertSame([], $result['options']['publisher']);
        $this->assertSame('Consejería', $result['defaultRightsHolder']);
        $this->assertArrayNotHasKey(GovernanceFields::LICENCE, $result['notices']);
        $this->assertArrayHasKey(GovernanceFields::PUBLISHER, $result['notices']);
    }

    public function testMissingLicenceIsReportedAndUnconfiguredVocabulariesRaiseNotices(): void
    {
        $this->settings->method('get')->willReturn('');
        $service = $this->makeService();

        $result = $service->read($this->items[1]);

        $this->assertSame(LicenceStatus::MISSING, $result['licenceStatus']);
        $this->assertArrayHasKey(GovernanceFields::LICENCE, $result['notices']);
        $this->assertArrayHasKey(GovernanceFields::PUBLISHER, $result['notices']);
        $this->assertSame('', $result['defaultRightsHolder']);
    }

    public function testUnresolvedVocabularyIsUncheckedAndOutsideVocabIsDetected(): void
    {
        $this->items[1] = $this->item(1, '', [
            GovernanceFields::LICENCE => [$this->value('', null, 'uri', 'https://example.org/own-licence')],
        ]);
        $this->settings->method('get')->willReturn('');

        // Vocab id configured but the CustomVocab it points to no longer resolves.
        $broken = $this->makeService(licenceVocabId: 2, licenceThrows: true);
        $brokenResult = $broken->read($this->items[1]);
        $this->assertSame(LicenceStatus::UNCHECKED, $brokenResult['licenceStatus']);
        $this->assertArrayHasKey(GovernanceFields::LICENCE, $brokenResult['notices']);

        // Vocab resolves but the licence is not one of its entries.
        $configured = $this->makeService(
            licenceVocabId: 2,
            licenceEntries: [['uri' => 'https://creativecommons.org/licenses/by/4.0/']]
        );
        $configuredResult = $configured->read($this->items[1]);
        $this->assertSame(LicenceStatus::OUTSIDE_VOCAB, $configuredResult['licenceStatus']);
        $this->assertArrayNotHasKey(GovernanceFields::LICENCE, $configuredResult['notices']);
    }

    // --- apply(): validation and no-op paths ---

    public function testApplyReturnsValidationErrorsWithoutWriting(): void
    {
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::SOURCE => ['not-a-url']], 'Curator');

        $this->assertFalse($result['updated']);
        $this->assertSame(['dcterms:source' => 'not-http-uri'], $result['errors']);
        $this->assertSame([], $this->writes);
    }

    public function testApplySkipsTermsWithoutAResolvedPropertyAndWritesNothing(): void
    {
        $this->missingProperties = [GovernanceFields::CREATOR];
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::CREATOR => ['Ana']], 'Curator');

        $this->assertFalse($result['updated']);
        $this->assertSame([], $result['properties'] ?? []);
        $this->assertSame([], $this->writes);
    }

    public function testApplyIsANoopWhenSubmittedValuesMatchCurrentState(): void
    {
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Ana')]]);
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::CREATOR => ['Ana']], 'Curator');

        $this->assertFalse($result['updated']);
        $this->assertTrue($result['unchanged']);
        $this->assertSame([], $this->writes);
    }

    // --- apply(): writes, vocabulary upgrade and event ---

    public function testApplyUpgradesMatchingValuesToVocabularyTypeAndWritesTypedEvent(): void
    {
        $service = $this->makeService(
            licenceVocabId: 2,
            licenceEntries: [['uri' => 'https://creativecommons.org/licenses/by/4.0/', 'label' => 'CC BY 4.0']],
            publisherVocabId: 3,
            publisherEntries: [['value' => 'ACME']]
        );

        $result = $service->apply(1, [
            GovernanceFields::LICENCE => ['https://creativecommons.org/licenses/by/4.0/'],
            GovernanceFields::PUBLISHER => ['ACME'],
            GovernanceFields::CREATOR => ['Ana'],
            GovernanceFields::SOURCE => ['https://example.org/rea/7'],
        ], 'Curator');

        $this->assertTrue($result['updated']);
        $data = $this->writes[0];

        $licence = $data[GovernanceFields::LICENCE][0];
        $this->assertSame('customvocab:2', $licence['type']);
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $licence['@id']);
        $this->assertSame('CC BY 4.0', $licence['o:label']);

        $publisher = $data[GovernanceFields::PUBLISHER][0];
        $this->assertSame('customvocab:3', $publisher['type']);
        $this->assertSame('ACME', $publisher['@value']);
        $this->assertArrayNotHasKey('@id', $publisher);

        $creator = $data[GovernanceFields::CREATOR][0];
        $this->assertSame('literal', $creator['type']);
        $this->assertSame('Ana', $creator['@value']);

        $source = $data[GovernanceFields::SOURCE][0];
        $this->assertSame('uri', $source['type']);
        $this->assertSame('https://example.org/rea/7', $source['@id']);
        $this->assertArrayNotHasKey('o:label', $source);

        $event = $data['dcterms:provenance'][0];
        $this->assertFalse($event['is_public']);
        $payload = CurationEvent::decode($event['@annotation']['dcterms:replaces'][0]['@value']);
        $this->assertTrue(CurationEvent::isTyped($payload));
        $this->assertSame('governance', CurationEvent::scopeOf($payload));
        $this->assertSame([], $payload['terms'][GovernanceFields::LICENCE]['before']);
        $this->assertSame($licence['type'], $payload['terms'][GovernanceFields::LICENCE]['after'][0]['type']);
    }

    public function testMatchedVocabularyEntryWithoutALabelWritesNoLabel(): void
    {
        $service = $this->makeService(
            licenceVocabId: 2,
            licenceEntries: [['uri' => 'https://creativecommons.org/licenses/by/4.0/']]
        );

        $result = $service->apply(1, [
            GovernanceFields::LICENCE => ['https://creativecommons.org/licenses/by/4.0/'],
        ], 'Curator');

        $this->assertTrue($result['updated']);
        $licence = $this->writes[0][GovernanceFields::LICENCE][0];
        $this->assertSame('customvocab:2', $licence['type']);
        $this->assertArrayNotHasKey('o:label', $licence);
    }

    public function testUnmatchedValuesPassThroughUnchangedAndMissingAuditPropertyStaysPartial(): void
    {
        $this->missingProperties = ['dcterms:replaces'];
        $service = $this->makeService(licenceVocabId: 2, licenceEntries: [['uri' => 'https://example.org/other']]);

        $result = $service->apply(1, [
            GovernanceFields::LICENCE => ['https://example.org/unmatched-licence'],
        ], 'Curator');

        $this->assertTrue($result['updated']);
        $licence = $this->writes[0][GovernanceFields::LICENCE][0];
        $this->assertSame('uri', $licence['type']);
        $this->assertSame('https://example.org/unmatched-licence', $licence['@id']);
        $this->assertArrayNotHasKey('dcterms:provenance', $this->writes[0]);
    }

    public function testClearingAFieldWritesAnEmptyClearWithNoAppendedValues(): void
    {
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Ana')]]);
        $service = $this->makeService();

        $result = $service->apply(1, [GovernanceFields::CREATOR => []], 'Curator');

        $this->assertTrue($result['updated']);
        $this->assertArrayNotHasKey(GovernanceFields::CREATOR, $this->writes[0]);
        $this->assertSame([$this->propertyId(GovernanceFields::CREATOR)], $this->writes[0]['clear_property_values']);
    }

    // --- undoEvent() ---

    public function testUndoEventRejectsStaleStateUnlessForced(): void
    {
        $payload = CurationEvent::buildTyped([
            GovernanceFields::CREATOR => [
                'before' => [['type' => 'literal', 'value' => 'Ana']],
                'after' => [['type' => 'literal', 'value' => 'Ana Actualizada']],
            ],
        ]);
        $event = ['when' => '2026-01-01T00:00:00.000000+00:00', 'payload' => $payload];
        // The item drifted from what the event left: current author differs from `after`.
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Otro autor')]]);
        $service = $this->makeService();

        $result = $service->undoEvent(1, $event, 'Curator');

        $this->assertFalse($result['updated']);
        $this->assertSame('stale', $result['error']);
        $this->assertSame([GovernanceFields::CREATOR], $result['terms']);
        $this->assertSame([], $this->writes);
    }

    public function testUndoEventRestoresBeforeValuesAndRecordsUndoneAt(): void
    {
        $payload = CurationEvent::buildTyped([
            GovernanceFields::LICENCE => [
                'before' => [['type' => 'uri', 'uri' => 'https://creativecommons.org/licenses/by-sa/4.0/']],
                'after' => [['type' => 'uri', 'uri' => 'https://creativecommons.org/licenses/by/4.0/']],
            ],
            GovernanceFields::CREATOR => [
                'before' => [['type' => 'literal', 'value' => 'Ana']],
                'after' => [['type' => 'literal', 'value' => 'Ana Actualizada']],
            ],
        ]);
        $event = ['when' => '2026-01-01T00:00:00.000000+00:00', 'payload' => $payload];
        $this->items[1] = $this->item(1, '', [
            GovernanceFields::LICENCE => [$this->value('', null, 'uri', 'https://creativecommons.org/licenses/by/4.0/')],
            GovernanceFields::CREATOR => [$this->value('Ana Actualizada')],
        ]);
        $service = $this->makeService();

        $result = $service->undoEvent(1, $event, 'Curator');

        $this->assertTrue($result['updated']);
        $this->assertSame('2026-01-01T00:00:00.000000+00:00', $result['undoneAt']);
        $this->assertSame(
            'https://creativecommons.org/licenses/by-sa/4.0/',
            $this->writes[0][GovernanceFields::LICENCE][0]['@id']
        );
        $this->assertSame('Ana', $this->writes[0][GovernanceFields::CREATOR][0]['@value']);
        $undoPayload = CurationEvent::decode(
            $this->writes[0]['dcterms:provenance'][0]['@annotation']['dcterms:replaces'][0]['@value']
        );
        $this->assertSame($event['when'], $undoPayload['undoOf']);
    }

    public function testUndoEventForcedSkipsStaleCheck(): void
    {
        $payload = CurationEvent::buildTyped([
            GovernanceFields::CREATOR => [
                'before' => [['type' => 'literal', 'value' => 'Ana']],
                'after' => [['type' => 'literal', 'value' => 'Ana Actualizada']],
            ],
        ]);
        $event = ['when' => '2026-01-01T00:00:00.000000+00:00', 'payload' => $payload];
        $this->items[1] = $this->item(1, '', [GovernanceFields::CREATOR => [$this->value('Otro autor')]]);
        $service = $this->makeService();

        $result = $service->undoEvent(1, $event, 'Curator', true);

        $this->assertTrue($result['updated']);
        $this->assertSame('Ana', $this->writes[0][GovernanceFields::CREATOR][0]['@value']);
    }
}
