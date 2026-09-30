# plugged.host Core

Core plugged.host features in one plugin.

## Features

- Sign-in through noprofit.space (SSO); sign-up, passkeys, two-factor codes, Discord linking, legal acceptance and account deletion live there
- Public status collection, 90-day history, node aliases, and incident publishing
- Available Resources admin page with aggregate and per-node usage charts
- User-created servers within the account's noprofit.space plan, with multi-tier presets (Starter to Master)
- Credits economy and Resource Store for purchasing additional CPU, RAM and Disk
- Automatic allocation placement during server creation
- Automatic next-port creation from the client Network page
- Always shows all accessible servers, hides server list tabs, and removes Pelican footer branding
- Enforces a primary allocation for every server
- Resource usage overview for users with direct store integration
- Configurable deployment tags, ports, eggs, databases, allocations, backups, and server actions

## Setup

By default, servers can be created on any public node with enough available resources. To restrict deployment to specific nodes, set `UCS_DEPLOYMENT_TAGS` to a comma-separated list of node tags.

Sign-up (which methods are open), starting credits, the legal documents and their versions, the compute tiers and caps, and the store prices for CPU, memory and disk are set on noprofit.space (Admin › Settings). The panel's own settings (welcome tour, sidebar) are in the `plugged.host Core` plugin settings.

The public status exporter writes sanitized JSON and RSS data to `STATUS_EXPORT_PATH` (default: `/var/www/status`).

