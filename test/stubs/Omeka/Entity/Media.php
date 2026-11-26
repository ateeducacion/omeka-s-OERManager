<?php

namespace Omeka\Entity;

class Media
{
    public ?string $source = null;
    public function setSource($source)
    {
        $this->source = $source;
    }
}
