<?php

namespace PluggedHost\Minecraft\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PluggedHost\Minecraft\Support\UserAgent;
use Throwable;

/**
 * Turn a username into the uuid/name pair Minecraft's own whitelist.json and ops.json hold.
 *
 * Editing those files while the server is stopped is the only way to whitelist or op someone
 * without a running console, and both files are keyed by uuid — a wrong or invented one is
 * silently ignored by the server, which looks exactly like the panel having done nothing. So
 * the name is resolved first and the edit is refused when it cannot be.
 *
 * Which uuid is correct depends on the server, not on Mojang: an online-mode server matches
 * players against their Mojang account, while an offline-mode ("cracked") server derives the
 * uuid from the name itself. Both are implemented here so the caller only has to know which
 * mode the server runs in.
 */
class MinecraftProfileResolver
{
    /**
     * A profile only changes when someone renames their account, which is rare and rate
     * limited by Mojang to once a month.
     */
    protected const TTL = 86400;

    /**
     * A name that does not exist yet may exist tomorrow, so a miss is remembered only long
     * enough to stop a typo from being retried on every keystroke.
     */
    protected const MISS_TTL = 300;

    protected const TIMEOUT = 8;

    /**
     * The Mojang profile for a username, or null when there is no such account.
     *
     * Null is also what a Mojang outage produces. That is deliberate: writing a guessed uuid
     * into whitelist.json would produce an entry that never matches the player and that
     * nobody would think to look at again.
     *
     * @return array{uuid: string, name: string}|null
     */
    public function resolve(string $username): ?array
    {
        $username = trim($username);

        if (!$this->plausible($username)) {
            return null;
        }

        $key = 'plugged-host-core:mc-profile:' . strtolower($username);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->connectTimeout(4)
                ->withHeaders(['User-Agent' => UserAgent::get()])
                ->get('https://api.mojang.com/users/profiles/minecraft/' . urlencode($username));
        } catch (Throwable $exception) {
            report($exception);

            // Not cached: the next attempt should ask again rather than inherit an outage.
            return null;
        }

        // 404 is Mojang's answer for "no such account"; older deployments answered 204 with
        // an empty body. Anything else (429, 5xx) is Mojang's problem, not the name's.
        if (in_array($response->status(), [204, 404], true)) {
            Cache::put($key, false, self::MISS_TTL);

            return null;
        }

        $body = $response->successful() ? $response->json() : null;
        $id = is_array($body) ? (string) ($body['id'] ?? '') : '';

        if (preg_match('/^[0-9a-f]{32}$/i', $id) !== 1) {
            return null;
        }

        $profile = [
            'uuid' => $this->dashed($id),
            // Mojang answers with the canonical capitalisation, which is what the server
            // writes into its own files.
            'name' => is_array($body) ? (string) ($body['name'] ?? $username) : $username,
        ];

        Cache::put($key, $profile, self::TTL);

        return $profile;
    }

    /**
     * The uuid an offline-mode server assigns to a name.
     *
     * Vanilla derives it as UUID.nameUUIDFromBytes("OfflinePlayer:<name>") — a name-based
     * (version 3) uuid over the MD5 of that string. No network call, and no way for it to
     * be wrong as long as the name matches what the player types.
     *
     * @return array{uuid: string, name: string}
     */
    public function offline(string $username): array
    {
        $username = trim($username);
        $hash = md5("OfflinePlayer:$username", true);

        // Version 3 in the high nibble of byte 6, RFC 4122 variant in the top bits of byte 8.
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30);
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80);

        return ['uuid' => $this->dashed(bin2hex($hash)), 'name' => $username];
    }

    /**
     * Whether a string is shaped like a Minecraft username at all.
     *
     * Deliberately looser than the 3-16 characters Mojang enforces today: a handful of
     * accounts from before that rule still have shorter names, and the only job here is to
     * keep junk out of the URL and out of the files.
     */
    public function plausible(string $username): bool
    {
        return preg_match('/^\w{1,16}$/', trim($username)) === 1;
    }

    /**
     * 32 hex characters as the dashed form both JSON files use.
     */
    protected function dashed(string $id): string
    {
        $id = strtolower($id);

        return implode('-', [
            substr($id, 0, 8),
            substr($id, 8, 4),
            substr($id, 12, 4),
            substr($id, 16, 4),
            substr($id, 20, 12),
        ]);
    }
}
