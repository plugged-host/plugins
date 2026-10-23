@php
    $cfg = fn (string $key, mixed $default = null) => config($key, $default);
    $ucs = (array) $cfg('plugged-host-servers.user-creatable-servers', []);
    $plans = collect((array) ($ucs['plans'] ?? []))->filter(fn ($p) => is_array($p) && ($p['id'] ?? '') !== '');
    $label = fn ($p) => is_string($p['label'] ?? null) && str_contains($p['label'], '::') ? trans($p['label']) : ($p['label'] ?? $p['id']);
    $gb = fn ($mb) => rtrim(rtrim(number_format(((int) $mb) / 1024, 1), '0'), '.') . ' GB';
    $subCosts = (array) $cfg('subdomains.token_costs', []);
    // The allowance, limits and store prices: economy's ComputePlans (this panel's settings, or noprofit.space's).
    $computePlans = \PluggedHost\Core\Support\Plugins::enabled('plugged-host-economy') ? app(\PluggedHost\Economy\Contracts\ComputePlans::class) : null;
    $tiers = $computePlans?->tiers();
    $pricing = $computePlans?->pricing();
    $plan = $computePlans && isset($user) ? $computePlans->plan($user) : null;
    // Where accounts live: core's AccountPortal (noprofit.space with plugged-host-noprofit, or the panel itself).
    $accountPortal = app(\PluggedHost\Core\Contracts\AccountPortal::class);
    $externalAccounts = $accountPortal->external();
    $portalName = $accountPortal->name();
    $portalHost = $externalAccounts ? parse_url((string) $accountPortal->manageUrl(), PHP_URL_HOST) : null;
    // This host's own name, links and contacts (APP_NAME, the Discord invite, neo's settings), and
    // which plugins are on: nothing here may speak for another host.
    $appName = (string) ($appName ?? $cfg('app.name'));
    $enabled = fn (string $id): bool => \PluggedHost\Core\Support\Plugins::enabled($id);
    $economy = $computePlans !== null;
    $discordInvite = trim((string) $cfg('plugged-host-discord.invite_url', ''));
    $helpUrl = trim((string) ($discordInvite ?: ($supportUrl ?? '')));
    $helpSentence = match (true) {
        $discordInvite !== '' => 'Human help and account problems go to the public channels of the ' . $appName . ' Discord server, where staff answer: ' . $helpUrl . '. ',
        $helpUrl !== '' => 'Human help and account problems go to ' . $appName . '\'s staff: ' . $helpUrl . '. ',
        default => 'Human help and account problems go to ' . $appName . '\'s staff. ',
    };
    $legalEmail = trim((string) $cfg('plugged-host-ai.legal_email', ''));
    $privacyEmail = trim((string) $cfg('plugged-host-ai.privacy_email', ''));
    $contactSentence = match (true) {
        $legalEmail !== '' && $privacyEmail !== '' => 'Legal complaints (abuse, copyright, illegal content) go by email to ' . $legalEmail . ', privacy complaints and data requests to ' . $privacyEmail . '. ',
        $legalEmail !== '' => 'Legal complaints (abuse, copyright, illegal content) go by email to ' . $legalEmail . '. ',
        $privacyEmail !== '' => 'Privacy complaints and data requests go by email to ' . $privacyEmail . '. ',
        default => '',
    };
    $hostOf = fn (string $url): string => (string) preg_replace('#^https?://#i', '', rtrim(trim($url), '/'));
    $statusHost = $hostOf((string) $cfg('plugged-host-core.status_url', ''));
    // The terms are noprofit.space's, which cover this host, only with plugged-host-noprofit.
    $termsSentence = $enabled('plugged-host-noprofit')
        ? 'Terms: noprofit.space/terms, privacy: noprofit.space/privacy (they cover noprofit.space and ' . $appName
            . ($legalEmail !== '' ? '; legal questions to ' . $legalEmail : '')
            . ($privacyEmail !== '' ? ($legalEmail !== '' ? ', ' : '; ') . 'privacy requests to ' . $privacyEmail : '')
            . '). '
        : '';
    $inGameMessage = trim((string) preg_replace('/[\r\n\0]+/', ' ', (string) $cfg('plugged-host-ads.in_game.message', ''))) ?: 'This server is powered by ' . $appName;
    $storage = $enabled('plugged-host-storage');
    $backupsHost = $hostOf((string) $cfg('plugged-host-storage.portal_url', ''));
    $logCheck = (bool) $cfg('plugged-host-ai.log_check.enabled', true);
    $subdomains = $enabled('subdomains');
    // Several Discord servers that each add resources (noprofit.space's DiscordServersStatus); standalone, the one Discord server's tier.
    $discordServers = $enabled('plugged-host-discord') && app(\PluggedHost\Discord\Contracts\DiscordServersStatus::class)->available();
    $earnLinks = $economy ? app(\PluggedHost\Economy\Services\Rewards\RewardRegistry::class)->available() : [];
    $devbox = $enabled('plugged-host-devbox') && (bool) $cfg('plugged-host-devbox.enabled', false);
    $fivem = $enabled('plugged-host-fivem');
    // The ad waiting room's size line; all 0 gates every start (StartGateService::exceeds()).
    $gateSizes = [(int) $cfg('plugged-host-ads.start_credits.cpu', 100), (int) $cfg('plugged-host-ads.start_credits.memory', 2048), (int) $cfg('plugged-host-ads.start_credits.disk', 10240)];
    $subdomainDiscord = (bool) $cfg('subdomains.require_discord', true);
    // The Discord server gate only applies while plugged-host-discord is on (Subdomain::requiresDiscordGuild()).
    $subdomainGuild = $enabled('plugged-host-discord') && (bool) $cfg('subdomains.require_discord_guild', true);
    $subdomainRules = match (true) {
        $subdomainDiscord && $subdomainGuild => ' The owner needs Discord linked and must be in the Discord server.',
        $subdomainDiscord => ' The owner needs Discord linked.',
        $subdomainGuild => ' The owner must be in the Discord server.',
        default => '',
    } . ($economy ? ' Each costs a refundable token deposit (' . (int) ($subCosts[1] ?? 1) . ', ' . (int) ($subCosts[2] ?? 3) . ', ' . (int) ($subCosts[3] ?? 8) . ', then ' . (int) ($subCosts['default'] ?? 15) . ' each), returned when the subdomain or server is deleted.' : '');
