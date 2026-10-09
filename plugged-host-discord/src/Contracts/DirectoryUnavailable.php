<?php

namespace PluggedHost\Discord\Contracts;

use RuntimeException;

/**
 * The account directory or payout service could not answer (down, not configured, or it
 * refused the call). Callers decide whether to retry; nothing was paid.
 */
final class DirectoryUnavailable extends RuntimeException {}
