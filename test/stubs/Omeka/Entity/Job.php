<?php

namespace Omeka\Entity;

class Job
{
    public function getId()
    {
        return 1;
    }
    public function getArgs()
    {
        return [];
    }
    public function setArgs($args)
    {
    }
    public function getStatus()
    {
        return 'in_progress';
    }
    public function setStatus($status)
    {
    }
    public function getOwner()
    {
        return null;
    }
    public function setData($data)
    {
    }

    public function getData()
    {
        return [];
    }
}
