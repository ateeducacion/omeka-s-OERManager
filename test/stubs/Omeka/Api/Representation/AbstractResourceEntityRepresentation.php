<?php

namespace Omeka\Api\Representation;

abstract class AbstractResourceEntityRepresentation extends AbstractEntityRepresentation
{
    public function value($term, $options = [])
    {
        return $options['default'] ?? null;
    }
    public function values()
    {
        return [];
    }
    public function resourceClass()
    {
        return null;
    }
    public function resourceTemplate()
    {
        return null;
    }
    public function isPublic()
    {
        return false;
    }
    public function owner()
    {
        return null;
    }
    public function media()
    {
        return [];
    }
}
