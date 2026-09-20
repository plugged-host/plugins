<?php

namespace Boy132\Subdomains\Models;

use App\Models\Server;
use App\Models\User;
use Boy132\Subdomains\Enums\SRVServiceType;
use Exception;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;

/**
 * @property int $id
 * @property string $name
 * @property string $record_type
 * @property ?string $cloudflare_id
 * @property int $domain_id
 * @property CloudflareDomain $domain
 * @property int $server_id
 * @property Server $server
 */
class Subdomain extends Model implements HasLabel
{
    protected $fillable = [
        'name',
        'record_type',
        'token_cost',
        'cloudflare_id',
        'domain_id',
        'server_id',
    ];

    protected $casts = [
        'token_cost' => 'integer',
    ];

    protected static ?bool $hasDiscordLinksTable = null;

    protected static function hasDiscordLinksTable(): bool
    {
        if (self::$hasDiscordLinksTable === null) {
            self::$hasDiscordLinksTable = \Illuminate\Support\Facades\Schema::hasTable('discord_links');
        }

        return self::$hasDiscordLinksTable;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleted(function (self $model) {
            $model->deleteOnCloudflare();
            $model->refundTokens();
        });
    }

    public function refundTokens(): void
    {
        $cost = (int) $this->token_cost;
        if ($cost <= 0) {
            return;
        }

        $this->token_cost = 0;

        if (!class_exists(\PluggedHost\Economy\Services\TokenService::class)) {
            return;
        }

        $server = $this->server;
        $owner = $server?->user;
        if (!$owner) {
            return;
        }

        try {
            $tokenService = app(\PluggedHost\Economy\Services\TokenService::class);
            if ($tokenService->isEnabled()) {
                $tokenService->add(
                    $owner,
                    $cost,
                    'subdomain_refund',
                    "Refund for deleted subdomain {$this->name} (Server #{$server->id})",
                    [
                        'server_id' => $server->id,
                        'subdomain' => $this->name,
                        'refunded_tokens' => $cost,
                    ]
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function calculateTokenCost(Server $server): int
    {
        if (!class_exists(\PluggedHost\Economy\Services\TokenService::class)) {
            return 0;
        }

        $existingCount = $server->subdomains()->count();
        $tier = $existingCount + 1;

        $costs = config('subdomains.token_costs', [
            1 => 1,
            2 => 3,
            3 => 8,
            'default' => 15,
        ]);

        return (int) ($costs[$tier] ?? $costs['default'] ?? 15);
    }

    public static function requiresDiscord(): bool
    {
        return (bool) config('subdomains.require_discord', true);
    }

    public static function isUserDiscordLinked(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (class_exists(\PluggedHost\Discord\Models\DiscordLink::class)) {
            $linked = \PluggedHost\Discord\Models\DiscordLink::where('user_id', $user->id)
                ->whereNotNull('discord_id')
                ->where('discord_id', '!=', '')
                ->exists();
            if ($linked) {
                return true;
            }
        }

        try {
            if (self::hasDiscordLinksTable()) {
                $linked = \Illuminate\Support\Facades\DB::table('discord_links')
                    ->where('user_id', $user->id)
                    ->whereNotNull('discord_id')
                    ->where('discord_id', '!=', '')
                    ->exists();
                if ($linked) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        if (is_array($user->oauth ?? null) && !empty($user->oauth['discord'])) {
            return true;
        }

        return false;
    }

    public static function requiresDiscordGuild(): bool
    {
        return (bool) config('subdomains.require_discord_guild', true);
    }

    public static function getDiscordInviteUrl(): string
    {
        if (class_exists(\PluggedHost\Discord\Services\DiscordVerificationService::class)) {
            try {
                return app(\PluggedHost\Discord\Services\DiscordVerificationService::class)->getInviteUrl();
            } catch (\Throwable) {}
        }

        return (string) config('plugged-host-discord.invite_url', 'https://discord.gg/RuzP2yaRrN');
    }

    public static function isUserInDiscordGuild(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (class_exists(\PluggedHost\Discord\Models\DiscordLink::class)) {
            $link = \PluggedHost\Discord\Models\DiscordLink::where('user_id', $user->id)->first();
            if ($link && $link->isLinked()) {
                if ($link->is_in_guild) {
                    return true;
                }

                // If not marked in guild, try a quick live check/sync via DiscordVerificationService
                try {
                    if (class_exists(\PluggedHost\Discord\Services\DiscordVerificationService::class)) {
                        $service = app(\PluggedHost\Discord\Services\DiscordVerificationService::class);
                        $sync = $service->syncUser($user);
                        if (($sync['status'] ?? null) === 'verified') {
                            return true;
                        }
                    }
                } catch (\Throwable) {}

                return (bool) $link->fresh()?->is_in_guild;
            }
        }

        try {
            if (self::hasDiscordLinksTable()) {
                $link = \Illuminate\Support\Facades\DB::table('discord_links')
                    ->where('user_id', $user->id)
                    ->first();
                if ($link && !empty($link->discord_id) && (bool) $link->is_in_guild) {
                    return true;
                }
            }
        } catch (\Throwable) {}

        return false;
    }

    public static function isServerEggSupported(?Server $server): bool
    {
        if (!$server || !$server->egg_id) {
            return false;
        }

        $supportedEggs = config('subdomains.supported_eggs', [11, 12]);

        return in_array((int) $server->egg_id, $supportedEggs, true);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(CloudflareDomain::class, 'domain_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getLabel(): string|Htmlable|null
    {
        return $this->name . '.' . ($this->domain?->name ?? '');
    }

    public function getLabelAttribute(): string
    {
        return $this->name . '.' . ($this->domain?->name ?? '');
    }

    public function getFqdnAttribute(): string
    {
        return $this->name . '.' . ($this->domain?->name ?? '');
    }

    /**
     * Parse stored cloudflare_id which may be JSON (e.g. {"a":"...","srv":"..."}) or legacy string.
     *
     * @return array<string, string>
     */
    public function getCloudflareRecordIds(): array
    {
        if (empty($this->cloudflare_id)) {
            return [];
        }

        $decoded = json_decode($this->cloudflare_id, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Legacy format (single string ID)
        return ['a' => $this->cloudflare_id];
    }

    public function getSrvType(): ?SRVServiceType
    {
        return $this->server ? SRVServiceType::fromServer($this->server) : null;
    }

    public function getGameProfileAttribute(): string
    {
        $srv = $this->getSrvType();
        return match ($srv) {
            SRVServiceType::Minecraft => 'minecraft',
            SRVServiceType::FiveM => 'fivem',
            null => 'standard',
            default => 'srv',
        };
    }

    public function getGameLabelAttribute(): string
    {
        $srv = $this->getSrvType();
        return match ($srv) {
            SRVServiceType::Minecraft => 'Minecraft (SRV)',
            SRVServiceType::FiveM => 'FiveM / RedM (SRV)',
            null => 'Standard (' . $this->record_type . ')',
            default => $srv->getLabel() . ' (SRV)',
        };
    }

    public function getConnectionCommandAttribute(): string
    {
        $fqdn = $this->fqdn;
        $srv = $this->getSrvType();

        if ($srv === SRVServiceType::FiveM) {
            return "connect {$fqdn}";
        }

        if ($srv === SRVServiceType::Minecraft) {
            return $fqdn;
        }

        $port = $this->server?->allocation?->port;
        if ($port && !in_array($port, [80, 443])) {
            return "{$fqdn}:{$port}";
        }

        return $fqdn;
    }

    public function isPropagating(): bool
    {
        return $this->created_at && $this->created_at->copy()->addMinutes(3)->isFuture();
    }

    public function getStatusLabelAttribute(): string
    {
        if ($this->isPropagating()) {
            $diffSecs = max(0, now()->diffInSeconds($this->created_at->copy()->addMinutes(3), false));
            $mins = max(1, (int) ceil($diffSecs / 60));
            return "Processing ({$mins}m)";
        }

        return 'Active';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->isPropagating() ? 'warning' : 'success';
    }

    /**
     * The routable IP a DNS A/AAAA record for this server should point at.
     *
     * A server allocation now binds 0.0.0.0 (every interface) by default — the address a client
     * actually reaches lives in the allocation alias / node hostname, not the bind IP. So a literal
     * 0.0.0.0 (or ::) is not an error any more: resolve it to a real address from the alias, the
     * node's SRV target, or the node fqdn (a hostname is resolved via DNS). Returns null only when
     * there is genuinely nothing routable to point at.
     */
    public static function publicIpFor(?Server $server): ?string
    {
        $allocation = $server?->allocation;

        if (!$allocation) {
            return null;
        }

        $ip = (string) $allocation->ip;

        if ($ip !== '' && !in_array($ip, ['0.0.0.0', '::'], true)) {
            return $ip;
        }

        foreach ([$allocation->ip_alias, $server->node?->srv_target, $server->node?->fqdn] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate === '') {
                continue;
            }

            if (is_ip($candidate)) {
                return $candidate;
            }

            $resolved = get_ip_from_hostname($candidate);

            if (is_string($resolved) && is_ip($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    /** @throws Exception */
    public function upsertOnCloudflare(): void
    {
        if (!$this->server->allocation) {
            throw new Exception('Server has no allocation.');
        }

        $ip = self::publicIpFor($this->server);
        if ($ip === null) {
            throw new Exception('Could not determine a routable IP for this server (its allocation is 0.0.0.0/:: and the node hostname did not resolve).');
        }

        $zoneId = $this->domain->cloudflare_id;
        if (!$zoneId) {
            throw new Exception('Domain zone ID is missing.');
        }

        $srvServiceType = SRVServiceType::fromServer($this->server);
        $srvTarget = $this->server->node?->srv_target ?: $this->server->node?->fqdn;
        $ipRecordType = is_ipv6($ip) ? 'AAAA' : 'A';

        $currentIds = $this->getCloudflareRecordIds();
        $newIds = [];

        // 1. Pre-flight check: Verify no duplicate A record exists on Cloudflare under another ID
        $searchA = Http::cloudflare()->get("zones/{$zoneId}/dns_records", [
            'name' => "{$this->name}.{$this->domain->name}",
            'type' => $ipRecordType,
        ])->json();

        if (($searchA['success'] ?? false) && !empty($searchA['result'])) {
            foreach ($searchA['result'] as $record) {
                if ($record['id'] !== ($currentIds['a'] ?? null)) {
                    throw new Exception("The subdomain '{$this->name}.{$this->domain->name}' is already in use on Cloudflare.");
                }
            }
        }

        // 2. Pre-flight check: If SRV is used, verify no duplicate SRV record exists under another ID
        $srvSpecs = [];
        if ($srvServiceType) {
            if (!$srvTarget) {
                throw new Exception('Node hostname or SRV target is missing.');
            }

            if ($srvServiceType === SRVServiceType::FiveM) {
                $srvSpecs['srv_udp'] = [
                    'key' => 'srv_udp',
                    'service' => '_cfx',
                    'proto' => '_udp',
                    'fullName' => "_cfx._udp.{$this->name}",
                    'weight' => 5,
                    'comment' => "Created by plugged.host Subdomains (FiveM / RedM UDP SRV - Server #{$this->server_id})",
                ];
                $srvSpecs['srv_tcp'] = [
                    'key' => 'srv_tcp',
                    'service' => '_cfx',
                    'proto' => '_tcp',
                    'fullName' => "_cfx._tcp.{$this->name}",
                    'weight' => 5,
                    'comment' => "Created by plugged.host Subdomains (FiveM / RedM TCP SRV - Server #{$this->server_id})",
                ];
            } else {
                $srvSpecs['srv'] = [
                    'key' => 'srv',
                    'service' => $srvServiceType->getService(),
                    'proto' => $srvServiceType->getProto(),
                    'fullName' => "{$srvServiceType->value}.{$this->name}",
                    'weight' => 0,
                    'comment' => "Created by plugged.host Subdomains ({$srvServiceType->getLabel()} SRV - Server #{$this->server_id})",
                ];
            }

            foreach ($srvSpecs as $specKey => $spec) {
                $srvSearchName = "{$spec['fullName']}.{$this->domain->name}";
                $searchSRV = Http::cloudflare()->get("zones/{$zoneId}/dns_records", [
                    'name' => $srvSearchName,
                    'type' => 'SRV',
                ])->json();

                if (($searchSRV['success'] ?? false) && !empty($searchSRV['result'])) {
                    foreach ($searchSRV['result'] as $record) {
                        if ($record['id'] !== ($currentIds[$specKey] ?? $currentIds['srv'] ?? null)) {
                            throw new Exception("An SRV record for '{$spec['fullName']}' already exists on Cloudflare.");
                        }
                    }
                }
            }
        }

        // 3. Upsert A/AAAA Record (Mandatory for all servers, including Minecraft & FiveM)
        $aPayload = [
            'name' => $this->name,
            'type' => $ipRecordType,
            'content' => $ip,
            'proxied' => false,
            'comment' => "Created by plugged.host Subdomains (Server #{$this->server_id})",
        ];

        if (!empty($currentIds['a'])) {
            $aResponse = Http::cloudflare()->patch("zones/{$zoneId}/dns_records/{$currentIds['a']}", $aPayload)->json();
            $newIds['a'] = $currentIds['a'];
        } else {
            $aResponse = Http::cloudflare()->post("zones/{$zoneId}/dns_records", $aPayload)->json();
            if ($aResponse['success'] ?? false) {
                $newIds['a'] = $aResponse['result']['id'];
            }
        }

        if (!($aResponse['success'] ?? false)) {
            $errMsg = $aResponse['errors'][0]['message'] ?? 'Failed to create Cloudflare A record';
            throw new Exception($errMsg);
        }

        // 4. Upsert SRV Records if SRV service type is detected (Minecraft, FiveM, etc.)
        if (!empty($srvSpecs)) {
            foreach ($srvSpecs as $specKey => $spec) {
                $srvPayload = [
                    'name' => $spec['fullName'],
                    'type' => 'SRV',
                    'proxied' => false,
                    'comment' => $spec['comment'],
                    'data' => [
                        'service' => $spec['service'],
                        'proto' => $spec['proto'],
                        'name' => $this->name,
                        'priority' => 0,
                        'weight' => $spec['weight'],
                        'port' => (int) $this->server->allocation->port,
                        'target' => $srvTarget,
                    ],
                ];

                $existingSrvId = $currentIds[$specKey] ?? ($specKey === 'srv_udp' ? ($currentIds['srv'] ?? null) : null);

                if (!empty($existingSrvId)) {
                    $srvResponse = Http::cloudflare()->patch("zones/{$zoneId}/dns_records/{$existingSrvId}", $srvPayload)->json();
                    $newIds[$specKey] = $existingSrvId;
                } else {
                    $srvResponse = Http::cloudflare()->post("zones/{$zoneId}/dns_records", $srvPayload)->json();
                    if ($srvResponse['success'] ?? false) {
                        $newIds[$specKey] = $srvResponse['result']['id'];
                    }
                }

                if (!($srvResponse['success'] ?? false)) {
                    $errMsg = $srvResponse['errors'][0]['message'] ?? 'Failed to create Cloudflare SRV record';
                    // Rollback newly created records if this failed
                    foreach ($newIds as $createdKey => $createdId) {
                        if (empty($currentIds[$createdKey])) {
                            Http::cloudflare()->delete("zones/{$zoneId}/dns_records/{$createdId}");
                        }
                    }
                    throw new Exception($errMsg);
                }
            }
        } else {
            // If server switched away from SRV type, remove previous SRV records if any existed
            foreach (['srv', 'srv_udp', 'srv_tcp'] as $oldKey) {
                if (!empty($currentIds[$oldKey])) {
                    Http::cloudflare()->delete("zones/{$zoneId}/dns_records/{$currentIds[$oldKey]}");
                }
            }
        }

        // 5. Update database record with JSON IDs and record type
        $this->updateQuietly([
            'cloudflare_id' => json_encode($newIds),
            'record_type' => $srvServiceType ? 'SRV' : $ipRecordType,
        ]);
    }

    protected function deleteOnCloudflare(): void
    {
        $zoneId = $this->domain?->cloudflare_id;
        if (!$zoneId) {
            return;
        }

        // Delete known record IDs
        $ids = $this->getCloudflareRecordIds();
        foreach ($ids as $id) {
            if ($id) {
                try {
                    Http::cloudflare()->delete("zones/{$zoneId}/dns_records/{$id}");
                } catch (Exception $e) {
                    report($e);
                }
            }
        }

        // Fail-safe cleanup: query zone for any lingering records matching this exact name
        try {
            $fqdn = "{$this->name}.{$this->domain->name}";
            $records = Http::cloudflare()->get("zones/{$zoneId}/dns_records", [
                'name' => $fqdn,
            ])->json();

            if (!empty($records['result'])) {
                foreach ($records['result'] as $record) {
                    Http::cloudflare()->delete("zones/{$zoneId}/dns_records/{$record['id']}");
                }
            }
        } catch (Exception $e) {
            report($e);
        }
    }
}
