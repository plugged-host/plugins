<?php

namespace PluggedHost\Neo\Services\Gateway;

/**
 * Transient: another turn for the same account is running, or the gateway is at capacity.
 */
class GatewayBusyException extends GatewayException {}
