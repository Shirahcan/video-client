<?php

namespace Shirahcan\VideoClient\Exceptions;

use RuntimeException;

/**
 * Base for every video-service failure. Each subclass is a different REMEDY, which is
 * why they are separate types: an outage is retried, a stranded room is repaired, an
 * over-budget refusal needs a human, a missing room is a bug in the caller.
 */
class VideoServiceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
