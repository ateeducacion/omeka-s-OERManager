<?php

namespace Laminas\View\Renderer;

class PhpRenderer
{
    public array $plugins = [];
    public function plugin($name)
    {
        return $this->plugins[$name] ?? fn ($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    public function translate($value)
    {
        return $value;
    }
    public function partial($name, $args = [])
    {
        return $name;
    }
    public function url($route, $params = [])
    {
        return $route . '/' . ($params['action'] ?? '');
    }
}
