<?php

namespace Laminas\Validator;

class Csrf
{
    public function __construct(array $options = [])
    {
    }
    public function isValid($token)
    {
        return $token === 'valid';
    }
    public function getHash()
    {
        return 'valid';
    }
}
