<?php

declare(strict_types=1);

namespace OERManager\Test\Service;

use OERManager\Service\ReaSite;
use OERManager\Test\Support\RepresentationFactory;
use Omeka\Api\Manager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * TASK-067 (ADR-0023 §1.4): every REA belongs to the REA site. Membership is
 * one id-only search per request, never a query per row.
 */
final class ReaSiteTest extends TestCase
{
    use RepresentationFactory;

    public function testUnconfiguredSiteIsNotEvaluated(): void
    {
        $api = $this->createMock(Manager::class);
        $api->expects($this->never())->method('search');
        $site = new ReaSite($api, new Settings());
        $this->assertNull($site->siteId());
        $this->assertNull($site->contains(5));
    }

    public function testMembershipIsOneScalarSearchPerRequest(): void
    {
        $settings = new Settings();
        $settings->set(ReaSite::SETTING, '1');
        $api = $this->createMock(Manager::class);
        $api->expects($this->once())->method('read')->with('sites', 1)->willReturn($this->response($this->item(1)));
        $api->expects($this->once())->method('search')
            ->with('items', ['site_id' => 1], ['returnScalar' => 'id'])
            ->willReturn($this->response([5 => 5, 6 => 6]));
        $site = new ReaSite($api, $settings);
        $this->assertSame(1, $site->siteId());
        $this->assertTrue($site->contains(5));
        $this->assertTrue($site->contains(6));
        $this->assertFalse($site->contains(7));
    }

    public function testADeletedSiteIsNotEvaluated(): void
    {
        $settings = new Settings();
        $settings->set(ReaSite::SETTING, 9);
        $api = $this->createMock(Manager::class);
        $api->method('read')->willThrowException(new \RuntimeException('gone'));
        $api->expects($this->never())->method('search');
        $site = new ReaSite($api, $settings);
        $this->assertFalse($site->exists(9));
        $this->assertNull($site->contains(5));
        $this->assertNull($site->contains(6));
    }
}
