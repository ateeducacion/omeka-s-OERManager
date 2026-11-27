<?php

namespace Omeka\Api\Exception;

use Omeka\Stdlib\ErrorStore;

class ValidationException extends \RuntimeException
{
    private ?ErrorStore $errorStore = null;
    public function setErrorStore(ErrorStore $errorStore)
    {
        $this->errorStore = $errorStore;
    }
    public function getErrorStore()
    {
        return $this->errorStore ?? new ErrorStore();
    }
}
