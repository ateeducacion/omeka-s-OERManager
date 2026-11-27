<?php

namespace Omeka\File;

class TempFileFactory
{
    public function build()
    {
        return new TempFile((string) tempnam(sys_get_temp_dir(), 'omeka'));
    }
}
