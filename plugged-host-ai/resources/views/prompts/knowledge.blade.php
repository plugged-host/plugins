@php
    $cfg = fn (string $key, mixed $default = null) => config($key, $default);
    $ucs = (array) $cfg('plugged-host-servers.user-creatable-servers', []);
    $plans = collect((array) ($ucs['plans'] ?? []))->filter(fn ($p) => is_array($p) && ($p['id'] ?? '') !== '');
    $label = fn ($p) => is_string($p['label'] ?? null) && str_contains($p['label'], '::') ? trans($p['label']) : ($p['label'] ?? $p['id']);
    $gb = fn ($mb) => rtrim(rtrim(number_format(((int) $mb) / 1024, 1), '0'), '.') . ' GB';
    $subCosts = (array) $cfg('subdomains.token_costs', []);
    // The allowance, caps and store prices are decided on noprofit.space and arrive with the credit sync.
    $noprofit = class_exists(\PluggedHost\Economy\Services\NoprofitCreditSync::class);
    $tiers = $noprofit ? \PluggedHost\Economy\Services\NoprofitCreditSync::tiers() : null;
    $pricing = $noprofit ? \PluggedHost\Economy\Services\NoprofitCreditSync::pricing() : null;
    $plan = $noprofit && isset($user) ? \PluggedHost\Economy\Services\NoprofitCreditSync::plan($user) : null;
@endphp
What plugged.host is and how it works (answer from this; if something is not covered, say you are not sure and point to support):

Accounts and support
- Paths like /create-server, /store or /profile are pages of the panel ({{ rtrim((string) $cfg('app.url'), '/') }}/store and so on), not chat commands.
- plugged.host is a free game and app server host on the Pelican panel. Accounts, sign-in (SSO) and the Discord link are handled on noprofit.space (accounts.noprofit.space); the panel is where servers live.
- Sign-in details, passkeys and two-factor codes are managed on noprofit.space, not in the panel. Deleting the noprofit.space account also deletes the panel account and all its servers.
- Human support, abuse reports and account problems go through a private ticket on Discord: {{ $cfg('plugged-host-discord.invite_url') ?: $supportUrl }}. Never ask users to post passwords or keys anywhere.
- Status page: status.plugged.host (nodes, incidents, history). Terms: noprofit.space/terms, privacy: noprofit.space/privacy (they cover noprofit.space and plugged.host; legal questions to legal@noprofit.space, privacy requests to privacy@noprofit.space). It is a free service with no uptime guarantee (SLA).

Creating servers and the free allowance
- Users create servers themselves (/create-server): pick a game, a location and a size. Everything is free within the account's allowance, which is shared across all their servers.
@if($tiers)
- A new account's allowance is {{ (int) $tiers['default']['cpu'] }}% CPU, {{ $gb($tiers['default']['memory']) }} RAM and {{ $gb($tiers['default']['disk']) }} disk in total; while the account's linked Discord is in the Discord server it is {{ (int) $tiers['discord']['cpu'] }}% CPU, {{ $gb($tiers['discord']['memory']) }} RAM and {{ $gb($tiers['discord']['disk']) }} disk. Discord is linked on noprofit.space (Settings). Credits buy more (see below).
@endif
- Size presets: @foreach($plans as $p){{ $label($p) }} {{ (int) $p['cpu'] }}% CPU / {{ $gb($p['memory']) }} / {{ $gb($p['disk']) }}{{ $loop->last ? '.' : '; ' }}@endforeach Custom sizes are allowed within the allowance.
- Each server gets {{ (int) ($ucs['database_limit'] ?? 0) }} databases, {{ (int) ($ucs['allocation_limit'] ?? 0) }} ports and {{ (int) ($ucs['backup_limit'] ?? 0) }} panel backups.
- Servers can be resized by their owner within the allowance ({{ ($ucs['can_users_update_servers'] ?? false) ? 'enabled' : 'disabled here' }}) and deleted by their owner ({{ ($ucs['can_users_delete_servers'] ?? false) ? 'enabled' : 'disabled here' }}). If an allowance shrinks (e.g. Discord unlinked), existing servers keep running but cannot grow.
- New servers install, then start on their own. Players connect with the node's hostname and the port (the address shown in the panel), not a raw IP. Minecraft servers open a setup wizard (software, version, modpack, Bedrock support, whitelist, operators) that must be finished before the rest of the panel unlocks.

