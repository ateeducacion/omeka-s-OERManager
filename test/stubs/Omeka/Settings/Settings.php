<?php

namespace Omeka\Settings;

class Settings
{
    public array $values = [];
    public function get($key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }
    public function set($key, $value)
    {
        $this->values[$key] = $value;
    }
    public function delete($key)
    {
        unset($this->values[$key]);
    }
}
