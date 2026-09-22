<?php

namespace Omeka\Api\Representation;

class ResourceClassRepresentation extends AbstractResourceEntityRepresentation
{
    public function term()
    {
        return "lrmi:LearningResource";
    }
}
