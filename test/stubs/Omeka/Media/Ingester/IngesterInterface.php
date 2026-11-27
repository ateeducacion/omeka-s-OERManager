<?php

namespace Omeka\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Stdlib\ErrorStore;

interface IngesterInterface
{
    public function getLabel();
    public function getRenderer();
    public function ingest(Media $media, Request $request, ErrorStore $errorStore);
    public function form(PhpRenderer $view, array $options = []);
}
