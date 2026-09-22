<?php

namespace Omeka\Module;

abstract class AbstractModule
{
    protected $serviceLocator;
    public function setServiceLocator($services)
    {
        $this->serviceLocator = $services;
    }
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
    public function onBootstrap(\Laminas\Mvc\MvcEvent $event): void
    {
    }
}
