<?php

namespace PluggedHost\Ai\Rux\Agent\Ai;

/**
 * Transient: rate limited or overloaded; worth trying again after retryAfter.
 */
class AiBusyException extends AiException {}
