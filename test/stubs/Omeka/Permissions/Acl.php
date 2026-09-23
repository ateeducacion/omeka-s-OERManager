<?php

namespace Omeka\Permissions;

class Acl
{
    public function allow($roles, $resources, $privileges)
    {
    }
    public function userIsAllowed($resource, $privilege)
    {
        return true;
    }
}
