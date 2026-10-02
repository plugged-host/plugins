<?php

namespace PluggedHost\Discord\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PluggedHost\Discord\Services\DiscordBotService;
use PluggedHost\Discord\Services\DiscordVerificationService;

class DiscordInteractionsController extends Controller
{
    public function handle(
        Request $request,
        DiscordVerificationService $verificationService,
        DiscordBotService $botService
    ): JsonResponse {
        $content = $request->getContent();

        // Only Discord may call this: every request must carry a valid Ed25519 signature from
        // the application's public key. Without a configured key there is nothing to check
        // against, so the endpoint refuses everything (slash commands then arrive over the
        // gateway daemon instead). It used to skip the check whenever the key or the headers
        // were missing, which let anyone post a /setboost and mint credits.
        if (!$this->hasValidSignature($request, $content)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $type = $data['type'] ?? 0;

        // Type 1: PING -> Respond with Type 1 PONG
        if ($type === 1) {
            return response()->json(['type' => 1]);
        }

        // Type 2: APPLICATION_COMMAND (Slash Commands)
        if ($type === 2) {
            $commandName = $data['data']['name'] ?? '';

            if ($commandName === 'invites') {
                $options = $data['data']['options'] ?? [];
                $targetUserId = null;
                $targetUsername = null;
                $targetAvatar = null;
                $targetMemberData = null;

                foreach ($options as $opt) {
                    if (($opt['name'] ?? '') === 'user' && !empty($opt['value'])) {
                        $targetUserId = (string) $opt['value'];
                        if (isset($data['data']['resolved']['users'][$targetUserId])) {
                            $targetUsername = $data['data']['resolved']['users'][$targetUserId]['username'] ?? null;
                            $targetAvatar = $data['data']['resolved']['users'][$targetUserId]['avatar'] ?? null;
                        }
                        break;
                    }
                }

                if (!$targetUserId) {
                    $targetUserId = $data['member']['user']['id'] ?? ($data['user']['id'] ?? null);
                    $targetUsername = $data['member']['user']['username'] ?? ($data['user']['username'] ?? null);
                    $targetAvatar = $data['member']['user']['avatar'] ?? ($data['user']['avatar'] ?? null);
                }

                $embed = $botService->buildInvitesEmbed($targetUserId, $targetUsername, $targetAvatar, $targetMemberData);
                return response()->json([
                    'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
                    'data' => [
                        'embeds' => [$embed],
                    ],
                ]);
            }

            if ($commandName === 'topinvites') {
                $options = $data['data']['options'] ?? [];
                $limit = 10;
                foreach ($options as $opt) {
                    if (($opt['name'] ?? '') === 'limit' && !empty($opt['value'])) {
                        $limit = max(1, min(25, (int) $opt['value']));
                        break;
                    }
                }

                $embed = $botService->buildTopInvitesEmbed($limit);

                return response()->json([
                    'type' => 4, // CHANNEL_MESSAGE_WITH_SOURCE
                    'data' => [
                        'embeds' => [$embed],
                    ],
                ]);
            }

            if ($commandName === 'setboost') {
                // Administrator of plugged.host's own server, as the gateway path checks: a
                // globally registered command can be run from any guild the app is in.
                $guildId = $verificationService->getGuildId();
                if ($guildId === '' || (string) ($data['guild_id'] ?? '') !== $guildId || !DiscordBotService::invokerIsAdministrator($data)) {
                    return response()->json(['type' => 4, 'data' => $botService->setBoostDeniedResponse()]);
                }

                $options = $data['data']['options'] ?? [];
                $targetUserId = null;
                $targetUsername = null;
                $count = 2;

                foreach ($options as $opt) {
                    if (($opt['name'] ?? '') === 'user' && !empty($opt['value'])) {
                        $targetUserId = (string) $opt['value'];
                        if (isset($data['data']['resolved']['users'][$targetUserId])) {
                            $targetUsername = $data['data']['resolved']['users'][$targetUserId]['username'] ?? null;
                        }
                    } elseif (($opt['name'] ?? '') === 'count') {
                        $count = (int) $opt['value'];
                    }
                }

                if ($targetUserId) {
                    $response = $botService->handleSetBoostCommand($targetUserId, $count, $targetUsername);

                    return response()->json([
                        'type' => 4,
                        'data' => $response,
                    ]);
                }
            }
        }

        return response()->json(['error' => 'Unhandled interaction type'], 400);
    }

    private function hasValidSignature(Request $request, string $content): bool
    {
        $publicKey = (string) config('plugged-host-discord.public_key', '');
        $signature = (string) $request->header('X-Signature-Ed25519', '');
        $timestamp = (string) $request->header('X-Signature-Timestamp', '');

        if ($publicKey === '' || $signature === '' || $timestamp === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        if (!ctype_xdigit($signature) || strlen($signature) !== 128 || !ctype_xdigit($publicKey) || strlen($publicKey) !== 64) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp . $content, hex2bin($publicKey));
    }
}

