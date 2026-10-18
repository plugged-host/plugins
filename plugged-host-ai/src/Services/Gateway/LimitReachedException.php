<?php

namespace PluggedHost\Ai\Services\Gateway;

/**
 * A usage window is used up: the account's 5-hour or weekly one (short, long), or the weekly
 * window of the limited model the turn asked for (model).
 */
class LimitReachedException extends GatewayException
{
    /**
     * @param  array<string, mixed>  $limits
     */
    public function __construct(public readonly string $window, public readonly ?string $retryAt, public readonly array $limits = [])
    {
        parent::__construct('limit_reached');
    }
}
