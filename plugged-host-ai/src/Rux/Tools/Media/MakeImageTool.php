<?php

namespace PluggedHost\Ai\Rux\Tools\Media;

use Carbon\CarbonImmutable;
use PluggedHost\Ai\Contracts\NeoBackend;
use PluggedHost\Ai\Discord\DiscordException;
use PluggedHost\Ai\Rux\Discord\RuxDiscord;
use PluggedHost\Ai\Rux\Support\RuxAccess;
use PluggedHost\Ai\Rux\Tools\RuxContext;
use PluggedHost\Ai\Rux\Tools\RuxTool;
use PluggedHost\Ai\Rux\Tools\ToolResult;
use PluggedHost\Ai\Services\Gateway\GatewayException;
use PluggedHost\Ai\Services\Gateway\LimitReachedException;
use PluggedHost\Ai\Services\Gateway\ModelUnavailableException;

/**
 * A picture from neo's image model (noprofit.space's "image" model), posted as a reply to the
 * order. It counts against the image model's daily images of whoever ordered it.
 */
final class MakeImageTool extends RuxTool
{
    private const MAX_PROMPT = 1500;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly NeoBackend $backend,
        private readonly RuxDiscord $discord,
    ) {}

    public function name(): string
    {
        return 'make_image';
    }

    public function description(): string
    {
        return 'Make a picture with the image model and post it as a reply to this order. Use it when you are asked to make, draw or generate an image. Write the prompt in English, describing the picture in detail (subject, style, setting, light). Afterwards answer in one short line or not at all: the picture is already posted.';
    }

    public function level(): string
    {
        return RuxAccess::MODERATOR;
    }

    public function parameters(): array
    {
        return $this->schema([
            'prompt' => ['type' => 'string', 'description' => 'What the picture shows, in English, up to ' . self::MAX_PROMPT . ' characters.'],
        ], ['prompt']);
    }

    public function execute(RuxContext $context, array $arguments): ToolResult
    {
        $prompt = mb_substr($this->required($arguments, 'prompt'), 0, self::MAX_PROMPT);

        try {
            $this->discord->typing($context->channelId);
        } catch (DiscordException) {
            // Only the "rux is typing" notice.
        }

        try {
            $image = $this->backend->discordImage($context->actorDiscordId, $prompt);
        } catch (LimitReachedException $e) {
            $limit = (int) ($e->limits['images']['limit'] ?? 0);
            $when = $e->retryAt !== null ? '<t:' . CarbonImmutable::parse($e->retryAt)->getTimestamp() . ':R>' : 'later';

            return ToolResult::error("They have made the {$limit} images allowed in 24 hours. The next one can be made {$when} (write that Discord timestamp as it is).");
        } catch (ModelUnavailableException $e) {
            return ToolResult::error($e->reason === 'model_not_allowed'
                ? 'Their plan does not include the image model.'
                : 'No image model is set up right now.');
        } catch (GatewayException) {
            return ToolResult::error('The image model could not be reached. Try again in a moment.');
        }

        $bytes = base64_decode($image['data'], true);
        if ($bytes === false || $bytes === '') {
            return ToolResult::error('The image model sent no picture.');
        }

        $payload = ['allowed_mentions' => ['parse' => []]];
        if ($context->sourceMessageId !== null) {
            $payload['message_reference'] = ['message_id' => $context->sourceMessageId, 'fail_if_not_exists' => false];
        }

        $message = $this->discord->createMessageWithFile($context->channelId, $payload, 'image.' . (self::EXTENSIONS[$image['mime']] ?? 'jpg'), $bytes);

        return ToolResult::ok([
            'posted' => true,
            'model' => $image['label'],
            'message_id' => $message['id'] ?? null,
        ]);
    }
}
