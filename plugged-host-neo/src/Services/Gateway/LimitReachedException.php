<?php

namespace PluggedHost\Neo\Services\Gateway;

/**
 * The account's 5-hour or weekly window is used up.
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
