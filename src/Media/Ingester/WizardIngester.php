<?php

declare(strict_types=1);

namespace OERManager\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use OERManager\Service\Wizard\WizardUploadStore;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Ingests the file uploaded in the wizard's step ① (TASK-077, spike of the
 * plan). Omeka's `upload` ingester runs `RenameUpload`, which only accepts a
 * file uploaded in the same request, so the wizard stores the file under a
 * token and this ingester turns it into an Omeka temp file at `save`.
 *
 * `mediaIngestFile()` applies Omeka's own validator (allowed media types and
 * extensions) and stores the original and its thumbnails, as for an upload.
 * A token is only good for the user who uploaded it.
 */
final class WizardIngester implements IngesterInterface
{
    public const NAME = 'oermanager_wizard';
    public const TOKEN_KEY = 'wizard_token';

    /** @var \Closure(): ?int */
    private \Closure $currentUserId;

    /** @param callable(): ?int $currentUserId */
    public function __construct(
        private readonly WizardUploadStore $store,
        private readonly TempFileFactory $tempFileFactory,
        callable $currentUserId
    ) {
        $this->currentUserId = \Closure::fromCallable($currentUserId);
    }

    public function getLabel()
    {
        return 'Fichero subido con el asistente de REA'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        $userId = ($this->currentUserId)();
        $file = null === $userId ? null : $this->store->get($userId, (string) ($data[self::TOKEN_KEY] ?? ''));
        if (null === $file) {
            $errorStore->addError(
                self::TOKEN_KEY,
                'El fichero subido ya no está disponible. Vuelve a subirlo.' // @translate
            );
            return;
        }
        $tempFile = $this->tempFileFactory->build();
        if (!@copy($file['path'], $tempFile->getTempPath())) {
            $tempFile->delete();
            $errorStore->addError(self::TOKEN_KEY, 'No se pudo preparar el fichero subido.'); // @translate
            return;
        }
        $tempFile->setSourceName($file['name']);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($file['name']);
        }
        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return '<p>' . $view->translate(
            'Este tipo de medio solo se usa desde el asistente para añadir REA.' // @translate
        ) . '</p>';
    }
}
