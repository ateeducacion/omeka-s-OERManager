<?php

namespace Laminas\EventManager;

class Event
{
    private array $params = [];
    private $target;
    public function getParam($key, $default = null)
    {
        return $this->params[$key] ?? $default;
    }
    public function setParam($key, $value)
    {
        $this->params[$key] = $value;
        return $this;
    }
    public function setTarget($target)
    {
        $this->target = $target;
        return $this;
    }
    public function getTarget()
    {
        return $this->target;
    }
}
