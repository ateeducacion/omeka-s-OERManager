<?php

namespace Laminas\EventManager;

interface SharedEventManagerInterface
{
    public function attach($id, $event, $callback);
}
