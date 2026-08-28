<?php

namespace App\Security\Parsing;

use RuntimeException;

final class ResourceLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The input exceeds a configured processing limit.');
    }
}
