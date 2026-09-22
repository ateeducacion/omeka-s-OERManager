<?php

namespace Laminas\Http;

class Client
{
    public static $nextResponse;
    public static $nextException;
    public static $last;
    public array $record = [];
    public function __construct()
    {
        self::$last = $this;
    }
    public function setUri($value)
    {
        $this->record['uri'] = $value;
    }
    public function setMethod($value)
    {
        $this->record['method'] = $value;
    }
    public function setOptions($value)
    {
        $this->record['options'] = $value;
    }
    public function setHeaders($value)
    {
        $this->record['headers'] = $value;
    }
    public function setRawBody($value)
    {
        $this->record['body'] = $value;
    }
    public function send()
    {
        if (self::$nextException) {
            throw self::$nextException;
        } return self::$nextResponse;
    }
}
