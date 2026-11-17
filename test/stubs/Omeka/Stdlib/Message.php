<?php

namespace Omeka\Stdlib;

class Message
{
    private array $args;

    public function __construct(private $message, ...$args)
    {
        $this->args = $args;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function getArgs()
    {
        return $this->args;
    }

    public function __toString()
    {
        return (string) sprintf($this->message, ...$this->args);
    }
}
