<?php

namespace Omeka\Api\Representation;

class ValueRepresentation
{
    public function type()
    {
        return 'literal';
    }
    public function value()
    {
        return '';
    }
    public function valueResource()
    {
        return null;
    }
    public function valueAnnotation()
    {
        return null;
    }
    public function uri()
    {
        return null;
    }
    public function property()
    {
        return null;
    }
    public function lang()
    {
        return null;
    }
    public function isPublic()
    {
        return true;
    }
    public function __toString()
    {
        return (string) $this->value();
    }
}
