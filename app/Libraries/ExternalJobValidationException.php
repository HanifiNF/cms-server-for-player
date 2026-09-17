<?php
namespace App\Libraries;
class ExternalJobValidationException extends \RuntimeException
{
    public function __construct(public array $fields)
    {
        parent::__construct((string) reset($fields));
    }
}
