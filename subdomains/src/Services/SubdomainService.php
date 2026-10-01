<?php

namespace Boy132\Subdomains\Services;

use Boy132\Subdomains\Models\Subdomain;
use Exception;

class SubdomainService
{
    /**
     * @param  array<mixed>  $data
     *
     * @throws Exception
     */
    public function handle(array $data, ?Subdomain $subdomain = null): Subdomain
    {
        $newSubdomain = true;

        if (empty($data['record_type'])) {
            $serverId = $data['server_id'] ?? $subdomain?->server_id;
            $server = $serverId ? \App\Models\Server::find($serverId) : null;
            if ($server) {
                $srv = \Boy132\Subdomains\Enums\SRVServiceType::fromServer($server);
                $data['record_type'] = $srv ? 'SRV' : (is_ipv6(Subdomain::publicIpFor($server) ?? '') ? 'AAAA' : 'A');
            } else {
                $data['record_type'] = 'A';
            }
        }

        if (is_null($subdomain)) {
            $serverId = $data['server_id'];
            $server = \App\Models\Server::findOrFail($serverId);

            if (!Subdomain::isServerEggSupported($server)) {
                throw new Exception('Subdomains are not enabled for this server egg (only supported game servers are allowed).');
            }

            if (Subdomain::requiresDiscord() && !Subdomain::isUserDiscordLinked($server->user)) {
                throw new Exception('A linked Discord account is required to create subdomains. The server owner must link their Discord in Account Settings.');
            }

            if (Subdomain::requiresDiscordGuild() && !Subdomain::isUserInDiscordGuild($server->user)) {
                $invite = Subdomain::getDiscordInviteUrl();
                throw new Exception("The server owner must be a member of our Discord server ({$invite}) to create subdomains.");
            }

            // Limit, price tier and the insert run under a lock on the server row: counted
            // without it, parallel creates all passed the limit and all paid the same tier.
            $subdomain = \Illuminate\Support\Facades\DB::transaction(function () use ($server, $data): Subdomain {
                \App\Models\Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

                $limit = (int) ($server->subdomain_limit ?? 0);
                $limit = $limit > 0 ? $limit : (int) config('subdomains.default_limit', 1);
                if ($server->subdomains()->count() >= $limit) {
                    throw new Exception(trans('subdomains::strings.limit_reached'));
                }

                $tokensCharged = 0;
                $cost = Subdomain::calculateTokenCost($server);

                if ($cost > 0 && class_exists(\PluggedHost\Economy\Services\TokenService::class)) {
                    $tokenService = app(\PluggedHost\Economy\Services\TokenService::class);
                    if ($tokenService->isEnabled()) {
                        $owner = $server->user;
                        $balance = $tokenService->balance($owner);
                        $subNum = $server->subdomains()->count() + 1;

                        if ($balance < $cost) {
                            throw new Exception("You need {$cost} tokens for Subdomain #{$subNum}. Current balance: {$balance} tokens.");
                        }

                        $spent = $tokenService->spend(
                            $owner,
                            $cost,
                            'subdomain_create',
                            "Created subdomain {$data['name']} (Subdomain #{$subNum})",
                            ['server_id' => $server->id, 'subdomain' => $data['name']]
                        );

                        if (!$spent) {
                            throw new Exception("Payment error: Could not deduct {$cost} tokens for subdomain creation.");
                        }

                        $tokensCharged = $cost;
                    }
                }

                $data['token_cost'] = $tokensCharged;

                return Subdomain::create($data);
            });
        } else {
            $subdomain->update($data);
            $newSubdomain = false;
        }

        $subdomain->refresh();

        try {
            $subdomain->upsertOnCloudflare();
        } catch (Exception $exception) {
            if ($newSubdomain) {
                // delete() triggers deleteOnCloudflare and refundTokens()!
                $subdomain->delete();
            }

            throw $exception;
        }

        return $subdomain;
    }
}
