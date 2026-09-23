<?php

namespace Laminas\View\Model;

class ViewModel
{
    private array $variables;
    public function setTerminal($terminal)
    {
        return $this;
    }
    public function setVariables($variables)
    {
        $this->variables = $variables;
        return $this;
    }
    private string $template = '';
    public function __construct(array $variables = [])
    {
        $this->variables = $variables;
    }
    public function setVariable($name, $value)
    {
        $this->variables[$name] = $value;
        return $this;
    }
    public function getVariable($name, $default = null)
    {
        return $this->variables[$name] ?? $default;
    }
    public function getVariables()
    {
        return $this->variables;
    }
    public function setTemplate($template)
    {
        $this->template = $template;
        return $this;
    }
    public function getTemplate()
    {
        return $this->template;
    }
}
