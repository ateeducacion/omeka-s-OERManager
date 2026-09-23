<?php

namespace Laminas\Http;

class Response
{
    public int $status = 200;
    public string $content = '';
    public array $headers = [];
    public function setStatusCode($code)
    {
        $this->status = $code;
        return $this;
    }
    public function getStatusCode()
    {
        return $this->status;
    }
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }
    public function getContent()
    {
        return $this->content;
    }
    public function getHeaders()
    {
        return $this;
    }
    public function addHeaderLine($name, $value)
    {
        $this->headers[$name] = $value;
        return $this;
    }
    public function getBody()
    {
        return $this->content;
    }
}
