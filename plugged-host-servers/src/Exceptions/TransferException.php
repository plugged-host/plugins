<?php

namespace PluggedHost\Servers\Exceptions;

use RuntimeException;

/**
 * Why a move cannot start. `$reason` is a key under strings.transfers.blocked, `$params` its
 * replacements; callers turn it into their own message.
 */
class TransferException extends RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
