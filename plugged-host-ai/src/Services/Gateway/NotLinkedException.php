<?php

namespace PluggedHost\Ai\Services\Gateway;

/**
 * The backend does not know which account this panel user belongs to yet (only noprofit.space's
 * gateway says so: NeoBackend::activationUrl() is where they connect it).
 */
class NotLinkedException extends GatewayException {}