Locations and tiers
- Locations are countries. Free locations have no uptime guarantee; Plus and Premium locations run on better hardware that is never shared with the free tier. One purchase of {{ (int) ($ucs['premium']['cost'] ?? 25) }} tokens unlocks every Plus and Premium location for the account, permanently. New servers go to the least-busy node in the chosen location.
@if($cfg('plugged-host-servers.transfers.enabled', true))
- A server can be moved to another location from its Transfer page (the owner, or a subuser given the "Transfer" permission). The user picks only the location; the node is chosen automatically. The server stops, its files and backups are copied and verified, and it starts again on the new node (offline meanwhile, usually a few minutes). Its address changes to the new node's hostname (the port is kept when free); subdomains update themselves. The old copy is only removed once the new one is verified; if a node fails the move retries on another node of the location, and if it gives up the server stays where it was, running again. After a move, the same server can be moved again {{ (int) $cfg('plugged-host-servers.transfers.cooldown_hours', 6) }} hours later.
@endif

Credits and tokens (two separate currencies; they cannot be exchanged)
- Credits buy permanent resource upgrades in the Resource Store (/store){{ $pricing ? ': +' . (int) ($pricing['cpu_unit_percent'] ?? 50) . '% CPU for ' . (int) ($pricing['cpu_price_credits'] ?? 0) . ', +' . $gb($pricing['memory_unit_mb'] ?? 1024) . ' RAM for ' . (int) ($pricing['memory_price_credits'] ?? 0) . ', +' . $gb($pricing['storage_unit_mb'] ?? 5120) . ' disk for ' . (int) ($pricing['storage_price_credits'] ?? 0) . '' : '' }}{{ $plan ? '; this account can reach at most ' . (int) $plan['max_cpu'] . '% CPU / ' . $gb($plan['max_memory']) . ' / ' . $gb($plan['max_disk']) : '' }}. The same credits and resources show on noprofit.space, which has a store of its own. Credits are earned by inviting people to the Discord server ({{ (int) $cfg('plugged-host-discord.credits_per_invite', 50) }} per invite; the invited account must be at least {{ (int) $cfg('plugged-host-discord.min_account_age_days', 30) }} days old and is reversed if they leave), boosting it ({{ (int) $cfg('plugged-host-discord.credits_per_boost_1', 500) }} for one boost, {{ (int) $cfg('plugged-host-discord.credits_per_boost_2', 1250) }} for two) and giveaways; the Discord rewards tab of the profile page (/profile?tab=discord-rewards) shows what invites and boosts earned and what is still waiting.
- Tokens pay for extras: start-queue priority and the Plus/Premium locations (both sold in the Token Store, /token-store), restoring hibernated servers and subdomain deposits (paid on the server itself). They are earned from sponsored links on the tokens page ({{ (int) $cfg('plugged-host-ads.start_credits.linkvertise.reward', 1) }} per link, up to {{ (int) $cfg('plugged-host-ads.start_credits.linkvertise.daily_cap', 5) }} a day){{ (int) $cfg('plugged-host-economy.tokens.daily_grant', 0) > 0 ? ', a free ' . (int) $cfg('plugged-host-economy.tokens.daily_grant') . ' per day' : '' }}. Balances show in the sidebar.

