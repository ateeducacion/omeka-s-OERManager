<?php

namespace Omeka\Api\Representation;

class ResourceTemplatePropertyRepresentation extends AbstractResourceEntityRepresentation
{
    public function isRequired()
    {
        return false;
    }

    public function property()
    {
        return null;
    }
}
