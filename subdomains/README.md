# Subdomains (by Boy132 & HarlequinSin)

Allows users to create and manage custom subdomains (A/AAAA or SRV) for their game servers using Cloudflare DNS.

## Setup

[Create a Cloudflare API token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/) and enter it via the plugin settings.  
The token needs to have read permissions for `Zone.Zone` and write for `Zone.Dns`. For better security you can also set the `Zone Resources` to exclude certain domains and add the panel ip to the `Client IP Address Filtering`.

By default every server has a subdomain limit of 0. You can change this limit by editing the server in the admin area.

Note: You can't create subdomains for servers with `0.0.0.0` or `::` as allocation!

## Which servers get subdomains

`SUBDOMAINS_SUPPORTED_EGGS` (Supported Egg IDs) lists the eggs whose servers may have subdomains.
Left empty, every egg recognised as Minecraft: Java Edition or FiveM / RedM qualifies (see below).

Creating one can require the owner to have linked Discord (`SUBDOMAINS_REQUIRE_DISCORD`) and to be
in your Discord server (`SUBDOMAINS_REQUIRE_DISCORD_GUILD`). The server check needs the Discord
plugin (`plugged-host-discord`) and is skipped while it is disabled. The "Join Discord" link only
shows when that plugin has an invite URL (`DISCORD_INVITE_URL`).

Each DNS record is created with the comment "Created by <APP_NAME> Subdomains (…)"; records are
recognised as the plugin's own by that comment under any panel name.

## SRV Records

In order to create SRV records instead of A/AAAA you need to do the following:

1. Set a `SRV target` for the node
2. Let the plugin know the egg's service. It checks, in this order:
   - the egg ids under **SRV Service Detection** (`SUBDOMAINS_MINECRAFT_EGGS`, `SUBDOMAINS_FIVEM_EGGS`);
   - a [SRV service type](https://github.com/pelican-dev/plugins/blob/main/subdomains/src/Enums/SRVServiceType.php#L10-L15) in the egg's features. The format is `srv-` and then the service name, e.g. `srv-minecraft` or `srv-rust`;
   - the egg's tags: `cfx` is FiveM / RedM, `minecraft` is Minecraft: Java Edition (not for Bedrock eggs or eggs also tagged `proxy`);
   - the egg's name ("Minecraft" without "Bedrock", "FiveM", "RedM").