Starting servers: start queue, waiting room, hibernation
@if($cfg('plugged-host-queue.enabled', false))
- When a node is busy (over {{ (int) $cfg('plugged-host-queue.threshold_percent', 85) }}% CPU or memory), starts and restarts wait in a start queue and fire automatically when there is room, even if the tab is closed. Priority costs {{ (int) $cfg('plugged-host-queue.priority.token_cost', 15) }} tokens for {{ (int) $cfg('plugged-host-queue.priority.days', 3) }} days{{ $cfg('plugged-host-queue.priority.ad_free.enabled', false) ? ', or ' . (int) $cfg('plugged-host-queue.priority.ad_free.token_cost', 35) . ' tokens for ' . (int) $cfg('plugged-host-queue.priority.ad_free.days', 2) . ' days with no ads at all' : '' }}, and covers all the user's servers. With priority, a server under {{ $gb($cfg('plugged-host-queue.transfer.max_disk', 3072)) }} can be moved to a quieter node in the same location (its address changes).
@endif
@if($cfg('plugged-host-ads.start_credits.enabled', false))
- Starting a server bigger than {{ (int) $cfg('plugged-host-ads.start_credits.cpu', 100) }}% CPU / {{ $gb($cfg('plugged-host-ads.start_credits.memory', 2048)) }} RAM / {{ $gb($cfg('plugged-host-ads.start_credits.disk', 10240)) }} disk shows a short ad waiting room ({{ (int) $cfg('plugged-host-ads.start_credits.min_dwell', 15) }}-{{ (int) $cfg('plugged-host-ads.start_credits.max_dwell', 30) }} s); the server is already starting meanwhile, and there is a {{ (int) $cfg('plugged-host-ads.start_credits.pass_minutes', 10) }}-minute grace window afterwards. Only ad-free priority removes it.
@endif
@if($cfg('plugged-host-limbo.enabled', false))
- Servers with no panel activity are stopped after {{ (int) $cfg('plugged-host-limbo.stop_hours', 48) }} hours and hibernated after {{ (int) $cfg('plugged-host-limbo.transfer_hours', 72) }} hours. The owner restores a hibernated server from its page for {{ (int) $cfg('plugged-host-limbo.cost_early', 5) }} tokens within {{ (int) $cfg('plugged-host-limbo.cost_window_hours', 72) }} hours, {{ (int) $cfg('plugged-host-limbo.cost_late', 10) }} after that.
@endif

Backups and safety
- Each server has panel backups (restore or download). Server files are also mirrored off-site; the Backups page and backups.plugged.host offer snapshot downloads, even when a server is offline. If a node goes down for several minutes, its servers are restored automatically on another node in the same location (the address can change). Users should still keep their own copies of important data.

Console logs
- On Minecraft servers the server log is checked automatically: it is uploaded to mclo.gs on its own (nobody has to press anything; secrets are removed first) and whatever mclo.gs recognises is shown above the console, with its suggested fix and an "Ask neo to fix it" button that opens this chat with the log's analysis loaded. With no problems there is a line with the log's mclo.gs link, to share with support. There is no upload button.

Subdomains
- Available for game servers that support it (Minecraft Java and FiveM/RedM), up to {{ (int) $cfg('subdomains.default_limit', 3) }} per server. The owner needs Discord linked and must be in the Discord server. Each costs a refundable token deposit ({{ (int) ($subCosts[1] ?? 1) }}, {{ (int) ($subCosts[2] ?? 3) }}, {{ (int) ($subCosts[3] ?? 8) }}, then {{ (int) ($subCosts['default'] ?? 15) }} each), returned when the subdomain or server is deleted. They are managed on the server's Subdomains page.

Minecraft
- Java servers run Purpur (Paper/Spigot/Bukkit plugins), Fabric, Forge or NeoForge; switching software or version reinstalls the server but keeps the world and configs. Plugins and mods come from Modrinth (and CurseForge where available), including one-click modpacks{{ $cfg('plugged-host-minecraft.spigotmc_enabled', true) ? '; Paper/Purpur servers can also install SpigotMC plugins (latest build only, free ones hosted on SpigotMC or as a GitHub release)' : '' }}. Geyser + Floodgate add Bedrock (phone/console) support on a separate port. Game settings and server.properties, the whitelist and operators have their own pages. The Worlds page downloads, uploads (replacing the active world), regenerates or switches worlds and sets how a new world is generated (seed, type, structures, data packs); anything that replaces a world needs the server stopped and can keep a zip of the old one. The installed plugins/mods list identifies every jar, shows updates and missing dependencies, and can update, disable or delete them.
