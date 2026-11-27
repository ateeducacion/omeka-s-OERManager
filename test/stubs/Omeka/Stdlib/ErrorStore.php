<?php

namespace Omeka\Stdlib;

class ErrorStore
{
    private array $errors = [];
    public function addError($key, $message)
    {
        $this->errors[$key][] = $message;
    }
    public function getErrors()
    {
        return $this->errors;
    }
    public function hasErrors()
    {
        return (bool) $this->errors;
    }
}
