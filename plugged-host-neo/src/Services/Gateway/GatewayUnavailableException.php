<?php

namespace PluggedHost\Neo\Services\Gateway;

/**
 * Every provider failed, the gateway is switched off, misconfigured or unreachable.
 */
class GatewayUnavailableException extends GatewayException {}
