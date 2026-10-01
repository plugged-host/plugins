<?php

namespace PluggedHost\Rux\Agent\Gateway;

use RuntimeException;

/**
 * Base for everything that can go wrong talking to noprofit.space's neo gateway (copied from
 * plugged-host-neo, so rux does not depend on that plugin being installed).
 */
class GatewayException extends RuntimeException
{
    public function __construct(string $message = '', public readonly int $retryAfter = 0)
    {
        parent::__construct($message);
    }
}
