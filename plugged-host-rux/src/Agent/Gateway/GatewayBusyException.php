<?php

namespace PluggedHost\Rux\Agent\Gateway;

/**
 * Transient: another turn for the same account is running, or the gateway is at capacity.
 */
class GatewayBusyException extends GatewayException {}
