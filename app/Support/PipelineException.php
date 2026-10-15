<?php

namespace App\Support;

use RuntimeException;

/** A refused custom-order pipeline action; the message is safe to show an admin. */
class PipelineException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $stale = false)
    {
        parent::__construct($message);
    }
}
