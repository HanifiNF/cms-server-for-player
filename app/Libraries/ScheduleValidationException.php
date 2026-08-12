<?php

namespace App\Libraries;

use RuntimeException;

class ScheduleValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The schedule data is invalid.');
    }
}
