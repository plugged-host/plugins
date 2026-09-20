<?php

namespace PluggedHost\Core\Exceptions;

use Exception;

/**
 * Thrown when an OAuth callback tries to create a brand-new panel account while
 * OAuth registration is switched off in the plugged.host settings.
 *
 * Upstream's OAuthController already wraps its UserCreationService call in a
 * try/catch that reports the exception and returns the generic "No linked User
 * found" redirect, so throwing from GuardedUserCreationService produces exactly
 * the response the old in-core guard produced -- plus a log line recording the
 * blocked attempt, which the in-core guard never gave us.
 */
class OAuthRegistrationDisabledException extends Exception
{
}
