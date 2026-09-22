<?php

namespace Omeka\Api;

class Response
{
    public function __construct(private $content = null, private $total = 0)
    {
    }
    public function getContent()
    {
        return $this->content;
    }
    public function getTotalResults()
    {
        return $this->total;
    }
}
