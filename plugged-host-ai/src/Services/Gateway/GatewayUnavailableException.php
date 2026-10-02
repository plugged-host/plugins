<?php

namespace PluggedHost\Ai\Services\Gateway;

/**
 * Every provider failed, the gateway is switched off, misconfigured or unreachable.
 */
class GatewayUnavailableException extends GatewayException {}