@endphp
What {!! $appName !!} is and how it works (answer from this; if something is not covered, say you are not sure and point to support):

Accounts and support
- Paths like /create-server, /store or /profile are pages of the panel ({{ rtrim((string) $cfg('app.url'), '/') }}/store and so on), not chat commands.
@if($externalAccounts)
- {!! $appName !!} is a free game and app server host on the Pelican panel. Accounts, sign-in (SSO) and the Discord link are handled on {{ $portalName }}{{ $portalHost ? ' (' . $portalHost . ')' : '' }}; the panel is where servers live.
- Sign-in details, passkeys and two-factor codes are managed on {{ $portalName }}, not in the panel. Deleting the {{ $portalName }} account also deletes the panel account and all its servers.
@else
- {!! $appName !!} is a free game and app server host on the Pelican panel. Accounts live on the panel itself: sign-in, the account and the Discord link are on the profile page (/profile).
- Sign-in details, passkeys and two-factor codes are managed on the profile page (/profile).
@endif
- There are no private tickets. {!! $helpSentence !!}{!! $contactSentence !!}Never ask users to post passwords, keys or other private account details anywhere, and tell them not to post them in public channels.
- This panel runs {!! $appName !!} {{ \PluggedHost\Core\Support\Release::full() }} (versions read v<generation>.<major release>.<patch>.<commit>; it is also in the footer of every panel page).
- {!! $statusHost !== '' ? 'Status page: ' . $statusHost . ' (nodes, incidents, history). ' : '' !!}{!! $termsSentence !!}It is a free service with no uptime guarantee (SLA).

