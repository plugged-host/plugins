<?php

namespace PluggedHost\Devbox\Services;

use RuntimeException;

/**
 * A devbox action that can't happen, with a short machine reason the UI turns into a sentence
 * (plugged-host-devbox::strings.errors.<reason>).
 */
class DevboxException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("devbox: {$reason}");
    }
}
