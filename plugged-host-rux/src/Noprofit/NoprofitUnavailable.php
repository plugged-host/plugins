<?php

namespace PluggedHost\Rux\Noprofit;

use RuntimeException;

/**
 * noprofit.space could not answer (down, misconfigured, or it refused the call).
 */
final class NoprofitUnavailable extends RuntimeException {}
