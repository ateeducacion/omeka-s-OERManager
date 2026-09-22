<?php

namespace Omeka\Api\Representation;

class JobRepresentation extends AbstractResourceEntityRepresentation
{
    public function status()
    {
        return "completed";
    }

    public function log()
    {
        return "";
    }

    public function args()
    {
        return [];
    }

    public function getEntity()
    {
        return null;
    }
}
