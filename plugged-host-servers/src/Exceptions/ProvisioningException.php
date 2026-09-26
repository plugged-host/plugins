<?php

namespace PluggedHost\Servers\Exceptions;

use RuntimeException;

/**
 * A server could not be created or resized. $reason is a stable code the caller turns into
 * its own message (HTTP error, notification, neo tool result).
 */
class ProvisioningException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
