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
    public array $stylesheets = [];
    public function partial($name, $args = [])
    {
        return $name . (isset($args['badge']) ? '[' . $args['badge'] . ']' : '');
    }
    public function headLink()
    {
        $view = $this;
        return new class ($view) {
            public function __construct(private $view)
            {
            }
            public function appendStylesheet($href)
            {
                $this->view->stylesheets[] = $href;
                return $this;
            }
        };
    }
    public function assetUrl($file, $module = null)
    {
        return $module . '/' . $file;
    }
    public function url($route, $params = [])
    {
        return $route . '/' . ($params['action'] ?? '');
    }
}
