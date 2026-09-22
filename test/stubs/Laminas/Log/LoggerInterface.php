<?php

namespace Laminas\Log;

interface LoggerInterface
{
    public function err($message);
    public function warn($message);
    public function info($message);
    public function debug($message);
}
