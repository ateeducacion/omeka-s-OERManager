<?php

declare(strict_types=1);

namespace OERManager\Test\Media;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Media\Ingester\WizardIngester;
use OERManager\Service\Wizard\WizardUploadStore;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFile;
use Omeka\File\TempFileFactory;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;

/**
 * TASK-077 spike: Omeka's `upload` ingester only takes a file of the same
 * request, so the wizard ingests the file stored in step ① with its own
 * ingester. The container harness ingests a real file through Omeka.
 */
final class WizardIngesterTest extends TestCase
{
    private string $dir;
    private WizardUploadStore $store;
    private ?TempFile $temp = null;
    private ?int $user = 7;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/oer_wizard_ing_' . bin2hex(random_bytes(6));
        $this->store = new WizardUploadStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->temp?->delete();
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function ingester(): WizardIngester
    {
        $factory = new class ($this) extends TempFileFactory {
            public function __construct(private $test)
            {
            }
            public function build()
            {
                return $this->test->built(parent::build());
            }
        };
        return new WizardIngester($this->store, $factory, fn () => $this->user);
    }

    public function built(TempFile $temp): TempFile
    {
        return $this->temp = $temp;
    }

    private function stored(string $content, string $name = 'clase.elpx'): string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'oerup');
        file_put_contents($tmp, $content);
        return $this->store->put(7, $tmp, $name, 'application/zip');
    }

    public function testItIngestsTheStoredFileOfTheCurrentUser(): void
    {
        $token = $this->stored('package-bytes');
        $media = new Media();
        $errors = new ErrorStore();
        $this->ingester()->ingest($media, new Request(['wizard_token' => $token]), $errors);
        $this->assertFalse($errors->hasErrors());
        $this->assertTrue($this->temp->ingested);
        $this->assertSame('package-bytes', file_get_contents($this->temp->getTempPath()));
        $this->assertSame('clase.elpx', $this->temp->sourceName);
        $this->assertSame('clase.elpx', $media->source);
        // The stored copy stays until the save succeeds (ReaWizardSave forgets it).
        $this->assertNotNull($this->store->get(7, $token));
    }

    public function testAnExplicitSourceIsKept(): void
    {
        $media = new Media();
        $this->ingester()->ingest($media, new Request(['wizard_token' => $this->stored('x'), 'o:source' => 'mine']), new ErrorStore());
        $this->assertNull($media->source);
    }

    public function testAnotherUsersOrAMissingTokenIsAnError(): void
    {
        $token = $this->stored('x');
        $this->user = 8;
        foreach ([['wizard_token' => $token], ['wizard_token' => 'bad'], []] as $data) {
            $errors = new ErrorStore();
            $this->ingester()->ingest(new Media(), new Request($data), $errors);
            $this->assertArrayHasKey('wizard_token', $errors->getErrors());
            $this->assertNull($this->temp);
        }
        $this->user = null;
        $errors = new ErrorStore();
        $this->ingester()->ingest(new Media(), new Request(['wizard_token' => $token]), $errors);
        $this->assertTrue($errors->hasErrors());
    }

    public function testItDescribesItselfAndRendersAsAFile(): void
    {
        $ingester = $this->ingester();
        $this->assertSame('file', $ingester->getRenderer());
        $this->assertNotSame('', $ingester->getLabel());
        $this->assertStringContainsString('asistente', $ingester->form(new PhpRenderer()));
    }
}
