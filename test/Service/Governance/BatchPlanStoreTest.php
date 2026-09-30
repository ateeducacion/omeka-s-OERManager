<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Governance;

use OERManager\Service\Governance\BatchPlan;
use OERManager\Service\Governance\BatchPlanStore;
use OERManager\Service\Governance\BatchRequest;
use PHPUnit\Framework\TestCase;

final class BatchPlanStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_batch_plans_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function plan(int $owner = 3): BatchPlan
    {
        return new BatchPlan($owner, [1, 2], ['dcterms:creator' => ['Ana']], BatchRequest::MODE_FILL);
    }

    public function testATokenCanBeTakenOnceByItsOwner(): void
    {
        $store = new BatchPlanStore($this->dir);
        $token = $store->put($this->plan());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertNull($store->take($token, 99), 'another user cannot take it');
        $this->assertSame([1, 2], $store->take($token, 3)->ids);
        $this->assertNull($store->take($token, 3), 'one-shot');
    }

    public function testAnotherUsersAttemptDoesNotBurnTheToken(): void
    {
        $store = new BatchPlanStore($this->dir);
        $token = $store->put($this->plan());

        $store->take($token, 99);

        $this->assertNotNull($store->take($token, 3));
    }

    public function testExpiredPlansAreRefusedAndSwept(): void
    {
        $store = new BatchPlanStore($this->dir, 60);
        $token = $store->put($this->plan());
        touch($this->dir . '/' . $token . '.json', time() - 120);

        $this->assertNull($store->take($token, 3));

        $other = $store->put($this->plan());
        touch($this->dir . '/' . $other . '.json', time() - 120);
        $store->sweepOld();
        $this->assertFileDoesNotExist($this->dir . '/' . $other . '.json');
    }

    public function testMalformedTokensNeverReachTheFilesystem(): void
    {
        $store = new BatchPlanStore($this->dir);
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir . '/../oer_victim.json', '{}');

        foreach (['', '../oer_victim', 'ABC', str_repeat('g', 32), str_repeat('a', 31), str_repeat('a', 32) . "\n"] as $token) {
            $this->assertNull($store->take($token, 3), var_export($token, true));
        }
        $this->assertFileExists($this->dir . '/../oer_victim.json');
        unlink($this->dir . '/../oer_victim.json');
    }

    public function testCorruptedPlanDataIsRejectedWithoutReturning(): void
    {
        $store = new BatchPlanStore($this->dir);
        mkdir($this->dir, 0700, true);

        // Write a corrupted plan with bad ids directly to disk
        $token = bin2hex(random_bytes(16));
        $corruptedData = [
            'owner' => 3,
            'ids' => ['abc'],  // Should be integers > 0
            'raw' => ['dcterms:creator' => ['Ana']],
            'mode' => BatchRequest::MODE_FILL
        ];
        file_put_contents(
            $this->dir . '/' . $token . '.json',
            (string) json_encode($corruptedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // take() should return null without returning corrupted plan
        $result = $store->take($token, 3);
        $this->assertNull($result);
    }
}
