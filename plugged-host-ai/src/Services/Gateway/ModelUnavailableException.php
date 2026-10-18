<?php

namespace PluggedHost\Ai\Services\Gateway;

/**
 * The model a turn named cannot answer it: model_not_allowed (not on the account's plan; access
 * says which plan it needs), model_unavailable (switched off or gone) or no_model (there is none
 * at all). A turn never moves to another model on its own: the user picks one.
 */
class ModelUnavailableException extends GatewayUnavailableException
{
    public function __construct(public readonly string $reason, public readonly ?string $label = null, public readonly ?string $access = null)
    {
        parent::__construct($reason);
    }
}
