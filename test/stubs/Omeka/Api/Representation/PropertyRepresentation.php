<?php

namespace Omeka\Api\Representation;

class PropertyRepresentation extends AbstractResourceEntityRepresentation
{
    public function term()
    {
        return "dcterms:title";
    }
}
