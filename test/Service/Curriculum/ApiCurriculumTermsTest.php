<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Curriculum;

use OERManager\Service\Curriculum\ApiCurriculumTerms;
use OERManager\Service\Curriculum\CurModel;
use OERManager\Service\Curriculum\CurriculumTerms;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

final class ApiCurriculumTermsTest extends TestCase
{
    use RepresentationFactory;

    private const BASE = 'https://example.org/curriculo/';

    private $api;
    private Settings $settings;
    private array $sets;
    private array $searches = [];
    private array $reads = [];

    protected function setUp(): void
    {
        $this->api = $this->createMock(Manager::class);
        $this->settings = new Settings();
        $this->sets = [
            46259 => $this->set(46259, self::BASE . 'id/curriculo'),
            46260 => $this->set(46260, self::BASE . 'id/curriculo/retirados'),
            7 => $this->set(7, 'https://example.org/other'),
        ];
        $this->api->method('read')->willReturnCallback(function ($resource, $id) {
            $this->assertSame('item_sets', $resource);
            $this->reads[] = $id;
            if (!isset($this->sets[$id])) {
                throw new \RuntimeException('missing');
            }
            return $this->response($this->sets[$id]);
        });
        $this->api->method('search')->willReturnCallback(function ($resource, $query) {
            $this->searches[] = [$resource, $query];
            if ('resource_classes' === $resource) {
                return $this->response('cur:Curso' === $query['term'] ? [$this->item(4764)] : []);
            }
            if ($query === CurModel::schemeQuery()) {
                return $this->response([$this->sets[46260], $this->sets[46259]]);
            }
            if ($query === CurModel::retiredSetQuery(self::BASE . 'id/curriculo')) {
                return $this->response([$this->sets[46260]]);
            }
            return $this->response([]);
        });
    }

    private function set(int $id, string $uri)
    {
        return $this->item($id, 'Set ' . $id, ['dcterms:identifier' => [$this->value('', null, 'uri', $uri)]]);
    }

    private function terms(): CurriculumTerms
    {
        return new ApiCurriculumTerms($this->api, $this->settings);
    }

    public function testConfiguredSchemeIsValidatedAndCachedForTheRequest(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, '46259');
        $terms = $this->terms();
        $this->assertSame(46259, $terms->schemeId());
        $this->assertSame(46259, $terms->schemeId());
        $this->assertSame([46259], $this->reads);
        $this->assertSame([], $this->searches);
    }

    public function testInvalidOrMissingConfigurationFallsBackToDetection(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 7);
        $this->assertSame(46259, $this->terms()->schemeId());
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 999);
        $this->assertSame(46259, $this->terms()->schemeId());
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, null);
        $this->assertSame(46259, $this->terms()->schemeId());
    }

    public function testIsSchemeRequiresTheCurriculumIdentifier(): void
    {
        $terms = $this->terms();
        $this->assertTrue($terms->isScheme(46259));
        $this->assertFalse($terms->isScheme(46260));
        $this->assertFalse($terms->isScheme(7));
        $this->assertFalse($terms->isScheme(999));
        $this->assertFalse($terms->isScheme(0));
    }

    public function testDetectionRefusesAnAmbiguousOrMissingScheme(): void
    {
        $this->sets[8] = $this->set(8, 'https://other.org/id/curriculo');
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturnOnConsecutiveCalls(
            $this->response([$this->sets[46259], $this->sets[8]]),
            $this->response([]),
            $this->response([$this->sets[46259], $this->sets[46259]])
        );
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
        $this->assertSame(46259, (new ApiCurriculumTerms($api, $this->settings))->detectSchemeId());
    }

    public function testRetiredSetIsResolvedByTheSchemeUri(): void
    {
        $this->settings->set(CurriculumTerms::SCHEME_SETTING, 46259);
        $terms = $this->terms();
        $this->assertSame(46260, $terms->retiredSetId());
        $this->assertSame(46260, $terms->retiredSetId());
        $this->assertCount(1, $this->searches);
    }

    public function testNoSchemeMeansNoRetiredSet(): void
    {
        $api = $this->createMock(Manager::class);
        $api->method('search')->willReturn($this->response([]));
        $this->assertNull((new ApiCurriculumTerms($api, $this->settings))->retiredSetId());
    }

    public function testClassIdsAreResolvedByTermOnce(): void
    {
        $terms = $this->terms();
        $this->assertSame(4764, $terms->classId('cur:Curso'));
        $this->assertSame(4764, $terms->classId('cur:Curso'));
        $this->assertNull($terms->classId('cur:Nada'));
        $this->assertNull($terms->classId('cur:Nada'));
        $this->assertSame(
            [['resource_classes', CurModel::classQuery('cur:Curso')], ['resource_classes', CurModel::classQuery('cur:Nada')]],
            $this->searches
        );
    }
}
