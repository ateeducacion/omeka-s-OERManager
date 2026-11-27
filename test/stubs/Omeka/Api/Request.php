<?php

namespace Omeka\Api;

class Request
{
    public function __construct(private array $content = [])
    {
    }
    public function getContent()
    {
        return $this->content;
    }
}
