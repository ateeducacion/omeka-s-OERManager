<?php

namespace Laminas\Session;

class Container
{
    private static array $values = [];
    public function __construct(private string $name)
    {
    }
    public function __get($key)
    {
        return self::$values[$this->name][$key] ?? null;
    }
    public function __set($key, $value)
    {
        self::$values[$this->name][$key] = $value;
    }
    public function __isset($key)
    {
        return isset(self::$values[$this->name][$key]);
    }
}