Creating servers and the free allowance
- Users create servers themselves (/create-server): pick a game, a location and a size. Everything is free within the account's allowance, which is shared across all their servers.
@if($tiers)
- A new account's allowance is {{ (int) $tiers['default']['cpu'] }}% CPU, {{ $gb($tiers['default']['memory']) }} RAM and {{ $gb($tiers['default']['disk']) }} disk in total; @if($discordServers)being in Discord servers adds more: each server on the Discord servers tab of the profile page (/profile?tab=discord-servers) adds its own RAM, CPU or disk while the account's linked Discord is in it, and some also pay credits once for joining. In all of them the allowance is up to {{ (int) $tiers['discord']['cpu'] }}% CPU, {{ $gb($tiers['discord']['memory']) }} RAM and {{ $gb($tiers['discord']['disk']) }} disk. Memberships are checked every few minutes (the tab's "Check again" asks right away), and leaving a server takes what it adds away again.@else while the account's linked Discord is in the Discord server it is {{ (int) $tiers['discord']['cpu'] }}% CPU, {{ $gb($tiers['discord']['memory']) }} RAM and {{ $gb($tiers['discord']['disk']) }} disk.@endif Discord is linked {{ $externalAccounts ? 'on ' . $portalName . ' (Settings)' : 'on the profile page (/profile)' }}. Credits buy more (see below).{!! $externalAccounts ? ' The allowance is the ' . $portalName . ' account\'s pool, shared with the other services that use that account: what they use is not available here.' : '' !!}
@endif
- Size presets: @foreach($plans as $p){{ $label($p) }} {{ (int) $p['cpu'] }}% CPU / {{ $gb($p['memory']) }} / {{ $gb($p['disk']) }}{{ $loop->last ? '.' : '; ' }}@endforeach Custom sizes are allowed within the allowance.
- Each server gets {{ (int) ($ucs['database_limit'] ?? 0) }} databases, {{ (int) ($ucs['allocation_limit'] ?? 0) }} ports and {{ (int) ($ucs['backup_limit'] ?? 0) }} panel backups.
- Servers can be resized by their owner within the allowance ({{ ($ucs['can_users_update_servers'] ?? false) ? 'enabled' : 'disabled here' }}) and deleted by their owner ({{ ($ucs['can_users_delete_servers'] ?? false) ? 'enabled' : 'disabled here' }}). If an allowance shrinks (e.g. Discord unlinked), existing servers keep running but cannot grow.
- New servers install, then start on their own.{{ $enabled('plugged-host-minecraft') ? ' If an install is still running after ' . \PluggedHost\Minecraft\Filament\Server\Pages\ServerInstallingPage::STUCK_AFTER_MINUTES . ' minutes, the installing page offers Reinstall and Delete server (Delete also when the install failed).' : '' }} Players connect with the node's hostname and the port (the address shown in the panel), not a raw IP. Minecraft servers open a setup wizard (software, version, modpack, Bedrock support, whitelist, operators) that must be finished before the rest of the panel unlocks.

Locations and tiers
- Locations are countries.{!! $economy ? ' Free locations have no uptime guarantee; Plus and Premium locations run on better hardware that is never shared with the free tier. Plus locations need ' . $appName . ' Plus, Premium locations need ' . $appName . ' PlusPlus (see below).' : '' !!} New servers go to the least-busy node in the chosen location.
@if($cfg('plugged-host-servers.transfers.enabled', true))
- A server can be moved to another location from its Transfer page (the owner, or a subuser given the "Transfer" permission). The user picks only the location; the node is chosen automatically. The server stops, its files and backups are copied and verified, and it starts again on the new node (offline meanwhile, usually a few minutes). Its address changes to the new node's hostname (the port is kept when free); subdomains update themselves. The old copy is only removed once the new one is verified; if a node fails the move retries on another node of the location, and if it gives up the server stays where it was, running again. After a move, the same server can be moved again {{ (int) $cfg('plugged-host-servers.transfers.cooldown_hours', 6) }} hours later.
@endif

@if($economy)
Credits and tokens (two separate currencies; they cannot be exchanged)
- Credits buy permanent resource upgrades in the Resource Store (/store). There is no hard cap; prices climb with what the account already has. On the way to its limit a step costs its share of the way there (about half price at half the limit, never less than {{ (int) ($pricing['curve_floor_percent'] ?? 50) }}%), the full step price at the limit{{ $pricing ? ' (+' . (int) ($pricing['cpu_unit_percent'] ?? 50) . '% CPU for ' . (int) ($pricing['cpu_price_credits'] ?? 0) . ', +' . $gb($pricing['memory_unit_mb'] ?? 1024) . ' RAM for ' . (int) ($pricing['memory_price_credits'] ?? 0) . ', +' . $gb($pricing['storage_unit_mb'] ?? 5120) . ' disk for ' . (int) ($pricing['storage_price_credits'] ?? 0) . ')' : '' }}, then up to {{ (int) ($pricing['soft_limit_multiplier'] ?? 4) }}x that at the soft limit ({{ (int) ($pricing['soft_limit_percent'] ?? 125) }}% of the limit), and past the soft limit the price doubles every {{ (int) ($pricing['doubling_percent'] ?? 5) }}% of the limit, so growing far past it is meant to stay rare. Buying several steps at once costs the same as one at a time; the store shows the exact price of each offer{{ $plan ? '. Limits for this account: ' . (int) $plan['max_cpu'] . '% CPU / ' . $gb($plan['max_memory']) . ' / ' . $gb($plan['max_disk']) . ' disk, soft limits ' . (int) round($plan['max_cpu'] * ($pricing['soft_limit_percent'] ?? 125) / 100) . '% / ' . $gb(round($plan['max_memory'] * ($pricing['soft_limit_percent'] ?? 125) / 100)) . ' / ' . $gb(round($plan['max_disk'] * ($pricing['soft_limit_percent'] ?? 125) / 100)) : '' }}. {{ $externalAccounts ? 'The same credits and resources show on ' . $portalName . ', which has a store of its own with the same prices. ' : '' }}Credits are earned by inviting people to the Discord server ({{ (int) $cfg('plugged-host-discord.credits_per_invite', 50) }} per invite, paid once they have stayed {{ (int) $cfg('plugged-host-discord.invite_maturity_hours', 6) }} hours; the invited Discord account must be at least {{ (int) $cfg('plugged-host-discord.min_account_age_days', 30) }} days old{{ $cfg('plugged-host-discord.invite_clawback', true) ? ', and the credits are taken back if they leave' : '' }}), {!! $discordServers ? 'boosting any of the Discord servers (boosts on all of them count together and each boost level pays once; the amounts are on the Discord rewards tab), joining the Discord servers that pay for it (once)' : 'boosting it (' . (int) $cfg('plugged-host-discord.credits_per_boost_1', 500) . ' for one boost, ' . (int) $cfg('plugged-host-discord.credits_per_boost_2', 1250) . ' for two)' !!} and giveaways; the Discord rewards tab of the profile page (/profile?tab=discord-rewards) shows what invites and boosts earned and what is still waiting.
- Tokens pay for {!! $appName !!} Plus and PlusPlus (the {!! $appName !!} Plus page, /plus), restoring hibernated servers and subdomain deposits (paid on the server itself). They are earned from sponsored links on the Tokens page (/tokens, which also shows the token history{{ $earnLinks ? '; ' . collect($earnLinks)->map(fn ($p) => $p->label() . ' ' . $p->rewardAmount() . ' per link, up to ' . $p->dailyCap() . ' a day')->implode(', ') : '' }}){{ (int) $cfg('plugged-host-economy.tokens.daily_grant', 0) > 0 ? ', a free ' . (int) $cfg('plugged-host-economy.tokens.daily_grant') . ' per day' : '' }}. Balances show in the sidebar.
- {!! $appName !!} Plus costs {{ (int) $cfg('plugged-host-economy.membership.plus_token_cost', 25) }} tokens once and is kept for good: the Plus locations, priority in the start queue, {!! $devbox ? 'VS Code on their servers (a devbox: a Linux box next to the server with its files at /server, a terminal and every VS Code extension, opened from the VS Code section of the server\'s Settings page; it stops after ' . (int) $cfg('plugged-host-devbox.idle_minutes', 15) . ' minutes without VS Code), ' : '' !!}{{ max(0, (int) $cfg('plugged-host-economy.membership.plus_ai_percent', 125) - 100) }}% more neo usage, and no in-game ads (Minecraft servers of accounts without Plus get a short "{!! $inGameMessage !!}" chat message about once an hour). Everyone who bought the old one-time Plus/Premium location unlock got Plus; the old priority passes were refunded.
- {!! $appName !!} PlusPlus needs Plus and is not permanent: {{ (int) $cfg('plugged-host-economy.membership.plusplus_token_cost', 10) }} tokens for {{ (int) $cfg('plugged-host-economy.membership.plusplus_token_days', 7) }} days or {{ (int) $cfg('plugged-host-economy.membership.plusplus_credit_cost', 150) }} credits for {{ (int) $cfg('plugged-host-economy.membership.plusplus_credit_days', 14) }} days, bought again to keep it (buying early adds to the time left; nothing renews by itself). While it runs: everything in Plus, the Premium locations, no ads anywhere (panel ads, the start waiting room, in-game), the front of the start queue (ahead of Plus), +{{ (int) $cfg('plugged-host-economy.membership.plusplus_cpu_bonus', 50) }}% CPU on the account's allowance, and another {{ max(0, (int) $cfg('plugged-host-economy.membership.plusplus_ai_percent', 125) - 100) }}% more neo usage on top of Plus. When it runs out, servers on Premium locations are moved to another location automatically (Plus before free, same country first), and if the servers then use more CPU than the allowance, only the excess comes off, split evenly across the account's servers.

@endif
Starting servers: start queue, waiting room, hibernation
@if($cfg('plugged-host-queue.enabled', false))
- When a node is busy (over {{ (int) $cfg('plugged-host-queue.threshold_percent', 85) }}% CPU or memory), starts and restarts wait in a start queue and fire automatically when there is room, even if the tab is closed.{!! $economy ? ' ' . $appName . ' Plus puts all the account\'s servers ahead of everyone without it, PlusPlus at the very front; priority is no longer sold on its own. With Plus, a server under ' . $gb($cfg('plugged-host-queue.transfer.max_disk', 3072)) . ' can be moved to a quieter node in the same location (its address changes).' : '' !!}
@endif
@if($cfg('plugged-host-ads.start_credits.enabled', false))
- Starting {!! max($gateSizes) > 0 ? 'a server bigger than ' . $gateSizes[0] . '% CPU / ' . $gb($gateSizes[1]) . ' RAM / ' . $gb($gateSizes[2]) . ' disk' : 'a server' !!} shows a short ad waiting room ({{ (int) $cfg('plugged-host-ads.start_credits.min_dwell', 15) }}-{{ (int) $cfg('plugged-host-ads.start_credits.max_dwell', 30) }} s); the server is already starting meanwhile, and there is a {{ (int) $cfg('plugged-host-ads.start_credits.pass_minutes', 10) }}-minute grace window afterwards.{!! $economy ? ' Only PlusPlus removes it.' : '' !!}
@endif
@if($cfg('plugged-host-limbo.enabled', false))
- Servers with no panel activity are stopped after {{ (int) $cfg('plugged-host-limbo.stop_hours', 48) }} hours and hibernated after {{ (int) $cfg('plugged-host-limbo.transfer_hours', 72) }} hours. The owner restores a hibernated server from its page for {{ (int) $cfg('plugged-host-limbo.cost_early', 5) }} tokens within {{ (int) $cfg('plugged-host-limbo.cost_window_hours', 72) }} hours, {{ (int) $cfg('plugged-host-limbo.cost_late', 10) }} after that.
@endif

Backups and safety
- Each server has panel backups (restore or download).{!! $storage ? ' Server files are also mirrored off-site; the Backups page' . ($backupsHost !== '' ? ' and ' . $backupsHost . ' offer' : ' offers') . ' snapshot downloads, even when a server is offline. If a node goes down for several minutes, its servers are restored automatically on another node in the same location (the address can change).' : '' !!} Users should still keep their own copies of important data.

@if($logCheck)
Console logs
- On Minecraft servers the server log is checked automatically: it is uploaded to mclo.gs on its own (nobody has to press anything; secrets are removed first) and whatever mclo.gs recognises is shown above the console, with its suggested fix and an "Ask neo to fix it" button that opens this chat with the log's analysis loaded. With no problems there is a line with the log's mclo.gs link, to share with support. There is no upload button.

@endif
@if($subdomains)
Subdomains
- Available for game servers that support it (Minecraft Java and FiveM/RedM), up to {{ (int) $cfg('subdomains.default_limit', 3) }} per server.{!! $subdomainRules !!} They are managed on the server's Subdomains page.

@endif
@if($fivem)
FiveM and RedM
- FiveM/RedM servers have their own pages: Server configuration (server.cfg as a form or as text; FXServer reads it at start, so changes apply after a restart), Frameworks (one-click framework installs from txAdmin's recipes, put in a new folder while the current files are kept; some need one of the server's databases), Artifacts (the FXServer build: pick one, or let every start install Cfx's newest) and Players (the live player list).{{ $cfg('plugged-host-fivem.enhanced', false) ? ' FiveM servers can switch to FiveM for GTAV Enhanced (early access at Cfx.re) on the GTAV Enhanced page, and back.' : '' }}

@endif
Minecraft
- Java servers run Purpur (Paper/Spigot/Bukkit plugins), Fabric, Forge or NeoForge; switching software or version reinstalls the server but keeps the world and configs. Plugins and mods come from Modrinth (and CurseForge where available), including one-click modpacks{{ $cfg('plugged-host-minecraft.spigotmc_enabled', true) ? '; Paper/Purpur servers can also install SpigotMC plugins (latest build only, free ones hosted on SpigotMC or as a GitHub release)' : '' }}. Geyser + Floodgate add Bedrock (phone/console) support on a separate port. Game settings and server.properties, the whitelist and operators have their own pages. The Worlds page downloads, uploads (replacing the active world), regenerates or switches worlds and sets how a new world is generated (seed, type, structures, data packs); anything that replaces a world needs the server stopped and can keep a zip of the old one. The installed plugins/mods list identifies every jar, shows updates and missing dependencies, and can update, disable or delete them.
