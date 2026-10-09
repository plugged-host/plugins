<?php

namespace PluggedHost\Ai\Chat;

use RuntimeException;

/**
 * The AI could not answer. retryAfter is how long the provider asked us to wait (seconds).
 */
class AiException extends RuntimeException
{
    public function __construct(string $message = '', public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
