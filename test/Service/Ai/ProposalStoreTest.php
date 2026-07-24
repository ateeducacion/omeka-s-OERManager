<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Ai;

use OERManager\Service\Ai\ProposalStore;
use PHPUnit\Framework\TestCase;

final class ProposalStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_store_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(42, ['status' => 'completed', 'payload' => ['alignment' => ['schema:about' => [7]]]]);

        $this->assertSame(
            ['status' => 'completed', 'payload' => ['alignment' => ['schema:about' => [7]]]],
            $store->read(42)
        );
    }

    public function testReadMissingReturnsNull(): void
    {
        $this->assertNull((new ProposalStore($this->dir))->read(999));
    }

    public function testReadIsIdempotentDoesNotDelete(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'in_progress', 'step' => 'Destilando', 'done' => 2, 'total' => 5]);
        $store->read(1);
        $this->assertNotNull($store->read(1), 'read no debe borrar: la recuperación tras abandono depende de ello');
    }

    public function testWriteOverwritesPreviousState(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'in_progress', 'done' => 1, 'total' => 5]);
        $store->write(1, ['status' => 'completed', 'payload' => []]);
        $this->assertSame('completed', $store->read(1)['status']);
    }

    public function testSweepOldDeletesOnlyExpired(): void
    {
        $store = new ProposalStore($this->dir);
        $store->write(1, ['status' => 'completed']);
        // Envejecer el fichero del job 1 a 2 horas atrás.
        touch($this->dir . '/1.json', time() - 7200);
        $store->write(2, ['status' => 'completed']);

        $store->sweepOld(3600); // TTL 1 hora

        $this->assertNull($store->read(1), 'el viejo se barre');
        $this->assertNotNull($store->read(2), 'el reciente se conserva');
    }
}
