<?php

namespace Omeka\File;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Stdlib\ErrorStore;

class TempFile
{
    public ?string $sourceName = null;
    public bool $ingested = false;
    public function __construct(private string $tempPath = '')
    {
    }
    public function getTempPath()
    {
        return $this->tempPath;
    }
    public function setSourceName($sourceName)
    {
        $this->sourceName = $sourceName;
    }
    public function mediaIngestFile(Media $media, Request $request, ErrorStore $errorStore)
    {
        $this->ingested = true;
    }
    public function delete()
    {
        @unlink($this->tempPath);
    }
}
