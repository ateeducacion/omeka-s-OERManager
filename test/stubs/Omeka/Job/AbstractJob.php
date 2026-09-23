<?php

namespace Omeka\Job;

abstract class AbstractJob
{
    public $job;
    public $serviceLocator;
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
    public function getArg($name, $default = null)
    {
        return $default;
    }
    public function shouldStop()
    {
        return false;
    }
    public function setArg($name, $value)
    {
    }
}
