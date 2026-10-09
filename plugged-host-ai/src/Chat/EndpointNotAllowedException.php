<?php

namespace PluggedHost\Ai\Chat;

use RuntimeException;

/**
 * A provider endpoint that may not be called: not https, or not on the public internet.
 */
class EndpointNotAllowedException extends RuntimeException {}
