<?php

namespace Laminas\ServiceManager;

interface ServiceLocatorInterface
{
    public function get($name);
    public function has($name);
}
