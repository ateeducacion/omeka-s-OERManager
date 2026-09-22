<?php

namespace Omeka\Api\Representation;

abstract class AbstractEntityRepresentation implements \JsonSerializable
{
    public function id()
    {
        return 1;
    }
    public function displayTitle($default = null)
    {
        return 'Item';
    }
    public function userIsAllowed($action)
    {
        return true;
    }
    public function jsonSerialize(): array
    {
        return [];
    }
}
