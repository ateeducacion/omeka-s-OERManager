<?php

declare(strict_types=1);

namespace OERManager\Test\Service\Wizard;

use OERManager\Service\Wizard\WizardUploadStore;
use PHPUnit\Framework\TestCase;

final class WizardUploadStoreTest extends TestCase
{
    private string $dir;
    private WizardUploadStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_wizard_store_' . bin2hex(random_bytes(6));
        $this->store = new WizardUploadStore($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function tmp(string $content = 'hello'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oerup');
        file_put_contents($path, $content);
        return $path;
    }

    public function testPutThenGetReturnsTheStoredFileForItsOwner(): void
    {
        $token = $this->store->put(7, $this->tmp('abc'), 'clase.elpx', 'application/zip');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $file = $this->store->get(7, $token);
        $this->assertSame('clase.elpx', $file['name']);
        $this->assertSame('application/zip', $file['mediaType']);
        $this->assertSame(3, $file['size']);
        $this->assertSame('abc', file_get_contents($file['path']));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file['path'])), -4));
    }

    public function testTheMovedSourceIsGone(): void
    {
        $tmp = $this->tmp();
        $this->store->put(7, $tmp, 'a.pdf', 'application/pdf');
        $this->assertFileDoesNotExist($tmp);
    }

    public function testAnotherUsersTokenIsNotFound(): void
    {
        $token = $this->store->put(7, $this->tmp(), 'a.pdf', 'application/pdf');
        $this->assertNull($this->store->get(8, $token));
    }

    public function testMalformedTokensAreNotFound(): void
    {
        foreach (['', '../etc/passwd', 'ABC', str_repeat('g', 32)] as $token) {
            $this->assertNull($this->store->get(7, $token), $token);
        }
    }

    public function testForgetRemovesTheFileAndItsMetadata(): void
    {
        $token = $this->store->put(7, $this->tmp(), 'a.pdf', 'application/pdf');
        $this->store->forget($token);
        $this->assertNull($this->store->get(7, $token));
        $this->assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testSweepRemovesOnlyOldUploads(): void
    {
        $old = $this->store->put(7, $this->tmp(), 'old.pdf', 'application/pdf');
        $new = $this->store->put(7, $this->tmp(), 'new.pdf', 'application/pdf');
        foreach (glob($this->dir . '/' . $old . '*') as $file) {
            touch($file, time() - 100000);
        }
        $this->store->sweepOld(86400);
        $this->assertNull($this->store->get(7, $old));
        $this->assertNotNull($this->store->get(7, $new));
    }
}
