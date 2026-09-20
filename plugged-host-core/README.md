# plugged.host Core

Core plugged.host features in one plugin.

## Features

- Self-service password and OAuth registration
- Required Terms of Service and Privacy Policy acknowledgement for every password, OAuth, and passkey authentication
- Public status collection, 90-day history, node aliases, and incident publishing
- Available Resources admin page with aggregate and per-node usage charts
- User-created servers with role-based resource limits and multi-tier presets (Starter to Master)
- Credits economy and Resource Store for purchasing additional CPU, RAM, Disk, and Server Slots
- Optional default resource limits for users without a configured resource role
- Automatic allocation placement during server creation
- Automatic next-port creation from the client Network page
- Always shows all accessible servers, hides server list tabs, and removes Pelican footer branding
- Enforces a primary allocation for every server
- Resource usage overview for users with direct store integration
- Configurable deployment tags, ports, eggs, databases, allocations, backups, and server actions

## Setup

By default, servers can be created on any public node with enough available resources. To restrict deployment to specific nodes, set `UCS_DEPLOYMENT_TAGS` to a comma-separated list of node tags.

Registration, credits, resource store, and user-created server settings are managed together from the `plugged.host Core` plugin settings.

Legal document URLs and policy versions are also managed in the plugin settings. The public status exporter writes sanitized JSON and RSS data to `STATUS_EXPORT_PATH` (default: `/var/www/status`).

