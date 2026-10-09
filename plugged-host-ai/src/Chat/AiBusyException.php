<?php

namespace PluggedHost\Ai\Chat;

/**
 * Transient: rate limited or overloaded; worth trying again after retryAfter.
 */
class AiBusyException extends AiException {}
