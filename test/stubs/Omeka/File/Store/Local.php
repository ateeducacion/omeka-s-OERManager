<?php

namespace Omeka\File\Store;

class Local implements StoreInterface
{
    public function getLocalPath($path)
    {
        return $path;
    }
}
