<?php

namespace PluggedHost\Ai\Services\Gateway;

use RuntimeException;

/**
 * Base for everything that can go wrong in a neo turn on its backend (NeoBackend: this panel,
 * or noprofit.space's neo gateway).
 */
class GatewayException extends RuntimeException
{
    public function __construct(string $message = '', public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
