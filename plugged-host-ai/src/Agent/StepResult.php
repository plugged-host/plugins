<?php

namespace PluggedHost\Ai\Agent;

/**
 * What one call into the agent did, and what the browser should do next. `continue` and
 * `busy` mean "call step() again" (after retry_after seconds for busy); everything else ends
 * the loop until the user acts.
 */
final class StepResult
{
    public const CONTINUE = 'continue';

    public const BUSY = 'busy';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const DONE = 'done';

    public const LIMITED = 'limited';

    public const UNLINKED = 'unlinked';

    public const ERROR = 'error';

    public const BLOCKED = 'blocked';

    public const REDIRECT = 'redirect';

    private function __construct(
        public readonly string $state,
        public readonly ?string $message = null,
        public readonly int $retryAfter = 0,
        public readonly ?string $redirectUrl = null,
    ) {}

    public static function continue(): self
    {
        return new self(self::CONTINUE);
    }

    public static function busy(int $retryAfter = 2): self
    {
        return new self(self::BUSY, retryAfter: max(1, $retryAfter));
    }

    public static function of(string $state, ?string $message = null): self
    {
        return new self($state, $message);
    }

    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, redirectUrl: $url);
    }

    /**
     * @return array{state: string, message: ?string, retry_after: int}
     */
    public function toArray(): array
    {
        return ['state' => $this->state, 'message' => $this->message, 'retry_after' => $this->retryAfter];
    }
}
