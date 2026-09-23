<?php

namespace Laminas\Mvc\Controller;

abstract class AbstractActionController
{
    public array $plugins = [];
    public function __call($name, $args)
    {
        return $this->plugins[$name];
    }
    public function getRequest()
    {
        return $this->plugins['request'];
    }
    public function getResponse()
    {
        return $this->plugins['response'];
    }
    public function plugin($name)
    {
        return $this->plugins[$name];
    }
}
