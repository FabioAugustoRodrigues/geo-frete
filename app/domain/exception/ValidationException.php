<?php

namespace app\domain\exception;

use InvalidArgumentException;

class ValidationException extends InvalidArgumentException
{
    /**
     * @param string[] $errors every problem found, so the client can fix them all at once
     */
    public function __construct(private array $errors)
    {
        parent::__construct('Invalid data.');
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
